# Milestone 1: research findings

Date: 2026-11-19. Sources read: `advplyr/audiobookshelf-app` (v0.14.2-beta, `master`),
`advplyr/audiobookshelf` (server v2.38.0), `crosspoint-reader/crosspoint-sync`,
`PaulWoitaschek/Voice`, `nextcloud/server` `stable33`.

**Verdict: no change of plan.** The official ABS Android app works without socket.io,
accepts a server URL with a path, and authenticates every call (including downloads) with
`Authorization: Bearer`. Nextcloud core ignores unknown Bearer tokens without throttling.

## Supported Nextcloud versions

| Major | Released | EOL | Min PHP |
| --- | --- | --- | --- |
| 33 | 2026-02-18 | 2027-02 | 8.2 |
| 34 | 2026-06-09 | 2027-06 | 8.2 |
| 35 | 2026-09-16 | 2027-09 | 8.3 |

36 has no beta yet (beta 1 is planned for 2027-01-12), so `info.xml` uses
`min-version="33" max-version="35"`. The code targets PHP 8.2.

## Official ABS Android app: calls it makes

All requests go to `${serverAddress}${path}`. `serverAddress` is the URL the user typed with
the trailing slash removed, so a path prefix like `/apps/abscompat` is kept. Native (Kotlin)
calls are in `ApiHandler.kt`; web-view calls go through `nativeHttp`.

**Connect and login** (`ServerConnectForm.vue`)

1. `GET /status` must return 200 with `isInit: true` and a `language` key, or the app says
   "not an Audiobookshelf server". It also reads `authMethods` (`["local"]` shows the
   password form) and `serverVersion`.
2. `GET /ping` returns `{ "success": true }`.
3. `POST /login` with `{username, password}` and header `x-return-tokens: true`. The response
   needs `user`, `userDefaultLibraryId`, `serverSettings` (with `version`, `language`) and
   `ereaderDevices`. From server version 2.26.0 the app expects `user.accessToken` and
   `user.refreshToken`. Below that it falls back to `user.token` and shows an "old auth"
   warning. **We report version 2.26+ and issue access and refresh tokens.**
4. On a 401 the app calls `POST /auth/refresh` with header `x-refresh-token`. It expects
   `user.accessToken` and `user.refreshToken` back. If that fails, it logs the user out.
5. On app start and reconnect: `POST /api/authorize` (Bearer). Same payload as login, minus
   the tokens.
6. `POST /logout`.

**Browsing**

- `GET /api/libraries` (also `?include=stats`)
- `GET /api/libraries/:id/personalized?minified=1&include=rssfeed,numEpisodesIncomplete`:
  the home shelves. This is an array of `{id, label, total, type, entities}`, with `type` set
  to `book`, `series`, `authors`, `episode` or `podcast`.
- `GET /api/libraries/:id/items?limit=&page=&minified=1&sort=&filter=`: library tab,
  series/author filters (`filter=series.<base64 id>`, `authors.<base64 id>`)
- `GET /api/libraries/:id/series`, `/authors`, `/collections`, `GET /api/series/:id`
- `GET /api/libraries/:id/search?q=`
- `GET /api/items/:id?expanded=1&include=rssfeed`
- `GET /api/me`, `GET /api/me/items-in-progress`, `GET /api/me/listening-stats`
- Covers: `GET /api/items/:id/cover`, with `?token=` when loaded by the image view

**Downloading** (`AbsDownloader.kt`, `InternalDownloadManager.kt`)

- `GET /api/items/:id/file/:ino/download` for each audio file, plus `/api/items/:id/cover`.
- Sends `Authorization: Bearer`, `Accept-Encoding: identity`, and `Range: bytes=N-` on
  resume. It checks `Content-Range`, so 206 responses must be correct.

**Playing and progress**

- Streaming a server item: `POST /api/items/:id/play` returns a `PlaybackSession` whose
  `audioTracks[].contentUrl` is played with `?token=`. Then `POST /api/session/:id/sync` and
  `POST /api/session/:id/close`. **We return direct-play URLs to the file endpoint. There is
  no HLS.**
- Playing a downloaded item while online: `POST /api/session/local` (one partial session:
  `id, libraryItemId, currentTime, timeListening, updatedAt, duration, ...`).
- On reconnect: `POST /api/session/local-all` with `{sessions:[...], deviceInfo}`. Expects
  `{results:[{id, success, progressSynced}]}`. The server only applies a session's
  `currentTime` when `session.updatedAt >= progress.lastUpdate`.
- Also on reconnect (`syncLocalMediaProgressForUser`): `GET /api/me`, then for each
  `user.mediaProgress[]` whose `lastUpdate` is newer than local, **the phone takes the server
  position**. This is how a CrossPoint position reaches the phone.
- Mark finished or reset from menus: `PATCH /api/me/progress/:id`.

**socket.io**

- `$socket.connect(address, token)` uses `path: ${serverPath}/socket.io` and the websocket
  transport only, so we will see repeated failed upgrade requests to `/socket.io/`. We return
  404, and it reconnects with backoff of at most 15 s.
- Without the socket the app still works. Effects: the connection icon shows `cloud_off`
  (warning colour) instead of `cloud_done`; the Account page redirects to `/connect`; the
  bookmark button in the player is hidden; opening a downloaded book shows the local copy and
  does not redirect to the server copy; live progress pushes from other devices are missed
  (they are picked up on the next `syncLocalMediaProgressForUser` / reconnect instead).
- **The native offline sync (`ApiHandler.kt`, `MediaProgressSyncer`) never touches the
  socket.** Offline progress sync on reconnect works over plain HTTP.

**Response-shape risk: Jackson + Kotlin.** Native code deserialises into Kotlin classes such
as `LibraryItem`, `Book`, `BookMetadata`, `AudioTrack`, `PlaybackSession`, `MediaProgress`
and `User`. A **missing non-null field fails the whole parse**. `MediaType` and
`MediaTypeMetadata` use `JsonTypeInfo.DEDUCTION`, so a book must **not** carry podcast-only
keys (`episodes`, `autoDownloadEpisodes`, `feedUrl`, `author`). The prototype emits every
non-null field from those classes.

## CrossPoint Sync

Confirmed as listed in the brief. Two notes:

- `baseUrl()` keeps the path and only strips trailing slashes, so the path prefix works.
- When it can't resolve a duration it PATCHes only `progress` and `ebookProgress`. Real ABS
  then leaves `currentTime` alone. Because the phone resumes from `currentTime`, **we also set
  `currentTime = progress × duration` when `currentTime` is absent** (a small deviation from
  real ABS, in the user's favour).

## Voice

Its ABS client (`core/audiobookshelf`) is behind a feature flag. It stays a fallback player,
so the prototype covers its overlap with the ABS app (`/status`, `/login`, `/auth/refresh`,
`/api/me`, `/api/libraries`, `/api/libraries/:id/items`, `/api/items/:id/play`, session
sync/close, progress PATCH). `POST /api/items/batch/get`, `PATCH /api/items/:id/media` and
bookmarks come in milestone 3.

## Nextcloud Bearer handling

`OC::handleLogin()` → `Session::tryTokenLogin()`: for `Authorization: Bearer X` it calls
`tokenProvider->getToken(X)`. An unknown token throws `InvalidTokenException`, which is
caught and returns `false`. **No brute-force throttler is involved**: throttling is only in
`tryBasicAuthLogin` / `logClientIn`, which need Basic auth. The request then continues as a
guest to our `#[PublicPage]` controller, which validates the token itself. If the Bearer value
happens to be a real Nextcloud app password, core logs the user in. We do not rely on that
for now.

Our own failed logins (`POST /login`, bad tokens) **are** throttled, using `#[BruteForceProtection]`
on the controller actions.

## Not testable here

- The real ABS app on the phone. The user needs to run the manual checklist.
- Hetzner Storage Share specifics (pretty URLs on or off). Both `/apps/abscompat` and
  `/index.php/apps/abscompat` are supported.
