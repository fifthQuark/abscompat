<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Http;

use OCA\AbsCompat\Service\LibraryService;

/** Cover of an item: the image in its folder, else the art embedded in the audio. */
class CoverFile {
	public static function response(LibraryService $library, string $userId, array $row, bool $headOnly): ?RangeFileResponse {
		$res = null;
		if ($row['cover_file_id'] !== null) {
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
		$res?->cacheFor(86400, false, true);
		return $res;
	}
}
