<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/** Title, author and cover of an EPUB, for books that have no audio. Other formats use their names. */
class EbookReader {
	private const MAX_XML = 2 * 1024 * 1024;
	private const MAX_COVER = 8 * 1024 * 1024;

	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/** @return array{tags: array<string, string>, picture: ?array{data: string, mime: string}} tags as in MetadataExtractor */
	public function read(File $file): array {
		$none = ['tags' => [], 'picture' => null];
		if (strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) !== 'epub' || !class_exists(\ZipArchive::class)) {
			return $none;
		}
		try {
			$path = $file->getStorage()->getLocalFile($file->getInternalPath());
			return is_string($path) && is_file($path) ? self::readEpub($path) : $none;
		} catch (\Throwable $e) {
			$this->logger->info('Could not read ebook ' . $file->getPath() . ': ' . $e->getMessage(), ['app' => 'abscompat']);
			return $none;
		}
	}

	/** @return array{tags: array<string, string>, picture: ?array{data: string, mime: string}} */
	public static function readEpub(string $path): array {
		$none = ['tags' => [], 'picture' => null];
		$zip = new \ZipArchive();
		if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
			return $none;
		}
		try {
			$container = self::xml(self::entry($zip, 'META-INF/container.xml', self::MAX_XML));
			$opfPath = (string)$container?->evaluate('string(//*[local-name()="rootfile"]/@full-path)');
			$opf = $opfPath === '' ? null : self::xml(self::entry($zip, $opfPath, self::MAX_XML));
			if ($opf === null) {
				return $none;
			}
			$all = static function (string $name) use ($opf): array {
				$out = [];
				foreach ($opf->query('//*[local-name()="metadata"]//*[local-name()="' . $name . '"]') ?: [] as $n) {
					$v = MetadataExtractor::cleanText((string)preg_replace('/\s+/u', ' ', $n->textContent));
					if ($v !== '') {
						$out[] = $v;
					}
				}
				return $out;
			};
			$meta = static fn (string $name) => MetadataExtractor::cleanText((string)$opf->evaluate('string(//*[local-name()="meta"][@name="' . $name . '"]/@content)'));
			$isbn = array_values(array_filter(array_map(static fn ($v) => preg_match('/(97[89][\d-]{10,14}|\b\d{9}[\dXx]\b)/', $v, $m) ? str_replace('-', '', $m[1]) : '', $all('identifier'))));
			$series = $meta('calibre:series');
			if ($series === '') {
				$series = MetadataExtractor::cleanText((string)$opf->evaluate('string(//*[local-name()="meta"][@property="belongs-to-collection"])'));
				$seq = MetadataExtractor::cleanText((string)$opf->evaluate('string(//*[local-name()="meta"][@property="group-position"])'));
			} else {
				$seq = $meta('calibre:series_index');
			}
			$tags = array_filter([
				'title' => $all('title')[0] ?? '',
				'artist' => implode('; ', $all('creator')),
				'publisher' => $all('publisher')[0] ?? '',
				'language' => $all('language')[0] ?? '',
				'description' => trim(html_entity_decode(strip_tags($all('description')[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
				'year' => $all('date')[0] ?? '',
				'genre' => implode('; ', array_slice($all('subject'), 0, 10)),
				'isbn' => $isbn[0] ?? '',
				'series' => $series,
				'seriesPart' => $series === '' ? '' : (string)preg_replace('/\.0+$/', '', $seq),
			], static fn ($v) => $v !== '');
			return ['tags' => $tags, 'picture' => self::cover($zip, $opf, $opfPath)];
		} finally {
			$zip->close();
		}
	}

	private static function cover(\ZipArchive $zip, \DOMXPath $opf, string $opfPath): ?array {
		$href = (string)$opf->evaluate('string(//*[local-name()="item"][contains(concat(" ", @properties, " "), " cover-image ")]/@href)');
		if ($href === '') {
			$id = (string)$opf->evaluate('string(//*[local-name()="meta"][@name="cover"]/@content)');
			foreach ($opf->query('//*[local-name()="item"]') ?: [] as $item) {
				if ($id !== '' && $item instanceof \DOMElement && $item->getAttribute('id') === $id) {
					$href = $item->getAttribute('href');
				}
			}
		}
		if ($href === '') {
			return null;
		}
		$parts = [];
		foreach (explode('/', (str_contains($opfPath, '/') ? dirname($opfPath) . '/' : '') . rawurldecode($href)) as $p) {
			if ($p === '..') {
				array_pop($parts);
			} elseif ($p !== '' && $p !== '.') {
				$parts[] = $p;
			}
		}
		$data = self::entry($zip, implode('/', $parts), self::MAX_COVER);
		$mime = match (true) {
			str_starts_with($data, "\xff\xd8\xff") => 'image/jpeg',
			str_starts_with($data, "\x89PNG\r\n") => 'image/png',
			default => null,
		};
		return $mime === null ? null : ['data' => $data, 'mime' => $mime];
	}

	private static function entry(\ZipArchive $zip, string $name, int $max): string {
		$stat = $zip->statName($name);
		return $stat === false || $stat['size'] > $max ? '' : (string)$zip->getFromName($name, $max);
	}

	private static function xml(string $data): ?\DOMXPath {
		// No entities and no network, whatever the file asks for.
		if ($data === '' || stripos($data, '<!ENTITY') !== false) {
			return null;
		}
		$doc = new \DOMDocument();
		return @$doc->loadXML($data, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? new \DOMXPath($doc) : null;
	}
}
