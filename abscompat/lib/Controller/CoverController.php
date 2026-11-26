<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\AppInfo\Application;
use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\CoverService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\Serializer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Finding and choosing covers. A cover chosen here wins over the one in the book's files, which stays
 * untouched and is shown again when the chosen one is removed. Session + CSRF token, like the page.
 */
class CoverController extends Controller {
	private const MAX_UPLOAD = 15 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private Catalog $catalog,
		private CoverService $covers,
		private LibraryService $library,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function uid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}

	private static function error(string $msg, int $status, string $code = ''): JSONResponse {
		return new JSONResponse(['error' => $msg, 'code' => $code], $status);
	}

	/** The item, or an error response when it is unknown. */
	private function book(string $id): array|JSONResponse {
		$row = $this->catalog->item($this->uid(), $id);
		if ($row === null) {
			return self::error('Book not found', Http::STATUS_NOT_FOUND);
		}
		if (Serializer::isEbookOnly($row)) {
			// Covers chosen here are made square, which suits audiobooks, not ebooks.
			return self::error('Ebooks keep the cover of their file', Http::STATUS_CONFLICT, 'ebook');
		}
		return $row;
	}

	private function done(string $id, bool $found = true): JSONResponse {
		$row = $this->catalog->item($this->uid(), $id);
		return new JSONResponse([
			'found' => $found,
			'hasCover' => $row !== null && Serializer::hasCover($row),
			'coverChosen' => $row !== null && CoverService::hasChosenCover($row),
			'updatedAt' => (int)($row['updated_at'] ?? 0),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/web/covers/auto')]
	public function setAuto(bool $enabled = false): JSONResponse {
		$this->covers->setAuto($this->uid(), $enabled);
		return new JSONResponse(['auto' => $enabled]);
	}

	/** Results to choose from. Only the words in q are sent to the search. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/web/covers/search')]
	public function search(string $q = ''): JSONResponse {
		try {
			$results = $this->covers->search($q);
		} catch (\RuntimeException $e) {
			return self::error($e->getMessage(), Http::STATUS_SERVICE_UNAVAILABLE, 'busy');
		}
		return new JSONResponse(['results' => array_map(fn ($r) => [
			'title' => $r['title'],
			'author' => $r['author'],
			'year' => $r['year'],
			'url' => $r['url'],
			'sig' => $this->covers->sign($r['url']),
			'thumb' => $r['thumb'],
			'thumbSig' => $this->covers->sign($r['thumb']),
		], $results)]);
	}

	/** Preview of a search result, passed through so the browser doesn't contact the image server. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 240, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/web/covers/preview')]
	public function preview(string $url = '', string $sig = ''): Response {
		if (!$this->covers->verify($url, $sig)) {
			return self::error('Unknown cover', Http::STATUS_BAD_REQUEST);
		}
		try {
			$data = $this->covers->download($url);
		} catch (\RuntimeException $e) {
			return self::error($e->getMessage(), Http::STATUS_SERVICE_UNAVAILABLE);
		}
		$mime = (string)(@getimagesizefromstring($data)['mime'] ?? '');
		if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
			return self::error('Not an image', Http::STATUS_NOT_FOUND);
		}
		$res = new DataDownloadResponse($data, 'cover', $mime);
		$res->addHeader('X-Content-Type-Options', 'nosniff');
		$res->cacheFor(86400, false, true);
		return $res;
	}

	/** Use a search result. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/web/items/{id}/cover')]
	public function choose(string $id, string $url = '', string $sig = ''): JSONResponse {
		$row = $this->book($id);
		if ($row instanceof JSONResponse) {
			return $row;
		}
		if (!$this->covers->verify($url, $sig)) {
			return self::error('Unknown cover', Http::STATUS_BAD_REQUEST);
		}
		try {
			$data = $this->covers->download($url);
		} catch (\RuntimeException $e) {
			return self::error($e->getMessage(), Http::STATUS_SERVICE_UNAVAILABLE, 'busy');
		}
		if (!$this->covers->set($this->uid(), $row, $data)) {
			return self::error('This image can\'t be used', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return $this->done($id);
	}

	/** Look the cover up and use it if the match is certain. */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/web/items/{id}/cover/auto')]
	public function auto(string $id): JSONResponse {
		$row = $this->book($id);
		if ($row instanceof JSONResponse) {
			return $row;
		}
		// Automatic lookups are only for books without any cover.
		if (Serializer::hasCover($row)) {
			return CoverService::hasOwnCover($row) ? self::error('This book has its own cover in its files', Http::STATUS_CONFLICT, 'own') : $this->done($id);
		}
		try {
			return $this->done($id, $this->covers->auto($this->uid(), $row));
		} catch (\RuntimeException $e) {
			return self::error($e->getMessage(), Http::STATUS_SERVICE_UNAVAILABLE, 'busy');
		}
	}

	/** Use an image from the user's device (form field "image"). */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/web/items/{id}/cover/upload')]
	public function upload(string $id): JSONResponse {
		$row = $this->book($id);
		if ($row instanceof JSONResponse) {
			return $row;
		}
		$file = $this->request->getUploadedFile('image');
		if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
			return self::error('No image received', Http::STATUS_BAD_REQUEST);
		}
		if ((int)filesize($file['tmp_name']) > self::MAX_UPLOAD) {
			return self::error('The image is too large', Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		if (!$this->covers->set($this->uid(), $row, (string)file_get_contents($file['tmp_name']))) {
			return self::error('This image can\'t be used. Choose a JPEG, PNG or WebP image.', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		return $this->done($id);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/web/items/{id}/cover')]
	public function remove(string $id): JSONResponse {
		$row = $this->book($id);
		if ($row instanceof JSONResponse) {
			return $row;
		}
		if (!CoverService::hasChosenCover($row)) {
			return self::error('This cover is in the book\'s files and can only be changed', Http::STATUS_CONFLICT, 'own');
		}
		$this->library->setCustomCover($this->uid(), $id, null);
		return $this->done($id, false);
	}
}
