<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Service\BookmarkService;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\SessionService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\ProgressService;
use OCA\AbsCompat\Service\Serializer;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

class MeController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private IUserManager $userManager,
		private ProgressService $progress,
		private Catalog $catalog,
		private BookmarkService $bookmarks,
		private SessionService $sessions,
	) {
		parent::__construct($request, $tokens, $library);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/me')]
	public function me(): JSONResponse {
		$uid = $this->currentUser();
		$user = $uid !== null ? $this->userManager->get($uid) : null;
		if ($user === null) {
			return $this->unauthorized();
		}
		return new JSONResponse(Serializer::user($uid, $user->getDisplayName(), $user->getEMailAddress(), $this->progress->allForUser($uid), $this->bookmarks->allForUser($uid)));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/me/items-in-progress')]
	public function itemsInProgress(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$this->ensureScanned($uid);
		$limit = max(1, min(100, (int)($this->request->getParam('limit') ?: 25)));
		$prog = array_filter($this->progress->allForUser($uid), static fn ($p) => !$p['is_finished'] && !$p['hide_continue']);
		$rows = $this->catalog->visibleRows($uid, null, array_map('strval', array_keys($prog)));
		$rows = Catalog::sortRows($rows, 'progress', true, $prog);
		$rows = array_slice($rows, 0, $limit);
		$items = $this->catalog->minified($uid, $rows);
		foreach ($items as &$it) {
			$it['progressLastUpdate'] = (int)$prog[$it['id']]['last_update'];
		}
		return new JSONResponse(['libraryItems' => $items]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/me/progress/{id}')]
	public function getProgress(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->progress->get($uid, $id);
		if ($row === null) {
			return $this->notFound('Progress not found');
		}
		return new JSONResponse(ProgressService::toJson($row, $uid));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/me/progress/{id}')]
	public function patchProgress(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$item = $this->catalog->item($uid, $id);
		if ($item === null) {
			return $this->notFound('Item not found');
		}
		$this->progress->apply($uid, $id, $this->body(), (float)$item['duration']);
		return new JSONResponse([]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/me/progress/batch/update')]
	public function batchUpdate(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$body = $this->body();
		$list = $body['_json'] ?? array_filter($body, 'is_array');
		foreach ((array)$list as $p) {
			if (!is_array($p) || !is_string($p['libraryItemId'] ?? null)) {
				continue;
			}
			$item = $this->catalog->item($uid, $p['libraryItemId']);
			if ($item !== null) {
				$this->progress->apply($uid, $p['libraryItemId'], $p, (float)$item['duration']);
			}
		}
		return new JSONResponse([]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/me/progress/{id}')]
	public function deleteProgress(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		// ABS deletes by media progress id; accept both that and the item id.
		$this->progress->delete($uid, str_starts_with($id, 'mp_') ? substr($id, 3) : $id);
		return new JSONResponse([]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/me/listening-stats')]
	public function listeningStats(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		return new JSONResponse($this->sessions->stats($uid));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/me/listening-sessions')]
	public function listeningSessions(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$perPage = max(1, min(100, (int)($this->request->getParam('itemsPerPage') ?: 10)));
		$page = max(0, (int)$this->request->getParam('page', 0));
		return new JSONResponse($this->sessions->listSessions($uid, $page, $perPage));
	}

	// ----- bookmarks: /api/me/item/:id/bookmark -----

	private function bookmarkInput(): ?array {
		$b = $this->body();
		$time = $b['time'] ?? null;
		if (!is_numeric($time)) {
			return null;
		}
		return [(float)$time, is_scalar($b['title'] ?? null) ? (string)$b['title'] : ''];
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/me/item/{id}/bookmark')]
	public function createBookmark(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->catalog->item($uid, $id) === null) {
			return $this->notFound('Item not found');
		}
		$in = $this->bookmarkInput();
		if ($in === null) {
			return new JSONResponse(['error' => 'Invalid time'], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse($this->bookmarks->upsert($uid, $id, $in[0], $in[1]));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/me/item/{id}/bookmark')]
	public function updateBookmark(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$in = $this->bookmarkInput();
		if ($in === null) {
			return new JSONResponse(['error' => 'Invalid time'], Http::STATUS_BAD_REQUEST);
		}
		$bm = $this->bookmarks->update($uid, $id, $in[0], $in[1]);
		return $bm === null ? $this->notFound('Bookmark not found') : new JSONResponse($bm);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/me/item/{id}/bookmark/{time}')]
	public function deleteBookmark(string $id, string $time): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if (!is_numeric($time) || !$this->bookmarks->delete($uid, $id, (float)$time)) {
			return $this->notFound('Bookmark not found');
		}
		return new JSONResponse([]);
	}
}
