<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Http;

use OCA\AbsCompat\Service\CoverService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\Serializer;

/** Cover of an item: the image in its folder, else the art embedded in the audio. */
class CoverFile {
	public static function response(LibraryService $library, string $userId, array $row, bool $headOnly): ?RangeFileResponse {
		$res = null;
		// A cover chosen in the app first, then the image in the folder, then embedded art.
		if (CoverService::hasChosenCover($row)) {
			$f = $library->getCachedCover((string)$row['cover_cache']);
			if ($f !== null) {
				$res = new RangeFileResponse(fn () => $f->read(), (int)$f->getSize(), $f->getMimeType(), null, null, $headOnly, (int)$f->getMTime());
			}
		}
		if ($res === null && $row['cover_file_id'] !== null) {
			$file = $library->getItemFile($userId, $row, (int)$row['cover_file_id']);
			if ($file !== null) {
				$res = new RangeFileResponse(fn () => $file->fopen('rb'), (int)$file->getSize(), $file->getMimetype(), null, null, $headOnly, (int)$file->getMTime());
			}
		}
		if ($res === null && $row['cover_cache'] !== null) {
			$f = $library->getCachedCover((string)$row['cover_cache']);
			if ($f !== null) {
				$res = new RangeFileResponse(fn () => $f->read(), (int)$f->getSize(), $f->getMimeType(), null, null, $headOnly, (int)$f->getMTime());
			}
		}
		$opds = Serializer::extra($row)['opdsCover'] ?? null;
		if ($res === null && $opds !== null) {
			$f = $library->opdsCover($userId, (int)$opds);
			if ($f !== null) {
				$fh = $f->fopen('rb');
				$head = is_resource($fh) ? (string)fread($fh, 3) : '';
				if (is_resource($fh)) {
					fclose($fh);
				}
				$mime = match ($head) {
					"\x89PN" => 'image/png', 'RIF' => 'image/webp', 'GIF' => 'image/gif', default => 'image/jpeg',
				};
				$res = new RangeFileResponse(fn () => $f->fopen('rb'), (int)$f->getSize(), $mime, null, null, $headOnly, (int)$f->getMTime());
			}
		}
		$res?->cacheFor(86400, false, true);
		return $res;
	}
}
