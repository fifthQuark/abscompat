<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\ListService;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/** Playlists and collections. ABS shares collections between users; here both belong to one user. */
class ListController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private Catalog $catalog,
		private ListService $lists,
	) {
		parent::__construct($request, $tokens, $library);
	}

	private function bad(string $msg): JSONResponse {
		return new JSONResponse(['error' => $msg], Http::STATUS_BAD_REQUEST);
	}

	/** Item ids in the body: ["id"] for collections, [{libraryItemId: "id"}] for playlists. @return ?list<string> */
	private static function ids(mixed $in): ?array {
		if (!is_array($in)) {
			return null;
		}
		$out = [];
		foreach ($in as $i) {
			$id = is_array($i) ? ($i['libraryItemId'] ?? null) : $i;
			if (!is_scalar($id) || (string)$id === '' || (is_array($i) && !empty($i['episodeId']))) {
				return null;
			}
			$out[] = (string)$id;
		}
		return array_values(array_unique($out));
	}

	/** Are these all books of that library? */
	private function known(string $uid, array $ids, string $libraryId): bool {
		return count(array_filter($this->catalog->visibleRows($uid, $libraryId, $ids))) === count($ids);
	}

	private function page(string $kind, string $libraryId): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $libraryId) === null) {
			return $this->notFound('Library not found');
		}
		$list = $this->lists->all($uid, $kind, $libraryId);
		$limit = max(0, (int)$this->request->getParam('limit', 0));
		$page = max(0, (int)$this->request->getParam('page', 0));
		$shown = $limit > 0 ? array_slice($list, $page * $limit, $limit) : $list;
		return new JSONResponse([
			'results' => array_map(fn ($l) => $this->catalog->listJson($uid, $l), $shown),
			'total' => count($list), 'limit' => $limit, 'page' => $page, 'offset' => $page * $limit,
			'minified' => true, 'include' => '', 'sortDesc' => false,
		]);
	}

	private function every(string $kind, string $key): JSONResponse {
		$uid = $this->currentUser();
		return $uid === null ? $this->unauthorized()
			: new JSONResponse([$key => array_map(fn ($l) => $this->catalog->listJson($uid, $l), $this->lists->all($uid, $kind))]);
	}

	private function add(string $kind, string $key): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$body = $this->body();
		$name = ListService::cleanName($body['name'] ?? null);
		$libraryId = is_scalar($body['libraryId'] ?? null) ? (string)$body['libraryId'] : '';
		$ids = self::ids($body[$key] ?? []);
		if ($name === '' || $ids === null || $this->library->getLibrary($uid, $libraryId) === null) {
			return $this->bad('Invalid name, library or items');
		}
		if (($kind === ListService::COLLECTION && $ids === []) || !$this->known($uid, $ids, $libraryId)) {
			return $this->bad('Invalid items');
		}
		return new JSONResponse($this->catalog->listJson($uid, $this->lists->create($uid, $kind, $libraryId, $name, ListService::cleanDescription($body['description'] ?? null), $ids)));
	}

	/** @param callable(string $uid, array $list, array $body): (JSONResponse|array) $fn returns a response, or changes */
	private function with(string $kind, string $id, callable $fn): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$list = $this->lists->get($uid, $kind, $id);
		if ($list === null) {
			return $this->notFound(ucfirst($kind) . ' not found');
		}
		$r = $fn($uid, $list, $this->body());
		if ($r instanceof JSONResponse) {
			return $r;
		}
		if ($r !== []) {
			$list = $this->lists->update($uid, $list, $r);
		}
		$json = $this->catalog->listJson($uid, $list);
		// As in ABS: a playlist without items is gone.
		if ($kind === ListService::PLAYLIST && ($r['items'] ?? null) === []) {
			$this->lists->delete($list);
		}
		return new JSONResponse($json);
	}

	private function edit(string $kind, string $id, string $key): JSONResponse {
		return $this->with($kind, $id, function (string $uid, array $list, array $body) use ($key) {
			$changes = [];
			if (ListService::cleanName($body['name'] ?? null) !== '') {
				$changes['name'] = ListService::cleanName($body['name']);
			}
			if (array_key_exists('description', $body)) {
				$changes['description'] = ListService::cleanDescription($body['description']);
			}
			if (!empty($body[$key])) {
				// Only a new order; items the client doesn't know (a missing folder) stay at the end.
				$ids = self::ids($body[$key]);
				$items = ListService::items($list);
				if ($ids === null || array_diff($ids, $items) !== []) {
					return $this->bad('Invalid items');
				}
				$changes['items'] = array_merge($ids, array_diff($items, $ids));
			}
			return $changes;
		});
	}

	private function remove(string $kind, string $id): JSONResponse {
		return $this->with($kind, $id, function (string $uid, array $list) {
			$this->lists->delete($list);
			return new JSONResponse([]);
		});
	}

	private function append(string $kind, string $id, ?array $ids): JSONResponse {
		return $this->with($kind, $id, function (string $uid, array $list) use ($ids) {
			if (!$ids || !$this->known($uid, $ids, (string)$list['library_id'])) {
				return $this->bad('Invalid items');
			}
			return ['items' => array_merge(ListService::items($list), $ids)];
		});
	}

	private function drop(string $kind, string $id, ?array $ids): JSONResponse {
		return $this->with($kind, $id, fn (string $uid, array $list) => $ids === null ? $this->bad('Invalid items')
			: ['items' => array_values(array_diff(ListService::items($list), $ids))]);
	}

	// ----- playlists -----

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/playlists')]
	public function libraryPlaylists(string $id): JSONResponse {
		return $this->page(ListService::PLAYLIST, $id);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/playlists')]
	public function playlists(): JSONResponse {
		return $this->every(ListService::PLAYLIST, 'playlists');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/playlists')]
	public function createPlaylist(): JSONResponse {
		return $this->add(ListService::PLAYLIST, 'items');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/playlists/collection/{id}')]
	public function playlistFromCollection(string $id): JSONResponse {
		return $this->with(ListService::COLLECTION, $id, function (string $uid, array $c) {
			$items = ListService::items($c);
			return $items === [] ? $this->bad('Collection has no books')
				: new JSONResponse($this->catalog->listJson($uid, $this->lists->create($uid, ListService::PLAYLIST, (string)$c['library_id'], (string)$c['name'], $c['description'], $items)));
		});
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/playlists/{id}')]
	public function playlist(string $id): JSONResponse {
		return $this->with(ListService::PLAYLIST, $id, static fn () => []);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/playlists/{id}')]
	public function updatePlaylist(string $id): JSONResponse {
		return $this->edit(ListService::PLAYLIST, $id, 'items');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/playlists/{id}')]
	public function deletePlaylist(string $id): JSONResponse {
		return $this->remove(ListService::PLAYLIST, $id);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/playlists/{id}/item')]
	public function addPlaylistItem(string $id): JSONResponse {
		return $this->append(ListService::PLAYLIST, $id, self::ids([$this->body()]));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/playlists/{id}/item/{itemId}')]
	public function removePlaylistItem(string $id, string $itemId): JSONResponse {
		return $this->drop(ListService::PLAYLIST, $id, [$itemId]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/playlists/{id}/batch/add')]
	public function addPlaylistItems(string $id): JSONResponse {
		return $this->append(ListService::PLAYLIST, $id, self::ids($this->body()['items'] ?? null));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/playlists/{id}/batch/remove')]
	public function removePlaylistItems(string $id): JSONResponse {
		return $this->drop(ListService::PLAYLIST, $id, self::ids($this->body()['items'] ?? null));
	}

	// ----- collections -----

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/collections')]
	public function libraryCollections(string $id): JSONResponse {
		return $this->page(ListService::COLLECTION, $id);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/collections')]
	public function collections(): JSONResponse {
		return $this->every(ListService::COLLECTION, 'collections');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/collections')]
	public function createCollection(): JSONResponse {
		return $this->add(ListService::COLLECTION, 'books');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/collections/{id}')]
	public function collection(string $id): JSONResponse {
		return $this->with(ListService::COLLECTION, $id, static fn () => []);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/collections/{id}')]
	public function updateCollection(string $id): JSONResponse {
		return $this->edit(ListService::COLLECTION, $id, 'books');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/collections/{id}')]
	public function deleteCollection(string $id): JSONResponse {
		return $this->remove(ListService::COLLECTION, $id);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/collections/{collectionId}/book')]
	public function addBook(string $collectionId): JSONResponse {
		return $this->append(ListService::COLLECTION, $collectionId, self::ids([$this->body()['id'] ?? null]));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/collections/{id}/book/{bookId}')]
	public function removeBook(string $id, string $bookId): JSONResponse {
		return $this->drop(ListService::COLLECTION, $id, [$bookId]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/collections/{id}/batch/add')]
	public function addBooks(string $id): JSONResponse {
		return $this->append(ListService::COLLECTION, $id, self::ids($this->body()['books'] ?? null));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/collections/{id}/batch/remove')]
	public function removeBooks(string $id): JSONResponse {
		return $this->drop(ListService::COLLECTION, $id, self::ids($this->body()['books'] ?? null));
	}
}
