#!/usr/bin/env bash
# Regenerates the tiny audio fixtures used by the unit tests (needs ffmpeg).
set -euo pipefail
cd "$(dirname "$0")"
q="-hide_banner -loglevel error -y"
cat > /tmp/abs-ch.txt <<CH
;FFMETADATA1
title=Fixture Book
album=Fixture Book
artist=Fix Author
album_artist=Fix Author
composer=Fix Narrator
genre=Test
date=2020
[CHAPTER]
TIMEBASE=1/1000
START=0
END=4000
title=One
[CHAPTER]
TIMEBASE=1/1000
START=4000
END=10000
title=Two
CH
ffmpeg $q -f lavfi -i "sine=frequency=440:duration=10" -i /tmp/abs-ch.txt -map 0:a -map_metadata 1 -map_chapters 1 -c:a aac -b:a 16k -f mp4 chapters.m4b
ffmpeg $q -f lavfi -i "sine=frequency=440:duration=10" -i /tmp/abs-ch.txt -map 0:a -map_metadata 1 -map_chapters 1 -c:a libmp3lame -b:a 16k -id3v2_version 3 chapters.mp3
ffmpeg $q -f lavfi -i "sine=frequency=440:duration=6" -c:a libmp3lame -b:a 16k -metadata title="Plain" -metadata track="2" -id3v2_version 3 plain.mp3
rm -f /tmp/abs-ch.txt
ls -l
