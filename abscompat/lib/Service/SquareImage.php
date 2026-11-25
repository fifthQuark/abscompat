<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

/** Turns any JPEG, PNG or WebP into a square JPEG, the shape audiobook players expect. */
class SquareImage {
	private const MAX_SIDE = 1200;
	/** Up to this ratio the edges are cropped; beyond it nothing is cut off and the sides are filled. */
	private const CROP_RATIO = 1.15;

	/** Width / height ratio, at least 1. Null when this is not a usable image. */
	public static function ratio(string $data): ?float {
		$size = self::size($data);
		return $size === null ? null : max($size) / min($size);
	}

	/** @return array{0: int, 1: int}|null */
	private static function size(string $data): ?array {
		$i = $data === '' ? false : @getimagesizefromstring($data);
		if ($i === false || !in_array($i[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
			return null;
		}
		// Refuse decompression bombs before decoding.
		if ($i[0] < 16 || $i[1] < 16 || $i[0] > 8000 || $i[1] > 8000 || $i[0] * $i[1] > 30_000_000) {
			return null;
		}
		return [$i[0], $i[1]];
	}

	/** JPEG data, or null when the image can't be read. */
	public static function make(string $data): ?string {
		if (self::size($data) === null || !function_exists('imagecreatefromstring')) {
			return null;
		}
		$src = @imagecreatefromstring($data);
		if ($src === false) {
			return null;
		}
		$src = self::upright($src, $data);
		$w = imagesx($src);
		$h = imagesy($src);
		$crop = max($w, $h) / min($w, $h) <= self::CROP_RATIO;
		$side = min(self::MAX_SIDE, $crop ? min($w, $h) : max($w, $h));
		$out = imagecreatetruecolor($side, $side);
		imagefill($out, 0, 0, (int)imagecolorallocate($out, 255, 255, 255));
		$min = min($w, $h);
		$cx = intdiv($w - $min, 2);
		$cy = intdiv($h - $min, 2);
		if ($crop) {
			imagecopyresampled($out, $src, 0, 0, $cx, $cy, $side, $side, $min, $min);
		} else {
			// Blurred, darker copy as background, the whole cover in front of it.
			$small = imagecreatetruecolor(24, 24);
			imagefill($small, 0, 0, (int)imagecolorallocate($small, 255, 255, 255));
			imagecopyresampled($small, $src, 0, 0, $cx, $cy, 24, 24, $min, $min);
			for ($i = 0; $i < 3; $i++) {
				imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
			}
			imagefilter($small, IMG_FILTER_BRIGHTNESS, -40);
			imagecopyresampled($out, $small, 0, 0, 0, 0, $side, $side, 24, 24);
			$scale = $side / max($w, $h);
			$nw = max(1, (int)round($w * $scale));
			$nh = max(1, (int)round($h * $scale));
			imagecopyresampled($out, $src, intdiv($side - $nw, 2), intdiv($side - $nh, 2), 0, 0, $nw, $nh, $w, $h);
		}
		ob_start();
		$ok = imagejpeg($out, null, 88);
		$jpeg = (string)ob_get_clean();
		return $ok && $jpeg !== '' ? $jpeg : null;
	}

	/** Phone photos are often stored sideways with a note saying so. */
	private static function upright(\GdImage $img, string $data): \GdImage {
		if (!function_exists('exif_read_data') || !str_starts_with($data, "\xff\xd8")) {
			return $img;
		}
		$fh = fopen('php://memory', 'r+b');
		fwrite($fh, $data);
		rewind($fh);
		$exif = @exif_read_data($fh);
		fclose($fh);
		$angle = match ((int)($exif['Orientation'] ?? 1)) {
			3 => 180, 6 => -90, 8 => 90, default => 0,
		};
		return $angle === 0 ? $img : (imagerotate($img, $angle, 0) ?: $img);
	}
}
