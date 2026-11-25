<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\OpdsLink;
use OCA\AbsCompat\Service\StreamingService;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Settings endpoints. Normal Nextcloud session + CSRF token (no #[PublicPage]).
 * Personal endpoints are #[NoAdminRequired]; /settings/admin/* are admin-only.
 */
class SettingsController extends Controller {
	/** Per request; the browser calls rescan again until a pass completes. */
	private const SCAN_SLICE = 20.0;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LibraryService $library,
		private TokenService $tokens,
		private StreamingService $streaming,
		private OpdsLink $opds,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private function state(string $uid): array {
		$found = [];
		foreach ($this->library->getLibraries($uid) as $lib) {
			$found[$lib['path']] = $lib['id'];
		}
		$rows = $this->library->getItemRows($uid);
		$libraries = [];
		foreach ($this->library->getFolderPaths($uid) as $path) {
			$id = $found[$path] ?? null;
			$mine = $id === null ? [] : array_filter($rows, static fn ($r) => (string)$r['library_id'] === $id);
			$libraries[] = [
				'path' => $path,
				'found' => $id !== null,
				'books' => count($mine),
				'duration' => array_sum(array_map(static fn ($r) => (float)$r['duration'], $mine)),
				'size' => array_sum(array_map(static fn ($r) => (int)$r['size'], $mine)),
			];
		}
		return [
			'folders' => $this->library->getFolderPaths($uid),
			'usesDefault' => $this->library->usesDefaultFolders($uid),
			'defaultFolders' => $this->library->getDefaultFolderPaths(),
			'libraries' => $libraries,
			'lastScan' => $this->library->getLastScan($uid),
			'tokens' => $this->tokens->listForUser($uid),
			'streaming' => ['allowed' => $this->streaming->isAllowed(), 'on' => $this->streaming->isOn($uid)],
			'opds' => ['available' => $this->opds->available($uid), 'links' => $this->opds->links($uid), 'library' => $this->opds->hasLibrary($uid)],
		];
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/settings')]
	public function get(): JSONResponse {
		return new JSONResponse($this->state($this->uid()));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/folders')]
	public function setFolders(array $folders = []): JSONResponse {
		$clean = LibraryService::cleanPaths($folders);
		if ($clean === []) {
			return new JSONResponse(['error' => 'Add at least one folder'], Http::STATUS_BAD_REQUEST);
		}
		$this->library->setFolderPaths($this->uid(), $clean);
		return new JSONResponse($this->state($this->uid()));
	}

	/** Playing without downloading. The page asks for confirmation, with a warning, before turning it on. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/streaming')]
	public function setStreaming(bool $enabled = false): JSONResponse {
		$uid = $this->uid();
		if ($enabled && !$this->streaming->isAllowed()) {
			return new JSONResponse(['error' => 'The administrator does not allow streaming'], Http::STATUS_FORBIDDEN);
		}
		$this->streaming->set($uid, $enabled);
		return new JSONResponse($this->state($uid));
	}

	/** What to use from OPDS+. Only this app's settings change. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/settings/opds')]
	public function setOpds(bool $links = true, bool $library = false): JSONResponse {
		$uid = $this->uid();
		$this->opds->configure($uid, $links, $library);
		return new JSONResponse($this->state($uid));
	}

	/** Go back to the instance default folders. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/folders')]
	public function resetFolders(): JSONResponse {
		$this->library->resetFolderPaths($this->uid());
		return new JSONResponse($this->state($this->uid()));
	}

	/**
	 * One slice of a rescan. Unchanged files are skipped, so calling again continues where the
	 * last slice stopped. "complete" is true once a whole pass finished within the slice.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/rescan')]
	public function rescan(): JSONResponse {
		$uid = $this->uid();
		$started = microtime(true);
		$stats = $this->library->scanUser($uid, self::SCAN_SLICE);
		$complete = microtime(true) - $started < self::SCAN_SLICE;
		if ($complete) {
			$this->library->markScanned($uid);
		}
		return new JSONResponse($stats + ['complete' => $complete, 'state' => $this->state($uid)]);
	}

	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'POST', url: '/settings/apikeys')]
	public function createApiKey(string $name = ''): JSONResponse {
		$name = trim($name) !== '' ? trim($name) : 'API key';
		return new JSONResponse(['token' => $this->tokens->createApiKey($this->uid(), mb_substr($name, 0, 100))]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/tokens/{id}')]
	public function revoke(int $id): JSONResponse {
		if (!$this->tokens->revoke($this->uid(), $id)) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse([]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/settings/devices')]
	public function revokeAllDevices(): JSONResponse {
		$this->tokens->revokeAllDevices($this->uid());
		return new JSONResponse([]);
	}

	// ----- admin -----

	#[FrontpageRoute(verb: 'GET', url: '/settings/admin')]
	public function getAdmin(): JSONResponse {
		return new JSONResponse(['defaultFolders' => $this->library->getDefaultFolderPaths(), 'allowStreaming' => $this->streaming->isAllowed()]);
	}

	#[FrontpageRoute(verb: 'PUT', url: '/settings/admin')]
	public function setAdmin(?array $defaultFolders = null, ?bool $allowStreaming = null): JSONResponse {
		if ($defaultFolders !== null) {
			$this->library->setDefaultFolderPaths($defaultFolders);
		}
		if ($allowStreaming !== null) {
			$this->streaming->allow($allowStreaming);
		}
		return $this->getAdmin();
	}
}
