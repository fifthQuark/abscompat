"""Series tags the way other programs write them. Usage: make-series.py plain.mp3 chapters.m4b"""
import struct
import sys

mp3 = open(sys.argv[1], 'rb').read()
size = (mp3[6] << 21) | (mp3[7] << 14) | (mp3[8] << 7) | mp3[9]
audio = mp3[10 + size:]


def synchsafe(x):
    return bytes([(x >> 21) & 127, (x >> 14) & 127, (x >> 7) & 127, x & 127])


def id3(name, frames):
    body = b''
    for frame, data in frames:
        data = b'\x03' + data
        body += frame + synchsafe(len(data)) + b'\0\0' + data
    open(name, 'wb').write(b'ID3\x04\0\0' + synchsafe(len(body)) + body + audio)


id3('series-text.mp3', [(b'TALB', b'A'), (b'TXXX', b'SERIES\0Saga \xc3\xa9'), (b'TXXX', b'SERIES-PART\x003/7')])
id3('series-movement.mp3', [(b'TALB', b'A'), (b'MVNM', b'Saga \xc3\xa9'), (b'MVIN', b'4')])
id3('series-grouping.mp3', [(b'TALB', b'A'), (b'TIT1', b'Saga #2.5; Other #1')])


def atom(kind, body):
    return struct.pack('>I', 8 + len(body)) + kind + body


def text(value):
    return atom(b'data', struct.pack('>II', 1, 0) + value)


def freeform(name, value):
    return atom(b'----', atom(b'mean', b'\0\0\0\0com.apple.iTunes') + atom(b'name', b'\0\0\0\0' + name) + text(value))


def replace(buf, path, new):
    out, o = b'', 0
    while o < len(buf):
        n, = struct.unpack('>I', buf[o:o + 4])
        kind, body = buf[o + 4:o + 8], buf[o + 8:o + n]
        if kind == path[0]:
            skip = 4 if kind == b'meta' else 0
            body = new if len(path) == 1 else body[:skip] + replace(body[skip:], path[1:], new)
        out += atom(kind, body)
        o += n
    return out


items = atom(b'\xa9alb', text(b'A')) + freeform(b'SERIES', b'Saga') + freeform(b'SERIES-PART', b'6')
open('series.m4b', 'wb').write(replace(open(sys.argv[2], 'rb').read(), [b'moov', b'udta', b'meta', b'ilst'], items))
