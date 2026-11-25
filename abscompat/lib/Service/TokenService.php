<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/**
 * Tokens issued by this app. Only SHA-256 hashes are stored.
 *
 * Kinds:
 *  - apikey:  created on the settings page (e.g. for CrossPoint Sync), never expires
 *  - refresh: created on /login, sliding expiry, one per device login
 *  - access:  short-lived, child of a refresh token
 */
class TokenService {
	public const ACCESS_TTL = 7 * 24 * 3600;
	public const REFRESH_TTL = 180 * 24 * 3600;
	private const PREFIX = 'abs_';
	private const TABLE = 'abs_tokens';

	public function __construct(
		private IDBConnection $db,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private IUserManager $userManager,
	) {
	}

	private static function hash(string $token): string {
		return hash('sha256', $token);
	}

	private function newSecret(): string {
		return self::PREFIX . $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
	}

	private function insert(string $userId, string $name, string $kind, string $secret, ?int $parentId, ?int $expiresAt): int {
		$now = $this->time->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'user_id' => $qb->createNamedParameter($userId),
			'name' => $qb->createNamedParameter(mb_substr($name, 0, 255)),
			'kind' => $qb->createNamedParameter($kind),
			'token_hash' => $qb->createNamedParameter(self::hash($secret)),
			'parent_id' => $qb->createNamedParameter($parentId, $parentId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'last_used' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'expires_at' => $qb->createNamedParameter($expiresAt, $expiresAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	/** @return array{accessToken: string, refreshToken: string} */
	public function createLoginTokens(string $userId, string $deviceName): array {
		$now = $this->time->getTime();
		$refresh = $this->newSecret();
		$refreshId = $this->insert($userId, $deviceName, 'refresh', $refresh, null, $now + self::REFRESH_TTL);
		$access = $this->newSecret();
		$this->insert($userId, $deviceName, 'access', $access, $refreshId, $now + self::ACCESS_TTL);
		return ['accessToken' => $access, 'refreshToken' => $refresh];
	}

	public function createApiKey(string $userId, string $name): string {
		$secret = $this->newSecret();
		$this->insert($userId, $name, 'apikey', $secret, null, null);
		return $secret;
	}

	/** @return array<string,mixed>|null */
	private function findValid(string $secret, array $kinds): ?array {
		if ($secret === '' || strlen($secret) > 200) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter(self::hash($secret))))
			->andWhere($qb->expr()->in('kind', $qb->createNamedParameter($kinds, IQueryBuilder::PARAM_STR_ARRAY)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		if (!$row) {
			return null;
		}
		$exp = $row['expires_at'];
		if ($exp !== null && (int)$exp < $this->time->getTime()) {
			return null;
		}
		$user = $this->userManager->get((string)$row['user_id']);
		if ($user === null || !$user->isEnabled()) {
			return null;
		}
		return $row;
	}

	public function isActiveUser(string $userId): bool {
		return $this->userManager->get($userId)?->isEnabled() === true;
	}

	private function touch(array $row, ?int $newExpiry = null): void {
		$now = $this->time->getTime();
		if ($newExpiry === null && $now - (int)$row['last_used'] < 60) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('last_used', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
		if ($newExpiry !== null) {
			$qb->set('expires_at', $qb->createNamedParameter($newExpiry, IQueryBuilder::PARAM_INT));
		}
		$qb->executeStatement();
	}

	/** Returns the user id for a valid access token or API key, else null. */
	public function validateAccessToken(string $secret): ?string {
		$row = $this->findValid($secret, ['access', 'apikey']);
		if ($row === null) {
			return null;
		}
		$this->touch($row);
		return (string)$row['user_id'];
	}

	/**
	 * Exchange a refresh token for a new access token. The refresh token is kept
	 * (sliding expiry) so a retried request after a lost response still works.
	 *
	 * @return array{userId: string, accessToken: string, refreshToken: string}|null
	 */
	public function refresh(string $refreshSecret): ?array {
		$row = $this->findValid($refreshSecret, ['refresh']);
		if ($row === null) {
			return null;
		}
		$now = $this->time->getTime();
		$this->touch($row, $now + self::REFRESH_TTL);
		// Drop expired access tokens of this login.
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('parent_id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		$access = $this->newSecret();
		$this->insert((string)$row['user_id'], (string)$row['name'], 'access', $access, (int)$row['id'], $now + self::ACCESS_TTL);
		return ['userId' => (string)$row['user_id'], 'accessToken' => $access, 'refreshToken' => $refreshSecret];
	}

	/** Revoke the login (refresh + its access tokens) that this access token belongs to. */
	public function logout(string $accessSecret): void {
		$row = $this->findValid($accessSecret, ['access', 'refresh']);
		if ($row === null) {
			return;
		}
		$loginId = $row['kind'] === 'refresh' ? (int)$row['id'] : (int)$row['parent_id'];
		$this->revoke((string)$row['user_id'], $loginId);
	}

	/** Lists API keys and device logins (never access tokens) for the settings page. */
	public function listForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'kind', 'created_at', 'last_used', 'expires_at')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('kind', $qb->createNamedParameter(['apikey', 'refresh'], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('created_at', 'DESC');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			// A device login was last used when any of its access tokens was.
			$out[] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'kind' => $row['kind'] === 'apikey' ? 'apikey' : 'device',
				'createdAt' => (int)$row['created_at'],
				'lastUsed' => $this->lastUsed((int)$row['id'], (int)$row['last_used']),
			];
		}
		$res->closeCursor();
		return $out;
	}

	private function lastUsed(int $id, int $own): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('last_used'))->from(self::TABLE)
			->where($qb->expr()->eq('parent_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$v = (int)$qb->executeQuery()->fetchOne();
		return max($own, $v);
	}

	/** Open playback sessions stream without a token, so they end when one is revoked. Apps still signed in reopen theirs with the next sync. */
	private function closeSessions(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('abs_sessions')->set('closed', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('closed', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function revoke(string $userId, int $id): bool {
		$this->closeSessions($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('parent_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
			));
		return $qb->executeStatement() > 0;
	}

	/** Log out every device (refresh + access tokens); API keys stay. Returns the number of logins. */
	public function revokeAllDevices(string $userId): int {
		$this->closeSessions($userId);
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('kind', $qb->createNamedParameter(['refresh', 'access'], IQueryBuilder::PARAM_STR_ARRAY)));
		return $qb->executeStatement();
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/** Bearer header, or ?token= (ABS clients use it for covers and media URLs). */
	public static function tokenFromRequest(IRequest $request, bool $allowQuery): string {
		$h = $request->getHeader('Authorization');
		if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
			return $m[1];
		}
		if ($allowQuery) {
			$q = $request->getParam('token');
			if (is_string($q)) {
				return $q;
			}
		}
		return '';
	}
}
