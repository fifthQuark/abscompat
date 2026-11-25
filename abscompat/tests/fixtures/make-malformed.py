"""Writes an mp3 whose ID3 tags hold invalid UTF-8 and NULs: make-malformed.py plain.mp3 out.mp3"""
import struct
import sys

src = open(sys.argv[1], 'rb').read()
size = sum((src[6 + i] & 0x7F) << (7 * (3 - i)) for i in range(4))
audio = src[10 + size:]


def frame(name, data):
    return name.encode() + struct.pack('>I', len(data)) + b'\0\0' + data


def chapter(cid, start, end, title):
    times = struct.pack('>IIII', start, end, 0xFFFFFFFF, 0xFFFFFFFF)
    return frame('CHAP', cid + b'\0' + times + frame('TIT2', b'\3' + title))


body = b''.join([
    frame('TALB', b'\1\xff\xfe' + 'ספר 🎧 Malformed'.encode('utf-16-le')),
    frame('TPE1', b'\0Bad\0Author\0\0'),
    frame('COMM', b'\3eng\0' + b'broken \xff\xfe\xc3( text'),
    chapter(b'c0', 0, 3000, b'Ch \xff\xfe one'),
    chapter(b'c1', 3000, 6000, b'Two'),
])
n = len(body)
header = b'ID3\3\0\0' + bytes([(n >> 21) & 127, (n >> 14) & 127, (n >> 7) & 127, n & 127])
open(sys.argv[2], 'wb').write(header + body + audio)
