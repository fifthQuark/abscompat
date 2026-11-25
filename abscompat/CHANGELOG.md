# Changelog

## 0.5.1 – unreleased

First release.

- Audiobookshelf-compatible API for the official Audiobookshelf mobile app, Voice and
  CrossPoint Sync: login with access/refresh tokens, libraries, home shelves, search, series,
  authors, item details, covers, ebook (EPUB) download, bookmarks, listening stats.
- Downloads and direct play of whole files with HTTP Range support. No transcoding or HLS.
- Listening progress with Audiobookshelf's rules, including offline session sync from the
  phone. A newer position from another device is never overwritten by an older offline session.
- Library scanner using getID3: m4b/mp3/m4a/ogg/opus/flac, embedded chapters (MP4 and ID3
  CHAP), covers from cover.jpg or embedded art, per-file cache, incremental rescans.
- Personal settings: library folders (file picker), rescan, API keys, signed-in devices.
- Admin settings: default library folders.
- Accounts with two-factor authentication log in with an app password.
- Supports Nextcloud 33–35.
