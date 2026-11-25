<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Http\CoverFile;
use OCA\AbsCompat\Http\RangeFileResponse;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\SessionService;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class ItemController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private Catalog $catalog,
		private SessionService $sessions,
	) {
		parent::__construct($request, $tokens, $library);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}')]
	public function item(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return $this->notFound('Library item not found');
		}
		$include = (string)$this->request->getParam('include', '');
		return new JSONResponse($this->catalog->expanded($uid, $row, str_contains($include, 'progress')));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/items/batch/get')]
	public function batchGet(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$ids = $this->body()['libraryItemIds'] ?? [];
		$ids = is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
		$rows = $this->catalog->visibleRows($uid, null, array_slice($ids, 0, 500));
		return new JSONResponse(['libraryItems' => array_map(fn ($r) => $this->catalog->expanded($uid, $r), $rows)]);
	}

	/**
	 * Metadata edits (Voice pushes title, authors and series). Stored as overrides in Nextcloud,
	 * kept across rescans; the audio files are not modified.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/items/{id}/media')]
	public function updateMedia(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->catalog->item($uid, $id) === null) {
			return $this->notFound('Library item not found');
		}
		$meta = $this->body()['metadata'] ?? null;
		$changes = [];
		if (is_array($meta)) {
			if (is_string($meta['title'] ?? null) && trim($meta['title']) !== '') {
				$changes['title'] = trim($meta['title']);
			}
			if (array_key_exists('authors', $meta) && is_array($meta['authors'])) {
				$names = [];
				foreach ($meta['authors'] as $a) {
					$n = is_array($a) ? ($a['name'] ?? null) : $a;
					if (is_string($n) && trim($n) !== '') {
						$names[] = trim($n);
					}
				}
				$changes['author'] = $names === [] ? null : implode(', ', array_unique($names));
			} elseif (is_string($meta['authorName'] ?? null)) {
				$changes['author'] = trim($meta['authorName']) ?: null;
			}
			if (array_key_exists('series', $meta) && is_array($meta['series'])) {
				$first = $meta['series'][0] ?? null;
				$name = is_array($first) && is_string($first['name'] ?? null) ? trim($first['name']) : '';
				$changes['series'] = $name !== '' ? $name : null;
				$seq = is_array($first) ? ($first['sequence'] ?? null) : null;
				$changes['seriesSeq'] = $name !== '' && is_scalar($seq) && (string)$seq !== '' ? (string)$seq : null;
			}
		}
		if ($changes === []) {
			return new JSONResponse(['updated' => false, 'libraryItem' => $this->catalog->expanded($uid, $this->catalog->item($uid, $id))]);
		}
		$this->library->updateMetadata($uid, $id, $changes);
		return new JSONResponse(['updated' => true, 'libraryItem' => $this->catalog->expanded($uid, $this->catalog->item($uid, $id))]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/cover')]
	public function cover(string $id): Response {
		$uid = $this->currentUser(true);
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return $this->notFound();
		}
		return CoverFile::response($this->library, $uid, $row, $this->isHead()) ?? $this->notFound('Cover not found');
	}

	private function isHead(): bool {
		return strtoupper($this->request->getMethod()) === 'HEAD';
	}

	private function serveFile(string $id, string $fileId, bool $attachment): Response {
		$uid = $this->currentUser(true);
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null || !ctype_digit($fileId)) {
			return $this->notFound();
		}
		$file = $this->library->getItemFile($uid, $row, (int)$fileId);
		if ($file === null) {
			return $this->notFound('File not found');
		}
		$mime = LibraryService::isAudio($file->getName()) ? LibraryService::mimeFor($file->getName()) : $file->getMimetype();
		$range = $this->request->getHeader('Range');
		return new RangeFileResponse(fn () => $file->fopen('rb'), (int)$file->getSize(), $mime, $file->getName(), $range !== '' ? $range : null, $this->isHead(), (int)$file->getMTime(), $attachment);
	}

	/** Direct play URL used in audioTracks[].contentUrl. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/file/{fileId}')]
	public function file(string $id, string $fileId): Response {
		return $this->serveFile($id, $fileId, false);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/file/{fileId}/download')]
	public function download(string $id, string $fileId): Response {
		return $this->serveFile($id, $fileId, true);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/ebook')]
	public function ebook(string $id): Response {
		$uid = $this->currentUser(true);
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return $this->notFound();
		}
		if ($row['ebook_file_id'] === null) {
			return $this->notFound('No ebook');
		}
		return $this->serveFile($id, (string)$row['ebook_file_id'], false);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/items/{id}/play')]
	public function play(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return $this->notFound('Library item not found');
		}
		$body = $this->body();
		$session = $this->sessions->start($uid, $row, is_array($body['deviceInfo'] ?? null) ? $body['deviceInfo'] : [], is_string($body['mediaPlayer'] ?? null) ? $body['mediaPlayer'] : 'unknown');
		return new JSONResponse($session);
	}

	/**
	 * No websockets inside PHP. Answer the socket.io handshake the way a socket.io server rejects
	 * an unknown transport, so the app just shows "not connected" and retries with backoff.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/socket.io/')]
	public function socketIo(): JSONResponse {
		return new JSONResponse(['code' => 0, 'message' => 'Transport unknown'], Http::STATUS_BAD_REQUEST);
	}
}
