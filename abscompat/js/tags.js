/* Audiobookshelf Minus: reads title, author, cover etc. from audio files in the browser, before
 * they are uploaded, and sorts dropped files into books. No dependencies; also runs in Node (tests). */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) module.exports = factory()
	else root.AbsTags = factory()
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict'

	const AUDIO = ['m4b', 'mp3', 'm4a', 'ogg', 'oga', 'opus', 'flac', 'aac', 'wav', 'wma', 'mp4']
	const EBOOK = ['epub']
	const IMAGE = ['jpg', 'jpeg', 'png', 'webp']
	// Tags with embedded art are a few MB; anything far larger is a damaged or hostile file.
	const MAX_TAG = 64 * 1024 * 1024

	const ext = (name) => (/\.([^./\\]+)$/.exec(name) || ['', ''])[1].toLowerCase()
	function kindOf(name) {
		const base = name.split('/').pop()
		if (base.startsWith('.')) return null
		const e = ext(base)
		return AUDIO.includes(e) ? 'audio' : EBOOK.includes(e) ? 'ebook' : IMAGE.includes(e) ? 'image' : null
	}

	async function bytes(blob, start, length) {
		if (start < 0 || length < 0 || length > MAX_TAG) throw new Error('bad length')
		return new Uint8Array(await blob.slice(start, start + length).arrayBuffer())
	}
	const u32 = (b, o) => ((b[o] << 24) >>> 0) + (b[o + 1] << 16) + (b[o + 2] << 8) + b[o + 3]
	const u32le = (b, o) => ((b[o + 3] << 24) >>> 0) + (b[o + 2] << 16) + (b[o + 1] << 8) + b[o]
	const synchsafe = (b, o) => ((b[o] & 127) << 21) | ((b[o + 1] & 127) << 14) | ((b[o + 2] & 127) << 7) | (b[o + 3] & 127)
	const ascii = (b, o, n) => String.fromCharCode(...b.subarray(o, o + n))
	const utf8 = (b) => new TextDecoder('utf-8').decode(b)
	// eslint-disable-next-line no-control-regex
	const clean = (s) => String(s == null ? '' : s).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]+/g, '').trim()

	function imageMime(b) {
		if (b[0] === 0xff && b[1] === 0xd8) return 'image/jpeg'
		if (b[0] === 0x89 && b[1] === 0x50) return 'image/png'
		if (b.length > 12 && ascii(b, 0, 4) === 'RIFF' && ascii(b, 8, 4) === 'WEBP') return 'image/webp'
		return null
	}
	function setPicture(out, data) {
		const mime = data.length > 32 ? imageMime(data) : null
		if (mime && !out.picture) out.picture = { mime, data: data.slice() }
	}
	function set(out, key, value) {
		const v = clean(value)
		if (v && !out[key]) out[key] = v
	}

	// ----- ID3v2 (mp3, aac) -----

	const ID3 = {
		TIT2: 'title', TT2: 'title', TALB: 'album', TAL: 'album', TPE1: 'artist', TP1: 'artist',
		TPE2: 'albumArtist', TP2: 'albumArtist', TCOM: 'narrator', TCM: 'narrator', TDRC: 'year',
		TYER: 'year', TYE: 'year', TRCK: 'track', TRK: 'track', TPOS: 'disc', TPA: 'disc',
		MVNM: 'series', MVIN: 'seriesSeq', TIT1: 'grouping', TT1: 'grouping',
	}

	function id3String(enc, b) {
		if (enc === 0) return new TextDecoder('windows-1252').decode(b)
		if (enc === 3) return utf8(b)
		if (b[0] === 0xff && b[1] === 0xfe) return new TextDecoder('utf-16le').decode(b.subarray(2))
		if (b[0] === 0xfe && b[1] === 0xff) return new TextDecoder('utf-16be').decode(b.subarray(2))
		return new TextDecoder(enc === 2 ? 'utf-16be' : 'utf-16le').decode(b)
	}
	/** Index after the terminator of a string that starts at o. */
	function id3End(enc, b, o) {
		if (enc === 0 || enc === 3) {
			while (o < b.length && b[o] !== 0) o++
			return [o, o + 1]
		}
		while (o + 1 < b.length && (b[o] !== 0 || b[o + 1] !== 0)) o += 2
		return [o, o + 2]
	}
	function unsync(b) {
		const out = new Uint8Array(b.length)
		let n = 0
		for (let i = 0; i < b.length; i++) {
			out[n++] = b[i]
			if (b[i] === 0xff && b[i + 1] === 0) i++
		}
		return out.subarray(0, n)
	}

	async function readId3(blob) {
		const out = {}
		const h = await bytes(blob, 0, 10)
		if (h.length < 10 || ascii(h, 0, 3) !== 'ID3') return out
		const ver = h[3]
		let b = await bytes(blob, 10, synchsafe(h, 6))
		if ((h[5] & 0x80) && ver < 4) b = unsync(b)
		let o = 0
		if ((h[5] & 0x40) && ver >= 3) o = ver === 4 ? synchsafe(b, 0) : u32(b, 0) + 4
		const head = ver === 2 ? 6 : 10
		while (o + head <= b.length && b[o] !== 0) {
			const id = ascii(b, o, ver === 2 ? 3 : 4)
			const size = ver === 2 ? (b[o + 3] << 16) + (b[o + 4] << 8) + b[o + 5] : ver === 4 ? synchsafe(b, o + 4) : u32(b, o + 4)
			const flags = ver === 2 ? 0 : b[o + 9]
			o += head
			if (size <= 0 || o + size > b.length) break
			let f = b.subarray(o, o + size)
			o += size
			if (ver === 4) {
				if (flags & 0x0c) continue // compressed or encrypted
				if (flags & 0x01) f = f.subarray(4)
				if (flags & 0x02) f = unsync(f)
			} else if (ver === 3 && (flags & 0xc0)) {
				continue
			}
			const enc = f[0]
			if (ID3[id]) {
				// v2.4 separates several values with NUL.
				set(out, ID3[id], id3String(enc, f.subarray(1)).split('\0').map(clean).filter(Boolean).join(', '))
			} else if (id === 'TXXX' || id === 'TXX') {
				const [e, next] = id3End(enc, f, 1)
				const name = clean(id3String(enc, f.subarray(1, e))).toLowerCase()
				const value = id3String(enc, f.subarray(next))
				if (name === 'series') set(out, 'series', value)
				if (name === 'series-part') set(out, 'seriesSeq', value)
			} else if (id === 'APIC') {
				let p = 1
				while (p < f.length && f[p] !== 0) p++
				setPicture(out, f.subarray(id3End(enc, f, p + 2)[1]))
			} else if (id === 'PIC') {
				setPicture(out, f.subarray(id3End(enc, f, 5)[1]))
			}
		}
		return out
	}

	// ----- MP4 (m4b, m4a, mp4) -----

	const MP4 = {
		'\xa9nam': 'title', '\xa9alb': 'album', '\xa9ART': 'artist', aART: 'albumArtist', '\xa9wrt': 'narrator',
		'\xa9day': 'year', '\xa9mvn': 'series', '\xa9grp': 'grouping',
	}

	function* atoms(b, start, end) {
		for (let o = start; o + 8 <= end;) {
			let size = u32(b, o)
			let head = 8
			if (size === 1) {
				size = u32(b, o + 8) * 4294967296 + u32(b, o + 12)
				head = 16
			} else if (size === 0) {
				size = end - o
			}
			if (size < head || o + size > end) return
			yield { type: ascii(b, o + 4, 4), start: o + head, end: o + size }
			o += size
		}
	}
	function child(b, a, type, skip) {
		for (const c of atoms(b, a.start + (skip || 0), a.end)) if (c.type === type) return c
		return null
	}

	async function readMp4(blob) {
		const out = {}
		// Top level: step over the (large) audio data by its size, wherever moov is.
		let moov = null
		for (let o = 0; o + 8 <= blob.size;) {
			const h = await bytes(blob, o, 16)
			let size = u32(h, 0)
			if (size === 1) size = u32(h, 8) * 4294967296 + u32(h, 12)
			else if (size === 0) size = blob.size - o
			if (size < 8) break
			if (ascii(h, 4, 4) === 'moov') {
				moov = await bytes(blob, o, size)
				break
			}
			o += size
		}
		if (!moov) return out
		const root = { start: 8, end: moov.length }
		const udta = child(moov, root, 'udta')
		const meta = (udta && child(moov, udta, 'meta')) || child(moov, root, 'meta')
		const ilst = meta && (child(moov, meta, 'ilst', 4) || child(moov, meta, 'ilst'))
		if (!ilst) return out
		for (const item of atoms(moov, ilst.start, ilst.end)) {
			const data = child(moov, item, 'data')
			if (!data || data.end - data.start < 8) continue
			const val = moov.subarray(data.start + 8, data.end)
			if (MP4[item.type]) {
				set(out, MP4[item.type], utf8(val))
			} else if (item.type === 'covr') {
				setPicture(out, val)
			} else if (item.type === 'trkn' && val.length >= 4) {
				set(out, 'track', String((val[2] << 8) + val[3] || ''))
			} else if (item.type === 'disk' && val.length >= 4) {
				set(out, 'disc', String((val[2] << 8) + val[3] || ''))
			} else if (item.type === '\xa9mvi') {
				let v = 0
				for (const x of val) v = v * 256 + x
				set(out, 'seriesSeq', String(v || ''))
			} else if (item.type === '----') {
				const name = child(moov, item, 'name')
				const key = name ? utf8(moov.subarray(name.start + 4, name.end)).toLowerCase() : ''
				if (key === 'series') set(out, 'series', utf8(val))
				if (key === 'series-part') set(out, 'seriesSeq', utf8(val))
			}
		}
		return out
	}

	// ----- Vorbis comments (flac, ogg, opus) -----

	const VORBIS = {
		title: 'title', album: 'album', artist: 'artist', albumartist: 'albumArtist', 'album artist': 'albumArtist',
		album_artist: 'albumArtist', composer: 'narrator', narrator: 'narrator', date: 'year', year: 'year',
		tracknumber: 'track', discnumber: 'disc', series: 'series', 'series-part': 'seriesSeq', grouping: 'grouping',
	}

	function flacPicture(out, b) {
		let o = 4
		o += 4 + u32(b, o)
		o += 4 + u32(b, o)
		o += 16
		setPicture(out, b.subarray(o + 4, o + 4 + u32(b, o)))
	}
	function vorbis(out, b, o) {
		o += 4 + u32le(b, o)
		const n = u32le(b, o)
		o += 4
		for (let i = 0; i < n && o + 4 <= b.length; i++) {
			const len = u32le(b, o)
			o += 4
			if (o + len > b.length) break
			const raw = b.subarray(o, o + len)
			o += len
			const eq = raw.indexOf(61)
			if (eq < 1) continue
			const key = utf8(raw.subarray(0, eq)).toLowerCase()
			if (VORBIS[key]) {
				set(out, VORBIS[key], utf8(raw.subarray(eq + 1)))
			} else if (key === 'metadata_block_picture') {
				const bin = atob(utf8(raw.subarray(eq + 1)))
				flacPicture(out, Uint8Array.from(bin, (c) => c.charCodeAt(0)))
			}
		}
	}

	async function readFlac(blob) {
		const out = {}
		for (let o = 4; o + 4 <= blob.size;) {
			const h = await bytes(blob, o, 4)
			const size = (h[1] << 16) + (h[2] << 8) + h[3]
			const type = h[0] & 127
			if (type === 4) vorbis(out, await bytes(blob, o + 4, size), 0)
			if (type === 6) flacPicture(out, await bytes(blob, o + 4, size))
			if (h[0] & 128) break
			o += 4 + size
		}
		return out
	}

	async function readOgg(blob) {
		const out = {}
		// The comments are the second packet; it can span many pages when it holds a cover.
		const parts = []
		let total = 0
		let packet = 0
		for (let o = 0; o + 27 <= blob.size && total < MAX_TAG;) {
			const h = await bytes(blob, o, 27)
			if (ascii(h, 0, 4) !== 'OggS') break
			const lace = await bytes(blob, o + 27, h[26])
			const size = lace.reduce((a, x) => a + x, 0)
			const body = await bytes(blob, o + 27 + h[26], size)
			let p = 0
			for (const l of lace) {
				if (packet === 1) {
					parts.push(body.subarray(p, p + l))
					total += l
				}
				p += l
				if (l < 255) packet++
				if (packet > 1) break
			}
			if (packet > 1) break
			o += 27 + h[26] + size
		}
		const b = new Uint8Array(total)
		let p = 0
		for (const x of parts) {
			b.set(x, p)
			p += x.length
		}
		if (b.length > 16 && ascii(b, 0, 8) === 'OpusTags') vorbis(out, b, 8)
		else if (b.length > 16 && ascii(b, 1, 6) === 'vorbis') vorbis(out, b, 7)
		return out
	}

	/** Tags of one file. Never throws: an unreadable file just has none. */
	async function read(blob) {
		try {
			const h = await bytes(blob, 0, 12)
			if (h.length < 12) return {}
			if (ascii(h, 0, 3) === 'ID3') return await readId3(blob)
			if (ascii(h, 4, 4) === 'ftyp') return await readMp4(blob)
			if (ascii(h, 0, 4) === 'fLaC') return await readFlac(blob)
			if (ascii(h, 0, 4) === 'OggS') return await readOgg(blob)
		} catch (e) {
			// fall through
		}
		return {}
	}

	// ----- names -----

	const DISC = /^(?:cd|dis[ck]|part|teil)[\s._-]*\d{1,3}$/i
	const SEQ = /^(?:(?:book|vol(?:ume)?)\.?\s*)?#?(\d{1,3}(?:\.\d+)?)\s*[-.–:]\s*(.+)$/i

	function tidy(s) {
		return clean(String(s || '').replace(/_/g, ' ').replace(/\s*[([](?:un)?abridged[)\]]/ig, '').replace(/\s+/g, ' '))
	}

	/** Guess from folder/file names: Author/Series/1 - Title, Author/Title, "Author - Title". */
	function fromNames(dirs, name) {
		const parts = dirs.map(tidy).filter(Boolean)
		let title = tidy(name)
		const out = {}
		const m = SEQ.exec(title)
		if (m) {
			title = m[2]
			out.seriesSeq = m[1]
		}
		if (parts.length >= 2) {
			out.author = parts[parts.length - 2]
			out.series = parts[parts.length - 1]
		} else if (parts.length === 1) {
			out.author = parts[0]
		} else {
			const i = title.indexOf(' - ')
			if (i > 0) {
				out.author = title.slice(0, i).trim()
				title = title.slice(i + 3).trim()
				const n = SEQ.exec(title)
				if (n) {
					out.seriesSeq = n[1]
					title = n[2]
				}
			}
		}
		if (!out.series) delete out.seriesSeq
		out.title = title
		return out
	}

	const natural = (a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })

	/**
	 * Sort dropped files into books.
	 * @param {Array<{path: string, file: Blob}>} entries path relative to what was dropped
	 * @return {Promise<Array<{audio: Array, ebook: ?object, image: ?object, meta: object, picture: ?object}>>}
	 *         audio/ebook/image entries are {path, file}, path relative to the book
	 */
	async function group(entries) {
		const dirs = new Map()
		for (const e of entries) {
			const kind = kindOf(e.path)
			if (!kind) continue
			const parts = e.path.split('/').filter(Boolean)
			const name = parts.pop()
			const inner = []
			// CD1, Disc 2, ... belong to the folder above them.
			while (parts.length > 1 && DISC.test(parts[parts.length - 1])) inner.unshift(parts.pop())
			const dir = parts.join('/')
			if (!dirs.has(dir)) dirs.set(dir, { parts, files: [] })
			dirs.get(dir).files.push({ path: [...inner, name].join('/'), file: e.file, kind })
		}

		const books = []
		for (const { parts, files } of dirs.values()) {
			files.sort((a, b) => natural(a.path, b.path))
			const audio = files.filter((f) => f.kind === 'audio')
			if (!audio.length) continue
			for (const f of audio) f.tags = await read(f.file)

			// A folder is one book, unless it holds m4b files of different albums (an author's folder).
			// Loose files are split by album tag; m4b files are whole books.
			const isWhole = (f) => ['m4b', 'mp4'].includes(ext(f.path))
			const albums = new Set(audio.map((f) => (f.tags.album || '').toLowerCase()))
			const split = !parts.length || (audio.every(isWhole) && albums.size > 1)
			const sets = new Map()
			for (const f of audio) {
				const whole = isWhole(f)
				const key = !split ? '' : f.tags.album ? 'a:' + f.tags.album.toLowerCase() : whole ? 'f:' + f.path : ''
				if (!sets.has(key)) sets.set(key, [])
				sets.get(key).push(f)
			}
			const single = sets.size === 1
			const rest = files.filter((f) => f.kind !== 'audio')
			const stem = (p) => p.replace(/\.[^.]+$/, '').toLowerCase()
			for (const list of sets.values()) {
				const mine = (kind) => rest.filter((f) => f.kind === kind && (single || list.some((a) => stem(a.path) === stem(f.path))))
				const images = mine('image')
				const image = images.find((f) => /^(cover|folder|front)\./i.test(f.path.split('/').pop())) || images[0] || null
				books.push(describe(list, mine('ebook')[0] || null, image, parts, split))
			}
		}
		return books
	}

	function describe(audio, ebook, image, parts, split) {
		const first = audio[0].tags || {}
		const single = audio.length === 1
		const fileName = audio[0].path.split('/').pop().replace(/\.[^.]+$/, '')
		const guess = !split
			? fromNames(parts.slice(0, -1), parts[parts.length - 1])
			: single ? fromNames(parts, fileName) : {}
		const picture = (audio.find((a) => a.tags && a.tags.picture) || { tags: {} }).tags.picture || null
		return {
			audio: audio.map((a) => ({ path: a.path, file: a.file, tags: a.tags })),
			ebook: ebook && { path: ebook.path, file: ebook.file },
			image: image && { path: image.path, file: image.file },
			picture,
			meta: {
				title: first.album || (single && first.title) || guess.title || '',
				author: first.albumArtist || first.artist || guess.author || '',
				series: first.series || first.grouping || guess.series || '',
				seriesSeq: first.seriesSeq || (first.series || first.grouping ? '' : guess.seriesSeq) || '',
				narrator: first.narrator || '',
			},
		}
	}

	/** Combine several books into one, keeping the first one's details. */
	function merge(books) {
		const audio = books.flatMap((b) => b.audio).sort((a, b) => natural(a.path, b.path))
		const pick = (k) => (books.find((b) => b[k]) || {})[k] || null
		return { audio, ebook: pick('ebook'), image: pick('image'), picture: pick('picture'), meta: { ...books[0].meta } }
	}

	return { AUDIO, EBOOK, IMAGE, kindOf, read, group, merge, fromNames, natural }
})
