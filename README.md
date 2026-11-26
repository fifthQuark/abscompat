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

Or skip step 2 and upload from the **Audiobooks** page in Nextcloud's top menu: drop files or
folders on it, check the title, author, series and cover it filled in, and click **Upload**. It
creates the folders above for you. Each folder becomes one book (`CD1`, `CD2`, … are joined);
books you drop as single m4b files become one book each.

#### Series

A book's series comes from its tags (series, movement or grouping) or from the folders
(`Author/Series/1 - Title`). To set it yourself, open a book and click **Edit details**, or use
**New series** and **Edit** in the Series view, where you can also number, add and remove books. This
is stored in Nextcloud; your files are not changed. A book can be in several series.

Once you have finished a book, the next one of its series shows up under **Continue series**, in
the apps too.

#### Details, playlists and collections

**Edit details** on a book changes its title, authors, narrators, series, genres, tags and more. The
apps can do the same. **Lists** holds collections and playlists; **Add to list** on a book puts it
on one. Both belong to you alone. **Statistics** shows what you listened to.

#### Ebooks

Ebooks (EPUB, AZW3, MOBI, PDF, CBZ, CBR) in an audiobook's folder belong to it. Ebooks in folders
without audio are books of their own.

With the [OPDS+](https://github.com/fifthQuark/nextcloud-opds) app installed, an audiobook without an
ebook gets the OPDS+ one with the same title and author. You can choose another, or none, on the
book. The settings can also show the remaining OPDS+ ebooks as a library. OPDS+ is only read: this app
never writes to its tables, settings or files, and never starts its scans.

#### Podcasts

Podcasts come from [rePod](https://apps.nextcloud.com/apps/repod), or rather from GPodderSync, where
rePod keeps your subscriptions and where you are in each episode. With GPodderSync installed, the
podcasts you subscribed to show up in the apps as a **Podcasts** library. Subscribe and unsubscribe
in rePod (or AntennaPod).

- Episodes play and download straight from the publisher, like in rePod. They don't pass through
  your Nextcloud, so streaming doesn't need to be on.
- Where you are in an episode is saved in GPodderSync, so rePod and AntennaPod pick it up, and the
  other way round. It's written with GPodderSync's own code, the same way AntennaPod saves it.
- Feeds are read on your server and kept for an hour.
- You can turn the library off under Settings → Audiobookshelf.

#### Listening on the page

With streaming turned on (see below), books on the Audiobooks page get a **Play** button, and a
player stays at the bottom of the page. It saves your position like the apps do. Without streaming,
there is no player.

#### Covers

Books without a cover can get one under **Covers** on the Audiobooks page, or with **Find cover**
on a book. **Find missing covers** looks all of them up; a cover is only used when title and author
both match. Tick the box there to have it done automatically, once an hour and after uploads.

A book with a cover in its files can get another one with **Change cover**. The file isn't
changed: remove the new cover and the old one is back.

Lookups go from your Nextcloud server to Apple's public iTunes search and contain the title and
author, nothing else. They are off until you use them. Covers added this way are made square
(slightly off ones are cropped, portrait ones get filled sides) and are stored in Nextcloud, not in
your folders. A cover in the book's folder or audio files always wins.

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

**Streaming** (playing without downloading) is off. Before you turn it on under Settings →
Audiobookshelf, consider:

- **Bandwidth:** every listen sends the audio again, about 30 to 60 MB per hour and up to 150 MB for
  high quality files. It counts as traffic of your server and as mobile data.
- **Server load:** a playing book can keep one PHP process busy for a long time. Small or shared
  hosting has few of them, so Nextcloud may become slow or stop answering.
- **Your provider:** some limit traffic or long requests, or forbid media streaming. They may slow
  down, charge for or suspend an account.

An administrator can forbid streaming for everyone. Voice downloads and streams from the same
address, so the switch can't stop it from streaming.

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
- Author photos and online metadata matching are not supported. Collections are not shared between
  users. Podcasts are only read from rePod (GPodderSync); episodes are not stored in Nextcloud.
- The library page does not open ebooks. It plays audio only with streaming on.
- No live connection (socket): PHP can't keep one open. Bookmarks, lists and statistics are on the
  library page instead.
- Details entered when uploading, and edits, are stored in Nextcloud. The files are never changed.

## Development

| Path | What |
| --- | --- |
| `abscompat/` | The Nextcloud app |
| `abscompat/lib/Controller/` | ABS API (`Auth`, `Me`, `Library`, `Item`, `Session`), settings, library page (`Page`, `Web`) |
| `abscompat/js/` | Library page, upload and tag reader, settings (plain JS, no build step) |
| `abscompat/lib/Service/` | Tokens, scanner (getID3), progress rules, sessions, ABS JSON shapes |
| `abscompat/tests/` | PHPUnit unit tests (no Nextcloud server needed) and audio fixtures |
| `dev/` | Local test instance, test-book generator, end-to-end suites, version matrix |
| `docs/` | [Client research](docs/milestone-1-research.md), [release steps](docs/RELEASING.md) |
| `scripts/` | Certificate request, release check and signing (used by the release workflow) |

Unit tests (with PHP and Composer available, or inside the test container):

```bash
cd abscompat && composer install && vendor/bin/phpunit -c tests/phpunit.xml
```

Tag reader tests:

```bash
node --test abscompat/tests/js/*.test.mjs
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
node dev/web-e2e.mjs
```

With OPDS+ installed in the container:

```bash
node dev/opds-e2e.mjs
```

With GPodderSync installed in the container:

```bash
node dev/podcast-e2e.mjs
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
