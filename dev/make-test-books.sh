#!/usr/bin/env bash
# Generates small test audiobooks under dev/books/Audiobooks (needs ffmpeg).
set -euo pipefail
cd "$(dirname "$0")"
OUT=books/Audiobooks
rm -rf books && mkdir -p "$OUT"
q="-hide_banner -loglevel error -y"

# Cover image
ffmpeg $q -f lavfi -i "color=c=steelblue:s=300x300" -frames:v 1 books/cover.jpg

# 1) Single m4b with 3 chapters and embedded cover: Ada Author/The First Tale/
mkdir -p "$OUT/Ada Author/The First Tale"
cat > books/ch.txt <<CH
;FFMETADATA1
title=The First Tale
album=The First Tale
artist=Ada Author
album_artist=Ada Author
composer=Nora Narrator
genre=Fantasy
date=2021
[CHAPTER]
TIMEBASE=1/1000
START=0
END=40000
title=Opening
[CHAPTER]
TIMEBASE=1/1000
START=40000
END=80000
title=The Middle
[CHAPTER]
TIMEBASE=1/1000
START=80000
END=120000
title=The End
CH
ffmpeg $q -f lavfi -i "sine=frequency=440:duration=120" -i books/ch.txt -i books/cover.jpg \
  -map 0:a -map 2:v -map_metadata 1 -map_chapters 1 -c:a aac -b:a 32k -c:v mjpeg -disposition:v attached_pic \
  -f mp4 "$OUT/Ada Author/The First Tale/The First Tale.m4b"

# 2) Two mp3 files, series folder, cover.jpg and an epub: Bob Writer/Star Saga/1 - Rising/
D="$OUT/Bob Writer/Star Saga/1 - Rising"
mkdir -p "$D"
for i in 1 2; do
  ffmpeg $q -f lavfi -i "sine=frequency=$((300+i*100)):duration=60" -c:a libmp3lame -b:a 32k \
    -metadata title="Part $i" -metadata album="Rising" -metadata artist="Bob Writer" -metadata track="$i/2" \
    -id3v2_version 3 "$D/0$i - Part $i.mp3"
done
cp books/cover.jpg "$D/cover.jpg"
# Second book of the series (one file, no cover)
D2="$OUT/Bob Writer/Star Saga/2 - Falling"
mkdir -p "$D2"
ffmpeg $q -f lavfi -i "sine=frequency=700:duration=30" -c:a libmp3lame -b:a 32k \
  -metadata title="Falling" -metadata artist="Bob Writer" -id3v2_version 3 "$D2/Falling.mp3"
# Minimal valid EPUB
E=$(mktemp -d)
mkdir -p "$E/META-INF" "$E/OEBPS"
printf 'application/epub+zip' > "$E/mimetype"
cat > "$E/META-INF/container.xml" <<X
<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>
X
cat > "$E/OEBPS/content.opf" <<X
<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">rising-1</dc:identifier><dc:title>Rising</dc:title><dc:language>en</dc:language><meta property="dcterms:modified">2026-01-01T00:00:00Z</meta></metadata><manifest><item id="c1" href="c1.xhtml" media-type="application/xhtml+xml"/><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/></manifest><spine><itemref idref="c1"/></spine></package>
X
cat > "$E/OEBPS/c1.xhtml" <<X
<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Rising</title></head><body><h1>Rising</h1><p>Once upon a time.</p></body></html>
X
cat > "$E/OEBPS/nav.xhtml" <<X
<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops"><head><title>Nav</title></head><body><nav epub:type="toc"><ol><li><a href="c1.xhtml">Rising</a></li></ol></nav></body></html>
X
(cd "$E" && zip -qX0 "$OLDPWD/$D/Rising.epub" mimetype && zip -qXr9D "$OLDPWD/$D/Rising.epub" META-INF OEBPS)
rm -rf "$E"

# 3) Single mp3 in the library root with ID3v2 CHAP chapters
cat > books/ch2.txt <<CH
;FFMETADATA1
title=Short Story
album=Short Story
artist=Cleo Teller
[CHAPTER]
TIMEBASE=1/1000
START=0
END=30000
title=Part A
[CHAPTER]
TIMEBASE=1/1000
START=30000
END=90000
title=Part B
CH
ffmpeg $q -f lavfi -i "sine=frequency=550:duration=90" -i books/ch2.txt -map 0:a -map_metadata 1 -map_chapters 1 \
  -c:a libmp3lame -b:a 32k -id3v2_version 3 "$OUT/Short Story.mp3"
rm -f books/ch.txt books/ch2.txt
find books -type f | sort
