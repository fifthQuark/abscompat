<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Base for the Audiobookshelf API. Routes are #[PublicPage] + #[NoCSRFRequired]: the
 * Nextcloud session is never used. Every call authenticates with our own Bearer token
 * (or ?token= on media URLs), so a cookie-only request is always rejected.
 */
abstract class AbsBaseController extends Controller {
	public function __construct(
		IRequest $request,
		protected TokenService $tokens,
		protected LibraryService $library,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	protected function currentUser(bool $allowQueryToken = false): ?string {
		$token = TokenService::tokenFromRequest($this->request, $allowQueryToken);
		if ($token === '') {
			return null;
		}
		return $this->tokens->validateAccessToken($token);
	}

	protected function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
	}

	protected function notFound(string $msg = 'Not found'): JSONResponse {
		return new JSONResponse(['error' => $msg], Http::STATUS_NOT_FOUND);
	}

	/** JSON body (Nextcloud already merges JSON bodies into the params). */
	protected function body(): array {
		$p = $this->request->getParams();
		unset($p['_route']);
		return $p;
	}

	/** For browsing endpoints: first scan, or a short incremental rescan when stale. */
	protected function refreshLibrary(string $userId): void {
		if (!$this->library->hasItems($userId)) {
			$this->library->scanUser($userId, 20.0);
			return;
		}
		$this->library->rescanIfStale($userId);
	}

	/** Make sure the user's libraries have been scanned at least once. */
	protected function ensureScanned(string $userId): void {
		if (!$this->library->hasItems($userId)) {
			$this->library->scanUser($userId, 20.0);
		}
	}
}
