# Changelog

## 0.9.1 – 2026-11-27

- "Hide from Continue Listening" works for podcast episodes. The episode comes back once it is
  played again, in the apps, rePod or AntennaPod. GPodderSync's data is not changed.

## 0.9.0 – 2026-11-26

- Podcasts in the apps, from rePod: the podcasts you subscribed to in rePod (stored by GPodderSync)
  are a library. Episodes play and download straight from the publisher. Where you are in an
  episode is saved in GPodderSync, so rePod and AntennaPod see it, and the other way round.
- A player on the library page, shown only when streaming is turned on.
- Change the cover of a book that has one in its files. The file is not changed; removing the new
  cover brings it back.

## 0.8.0 – 2026-11-26

- Streaming in the Audiobookshelf app, off until you turn it on in the settings. Read the warning
  there: it costs bandwidth for every listen, keeps the server busy, and some hosting providers
  limit or forbid it. Admins can forbid it. Until now, the app failed when playing a book that was
  not downloaded.
- Playlists and collections, in the apps and on the library page.
- Ebooks: EPUB, AZW3, MOBI, PDF, CBZ and CBR, several files per book, and ebooks without audio.
- With OPDS+ installed, audiobooks get its matching ebook, and its other ebooks can be shown as a
  library. OPDS+ data is only read, never changed.
- Edit a book's details on the library page: title, subtitle, authors, narrators, series, genres,
  tags, year, publisher, language, description and more. Apps can change all of these too.
- Several series per book, and descriptions for series.
- Discover, Continue reading and Read again shelves; search finds authors, narrators, tags and
  genres; more filters; narrators list; year in review and statistics; bookmarks on the page.
- Edit a whole series (name, numbers, books) in the Series view. Edits are stored in Nextcloud; the
  files are not changed.
- Signing out a device ends its open streams. Listening sessions are kept for two years.
- "Continue series" in the apps and on the library page: the next book of a series you finished a
  book of.
- Search in the apps finds series, and their lists show each book's number in the series.
- Series are read from more tags: MP3 series tags, Apple's movement and grouping tags, and the
  upper-case names most m4b files use. A grouping like "Saga #2" now means book 2 of Saga. Books
  already in the library are read once more for this, on the next scans.
- Numbers in folder names are also found in "Book 2 Title", "Title - Vol. 2" and the like. A year
  ("1994 - Title") is no longer taken for a number.
- Collapsed series in the apps show the first book and the number of books, and are sorted by the
  series' name. An author's books can be collapsed too.

## 0.7.1 – 2026-11-25

- Fixed: the library could stop working with "Internal Server Error" until the web server was
  restarted, or treat the default folders as chosen by the user. It affected people who use the
  default folders, now and then, on Nextcloud 33 to 35 with PHP's JIT compiler switched on.

## 0.7.0 – 2026-11-25

- Find covers for books without one: search and choose, look up all missing ones, or let it
  happen automatically (off by default; sends title and author to Apple's audiobook search).
- Upload your own cover image for a book. Covers added in the app are always made square.

## 0.6.0 – 2026-11-25

- New library page in the top menu: continue listening, all books, authors and series, with
  covers, progress, search and sorting.
- Upload audiobooks on that page. Title, author, series, narrator and cover are filled in from
  the files or folder names, can be edited, and decide the folders the book is stored in.
  Large files are uploaded in chunks.
- Books split over several discs without disc numbers now play in file order.
- A newly chosen library folder is scanned right away even if another folder was scanned before.

## 0.5.1 – 2026-11-25

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
