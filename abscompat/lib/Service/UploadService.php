<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * Where an uploaded book goes: Author/Title or Author/Series/1 - Title, the layout the scanner
 * reads. The browser uploads the files itself over WebDAV; this only plans and creates folders.
 */
class UploadService {
	public const UNKNOWN_AUTHOR = 'Unknown author';
	private const COVER_EXT = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];
	private const MAX_FILES = 2000;

	public function __construct(
		private IRootFolder $rootFolder,
		private IFilenameValidator $validator,
		private LibraryService $library,
	) {
	}

	/**
	 * One folder or file name from free text. Empty when nothing usable is left.
	 */
	public static function component(string $s, callable $sanitize, int $maxBytes = 120): string {
		$s = MetadataExtractor::cleanText($s);
		$s = (string)preg_replace('/\s+/u', ' ', $s);
		$s = str_replace(': ', ' - ', $s);
		$s = (string)preg_replace('#[/\\\\:*?"<>|]+#', '_', $s);
		// No hidden files, and no trailing dots or spaces (Windows clients can't sync those).
		$s = rtrim(ltrim($s, ". \t"), ". \t");
		$s = rtrim(mb_strcut($s, 0, $maxBytes, 'UTF-8'), ". \t");
		if ($s === '') {
			return '';
		}
		return trim(str_replace(['/', '\\'], '_', $sanitize($s)));
	}

	/**
	 * Folder names below the library folder.
	 * @return list<string> empty when there is no usable title
	 */
	public static function bookParts(string $title, string $author, string $series, string $seq, callable $sanitize): array {
		$t = self::component($title, $sanitize);
		if ($t === '') {
			return [];
		}
		$a = self::component($author, $sanitize);
		$s = self::component($series, $sanitize);
		if ($s === '') {
			return $a === '' ? [$t] : [$a, $t];
		}
		$seq = trim($seq);
		if (preg_match('/^\d{1,6}(?:\.\d{1,3})?$/', $seq)) {
			$t = $seq . ' - ' . $t;
		}
		return [$a === '' ? self::UNKNOWN_AUTHOR : $a, $s, $t];
	}

	/**
	 * Final names inside the book folder, in the order given. Sub-folders (CD1/01.mp3) are flattened
	 * to "CD1 - 01.mp3": the scanner treats a folder that only holds folders as several books.
	 *
	 * @param list<array{path: string, kind: string}> $files kind: audio, ebook or cover
	 * @return list<string>|null null when a file can't be accepted
	 */
	public static function fileNames(array $files, callable $sanitize): ?array {
		$out = [];
		$used = [];
		foreach ($files as $f) {
			$path = str_replace('\\', '/', (string)($f['path'] ?? ''));
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$kind = (string)($f['kind'] ?? '');
			if ($kind === 'cover') {
				if (!isset(self::COVER_EXT[$ext])) {
					return null;
				}
				$base = 'cover';
				$ext = self::COVER_EXT[$ext];
			} else {
				if (!in_array($ext, $kind === 'audio' ? LibraryService::AUDIO_EXT : ($kind === 'ebook' ? LibraryService::EBOOK_EXT : []), true)) {
					return null;
				}
				$noExt = substr($path, 0, -strlen($ext) - 1);
				$parts = array_filter(
					array_map(static fn ($p) => self::component($p, $sanitize, 80), explode('/', $noExt)),
					static fn ($p) => $p !== '',
				);
				$base = mb_strcut(implode(' - ', $parts), 0, 200, 'UTF-8');
				if ($base === '') {
					$base = $kind === 'audio' ? 'Audio' : 'Book';
				}
			}
			$name = $base . '.' . $ext;
			for ($i = 2; isset($used[mb_strtolower($name)]); $i++) {
				$name = $base . ' (' . $i . ').' . $ext;
			}
			$used[mb_strtolower($name)] = true;
			$out[] = $name;
		}
		return $out;
	}

	/**
	 * Plan an upload, and create the folders unless $dryRun.
	 *
	 * @return array{error?: string, code?: string, folder?: string, files?: list<string>}
	 */
	public function plan(string $userId, string $libraryPath, array $meta, array $files, bool $dryRun): array {
		if (!in_array($libraryPath, $this->library->getFolderPaths($userId), true)) {
			return ['code' => 'library', 'error' => 'Unknown library folder'];
		}
		$sanitize = fn (string $s): string => $this->validator->sanitizeFilename($s);
		$text = static fn (string $k): string => is_scalar($meta[$k] ?? null) ? trim((string)$meta[$k]) : '';
		$parts = self::bookParts($text('title'), $text('author'), $text('series'), $text('seriesSeq'), $sanitize);
		if ($parts === []) {
			return ['code' => 'title', 'error' => 'Enter a title'];
		}
		foreach ($parts as $p) {
			if (!$this->validator->isFilenameValid($p)) {
				return ['code' => 'name', 'error' => 'Not a valid folder name: ' . $p];
			}
		}
		$files = array_values(array_filter($files, 'is_array'));
		if (count($files) > self::MAX_FILES) {
			return ['code' => 'files', 'error' => 'Too many files'];
		}
		$names = self::fileNames($files, $sanitize);
		if ($names === null) {
			return ['code' => 'files', 'error' => 'Unsupported file type'];
		}
		foreach ($names as $n) {
			if (!$this->validator->isFilenameValid($n)) {
				return ['code' => 'files', 'error' => 'Not a valid file name: ' . $n];
			}
		}

		$folder = $libraryPath . '/' . implode('/', $parts);
		$root = $this->rootFolder->getUserFolder($userId);
		$error = $this->conflict($root, $libraryPath, $parts);
		if ($error !== null) {
			return $error + ['folder' => $folder];
		}
		if (!$dryRun) {
			$dir = $root;
			foreach (explode('/', trim($folder, '/')) as $p) {
				$dir = $dir->nodeExists($p) ? $dir->get($p) : $dir->newFolder($p);
				if (!$dir instanceof Folder) {
					return ['code' => 'exists', 'error' => 'There is already a file with this name', 'folder' => $folder];
				}
			}
		}
		return ['folder' => $folder, 'files' => $names];
	}

	/** @param list<string> $parts */
	private function conflict(Folder $root, string $libraryPath, array $parts): ?array {
		$dir = $root;
		$libDepth = count(explode('/', trim($libraryPath, '/')));
		$all = [...explode('/', trim($libraryPath, '/')), ...$parts];
		$last = count($all) - 1;
		foreach ($all as $i => $p) {
			if (!$dir->nodeExists($p)) {
				return $dir->isCreatable() ? null : ['code' => 'permission', 'error' => 'You can\'t add files to this library'];
			}
			$dir = $dir->get($p);
			if (!$dir instanceof Folder) {
				return ['code' => 'exists', 'error' => 'There is already a file with this name'];
			}
			if ($i < $libDepth) {
				continue;
			}
			$children = $dir->getDirectoryListing();
			if ($i === $last) {
				if ($children !== []) {
					return ['code' => 'exists', 'error' => 'This book folder already exists'];
				}
			} else {
				// The scanner takes a folder that directly holds audio as one book, with everything below it.
				foreach ($children as $c) {
					if (!$c instanceof Folder && LibraryService::isAudio($c->getName())) {
						return ['code' => 'inside', 'error' => 'The folder "' . $p . '" already holds audio files, so the new book would become part of it'];
					}
				}
			}
		}
		return $dir->isCreatable() ? null : ['code' => 'permission', 'error' => 'You can\'t add files to this library'];
	}

	/** Remove the folders of a cancelled upload, from the book folder up, only while they are empty. */
	public function removeEmpty(string $userId, string $libraryPath, string $folder): int {
		if (!$this->inside($userId, $libraryPath, $folder)) {
			return 0;
		}
		$root = $this->rootFolder->getUserFolder($userId);
		$n = 0;
		while (strlen($folder) > strlen($libraryPath)) {
			try {
				$node = $root->get($folder);
			} catch (NotFoundException) {
				$folder = dirname($folder);
				continue;
			}
			if (!$node instanceof Folder || $node->getDirectoryListing() !== [] || !$node->isDeletable()) {
				break;
			}
			try {
				$node->delete();
			} catch (\Throwable) {
				// Busy (an interrupted upload is still being closed); the browser asks again.
				break;
			}
			$n++;
			$folder = dirname($folder);
		}
		return $n;
	}

	/** Whether $folder is a clean path below one of the user's library folders. */
	public function inside(string $userId, string $libraryPath, string $folder): bool {
		return in_array($libraryPath, $this->library->getFolderPaths($userId), true)
			&& LibraryService::cleanPaths([$folder]) === [$folder]
			&& !preg_match('#//|(^|/)\.(/|$)#', $folder)
			&& str_starts_with($folder, $libraryPath . '/');
	}
}
