# Audiobookshelf Minus (abscompat)

A Nextcloud app that makes a folder of audiobooks look like a small Audiobookshelf server, so the
official Audiobookshelf app (or Voice) can download and play the books, and CrossPoint Sync can
link them to ebooks. Listening progress lives in Nextcloud. There is no extra server to run, so
it works on managed Nextcloud hosting.

Supports Nextcloud 33–35 (PHP 8.2+). License: AGPL-3.0. Not affiliated with the Audiobookshelf
project.

## Setup

### 1. Install and choose your audiobook folder

1. In Nextcloud, go to **Apps → Multimedia**, find **Audiobookshelf Minus** and click
   **Download and enable**.
2. Put your audiobooks in a folder in your files, one folder per book:

   ```
   Audiobooks/
     Ada Author/
       The First Tale/            ← one book: audio files, optional cover.jpg and .epub
         The First Tale.m4b
     Bob Writer/
       Star Saga/                 ← a series
         1 - Rising/
           01 - Part 1.mp3
           02 - Part 2.mp3
           cover.jpg
     Short Story.mp3              ← a single file directly in the folder is also a book
   ```

   Supported formats: m4b, m4a, mp3, ogg, opus, flac, aac, wav. Chapters embedded in m4b/m4a or
   mp3 (ID3 CHAP) are used; otherwise each file becomes a chapter.
3. Open **Settings → Audiobookshelf**. The default folder is `/Audiobooks` (an admin can change
   the default). To use a different one, click **Add folder**, then **Rescan**.

### 2. Audiobookshelf app

Tested against the Android app's code. The iOS app uses the same server calls but hasn't been
tried.


1. Copy the **server address** from Settings → Audiobookshelf. It looks like
   `https://cloud.example.com/apps/abscompat`, or `…/index.php/apps/abscompat` on servers
   without pretty URLs.
2. In the app, add a server with that address and log in with your Nextcloud account name and
   password. **If you use two-factor authentication**, create an app password under
   Settings → Security → Devices & sessions and use that instead.
3. Download books to listen offline. Progress is sent when you are online again.

The connection icon in the app stays yellow ("socket not connected"). That's expected: this
server has no live socket connection. Downloads, playback and sync still work. Without the socket,
the Account page and the in-player bookmark button aren't available.

### 3. CrossPoint Sync

1. In Settings → Audiobookshelf, enter a name (e.g. "CrossPoint Sync") and click **Create API
   key**. Copy the key; it is shown once.
2. In CrossPoint Sync, connect **Audiobookshelf** with the same server address and the API key.
3. Link the ebook to its audiobook (automatic by title and author, or with the manual picker).
   Use the chapter calibration if the positions don't line up.

### Signing out and revoking

Settings → Audiobookshelf lists every signed-in device and API key, with its last use. You can
sign out a single device, delete a key, or sign out all devices at once.

## Limits

- Books are served as whole files (with resume). There is no transcoding or HLS streaming.
- Podcasts, collections, playlists, author photos and online metadata matching are not
  supported.
- Metadata edits from apps (title, authors, series) are stored in Nextcloud. The audio files are
  never changed.

## Development

| Path | What |
| --- | --- |
| `abscompat/` | The Nextcloud app |
| `abscompat/lib/Controller/` | ABS API (`Auth`, `Me`, `Library`, `Item`, `Session`) and settings |
| `abscompat/lib/Service/` | Tokens, scanner (getID3), progress rules, sessions, ABS JSON shapes |
| `abscompat/tests/` | PHPUnit unit tests (no Nextcloud server needed) and audio fixtures |
| `dev/` | Local test instance, test-book generator, end-to-end suites, version matrix |
| `docs/` | [Client research](docs/milestone-1-research.md), [release steps](docs/RELEASING.md) |
| `scripts/` | Certificate request and signed release build |

Unit tests (with PHP and Composer available, or inside the test container):

```bash
cd abscompat && composer install && vendor/bin/phpunit -c tests/phpunit.xml
```

Local test instance (needs podman, ffmpeg and Node 22+). `dev/test-instance.env` holds its
generated logins and is git-ignored.

```bash
podman start abs-nc34 || podman run -d --name abs-nc34 -p 8034:80 -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$(grep NC_ADMIN_PASSWORD dev/test-instance.env | cut -d= -f2)" -e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" -v "$PWD/abscompat:/var/www/html/custom_apps/abscompat:Z" docker.io/library/nextcloud:34-apache
```

```bash
dev/make-test-books.sh
```

```bash
node dev/e2e.mjs
```

```bash
node dev/settings-e2e.mjs
```

```bash
node dev/scan-e2e.mjs
```

```bash
CROSSPOINT_DIR=/path/to/crosspoint-sync /path/to/crosspoint-sync/node_modules/.bin/tsx dev/crosspoint-e2e.mts
```

The suites test failed logins on purpose, so repeated runs trip Nextcloud's brute-force
protection (HTTP 429). Clear it between runs:

```bash
dev/reset-throttle.sh
```

Release tarball installed into fresh Nextcloud 33, 34 and 35 containers, with every suite run
against each. CI runs the same script.

```bash
UNSIGNED=1 scripts/release.sh
```

```bash
CROSSPOINT_DIR=/path/to/crosspoint-sync dev/test-matrix.sh abscompat/build/abscompat-0.5.1.tar.gz
```

The same against MariaDB (`DB=mysql`) or PostgreSQL (`DB=pgsql`):

```bash
DB=mysql dev/test-matrix.sh abscompat/build/abscompat-0.5.1.tar.gz 34
```

### Phone checklist (manual)

Needs the app on a Nextcloud the phone can reach.

1. In the Audiobookshelf app, add the server address and log in.
2. The home screen shows your books, with the cloud icon in yellow.
3. Open a book and download it. Turn on flight mode, listen for a few minutes, then turn it off
   again.
4. Reopen the app. The position should reach the server (CrossPoint Sync shows it on the linked
   book).
5. Read ahead on the CrossPoint reader and sync. Reopen the app; the downloaded book should jump
   to the new spot.
