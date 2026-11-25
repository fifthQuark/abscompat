// node --test abscompat/tests/js
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const T = createRequire(import.meta.url)('../../js/tags.js')
const fixture = (n) => new Blob([readFileSync(new URL('../fixtures/' + n, import.meta.url))])
const e = (path, file = new Blob(['x'])) => ({ path, file })
const JPEG = Uint8Array.from([0xff, 0xd8, ...new Array(60).fill(7)])

const be = (n) => [n >>> 24, (n >>> 16) & 255, (n >>> 8) & 255, n & 255]
const str = (s) => [...new TextEncoder().encode(s)]
const id3 = (ver, frames, flags = 0) => {
	const n = frames.length
	return new Blob([Uint8Array.from([0x49, 0x44, 0x33, ver, 0, flags, (n >> 21) & 127, (n >> 14) & 127, (n >> 7) & 127, n & 127, ...frames])])
}
const frame = (id, data) => [...str(id), ...be(data.length), 0, 0, ...data]
const atom = (type, data) => [...be(data.length + 8), ...[...type].map((c) => c.charCodeAt(0)), ...data]
const le = (n) => be(n).reverse()
const comments = (list) => [...le(3), ...str('enc'), ...le(list.length), ...list.flatMap((c) => [...le(str(c).length), ...str(c)])]
const flacPic = (data) => [...be(3), ...be(10), ...str('image/jpeg'), ...be(0), ...be(1), ...be(1), ...be(24), ...be(0), ...be(data.length), ...data]

test('m4b made by ffmpeg', async () => {
	const t = await T.read(fixture('chapters.m4b'))
	assert.equal(t.album, 'Fixture Book')
	assert.equal(t.albumArtist, 'Fix Author')
	assert.equal(t.narrator, 'Fix Narrator')
})
test('mp3 made by ffmpeg', async () => {
	const t = await T.read(fixture('chapters.mp3'))
	assert.equal(t.album, 'Fixture Book')
	assert.equal(t.narrator, 'Fix Narrator')
	assert.equal((await T.read(fixture('plain.mp3'))).track, '2')
})
test('malformed tags are cleaned and never throw', async () => {
	const t = await T.read(fixture('malformed.mp3'))
	assert.equal(t.album, 'ספר 🎧 Malformed')
	assert.doesNotMatch(t.artist, /\0/)
	for (const n of [0, 3, 11, 40, 200]) {
		for (const f of ['chapters.m4b', 'chapters.mp3']) assert.ok(await T.read(fixture(f).slice(0, n)))
	}
	assert.deepEqual(await T.read(new Blob(['not audio at all, really'])), {})
	assert.ok(await T.read(new Blob([Uint8Array.from([0x49, 0x44, 0x33, 4, 0, 0, 127, 127, 127, 127, 1, 2])])))
})
test('random bytes behind every signature do not throw or hang', async () => {
	const sig = [[0x49, 0x44, 0x33, 3, 0, 0, 0, 0, 20, 0], [0, 0, 0, 8, ...str('ftyp')], str('fLaC'), str('OggS')]
	let seed = 1
	for (let i = 0; i < 400; i++) {
		const r = Array.from({ length: 300 }, () => { seed = (seed * 1103515245 + 12345) % 2147483648; return (seed >> 8) & 255 })
		assert.ok(await T.read(new Blob([Uint8Array.from([...sig[i % 4], ...r])])))
	}
})
test('id3 series, unicode, pictures', async () => {
	const b = id3(3, [
		...frame('TALB', [1, 0xff, 0xfe, ...'Ünï'.split('').flatMap((c) => [c.charCodeAt(0) & 255, c.charCodeAt(0) >> 8])]),
		...frame('TXXX', [3, ...str('SERIES'), 0, ...str('Saga')]),
		...frame('TXXX', [3, ...str('series-part'), 0, ...str('2.5')]),
		...frame('APIC', [0, ...str('image/jpeg'), 0, 3, 0, ...JPEG]),
	])
	const t = await T.read(b)
	assert.equal(t.album, 'Ünï')
	assert.equal(t.series, 'Saga')
	assert.equal(t.seriesSeq, '2.5')
	assert.equal(t.picture.mime, 'image/jpeg')
	assert.deepEqual([...t.picture.data], [...JPEG])
})
test('mp4 with moov after the audio, freeform series and cover', async () => {
	const data = (type, v) => atom('data', [...be(type), ...be(0), ...v])
	const ilst = atom('ilst', [
		...atom('\xa9nam', data(1, str('Name'))),
		...atom('----', [...atom('mean', [...be(0), ...str('com.apple.iTunes')]), ...atom('name', [...be(0), ...str('SERIES')]), ...data(1, str('Saga'))]),
		...atom('covr', data(13, [...JPEG])),
	])
	const moov = atom('moov', atom('udta', atom('meta', [...be(0), ...ilst])))
	const t = await T.read(new Blob([Uint8Array.from([...atom('ftyp', str('M4B ')), ...atom('mdat', new Array(5000).fill(1)), ...moov])]))
	assert.equal(t.title, 'Name')
	assert.equal(t.series, 'Saga')
	assert.equal(t.picture.mime, 'image/jpeg')
})
test('flac and opus comments with pictures', async () => {
	const c = comments(['ALBUM=A', 'artist=B', 'SERIES=S'])
	const p = flacPic([...JPEG])
	const flac = [...str('fLaC'), 4, 0, 0, c.length, ...c, 0x86, 0, 0, p.length, ...p]
	let t = await T.read(new Blob([Uint8Array.from(flac)]))
	assert.deepEqual([t.album, t.artist, t.series, t.picture.mime], ['A', 'B', 'S', 'image/jpeg'])

	// A comment packet long enough to need several lacing values and a second page.
	const tags = [...str('OpusTags'), ...comments(['ALBUM=A', 'X=' + 'y'.repeat(600), 'METADATA_BLOCK_PICTURE=' + Buffer.from(p).toString('base64')])]
	const page = (lace, body) => [...str('OggS'), 0, 0, ...new Array(20).fill(0), lace.length, ...lace, ...body]
	const tail = tags.slice(510)
	const lace = []
	for (let n = tail.length; ; n -= 255) { lace.push(Math.min(n, 255)); if (n < 255) break }
	const ogg = [...page([8], str('OpusHead')), ...page([255, 255], tags.slice(0, 510)), ...page(lace, tail)]
	t = await T.read(new Blob([Uint8Array.from(ogg)]))
	assert.deepEqual([t.album, t.picture.mime], ['A', 'image/jpeg'])
})
test('names', () => {
	assert.deepEqual(T.fromNames(['Ann', 'Saga'], '2 - Second'), { author: 'Ann', series: 'Saga', seriesSeq: '2', title: 'Second' })
	assert.deepEqual(T.fromNames(['Ann'], 'Title (Unabridged)'), { author: 'Ann', title: 'Title' })
	assert.deepEqual(T.fromNames([], 'Ann Lee - The_Title'), { author: 'Ann Lee', title: 'The Title' })
	assert.deepEqual(T.fromNames([], '1984'), { title: '1984' })
})
test('grouping: folders, discs, loose files, extras', async () => {
	let g = await T.group([
		e('Ann/Saga/1 - One/02.mp3'), e('Ann/Saga/1 - One/01.mp3'), e('Ann/Saga/1 - One/cover.jpg'), e('Ann/Saga/1 - One/one.epub'),
		e('Ann/Two/CD2/01.mp3'), e('Ann/Two/CD10/01.mp3'), e('Ann/Two/CD1/01.mp3'), e('Ann/notes.txt'), e('Ann/Two/.hidden.mp3'),
	])
	assert.equal(g.length, 2)
	assert.deepEqual(g[0].meta, { title: 'One', author: 'Ann', series: 'Saga', seriesSeq: '1', narrator: '' })
	assert.deepEqual(g[0].audio.map((a) => a.path), ['01.mp3', '02.mp3'])
	assert.equal(g[0].image.path, 'cover.jpg')
	assert.equal(g[0].ebook.path, 'one.epub')
	assert.deepEqual(g[1].audio.map((a) => a.path), ['CD1/01.mp3', 'CD2/01.mp3', 'CD10/01.mp3'])
	assert.equal(g[1].meta.title, 'Two')

	g = await T.group([e('Ann - A.m4b'), e('Ann - B.m4b'), e('Ann - A.epub'), e('01.mp3'), e('02.mp3')])
	assert.equal(g.length, 3)
	const a = g.find((b) => b.meta.title === 'A')
	assert.equal(a.ebook.path, 'Ann - A.epub')
	assert.equal(g.find((b) => b.audio.length === 2).meta.title, '')
	assert.deepEqual(T.merge(g).audio.length, 4)

	g = await T.group([e('x.mp3', fixture('chapters.mp3')), e('y.m4b', fixture('chapters.m4b'))])
	assert.equal(g.length, 1, 'same album tag is one book')
	assert.equal(g[0].meta.author, 'Fix Author')
	assert.deepEqual(await T.group([e('only.jpg')]), [])

	// An author's folder with whole books in it, against one book in several untagged parts.
	g = await T.group([e('Ann/a.m4b', fixture('chapters.m4b')), e('Ann/Other.m4b')])
	assert.deepEqual(g.map((b) => [b.meta.author, b.meta.title]), [['Fix Author', 'Fixture Book'], ['Ann', 'Other']])
	assert.equal((await T.group([e('Ann/Part 1.m4b'), e('Ann/Part 2.m4b')])).length, 1)
})
