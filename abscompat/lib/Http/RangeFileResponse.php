<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Streams a file (or a single byte range of it) without loading it into memory.
 * Supports "Range: bytes=a-b", "bytes=a-" and "bytes=-n". Multi-range requests get the whole file.
 */
class RangeFileResponse extends Response implements ICallbackResponse {
	/** @var callable(): resource|false */
	private $opener;
	private int $start = 0;
	private int $end;
	private bool $headOnly;

	/**
	 * @param callable(): (resource|false) $opener opens the file for reading
	 */
	public function __construct(callable $opener, private int $size, string $mime, ?string $filename, ?string $rangeHeader, bool $headOnly = false, ?int $mtime = null, bool $attachment = false) {
		parent::__construct();
		$this->opener = $opener;
		$this->headOnly = $headOnly;
		$this->end = max(0, $size - 1);

		$this->addHeader('Content-Type', $mime);
		$this->addHeader('Accept-Ranges', 'bytes');
		$this->addHeader('X-Content-Type-Options', 'nosniff');
		if ($filename !== null) {
			$disp = $attachment ? 'attachment' : 'inline';
			$this->addHeader('Content-Disposition', $disp . '; filename="' . addcslashes(preg_replace('/[\x00-\x1f"]/', '_', $filename), '\\') . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
		}
		if ($mtime !== null) {
			$this->setLastModified((new \DateTime())->setTimestamp($mtime));
			$this->setETag(md5($size . '-' . $mtime));
		}
		$this->cacheFor(0);

		$status = Http::STATUS_OK;
		if ($rangeHeader !== null && $rangeHeader !== '' && $size > 0) {
			$r = self::parseRange($rangeHeader, $size);
			if ($r === false) {
				$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
				$this->addHeader('Content-Range', 'bytes */' . $size);
				$this->addHeader('Content-Length', '0');
				$this->start = 0;
				$this->end = -1;
				return;
			}
			if ($r !== null) {
				[$this->start, $this->end] = $r;
				$status = Http::STATUS_PARTIAL_CONTENT;
				$this->addHeader('Content-Range', 'bytes ' . $this->start . '-' . $this->end . '/' . $size);
			}
		}
		$this->setStatus($status);
		$this->addHeader('Content-Length', (string)max(0, $this->end - $this->start + 1));
	}

	/**
	 * @return array{0:int,1:int}|null|false null = ignore header (serve whole file), false = unsatisfiable
	 */
	public static function parseRange(string $header, int $size): array|null|false {
		if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m)) {
			return null;
		}
		if ($m[1] === '' && $m[2] === '') {
			return null;
		}
		if ($m[1] === '') {
			$len = (int)$m[2];
			if ($len === 0) {
				return false;
			}
			return [max(0, $size - $len), $size - 1];
		}
		$start = (int)$m[1];
		$end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
		if ($start >= $size || $start > $end) {
			return false;
		}
		return [$start, $end];
	}

	public function callback(IOutput $output) {
		if ($this->headOnly || $this->end < $this->start) {
			return;
		}
		$fh = ($this->opener)();
		if (!is_resource($fh)) {
			return;
		}
		if ($this->start > 0) {
			if (@fseek($fh, $this->start) !== 0) {
				// Non-seekable stream: read and discard.
				$skip = $this->start;
				while ($skip > 0 && !feof($fh)) {
					$chunk = fread($fh, min(1 << 20, $skip));
					if ($chunk === false || $chunk === '') {
						break;
					}
					$skip -= strlen($chunk);
				}
			}
		}
		while (ob_get_level() > 0) {
			ob_end_flush();
		}
		$remaining = $this->end - $this->start + 1;
		while ($remaining > 0 && !feof($fh)) {
			$chunk = fread($fh, min(1 << 16, $remaining));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$remaining -= strlen($chunk);
			$output->setOutput($chunk);
			if (connection_aborted()) {
				break;
			}
		}
		fclose($fh);
	}
}
