<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

/** Finds a book's series and number in its tags and folder name, by Audiobookshelf's rules. */
final class SeriesParser {
	/**
	 * "Saga #2" => Saga, 2. Anything without a number at the end is only a name.
	 * @return array{name: string, seq: ?string}
	 */
	public static function split(string $s): array {
		$s = trim($s);
		if (preg_match('/^(.*\S)\s+#([^#\s]+)$/u', $s, $m)) {
			return ['name' => $m[1], 'seq' => $m[2]];
		}
		return ['name' => $s, 'seq' => null];
	}

	/** @return ?array{name: string, seq: ?string} the first series in the tags */
	public static function fromTags(array $tags): ?array {
		return self::allFromTags($tags)[0] ?? null;
	}

	/**
	 * Series tags win over the grouping tag. Several series are separated by semicolons, and so are
	 * their numbers if every series has one.
	 *
	 * @param array<string, string> $tags from MetadataExtractor
	 * @return list<array{name: string, seq: ?string}>
	 */
	public static function allFromTags(array $tags): array {
		$list = static fn (string $s) => array_values(array_filter(array_map('trim', explode(';', $s)), static fn ($n) => $n !== ''));
		$parts = $list((string)($tags['seriesPart'] ?? ''));
		foreach (['series', 'grouping'] as $k) {
			$out = array_map([self::class, 'split'], $list((string)($tags[$k] ?? '')));
			if ($out === []) {
				continue;
			}
			foreach ($out as $i => &$s) {
				if (isset($parts[$i]) && ($i === 0 || count($parts) === count($out))) {
					// "2/5" is book 2 of 5.
					$s['seq'] = (string)preg_replace('/^(\d+)\s*\/\s*\d+$/', '$1', $parts[$i]);
				}
			}
			unset($s);
			$seen = [];
			return array_values(array_filter($out, static function ($s) use (&$seen) {
				$key = mb_strtolower($s['name']);
				return !isset($seen[$key]) && ($seen[$key] = true);
			}));
		}
		return [];
	}

	/**
	 * The number in a book folder's name: "1 - Title", "Book 2 Title", "3. Title", "Title - Vol. 4".
	 * A number that belongs to the title ("101 Dalmatians") is left alone.
	 *
	 * @return array{title: string, seq: ?string}
	 */
	public static function fromFolder(string $name): array {
		// Also what people use instead of " - ": "1: Title", "1-Title", "1 – Title".
		if (preg_match('/^(?:(?:book|vol(?:ume)?)\.?\s*)?#?(\d{1,3}(?:\.\d{1,2})?)\s*[-.–:]\s*(.+)$/iu', $name, $m)) {
			return ['title' => $m[2], 'seq' => self::plain($m[1])];
		}
		$parts = explode(' - ', $name);
		foreach ($parts as $i => $p) {
			if (!preg_match('/^(vol\.? |volume |book )?#?(\d{1,3}(?:\.\d{1,2})?)(\.?)(?: (.*))?$/iu', $p, $m)) {
				continue;
			}
			$rest = $m[4] ?? '';
			if ($rest !== '' && $m[1] === '' && $m[3] === '') {
				continue;
			}
			if ($rest === '') {
				unset($parts[$i]);
			} else {
				$parts[$i] = $rest;
			}
			$title = trim(implode(' - ', $parts));
			// A folder that is only a number keeps it as its title.
			return ['title' => $title === '' ? $name : $title, 'seq' => self::plain($m[2])];
		}
		return ['title' => $name, 'seq' => null];
	}

	/** "01" => "1", "2.50" => "2.5" */
	private static function plain(string $n): string {
		return (string)(str_contains($n, '.') ? (float)$n : (int)$n);
	}

	/** For sorting: the number a sequence starts with, as Audiobookshelf does; null when there is none. */
	public static function number(mixed $seq): ?float {
		return preg_match('/^\s*(\d+(?:\.\d+)?)/', (string)$seq, $m) ? (float)$m[1] : null;
	}
}
