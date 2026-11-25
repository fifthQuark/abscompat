<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Http\CoverFile;
use OCA\AbsCompat\Service\BookmarkService;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\CoverService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\ListService;
use OCA\AbsCompat\Service\OpdsLink;
use OCA\AbsCompat\Service\ProgressService;
use OCA\AbsCompat\Service\Serializer;
use OCA\AbsCompat\Service\SessionService;
use OCA\AbsCompat\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/** Data for the library page. Normal Nextcloud session + CSRF token, unlike the ABS API. */
class WebController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private LibraryService $library,
		private Catalog $catalog,
		private ProgressService $progress,
		private UploadService $uploads,
		private CoverService $covers,
		private ListService $lists,
		private BookmarkService $bookmarks,
		private SessionService $sessions,
		private OpdsLink $opds,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private static function error(string $msg, int $status, string $code = ''): JSONResponse {
		return new JSONResponse(['error' => $msg, 'code' => $code], $status);
	}

	/**
	 * Keep only what differs from what is shown, so fields left alone go on following the tags and folders.
	 * @param array<string, mixed> $edits from LibraryService::cleanEdits()
	 */
	private static function changed(array $row, array $edits): array {
		$extra = Serializer::extra($row);
		$now = [
			'seriesSeq' => $row['series_seq'], 'year' => $row['published_year'], 'genres' => json_decode((string)$row['genres'], true) ?: [],
			'tags' => $extra['tags'] ?? [], 'moreSeries' => $extra['series'] ?? [], 'explicit' => !empty($extra['explicit']), 'abridged' => !empty($extra['abridged']),
		];
		return array_filter($edits, static function ($v, $k) use ($now, $row, $extra) {
			$old = array_key_exists($k, $now) ? $now[$k] : ($row[$k] ?? $extra[$k] ?? null);
			return is_array($v) || is_bool($v) ? $v !== $old : (string)$v !== (string)$old;
		}, ARRAY_FILTER_USE_BOTH);
	}

	private static function listJson(array $l): array {
		return ['id' => (string)$l['list_id'], 'kind' => (string)$l['kind'], 'libraryId' => (string)$l['library_id'], 'name' => (string)$l['name'], 'description' => $l['description'], 'items' => ListService::items($l)];
	}

	/** @param array<string, array> $progress */
	private static function book(array $r, array $progress): array {
		$p = $progress[(string)$r['item_key']] ?? null;
		$extra = Serializer::extra($r);
		return [
			'id' => (string)$r['item_key'],
			'libraryId' => (string)$r['library_id'],
			'title' => (string)$r['title'],
			'sortTitle' => Serializer::ignorePrefix((string)$r['title']),
			'subtitle' => $r['subtitle'],
			'author' => $r['author'],
			'authors' => Serializer::splitNames($r['author']),
			'narrator' => $r['narrator'],
			'series' => $r['series'],
			'seriesSeq' => $r['series_seq'],
			'moreSeries' => $extra['series'] ?? [],
			'narrators' => Serializer::splitNames($r['narrator']),
			'tags' => $extra['tags'] ?? [],
			'ebookOnly' => !empty($extra['ebookOnly']),
			'year' => $r['published_year'],
			'genres' => json_decode((string)$r['genres'], true) ?: [],
			'duration' => (float)$r['duration'],
			'size' => (int)$r['size'],
			'addedAt' => (int)$r['added_at'],
			'updatedAt' => (int)$r['updated_at'],
			'hasCover' => Serializer::hasCover($r),
			// Covers in the book's files can't be changed here.
			'coverEditable' => !CoverService::hasOwnCover($r),
			'hasEbook' => Serializer::ebooks($r) !== [],
			'progress' => $p === null ? null : [
				'progress' => !empty($extra['ebookOnly']) ? max((float)$p['progress'], (float)$p['ebook_progress']) : (float)$p['progress'],
				'currentTime' => (float)$p['cur_time'],
				'isFinished' => (bool)$p['is_finished'],
				'hidden' => (bool)$p['hide_continue'],
				'lastUpdate' => (int)$p['last_update'],
			],
		];
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/library')]
	public function library(): JSONResponse {
		$uid = $this->uid();
		if (!$this->library->hasItems($uid)) {
			$this->library->scanUser($uid, 20.0);
		} else {
			$this->library->rescanIfStale($uid);
		}
		$found = [];
		foreach ($this->library->getLibraries($uid) as $lib) {
			$found[$lib['path']] = $lib;
		}
		$libraries = [];
		foreach ($this->library->getFolderPaths($uid) as $path) {
			$lib = $found[$path] ?? null;
			$libraries[] = [
				'id' => $lib['id'] ?? null,
				'path' => $path,
				'name' => basename($path),
				// A missing library folder is created by the first upload.
				'writable' => $lib === null || $lib['folder']->isCreatable(),
			];
		}
		if ($this->opds->hasLibrary($uid)) {
			$libraries[] = ['id' => OpdsLink::LIBRARY, 'path' => '', 'name' => 'Ebooks', 'writable' => false];
		}
		$progress = $this->progress->allForUser($uid);
		return new JSONResponse([
			'lists' => array_map(fn ($l) => self::listJson($l), array_merge($this->lists->all($uid, ListService::COLLECTION), $this->lists->all($uid, ListService::PLAYLIST))),
			'opds' => $this->opds->links($uid),
			'covers' => ['auto' => $this->covers->isAuto($uid), 'online' => $this->covers->online()],
			'libraries' => $libraries,
			'books' => array_map(static fn ($r) => self::book($r, $progress), $this->catalog->visibleRows($uid)),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/items/{id}')]
	public function item(string $id): JSONResponse {
		$uid = $this->uid();
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		$p = $this->progress->get($uid, $id);
		return new JSONResponse(self::book($row, $p === null ? [] : [$id => $p]) + [
			'description' => $row['description'],
			'publisher' => Serializer::extra($row)['publisher'] ?? null,
			'language' => Serializer::extra($row)['language'] ?? null,
			'isbn' => Serializer::extra($row)['isbn'] ?? null,
			'asin' => Serializer::extra($row)['asin'] ?? null,
			'explicit' => !empty(Serializer::extra($row)['explicit']),
			'abridged' => !empty(Serializer::extra($row)['abridged']),
			'ebooks' => array_map(static fn ($e) => ['id' => $e['id'], 'name' => $e['name'], 'size' => $e['size'], 'opds' => !empty($e['opds']), 'primary' => $e['id'] === (int)$row['ebook_file_id']], Serializer::ebooks($row)),
			'bookmarks' => array_values(array_map(static fn ($b) => ['time' => $b['time'], 'title' => $b['title']], array_filter($this->bookmarks->allForUser($uid), static fn ($b) => (string)$b['libraryItemId'] === $id))),
			'path' => $row['rel_path'],
			'chapters' => json_decode((string)$row['chapters'], true) ?: [],
			'files' => array_map(static fn ($t) => [
				'name' => $t['filename'],
				'size' => (int)$t['size'],
				'duration' => (float)$t['duration'],
			], $this->library->getTrackRows((int)$row['id'])),
		]);
	}

	/** Change the details of a book. Stored in Nextcloud; the files are not changed. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/items/{id}')]
	public function update(string $id, array $metadata = []): JSONResponse {
		$uid = $this->uid();
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		if (array_key_exists('title', $metadata) && (!is_string($metadata['title']) || trim($metadata['title']) === '')) {
			return self::error('Enter a title', Http::STATUS_BAD_REQUEST);
		}
		$edits = LibraryService::cleanEdits($metadata);
		$changes = self::changed($row, $edits);
		if (array_intersect_key($changes, array_flip(['series', 'seriesSeq', 'moreSeries'])) !== []) {
			$changes += array_intersect_key($edits, array_flip(['series', 'seriesSeq', 'moreSeries']));
		}
		if ($changes !== []) {
			$this->library->updateMetadata($uid, $id, $changes);
		}
		return $this->item($id);
	}

	/** Choose which ebook is read (0: none), or which OPDS+ ebook belongs to an audiobook (0: none, null: find it). */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/items/{id}/ebook')]
	public function setEbook(string $id, ?int $fileId = null, bool $opds = false): JSONResponse {
		$uid = $this->uid();
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		if ($opds) {
			if ($fileId !== null && $fileId !== 0 && $this->opds->byFile($uid, $fileId) === null) {
				return self::error('Ebook not found', Http::STATUS_NOT_FOUND);
			}
			$this->library->setOpdsEbook($uid, $id, $fileId);
		} elseif ($fileId !== null) {
			$this->library->setPrimaryEbook($uid, $id, $fileId);
		}
		return $this->item($id);
	}

	/** Search the ebooks of OPDS+, to choose one by hand. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/opds')]
	public function opdsBooks(string $q = ''): JSONResponse {
		$uid = $this->uid();
		$words = array_filter(explode(' ', CoverService::norm($q, false)), 'strlen');
		$out = [];
		foreach ($this->opds->available($uid) ? ($this->opds->works($uid) ?? []) : [] as $w) {
			$text = CoverService::norm($w['title'] . ' ' . $w['author'] . ' ' . $w['series'], false);
			foreach ($words as $word) {
				if (!str_contains($text, $word)) {
					continue 2;
				}
			}
			$out[] = ['fileId' => $w['files'][0]['id'], 'title' => $w['title'], 'author' => $w['author'], 'formats' => array_map(static fn ($f) => strtoupper(pathinfo($f['name'], PATHINFO_EXTENSION)), $w['files'])];
			if (count($out) >= 50) {
				break;
			}
		}
		return new JSONResponse(['books' => $out]);
	}

	// ----- playlists and collections -----

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/web/lists')]
	public function createList(string $kind = '', string $libraryId = '', string $name = '', string $description = '', array $items = []): JSONResponse {
		$uid = $this->uid();
		$name = ListService::cleanName($name);
		if (!in_array($kind, [ListService::PLAYLIST, ListService::COLLECTION], true) || $name === '' || $this->library->getLibrary($uid, $libraryId) === null) {
			return self::error('Enter a name', Http::STATUS_BAD_REQUEST);
		}
		$items = $this->ownItems($uid, $libraryId, $items);
		if ($items === null) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse(self::listJson($this->lists->create($uid, $kind, $libraryId, $name, ListService::cleanDescription($description), $items)));
	}

	/** @return ?list<string> null when one of them is not a book of that library */
	private function ownItems(string $uid, string $libraryId, array $items): ?array {
		$items = array_values(array_unique(array_map('strval', array_filter($items, 'is_scalar'))));
		return count($this->catalog->visibleRows($uid, $libraryId, $items)) === count($items) ? $items : null;
	}

	private function findList(string $id): ?array {
		return $this->lists->get($this->uid(), ListService::PLAYLIST, $id) ?? $this->lists->get($this->uid(), ListService::COLLECTION, $id);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/lists/{id}')]
	public function updateList(string $id, string $name = '', string $description = '', array $items = []): JSONResponse {
		$uid = $this->uid();
		$list = $this->findList($id);
		if ($list === null) {
			return self::error('List not found', Http::STATUS_NOT_FOUND);
		}
		$name = ListService::cleanName($name);
		// Books that are away right now may stay.
		$new = array_diff(array_map('strval', array_filter($items, 'is_scalar')), ListService::items($list));
		if ($name === '' || $this->ownItems($uid, (string)$list['library_id'], $new) === null) {
			return self::error($name === '' ? 'Enter a name' : 'Book not found', Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(self::listJson($this->lists->update($uid, $list, ['name' => $name, 'description' => ListService::cleanDescription($description), 'items' => array_values(array_filter($items, 'is_scalar'))])));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/web/lists/{id}')]
	public function deleteList(string $id): JSONResponse {
		$list = $this->findList($id);
		if ($list === null) {
			return self::error('List not found', Http::STATUS_NOT_FOUND);
		}
		$this->lists->delete($list);
		return new JSONResponse([]);
	}

	// ----- series notes, bookmarks, statistics -----

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/series/description')]
	public function seriesDescription(string $name = ''): JSONResponse {
		return new JSONResponse(['description' => $this->lists->seriesDescription($this->uid(), Serializer::seriesId($name)) ?? '']);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/series/description')]
	public function setSeriesDescription(string $name = '', string $description = ''): JSONResponse {
		if (trim($name) === '') {
			return self::error('Enter the name of the series', Http::STATUS_BAD_REQUEST);
		}
		$this->lists->setSeriesDescription($this->uid(), Serializer::seriesId($name), ListService::cleanDescription($description));
		return $this->seriesDescription($name);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/items/{id}/bookmarks')]
	public function saveBookmark(string $id, float $time = -1, string $title = ''): JSONResponse {
		$uid = $this->uid();
		if ($this->catalog->item($uid, $id) === null || $time < 0) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		$this->bookmarks->upsert($uid, $id, $time, mb_substr($title, 0, 255));
		return $this->item($id);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/web/items/{id}/bookmarks')]
	public function deleteBookmark(string $id, float $time = -1): JSONResponse {
		$uid = $this->uid();
		if ($this->catalog->item($uid, $id) === null || !$this->bookmarks->delete($uid, $id, $time)) {
			return self::error('Bookmark not found', Http::STATUS_NOT_FOUND);
		}
		return $this->item($id);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/stats')]
	public function stats(int $year = 0): JSONResponse {
		$uid = $this->uid();
		$all = $this->sessions->stats($uid);
		return new JSONResponse([
			'total' => $all['totalTime'],
			'today' => $all['today'],
			'days' => $all['days'],
			'year' => $this->sessions->yearStats($uid, $year >= 1970 && $year < 2200 ? $year : (int)date('Y')),
		]);
	}

	/**
	 * Set or clear the series of books. Stored as edits in Nextcloud; the files are not changed.
	 * @param array $books list of {id, series, seriesSeq}; an empty series takes the book out of its series
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/series')]
	public function series(array $books = []): JSONResponse {
		$uid = $this->uid();
		if ($books === [] || count($books) > 500) {
			return self::error('Choose between 1 and 500 books', Http::STATUS_BAD_REQUEST);
		}
		$edits = [];
		foreach ($books as $b) {
			$row = is_array($b) && is_scalar($b['id'] ?? null) ? $this->catalog->item($uid, (string)$b['id']) : null;
			if ($row === null) {
				return self::error('Book not found', Http::STATUS_NOT_FOUND);
			}
			$edits[(string)$row['item_key']] = [$row, LibraryService::cleanEdits(['series' => $b['series'] ?? '', 'seriesSeq' => $b['seriesSeq'] ?? '', 'moreSeries' => $b['moreSeries'] ?? []])];
		}
		$out = [];
		$progress = $this->progress->allForUser($uid);
		foreach ($edits as [$row, $edit]) {
			$changes = self::changed($row, $edit);
			if ($changes !== []) {
				// These belong together: one of them alone would be taken as the only series.
				$row = $this->library->updateMetadata($uid, (string)$row['item_key'], $edit);
			}
			$out[] = self::book($row, $progress);
		}
		return new JSONResponse(['books' => $out]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/web/items/{id}/cover')]
	public function cover(string $id): Response {
		$uid = $this->uid();
		$row = $this->catalog->item($uid, $id);
		$res = $row === null ? null : CoverFile::response($this->library, $uid, $row, strtoupper($this->request->getMethod()) === 'HEAD');
		return $res ?? self::error('Cover not found', Http::STATUS_NOT_FOUND);
	}

	/**
	 * Where a book will be stored. With dryRun only the answer, for the preview in the form;
	 * otherwise the folders are created and the browser uploads into them over WebDAV.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/web/upload/plan')]
	public function plan(string $libraryPath = '', array $metadata = [], array $files = [], bool $dryRun = true): JSONResponse {
		$r = $this->uploads->plan($this->uid(), $libraryPath, $metadata, $files, $dryRun);
		if (!isset($r['error'])) {
			return new JSONResponse($r);
		}
		$status = match ($r['code'] ?? '') {
			'exists', 'inside' => Http::STATUS_CONFLICT,
			'permission' => Http::STATUS_FORBIDDEN,
			default => Http::STATUS_BAD_REQUEST,
		};
		return new JSONResponse($r, $status);
	}

	/** Add the uploaded book to the library with the details from the form. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/web/upload/finish')]
	public function finish(string $libraryPath = '', string $folder = '', array $metadata = []): JSONResponse {
		$uid = $this->uid();
		if (!$this->uploads->inside($uid, $libraryPath, $folder)) {
			return self::error('Unknown library folder', Http::STATUS_BAD_REQUEST);
		}
		$key = $this->library->scanBookFolder($uid, $libraryPath, $folder);
		if ($key === null) {
			return self::error('No audio files found in the uploaded folder', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$row = $this->catalog->item($uid, $key);
		$changes = self::changed($row, LibraryService::cleanEdits(array_intersect_key($metadata, array_flip(['title', 'author', 'series', 'seriesSeq', 'narrator']))));
		if ($changes !== []) {
			$row = $this->library->updateMetadata($uid, $key, $changes);
		}
		$p = $this->progress->get($uid, $key);
		return new JSONResponse(self::book($row, $p === null ? [] : [$key => $p]));
	}

	/** After a cancelled upload: remove its folders, only if they are empty. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/web/upload/cancel')]
	public function cancel(string $libraryPath = '', string $folder = ''): JSONResponse {
		return new JSONResponse(['removed' => $this->uploads->removeEmpty($this->uid(), $libraryPath, $folder)]);
	}
}
