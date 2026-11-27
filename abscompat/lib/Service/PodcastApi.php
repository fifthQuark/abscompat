<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

/** The podcast library's answers to the Audiobookshelf API, next to the book libraries. */
class PodcastApi {
	public function __construct(
		private PodcastService $podcasts,
		private HiddenEpisodes $hidden,
	) {
	}

	public function isOn(string $userId): bool {
		return $this->podcasts->isOn($userId);
	}

	/** The library is listed once there is something in it. */
	public function hasLibrary(string $userId): bool {
		return $this->isOn($userId) && $this->podcasts->subscriptions($userId) !== [];
	}

	/** @return list<array{0: array, 1: array, 2: array}> podcast, episode, position; most recent first */
	private function played(string $userId, bool $refresh = false): array {
		$out = [];
		foreach ($this->podcasts->podcasts($userId, $refresh) as $p) {
			foreach ($p['episodes'] as $e) {
				$a = $this->podcasts->position($userId, $e);
				if ($a !== null) {
					$out[] = [$p, $e, $a];
				}
			}
		}
		usort($out, static fn ($x, $y) => $y[2]['at'] <=> $x[2]['at']);
		return $out;
	}

	/** For /api/me and the login answer: positions the apps compare with their own. */
	public function progress(string $userId): array {
		if (!$this->isOn($userId)) {
			return [];
		}
		return array_map(fn ($x) => PodcastJson::progress($userId, ...$x, hidden: $this->hidden($userId, ...$x)), $this->played($userId));
	}

	private function hidden(string $userId, array $p, array $e, ?array $a): bool {
		return $this->hidden->isHidden($userId, $p['id'], $e['id'], $a);
	}

	/** Episodes started, not finished and not hidden, as items with recentEpisode. */
	public function inProgress(string $userId, int $limit): array {
		if (!$this->isOn($userId)) {
			return [];
		}
		$out = [];
		foreach ($this->played($userId) as [$p, $e, $a]) {
			if ($a['position'] > 0 && !PodcastService::finished($a) && !$this->hidden($userId, $p, $e, $a) && count($out) < $limit) {
				$out[] = PodcastJson::withEpisode($p, $e) + ['progressLastUpdate' => $a['at'] * 1000];
			}
		}
		return $out;
	}

	/** @return list<array> minified podcasts, sorted */
	public function items(string $userId, string $sort, bool $desc): array {
		$list = array_map(static fn ($p) => PodcastJson::item($p, false), $this->podcasts->podcasts($userId));
		usort($list, static fn ($a, $b) => match ($sort) {
			'addedAt', 'mtimeMs', 'birthtimeMs' => $a['addedAt'] <=> $b['addedAt'],
			'media.metadata.author' => strnatcasecmp((string)$a['media']['metadata']['author'], (string)$b['media']['metadata']['author']),
			'media.numTracks', 'media.numEpisodes' => $a['media']['numEpisodes'] <=> $b['media']['numEpisodes'],
			'size' => $a['size'] <=> $b['size'],
			default => strnatcasecmp($a['media']['metadata']['titleIgnorePrefix'], $b['media']['metadata']['titleIgnorePrefix']),
		});
		return $desc ? array_reverse($list) : $list;
	}

	public function shelves(string $userId, int $limit): array {
		$podcasts = $this->podcasts->podcasts($userId);
		$shelves = [];
		$going = $this->inProgress($userId, $limit);
		if ($going !== []) {
			$shelves[] = ['id' => 'continue-listening', 'label' => 'Continue Listening', 'labelStringKey' => 'LabelContinueListening', 'type' => 'episode', 'entities' => $going, 'total' => count($going)];
		}
		$recent = $this->recentEpisodes($userId, $limit, 0, $podcasts);
		if ($recent !== []) {
			$shelves[] = ['id' => 'newest-episodes', 'label' => 'Newest Episodes', 'labelStringKey' => 'LabelNewestEpisodes', 'type' => 'episode',
				'entities' => array_map(static fn ($x) => PodcastJson::withEpisode($x[0], $x[1]), $recent), 'total' => count($recent)];
		}
		if ($podcasts !== []) {
			$items = array_map(static fn ($p) => PodcastJson::item($p, false), $podcasts);
			usort($items, static fn ($a, $b) => $b['addedAt'] <=> $a['addedAt']);
			$shelves[] = ['id' => 'recently-added', 'label' => 'Recently Added', 'labelStringKey' => 'LabelRecentlyAdded', 'type' => 'podcast', 'entities' => array_slice($items, 0, $limit), 'total' => count($items)];
		}
		$again = array_values(array_filter($this->played($userId), static fn ($x) => PodcastService::finished($x[2])));
		if ($again !== []) {
			$shelves[] = ['id' => 'listen-again', 'label' => 'Listen Again', 'labelStringKey' => 'LabelListenAgain', 'type' => 'episode',
				'entities' => array_map(static fn ($x) => PodcastJson::withEpisode($x[0], $x[1]), array_slice($again, 0, $limit)), 'total' => count($again)];
		}
		return $shelves;
	}

	/** Newest episodes not finished, of all podcasts. @return list<array{0: array, 1: array}> */
	private function recentEpisodes(string $userId, int $limit, int $offset, ?array $podcasts = null): array {
		$all = [];
		foreach ($podcasts ?? $this->podcasts->podcasts($userId) as $p) {
			foreach ($p['episodes'] as $e) {
				if (!PodcastService::finished($this->podcasts->position($userId, $e))) {
					$all[] = [$p, $e];
				}
			}
		}
		usort($all, static fn ($a, $b) => ($b[1]['publishedAt'] ?? 0) <=> ($a[1]['publishedAt'] ?? 0));
		return array_slice($all, $offset, $limit > 0 ? $limit : null);
	}

	/** GET /api/libraries/podcasts/recent-episodes */
	public function recent(string $userId, int $limit, int $page): array {
		$eps = array_map(static function ($x) {
			[$p, $e] = $x;
			$json = PodcastJson::episode($p, $e, 0);
			$json['podcast'] = PodcastJson::item(['episodes' => []] + $p, false)['media'];
			$json['libraryId'] = PodcastService::LIBRARY;
			return $json;
		}, $this->recentEpisodes($userId, $limit, $page * $limit));
		return ['episodes' => $eps, 'limit' => $limit, 'page' => $page];
	}

	public function search(string $userId, string $q, int $limit): array {
		$out = [];
		foreach ($this->podcasts->podcasts($userId, false) as $p) {
			$text = mb_strtolower($p['title'] . ' ' . $p['author']);
			if ($q !== '' && str_contains($text, $q) && count($out) < $limit) {
				$out[] = ['libraryItem' => PodcastJson::item($p, false), 'matchKey' => 'title', 'matchText' => $p['title']];
			}
		}
		return $out;
	}

	public function item(string $userId, string $key): ?array {
		$p = $this->isOn($userId) ? $this->podcasts->podcast($userId, $key) : null;
		return $p === null ? null : PodcastJson::item($p, true);
	}

	public function coverUrl(string $userId, string $key): ?string {
		$p = $this->isOn($userId) ? $this->podcasts->podcast($userId, $key) : null;
		return $p['image'] ?? null;
	}

	/** The publisher's address of an episode's audio. */
	public function audioUrl(string $userId, string $key, string $episodeId): ?string {
		$x = $this->isOn($userId) ? $this->podcasts->episode($userId, $key, $episodeId) : null;
		return $x[1]['url'] ?? null;
	}

	/** @return ?array{0: array, 1: array} */
	public function find(string $userId, string $key, string $episodeId): ?array {
		return $this->isOn($userId) ? $this->podcasts->episode($userId, $key, $episodeId) : null;
	}

	public function getProgress(string $userId, string $key, string $episodeId): ?array {
		$x = $this->find($userId, $key, $episodeId);
		$a = $x === null ? null : $this->podcasts->position($userId, $x[1]);
		return $a === null ? null : PodcastJson::progress($userId, $x[0], $x[1], $a, $this->hidden($userId, $x[0], $x[1], $a));
	}

	/**
	 * An ABS progress update (currentTime, duration, progress, isFinished) for an episode, saved in
	 * GPodderSync. Updates older than the position there are skipped, like for books.
	 */
	public function apply(string $userId, string $key, string $episodeId, array $body, ?int $updatedAt = null): bool {
		$x = $this->find($userId, $key, $episodeId);
		if ($x === null) {
			return false;
		}
		[$p, $e] = $x;
		$old = $this->podcasts->position($userId, $e);
		if ($updatedAt !== null && $old !== null && $old['at'] * 1000 > $updatedAt) {
			return true;
		}
		if (array_key_exists('hideFromContinueListening', $body)) {
			$hide = filter_var($body['hideFromContinueListening'], FILTER_VALIDATE_BOOLEAN);
			$rest = array_diff_key($body, ['hideFromContinueListening' => 1, 'lastUpdate' => 1]);
			$ok = $rest === [] || $this->apply($userId, $key, $episodeId, $rest, $updatedAt);
			if ($ok) {
				$this->hidden->set($userId, $p['id'], $e['id'], $this->podcasts->position($userId, $e), $hide);
			}
			return $ok;
		}
		$num = static fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v));
		$total = $num($body['duration'] ?? null) && (float)$body['duration'] > 0 ? (float)$body['duration'] : (float)($old['total'] ?? 0 ?: $e['duration']);
		$finished = array_key_exists('isFinished', $body) && $body['isFinished'] !== null
			? filter_var($body['isFinished'], FILTER_VALIDATE_BOOLEAN) : PodcastService::finished($old);
		if (array_key_exists('isFinished', $body) && !$finished && PodcastService::finished($old)) {
			// Marked as not finished: back to the start, as ABS does.
			return $this->podcasts->save($userId, $p, $e, 0, $total, false);
		}
		$pos = $num($body['currentTime'] ?? null) ? (float)$body['currentTime']
			: ($num($body['progress'] ?? null) ? (float)$body['progress'] * $total : (float)($old['position'] ?? 0));
		if (!$finished && $total > 0 && $total - $pos < 10) {
			$finished = true;
		}
		return $this->podcasts->save($userId, $p, $e, $pos, $total, $finished);
	}

	/** "Remove from continue listening"; false when the episode has no position to hide. */
	public function hide(string $userId, string $key, string $episodeId): bool {
		$x = $this->find($userId, $key, $episodeId);
		$a = $x === null ? null : $this->podcasts->position($userId, $x[1]);
		if ($a === null) {
			return false;
		}
		$this->hidden->set($userId, $x[0]['id'], $x[1]['id'], $a, true);
		return true;
	}

	/** "Reset progress" in the apps: back to the start. */
	public function reset(string $userId, string $key, string $episodeId): bool {
		$x = $this->find($userId, $key, $episodeId);
		return $x !== null && $this->podcasts->save($userId, $x[0], $x[1], 0, (float)($this->podcasts->position($userId, $x[1])['total'] ?? 0), false);
	}
}
