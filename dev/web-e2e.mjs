// Library page and upload endpoints (Basic auth + OCS-APIRequest header; plainuser, own folder).
//   node dev/web-e2e.mjs
import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const user = env.NC_PLAIN_USER
const base = env.NC_URL + '/apps/abscompat'
const auth = { authorization: 'Basic ' + Buffer.from(`${user}:${env.NC_PLAIN_PASSWORD}`).toString('base64') }
const dav = (p) => `${env.NC_URL}/remote.php/dav/files/${user}` + p.split('/').map(encodeURIComponent).join('/')
const fixture = (n) => readFileSync(new URL(`../abscompat/tests/fixtures/${n}`, import.meta.url))
const LIB = '/WebLib', OTHER = '/WebOther'
async function call(method, path, body, headers = {}) {
  const r = await fetch(base + path, { method, headers: { ...auth, 'OCS-APIRequest': 'true', 'content-type': 'application/json', ...headers }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, type: r.headers.get('content-type') || '', json: await r.clone().json().catch(() => null), text: await r.text() }
}
async function put(path, body) {
  const r = await fetch(dav(path), { method: 'PUT', headers: auth, body })
  assert.ok([201, 204].includes(r.status), `PUT ${path}: ${r.status}`)
}
const plan = (metadata, files, dryRun = true, libraryPath = LIB) => call('POST', '/web/upload/plan', { libraryPath, metadata, files, dryRun })
const finish = (folder, metadata) => call('POST', '/web/upload/finish', { libraryPath: LIB, folder, metadata })
const exists = async (p) => (await fetch(dav(p), { method: 'PROPFIND', headers: { ...auth, depth: '0' } })).status === 207
const audio = (...p) => p.map((path) => ({ path, kind: 'audio' }))
let fails = 0, passes = 0
async function check(name, fn) {
  try { await fn(); passes++; console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 6).join(' ')) }
}
async function cleanup() {
  for (const p of [LIB, OTHER, '/WebAway']) {
    const r = await fetch(dav(p), { method: 'DELETE', headers: auth })
    assert.ok([204, 404].includes(r.status), String(r.status))
  }
  await call('DELETE', '/settings/folders')
  await call('PUT', '/web/covers/auto', { enabled: false })
}

await cleanup()
try {
  assert.equal((await call('PUT', '/settings/folders', { folders: [LIB] })).status, 200)

  await check('the page loads for a signed-in user and is not public', async () => {
    const r = await call('GET', '/')
    assert.equal(r.status, 200); assert.match(r.text, /abscompat-library/); assert.match(r.text, /js\/library/)
    const anon = await fetch(base + '/', { redirect: 'manual' })
    assert.ok([302, 303, 401].includes(anon.status), String(anon.status))
    assert.equal((await fetch(base + '/web/library', { headers: { 'OCS-APIRequest': 'true' } })).status, 401)
  })
  await check('an API key or app login is not accepted on the page endpoints', async () => {
    const { json } = await call('POST', '/settings/apikeys', { name: 'web-e2e' })
    const r = await fetch(base + '/web/library', { headers: { authorization: 'Bearer ' + json.token, 'OCS-APIRequest': 'true' } })
    assert.equal(r.status, 401)
    const id = (await call('GET', '/settings')).json.tokens.find((t) => t.name === 'web-e2e').id
    await call('DELETE', '/settings/tokens/' + id)
  })
  await check('missing library folder is listed as writable; a dry run creates nothing', async () => {
    const r = await call('GET', '/web/library')
    assert.deepEqual(r.json.libraries, [{ id: null, path: LIB, name: 'WebLib', writable: true }])
    assert.deepEqual(r.json.books, [])
    assert.equal(r.json.covers.auto, false, 'automatic covers are off by default')
    const p = await plan({ title: 'T', author: 'A' }, audio('x.mp3'))
    assert.deepEqual(p.json, { folder: LIB + '/A/T', files: ['x.mp3'] })
    assert.equal(await exists(LIB), false)
  })
  await check('paths: series, numbers, odd characters, traversal, flattened discs, cover name', async () => {
    const folder = async (m) => (await plan(m, audio('x.mp3'))).json.folder
    assert.equal(await folder({ title: 'T', author: 'A', series: 'S', seriesSeq: '2.5' }), LIB + '/A/S/2.5 - T')
    assert.equal(await folder({ title: 'T', author: 'A', series: 'S', seriesSeq: '../x' }), LIB + '/A/S/T')
    assert.equal(await folder({ title: 'T', series: 'S' }), LIB + '/Unknown author/S/T')
    assert.equal(await folder({ title: '../../etc', author: '..' }), LIB + '/_.._etc')
    assert.equal(await folder({ title: 'A: B/C?', author: 'ספר 🎧' }), LIB + '/ספר 🎧/A - B_C_')
    assert.equal(await folder({ title: 'x'.repeat(900), author: 'A' }), LIB + '/A/' + 'x'.repeat(120))
    const f = await plan({ title: 'T' }, [...audio('CD1/01.mp3', 'CD2/01.mp3', '../../e.mp3'), { path: 'q/b.epub', kind: 'ebook' }, { path: 'Photo.JPEG', kind: 'cover' }])
    assert.deepEqual(f.json.files, ['CD1 - 01.mp3', 'CD2 - 01.mp3', 'e.mp3', 'q - b.epub', 'cover.jpg'])
  })
  await check('refused: no title, other folder, wrong file types', async () => {
    assert.equal((await plan({ title: ' ', author: 'A' }, [])).json.code, 'title')
    assert.equal((await plan({ title: 'T' }, [], true, OTHER)).status, 400)
    assert.equal((await plan({ title: 'T' }, [], true, LIB + '/../WebOther')).status, 400)
    for (const f of [{ path: 'x.php', kind: 'audio' }, { path: 'x.svg', kind: 'cover' }, { path: 'x.mp3', kind: 'other' }, { path: 'mp3', kind: 'audio' }]) {
      assert.equal((await plan({ title: 'T' }, [f])).status, 400, JSON.stringify(f))
    }
    for (const bad of [OTHER + '/A', LIB, LIB + '/../WebOther/A', LIB + '/./A', LIB + '//A']) {
      assert.equal((await finish(bad, {})).status, 400, bad)
      assert.equal((await call('POST', '/web/upload/cancel', { libraryPath: LIB, folder: bad })).json.removed, 0, bad)
    }
  })
  let book
  await check('upload with form details: folders, files, cover; listed as typed', async () => {
    const meta = { title: 'My: Title', author: 'Ann Lee', series: 'Saga', seriesSeq: '3', narrator: 'Nora' }
    const p = await plan(meta, [...audio('b.mp3'), { path: 'c.png', kind: 'cover' }], false)
    assert.equal(p.json.folder, LIB + '/Ann Lee/Saga/3 - My - Title')
    assert.ok(await exists(p.json.folder))
    await put(p.json.folder + '/' + p.json.files[0], fixture('chapters.mp3'))
    await put(p.json.folder + '/' + p.json.files[1], Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'))
    const r = await finish(p.json.folder, meta)
    assert.equal(r.status, 200, r.text)
    book = r.json
    assert.deepEqual([book.title, book.author, book.series, book.seriesSeq, book.narrator, book.hasCover], ['My: Title', 'Ann Lee', 'Saga', '3', 'Nora', true])
    const lib = (await call('GET', '/web/library')).json
    assert.equal(lib.libraries[0].id, book.libraryId)
    assert.deepEqual(lib.books, [book])
    const c = await call('GET', `/web/items/${book.id}/cover`)
    assert.deepEqual([c.status, c.type], [200, 'image/png'])
  })
  await check('details survive a full rescan and reach the Audiobookshelf API', async () => {
    const rs = (await call('POST', '/settings/rescan')).json
    assert.deepEqual([rs.items, rs.removed], [1, 0])
    const d = (await call('GET', '/web/items/' + book.id)).json
    assert.deepEqual([d.title, d.narrator, d.files.length, d.chapters.length], ['My: Title', 'Nora', 1, 2])
    const before = (await call('GET', '/settings')).json.tokens.map((t) => t.id)
    const login = await (await fetch(base + '/login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ username: user, password: env.NC_PLAIN_PASSWORD }) })).json()
    const token = login.user.accessToken
    const m = (await (await fetch(`${base}/api/items/${book.id}`, { headers: { authorization: 'Bearer ' + token } })).json()).media.metadata
    assert.deepEqual([m.title, m.authorName, m.seriesName, m.narratorName], ['My: Title', 'Ann Lee', 'Saga #3', 'Nora'])
    for (const t of (await call('GET', '/settings')).json.tokens) if (!before.includes(t.id)) await call('DELETE', '/settings/tokens/' + t.id)
  })
  await check('unchanged tags are not stored as edits, so later tag changes show', async () => {
    const meta = { title: 'Fixture Book', author: 'Fix Author', narrator: 'Fix Narrator', series: '', seriesSeq: '' }
    const p = await plan(meta, audio('b.mp3'), false)
    await put(p.json.folder + '/b.mp3', fixture('chapters.mp3'))
    assert.equal((await finish(p.json.folder, meta)).json.title, 'Fixture Book')
    await put(p.json.folder + '/b.mp3', fixture('malformed.mp3'))
    await call('POST', '/settings/rescan')
    assert.ok((await call('GET', '/web/library')).json.books.some((b) => b.title === 'ספר 🎧 Malformed'))
  })
  await check('a book that exists, or would fall inside another book, is refused', async () => {
    const same = await plan({ title: 'My: Title', author: 'Ann Lee', series: 'Saga', seriesSeq: '3' }, audio('x.mp3'))
    assert.deepEqual([same.status, same.json.code], [409, 'exists'])
    assert.equal((await plan({ title: 'Ann Lee' }, audio('x.mp3'))).status, 409, 'would swallow the author folder')
    const inside = await plan({ title: 'New', author: 'Fix Author', series: 'Fixture Book' }, audio('x.mp3'))
    assert.deepEqual([inside.status, inside.json.code], [409, 'inside'])
  })
  await check('tracks with repeated numbers (discs) play in file order', async () => {
    const meta = { title: 'Discs', author: 'D' }
    const p = await plan(meta, audio('CD1/01.mp3', 'CD2/01.mp3'), false)
    for (const f of p.json.files) await put(p.json.folder + '/' + f, fixture('plain.mp3'))
    const b = (await finish(p.json.folder, meta)).json
    assert.deepEqual((await call('GET', '/web/items/' + b.id)).json.files.map((f) => f.name), ['CD1 - 01.mp3', 'CD2 - 01.mp3'])
  })
  await check('cancel removes only empty folders', async () => {
    const p = await plan({ title: 'Gone', author: 'Nobody' }, audio('x.mp3'), false)
    assert.equal((await call('POST', '/web/upload/cancel', { libraryPath: LIB, folder: p.json.folder })).json.removed, 2)
    assert.equal(await exists(LIB + '/Nobody'), false)
    const q = await plan({ title: 'Kept', author: 'Ann Lee' }, audio('x.mp3'), false)
    assert.equal((await call('POST', '/web/upload/cancel', { libraryPath: LIB, folder: q.json.folder })).json.removed, 1)
    assert.ok(await exists(LIB + '/Ann Lee/Saga'))
    assert.equal((await call('POST', '/web/upload/cancel', { libraryPath: LIB, folder: LIB + '/Ann Lee/Saga/3 - My - Title' })).json.removed, 0)
    assert.ok(await exists(LIB + '/Ann Lee/Saga/3 - My - Title/b.mp3'))
  })
  // No test contacts the search service.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABgCAIAAAAip+O/AAAAZ0lEQVR42u3PQQkAAAgEsItjCPtjLP82EAYrsEzXaxEQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQuBbvUCGlVIqooAAAAABJRU5ErkJggg==', 'base64')
  const uploadCover = async (id, body, headers = auth) => {
    const form = new FormData()
    form.append('image', new Blob([body]), 'x.png')
    const r = await fetch(`${base}/web/items/${id}/cover/upload`, { method: 'POST', headers: { ...headers, 'OCS-APIRequest': 'true' }, body: form })
    return { status: r.status, json: await r.json().catch(() => null) }
  }
  const coverSize = async (id) => {
    const b = Buffer.from(await (await fetch(`${base}/web/items/${id}/cover`, { headers: auth })).arrayBuffer())
    if (b[0] !== 0xff || b[1] !== 0xd8) return null
    for (let o = 2; o < b.length;) {
      if (b[o + 1] >= 0xc0 && b[o + 1] <= 0xc2) return [b.readUInt16BE(o + 7), b.readUInt16BE(o + 5)]
      o += 2 + b.readUInt16BE(o + 2)
    }
  }
  await check('own cover for a book without one: made square, kept, removable', async () => {
    const b = (await call('GET', '/web/library')).json.books.find((x) => x.title === 'Discs')
    assert.deepEqual([b.hasCover, b.coverEditable], [false, true])
    const r = await uploadCover(b.id, png)
    assert.deepEqual([r.status, r.json.hasCover], [200, true], JSON.stringify(r))
    assert.deepEqual(await coverSize(b.id), [96, 96], 'a 64x96 image becomes square without cutting')
    // Survives a rescan with changed files, and leaving and coming back.
    await put(LIB + '/D/Discs/CD2 - 01.mp3', fixture('chapters.mp3'))
    await call('POST', '/settings/rescan')
    assert.deepEqual(await coverSize(b.id), [96, 96])
    const move = (from, to) => fetch(dav(from), { method: 'MOVE', headers: { ...auth, destination: dav(to) } })
    assert.equal((await move(LIB + '/D', '/WebAway')).status, 201)
    await call('POST', '/settings/rescan')
    assert.equal((await call('GET', '/web/items/' + b.id)).status, 404)
    assert.equal((await move('/WebAway', LIB + '/D')).status, 201)
    await call('POST', '/settings/rescan')
    assert.deepEqual(await coverSize(b.id), [96, 96])
    assert.equal((await call('DELETE', `/web/items/${b.id}/cover`)).json.hasCover, false)
    assert.equal((await call('GET', `/web/items/${b.id}/cover`)).status, 404)
  })
  await check('a cover in the files can be replaced; removing the new one brings it back; the file stays', async () => {
    const type = async () => (await fetch(`${base}/web/items/${book.id}/cover`, { headers: auth })).headers.get('content-type')
    assert.equal(await type(), 'image/png')
    assert.equal((await call('DELETE', `/web/items/${book.id}/cover`)).status, 409, 'nothing chosen to remove')
    assert.equal((await call('POST', `/web/items/${book.id}/cover/auto`)).status, 409, 'no lookups for books with a cover')
    const r = await uploadCover(book.id, png)
    assert.deepEqual([r.status, r.json.hasCover, r.json.coverChosen], [200, true, true], JSON.stringify(r))
    assert.deepEqual(await coverSize(book.id), [96, 96])
    const d = (await call('GET', '/web/items/' + book.id)).json
    assert.deepEqual([d.coverChosen, d.coverInFiles, d.coverEditable], [true, true, true])
    await call('POST', '/settings/rescan')
    assert.deepEqual(await coverSize(book.id), [96, 96], 'kept on rescan')
    const login = await (await fetch(base + '/login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ username: user, password: env.NC_PLAIN_PASSWORD }) })).json()
    const app = await fetch(`${base}/api/items/${book.id}/cover`, { headers: { authorization: 'Bearer ' + login.user.accessToken } })
    assert.equal(app.headers.get('content-type'), 'image/jpeg', 'the apps get the new one too')
    await fetch(base + '/logout', { method: 'POST', headers: { authorization: 'Bearer ' + login.user.accessToken } })
    assert.deepEqual((await call('DELETE', `/web/items/${book.id}/cover`)).json.coverChosen, false)
    assert.equal(await type(), 'image/png')
    assert.ok(await exists(LIB + '/Ann Lee/Saga/3 - My - Title/cover.png'), 'the file is untouched')
  })
  await check('bad images and foreign addresses are refused', async () => {
    const other = (await call('GET', '/web/library')).json.books.find((x) => x.title === 'Discs')
    for (const bad of [Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>'), Buffer.from('GIF89a'), png.subarray(0, 60)]) {
      assert.equal((await uploadCover(other.id, bad)).status, 422)
    }
    for (const url of ['https://example.org/a.jpg', 'http://127.0.0.1/', 'https://is1-ssl.mzstatic.com/image/x/1200x1200bb.jpg']) {
      const sig = '00'.repeat(64)
      assert.equal((await call('POST', `/web/items/${other.id}/cover`, { url, sig })).status, 400, url)
      assert.equal((await call('GET', '/web/covers/preview?url=' + encodeURIComponent(url) + '&sig=' + sig)).status, 400, url)
    }
    const noCsrf = await fetch(`${base}/web/items/${other.id}/cover`, { method: 'DELETE' })
    assert.ok([401, 412].includes(noCsrf.status), String(noCsrf.status))
    assert.equal((await call('GET', '/web/items/' + other.id)).json.hasCover, false)
  })
  await check('automatic covers can be switched on and off', async () => {
    for (const enabled of [true, false]) {
      assert.equal((await call('PUT', '/web/covers/auto', { enabled })).json.auto, enabled)
      assert.equal((await call('GET', '/web/library')).json.covers.auto, enabled)
    }
  })
  await check('a newly chosen folder is scanned although an older folder was scanned just now', async () => {
    const mk = await fetch(dav(OTHER), { method: 'MKCOL', headers: auth })
    assert.equal(mk.status, 201)
    await put(OTHER + '/Found.mp3', fixture('plain.mp3'))
    await call('PUT', '/settings/folders', { folders: [OTHER] })
    assert.equal((await call('GET', '/web/library')).json.books.length, 1)
    await call('PUT', '/settings/folders', { folders: [LIB] })
  })
  await check('series of existing books: set, number, rename, clear; kept on rescan, seen by the apps', async () => {
    const lib = async () => (await call('GET', '/web/library')).json.books
    const discs = (await lib()).find((x) => x.title === 'Discs')
    assert.equal(discs.series, null)
    const r = await call('PUT', '/web/series', { books: [{ id: discs.id, series: '  New\u0000 Saga ', seriesSeq: '1.5' }, { id: book.id, series: 'New Saga', seriesSeq: '' }] })
    assert.equal(r.status, 200, r.text)
    assert.deepEqual(r.json.books.map((b) => [b.id, b.series, b.seriesSeq]), [[discs.id, 'New Saga', '1.5'], [book.id, 'New Saga', null]])
    await put(LIB + '/D/Discs/CD2 - 01.mp3', fixture('plain.mp3'))
    await call('POST', '/settings/rescan')
    const all = await lib()
    assert.deepEqual([discs.id, book.id].map((id) => all.find((b) => b.id === id)).map((b) => [b.series, b.seriesSeq]), [['New Saga', '1.5'], ['New Saga', null]])
    const { json } = await call('POST', '/settings/apikeys', { name: 'web-e2e-series' })
    const api = async (p) => (await fetch(base + p, { headers: { authorization: 'Bearer ' + json.token } })).json()
    const s = (await api(`/api/libraries/${book.libraryId}/series?limit=100`)).results
    assert.deepEqual(s.map((x) => [x.name, x.books.map((b) => b.id)]), [['New Saga', [discs.id, book.id]]])
    const id = (await call('GET', '/settings')).json.tokens.find((t) => t.name === 'web-e2e-series').id
    await call('DELETE', '/settings/tokens/' + id)
    const back = await call('PUT', '/web/series', { books: [{ id: discs.id, series: '', seriesSeq: '4' }] })
    assert.deepEqual([back.json.books[0].series, back.json.books[0].seriesSeq], [null, null])
  })
  await check('series: refused without books, for unknown books, for other users, and all or nothing', async () => {
    assert.equal((await call('PUT', '/web/series', { books: [] })).status, 400)
    assert.equal((await call('PUT', '/web/series', { books: Array(501).fill({ id: book.id }) })).status, 400)
    for (const bad of [{ id: '0', series: 'X' }, { series: 'X' }, 'x', { id: [book.id], series: 'X' }]) {
      assert.equal((await call('PUT', '/web/series', { books: [{ id: book.id, series: 'X' }, bad] })).status, 404, JSON.stringify(bad))
    }
    const admin = { authorization: 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64') }
    assert.equal((await call('PUT', '/web/series', { books: [{ id: book.id, series: 'X' }] }, admin)).status, 404)
    const noCsrf = await fetch(`${base}/web/series`, { method: 'PUT', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ books: [{ id: book.id, series: 'X' }] }) })
    assert.ok([401, 412].includes(noCsrf.status), String(noCsrf.status))
    assert.equal((await call('GET', '/web/items/' + book.id)).json.series, 'New Saga')
  })
  await check('details: edit, kept on rescan, only changed fields stick; bad input refused', async () => {
    const id = book.id
    const put2 = (metadata) => call('PUT', '/web/items/' + id, { metadata })
    assert.equal((await put2({ title: ' ' })).status, 400)
    assert.equal((await call('PUT', '/web/items/0', { metadata: { title: 'x' } })).status, 404)
    const r = await put2({ title: 'My: Title', subtitle: 'Under', tags: ['a', 'b', 'a'], genres: ['G'], publisher: 'P', year: '2001', description: 'Text', explicit: true, moreSeries: [{ name: 'Second', seq: '9' }, { name: '' }], series: 'New Saga', seriesSeq: '', evil: 'x', author: ['no'] })
    assert.equal(r.status, 200, r.text)
    await call('POST', '/settings/rescan')
    const d = (await call('GET', '/web/items/' + id)).json
    assert.deepEqual([d.subtitle, d.tags, d.genres, d.publisher, d.year, d.description, d.explicit, d.series, d.moreSeries, d.author], ['Under', ['a', 'b'], ['G'], 'P', '2001', 'Text', true, 'New Saga', [{ name: 'Second', seq: '9' }], 'Ann Lee'])
    assert.equal((await call('PUT', '/web/series/description', { name: 'Second', description: 'Note' })).json.description, 'Note')
    assert.equal((await call('GET', '/web/series/description?name=second')).json.description, 'Note')
    await call('PUT', '/web/series/description', { name: 'Second', description: '' })
  })
  await check('lists: create, change, delete; other users and foreign books are refused', async () => {
    const admin = { authorization: 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64') }
    const make = (body, h) => call('POST', '/web/lists', { kind: 'collection', libraryId: book.libraryId, name: 'L', items: [book.id], ...body }, h)
    for (const bad of [{ kind: 'x' }, { name: '<b></b>' }, { libraryId: '0' }]) assert.equal((await make(bad)).status, 400, JSON.stringify(bad))
    assert.equal((await make({ items: ['0'] })).status, 404)
    assert.equal((await make({}, admin)).status, 400)
    const l = (await make({})).json
    assert.deepEqual((await call('GET', '/web/library')).json.lists, [l])
    assert.equal((await call('PUT', '/web/lists/' + l.id, { name: 'x', items: ['0'] })).status, 400)
    for (const m of ['PUT', 'DELETE']) assert.equal((await call(m, '/web/lists/' + l.id, { name: 'x' }, admin)).status, 404, m)
    assert.deepEqual((await call('PUT', '/web/lists/' + l.id, { name: 'New', description: 'D', items: [] })).json, { ...l, name: 'New', description: 'D', items: [] })
    assert.equal((await call('DELETE', '/web/lists/' + l.id)).status, 200)
    assert.deepEqual((await call('GET', '/web/library')).json.lists, [])
  })
  await check('bookmarks and statistics on the page', async () => {
    assert.deepEqual((await call('PUT', `/web/items/${book.id}/bookmarks`, { time: 2.5, title: 'Here' })).json.bookmarks, [{ time: 2.5, title: 'Here' }])
    assert.deepEqual((await call('PUT', `/web/items/${book.id}/bookmarks`, { time: 2.5, title: 'There' })).json.bookmarks, [{ time: 2.5, title: 'There' }])
    assert.deepEqual((await call('DELETE', `/web/items/${book.id}/bookmarks?time=2.5`)).json.bookmarks, [])
    assert.equal((await call('DELETE', `/web/items/${book.id}/bookmarks?time=2.5`)).status, 404)
    const s = (await call('GET', '/web/stats?year=2020')).json
    assert.equal(s.year.totalListeningTime, 0)
  })
  await check('the player: only with streaming on, only the book\'s audio, moves the position', async () => {
    const discs = (await call('GET', '/web/library')).json.books.find((x) => x.title === 'Discs')
    assert.equal((await call('GET', '/web/library')).json.streaming, false)
    assert.equal((await call('POST', `/web/items/${discs.id}/play`)).status, 403)
    await call('PUT', '/settings/streaming', { enabled: true })
    try {
      assert.equal((await call('GET', '/web/library')).json.streaming, true)
      const p = await call('POST', `/web/items/${discs.id}/play`)
      assert.equal(p.status, 200, p.text)
      assert.equal(p.json.tracks.length, 2)
      assert.equal(p.json.tracks[1].start, p.json.tracks[0].duration)
      const get = (u, headers = {}) => fetch(env.NC_URL + u, { headers: { ...auth, ...headers } })
      const a = await get(p.json.tracks[0].url, { range: 'bytes=0-9' })
      assert.deepEqual([a.status, a.headers.get('content-type'), (await a.arrayBuffer()).byteLength], [206, 'audio/mpeg', 10])
      const other = (await call('GET', '/web/items/' + book.id)).json
      assert.equal((await get(p.json.tracks[0].url.replace(/\/audio\/\d+$/, '/audio/' + (await call('GET', '/web/library')).json.books.find((x) => x.id === book.id).id))).status, 404, 'not an audio file of this book')
      assert.equal(other.id, book.id)
      const admin = { authorization: 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64') }
      assert.equal((await fetch(env.NC_URL + p.json.tracks[0].url, { headers: admin })).status, 403, 'another user, without streaming')
      assert.equal((await call('POST', `/web/sessions/${p.json.sessionId}/sync`, { currentTime: 7, timeListened: 5 })).status, 200)
      assert.equal((await call('GET', '/web/items/' + discs.id)).json.progress.currentTime, 7)
      assert.equal((await call('POST', `/web/sessions/${p.json.sessionId}/sync`, { currentTime: 8, timeListened: 1 }, admin)).status, 404, 'not their session')
      assert.equal((await call('POST', `/web/sessions/${p.json.sessionId}/sync`, { currentTime: 9, close: true })).status, 200)
      assert.ok((await call('GET', '/web/stats')).json.total >= 5)
      await call('PUT', '/settings/streaming', { enabled: false })
      assert.equal((await get(p.json.tracks[0].url)).status, 403, 'off again')
    } finally {
      await call('PUT', '/settings/streaming', { enabled: false })
    }
  })
  await check('an upload without audio is reported; another user sees nothing', async () => {
    const p = await plan({ title: 'Empty' }, [], false)
    assert.equal((await finish(p.json.folder, {})).status, 422)
    const admin = { authorization: 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64') }
    for (const p2 of ['', '/cover']) assert.equal((await call('GET', `/web/items/${book.id}${p2}`, null, admin)).status, 404)
  })
} finally {
  await cleanup()
}
console.log(`\n${passes} passed, ${fails} failed`)
process.exit(fails ? 1 : 0)
