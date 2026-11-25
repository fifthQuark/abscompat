<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Http\CoverFile;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\MetadataExtractor;
use OCA\AbsCompat\Service\ProgressService;
use OCA\AbsCompat\Service\Serializer;
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
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private static function error(string $msg, int $status, string $code = ''): JSONResponse {
		return new JSONResponse(['error' => $msg, 'code' => $code], $status);
	}

	/** @param array<string, array> $progress */
	private static function book(array $r, array $progress): array {
		$p = $progress[(string)$r['item_key']] ?? null;
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
			'year' => $r['published_year'],
			'genres' => json_decode((string)$r['genres'], true) ?: [],
			'duration' => (float)$r['duration'],
			'size' => (int)$r['size'],
			'addedAt' => (int)$r['added_at'],
			'updatedAt' => (int)$r['updated_at'],
			'hasCover' => Serializer::hasCover($r),
			'hasEbook' => $r['ebook_file_id'] !== null,
			'progress' => $p === null ? null : [
				'progress' => (float)$p['progress'],
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
		$progress = $this->progress->allForUser($uid);
		return new JSONResponse([
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
			'path' => $row['rel_path'],
			'chapters' => json_decode((string)$row['chapters'], true) ?: [],
			'files' => array_map(static fn ($t) => [
				'name' => $t['filename'],
				'size' => (int)$t['size'],
				'duration' => (float)$t['duration'],
			], $this->library->getTrackRows((int)$row['id'])),
		]);
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
		// Keep only what differs from the tags and folder names, so later tag edits still show up.
		$changes = [];
		foreach (['title' => 'title', 'author' => 'author', 'series' => 'series', 'seriesSeq' => 'series_seq', 'narrator' => 'narrator'] as $k => $col) {
			if (!array_key_exists($k, $metadata) || !(is_scalar($metadata[$k]) || $metadata[$k] === null)) {
				continue;
			}
			$v = MetadataExtractor::cleanText((string)$metadata[$k]);
			$v = $v === '' ? null : $v;
			if ($k === 'title' && $v === null) {
				continue;
			}
			if ($v !== ($row[$col] === null || $row[$col] === '' ? null : (string)$row[$col])) {
				$changes[$k] = $v;
			}
		}
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
