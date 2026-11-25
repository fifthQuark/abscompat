<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Http\CoverFile;
use OCA\AbsCompat\Http\RangeFileResponse;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\Serializer;
use OCA\AbsCompat\Service\SessionService;
use OCA\AbsCompat\Service\StreamingService;
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
		private StreamingService $streaming,
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
		$changes = LibraryService::cleanEdits(self::edits($this->body()));
		if ($changes === []) {
			return new JSONResponse(['updated' => false, 'libraryItem' => $this->catalog->expanded($uid, $this->catalog->item($uid, $id))]);
		}
		$this->library->updateMetadata($uid, $id, $changes);
		return new JSONResponse(['updated' => true, 'libraryItem' => $this->catalog->expanded($uid, $this->catalog->item($uid, $id))]);
	}

	/** ABS's {metadata, tags} in the form of LibraryService::cleanEdits(). */
	private static function edits(array $body): array {
		$meta = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
		$names = static function (mixed $list): array {
			$out = [];
			foreach (is_array($list) ? $list : [] as $n) {
				$n = is_array($n) ? ($n['name'] ?? null) : $n;
				if (is_string($n) && trim($n) !== '') {
					$out[] = trim($n);
				}
			}
			return array_values(array_unique($out));
		};
		$in = array_intersect_key($meta, array_flip(['title', 'subtitle', 'description', 'publisher', 'language', 'isbn', 'asin', 'genres', 'explicit', 'abridged']));
		if (array_key_exists('publishedYear', $meta)) {
			$in['year'] = $meta['publishedYear'];
		}
		if (is_array($meta['authors'] ?? null)) {
			$in['author'] = implode(', ', $names($meta['authors']));
		} elseif (is_string($meta['authorName'] ?? null)) {
			$in['author'] = $meta['authorName'];
		}
		if (is_array($meta['narrators'] ?? null)) {
			$in['narrator'] = implode(', ', $names($meta['narrators']));
		} elseif (is_string($meta['narratorName'] ?? null)) {
			$in['narrator'] = $meta['narratorName'];
		}
		if (is_array($meta['series'] ?? null)) {
			$list = [];
			foreach ($meta['series'] as $s) {
				if (is_array($s) && is_string($s['name'] ?? null) && trim($s['name']) !== '') {
					$list[] = ['name' => $s['name'], 'seq' => is_scalar($s['sequence'] ?? null) ? (string)$s['sequence'] : ''];
				}
			}
			$in['series'] = $list[0]['name'] ?? '';
			$in['seriesSeq'] = $list[0]['seq'] ?? '';
			$in['moreSeries'] = array_slice($list, 1);
		}
		if (is_array($body['tags'] ?? null)) {
			$in['tags'] = $body['tags'];
		}
		return $in;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/items/batch/update')]
	public function batchUpdate(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$updates = 0;
		foreach (array_slice(array_filter($this->body(), 'is_array'), 0, 500) as $u) {
			$id = is_scalar($u['id'] ?? null) ? (string)$u['id'] : '';
			$changes = is_array($u['mediaPayload'] ?? null) ? LibraryService::cleanEdits(self::edits($u['mediaPayload'])) : [];
			if ($changes !== [] && $this->catalog->item($uid, $id) !== null) {
				$this->library->updateMetadata($uid, $id, $changes);
				$updates++;
			}
		}
		return new JSONResponse(['success' => true, 'updates' => $updates]);
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
		return $this->send($uid, $row, (int)$fileId, $attachment);
	}

	private function send(string $uid, array $row, int $fileId, bool $attachment): Response {
		$file = $this->library->getItemFile($uid, $row, $fileId);
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
		// With the token in the address it is a player's link. Apps that download send it as a header.
		if ($this->request->getHeader('Authorization') === '') {
			$uid = $this->currentUser(true);
			if ($uid !== null && !$this->streaming->isOn($uid)) {
				return $this->streamingOff();
			}
		}
		return $this->serveFile($id, $fileId, false);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/file/{fileId}/download')]
	public function download(string $id, string $fileId): Response {
		return $this->serveFile($id, $fileId, true);
	}

	private function streamingOff(): JSONResponse {
		return new JSONResponse(['error' => 'Streaming is off. Download the book, or turn streaming on in Nextcloud under Settings, Audiobookshelf.'], Http::STATUS_FORBIDDEN);
	}

	/**
	 * Audio of an open playback session: where the Audiobookshelf apps stream from. As in ABS, the
	 * session's random id is the key; it only works while the session is open.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/public/session/{id}/track/{index}')]
	public function sessionTrack(string $id, string $index): Response {
		$session = ctype_digit($index) ? $this->sessions->open($id) : null;
		$uid = (string)($session['user_id'] ?? '');
		if ($session === null || !$this->tokens->isActiveUser($uid)) {
			return $this->notFound('Session not found');
		}
		if (!$this->streaming->isOn($uid)) {
			return $this->streamingOff();
		}
		$row = $this->catalog->item($uid, (string)$session['item_key']);
		foreach ($row === null ? [] : $this->library->getTrackRows((int)$row['id']) as $t) {
			if ((int)$t['track_index'] === (int)$index) {
				return $this->send($uid, $row, (int)$t['file_id'], false);
			}
		}
		return $this->notFound('Track not found');
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/items/{id}/ebook/{fileId}')]
	public function ebookFile(string $id, string $fileId): Response {
		return $this->serveFile($id, $fileId, false);
	}

	/** Switch a file between the ebook to read and a supplementary one. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/items/{id}/ebook/{fileId}/status')]
	public function ebookStatus(string $id, string $fileId): Response {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$row = $this->catalog->item($uid, $id);
		if ($row === null) {
			return $this->notFound('Library item not found');
		}
		if (!in_array($fileId, array_map('strval', array_column(Serializer::ebooks($row), 'id')), true)) {
			return new JSONResponse(['error' => 'Invalid ebook file id'], Http::STATUS_BAD_REQUEST);
		}
		$this->library->setPrimaryEbook($uid, $id, (string)$row['ebook_file_id'] === $fileId ? 0 : (int)$fileId);
		return new JSONResponse([]);
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
		// Voice opens sessions only to report what it played; the other apps play from them.
		if (!$this->streaming->isOn($uid) && ($body['mediaPlayer'] ?? '') !== 'Voice') {
			return $this->streamingOff();
		}
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
