// Scanner regressions: edits survive books going missing, and CrossPoint Sync never rescans.
// Uses plainuser and its own folders (uploaded over WebDAV), and occ to age the last scan.
//   node dev/scan-e2e.mjs      (NC_CONTAINER=abs-nc34, DOCKER=podman by default)
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'
import { makeEpub } from './epub.mjs'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const CONTAINER = process.env.NC_CONTAINER || 'abs-nc34'
const user = env.NC_PLAIN_USER
const base = env.NC_URL + '/apps/abscompat'
const davRoot = `${env.NC_URL}/remote.php/dav/files/${user}`
const basic = 'Basic ' + Buffer.from(`${user}:${env.NC_PLAIN_PASSWORD}`).toString('base64')
const fixture = (n) => readFileSync(new URL(`../abscompat/tests/fixtures/${n}`, import.meta.url))
const davUrl = (p) => davRoot + p.split('/').map(encodeURIComponent).join('/')
const LIB = '/RegLib', AWAY = '/RegAway'

async function dav(method, path, { body, to, ok = [201, 204] } = {}) {
  const headers = { authorization: basic }
  if (to) { headers.destination = davUrl(to); headers.overwrite = 'F' }
  const r = await fetch(davUrl(path), { method, headers, body })
  assert.ok(ok.includes(r.status), `${method} ${path}: ${r.status}`)
}
async function settings(method, path, body) {
  const r = await fetch(base + '/settings' + path, { method, headers: { authorization: basic, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  assert.equal(r.status, 200, `${method} /settings${path}: ${r.status}`)
  return r.json()
}
let token
async function api(method, path, body) {
  const r = await fetch(base + path, { method, headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, json: await r.json().catch(() => null) }
}
const occ = (...a) => execFileSync(process.env.DOCKER || 'podman', ['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...a], { encoding: 'utf8' })
const rescan = () => settings('POST', '/rescan')
const lib = async () => (await api('GET', '/api/libraries')).json.libraries[0]
const items = async () => (await api('GET', `/api/libraries/${(await lib()).id}/items?limit=0`)).json.results

let fails = 0, passes = 0
async function check(name, fn) {
  try { await fn(); passes++; console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 14).join(' ')) }
}
async function cleanup() {
  for (const p of [LIB, AWAY]) await dav('DELETE', p, { ok: [204, 404] })
  await settings('DELETE', '/folders')
  for (const t of (await settings('GET', '')).tokens) await settings('DELETE', `/tokens/${t.id}`)
}

await cleanup()
try {
  for (const p of [LIB, `${LIB}/Ann`, `${LIB}/Ann/One`, AWAY]) await dav('MKCOL', p)
  await dav('PUT', `${LIB}/Ann/One/one.mp3`, { body: fixture('plain.mp3') })
  await settings('PUT', '/folders', { folders: [LIB] })
  await rescan()
  token = (await settings('POST', '/apikeys', { name: 'scan-e2e' })).token
  const [book] = await items()
  assert.ok(book, 'setup: book found')
  const edit = { metadata: { title: 'Edited Title', authors: [{ name: 'Edited Author' }], series: [{ name: 'Edited Series', sequence: '4' }] } }
  await api('PATCH', `/api/me/progress/${book.id}`, { currentTime: 2, duration: 6 })
  // Each check starts from: book in place, scanned, edited.
  const reset = async () => {
    await dav('MOVE', LIB + 'X', { to: LIB, ok: [201, 204, 404, 412] })
    await dav('MOVE', `${AWAY}/Ann`, { to: `${LIB}/Ann`, ok: [201, 204, 404, 412] })
    await rescan()
    assert.equal((await api('PATCH', `/api/items/${book.id}/media`, edit)).status, 200)
  }
  const assertEdited = async () => {
    const r = await api('GET', `/api/items/${book.id}`)
    assert.equal(r.status, 200, 'item gone')
    const m = r.json.media.metadata
    assert.deepEqual([m.title, m.authorName, m.seriesName], ['Edited Title', 'Edited Author', 'Edited Series #4'])
    assert.equal((await api('GET', `/api/me/progress/${book.id}`)).json.currentTime, 2)
  }

  await check('edits survive the library folder being renamed, scanned, and renamed back', async () => {
    await reset()
    await dav('MOVE', LIB, { to: LIB + 'X' })
    const r = await rescan()
    assert.equal(r.removed, 0, 'removed ' + r.removed)
    assert.equal((await api('GET', `/api/items/${book.id}`)).status, 404, 'hidden while its library is missing')
    await dav('MOVE', LIB + 'X', { to: LIB })
    await rescan()
    await assertEdited()
  })
  await check('edits survive a book leaving the library, being scanned out, and returning', async () => {
    await reset()
    await dav('MOVE', `${LIB}/Ann`, { to: `${AWAY}/Ann` })
    assert.equal((await rescan()).removed, 1)
    assert.equal((await api('GET', `/api/items/${book.id}`)).status, 404)
    await dav('MOVE', `${AWAY}/Ann`, { to: `${LIB}/Ann` })
    await rescan()
    await assertEdited()
  })
  await check('a book with malformed tags and chapters is still listed, with its chapters', async () => {
    await reset()
    await dav('MKCOL', `${LIB}/Ann/Bad`)
    await dav('PUT', `${LIB}/Ann/Bad/bad.mp3`, { body: fixture('malformed.mp3') })
    await rescan()
    const it = (await items()).find((i) => i.relPath.endsWith('Bad'))
    assert.ok(it, 'book missing from the library')
    const full = (await api('GET', `/api/items/${it.id}?expanded=1`)).json
    assert.equal(full.media.metadata.title, 'ספר 🎧 Malformed')
    assert.match(full.media.metadata.description, /^broken/)
    assert.equal(full.media.chapters.length, 2)
    await dav('DELETE', `${LIB}/Ann/Bad`)
    await rescan()
  })
  await check('series come from tags, also for books scanned by an older version of the app', async () => {
    await reset()
    const files = { Text: 'series-text.mp3', Move: 'series-movement.mp3', Group: 'series-grouping.mp3', M4b: 'series.m4b' }
    for (const [dir, f] of Object.entries(files)) {
      await dav('MKCOL', `${LIB}/Ann/${dir}`)
      await dav('PUT', `${LIB}/Ann/${dir}/${f}`, { body: fixture(f) })
    }
    const names = async () => Object.fromEntries((await items()).filter((i) => i.id !== book.id).map((i) => [i.relPath.split('/').pop(), i.media.metadata.seriesName]))
    const expected = { Text: 'Saga é #3', Move: 'Saga é #4', Group: 'Saga #2.5, Other #1', M4b: 'Saga #6' }
    await rescan()
    assert.deepEqual(await names(), expected)
    const before = await items()
    // As an older version left them: no version, no series tags.
    const php = `define("OC_CONSOLE", 1); require "/var/www/html/lib/base.php"; $db = \\OCP\\Server::get(\\OCP\\IDBConnection::class);
      foreach ($db->executeQuery("SELECT t.id, t.meta, i.id AS item FROM *PREFIX*abs_tracks t JOIN *PREFIX*abs_items i ON i.id = t.item_id WHERE i.user_id = ?", ["${user}"])->fetchAll() as $r) {
        $m = json_decode($r["meta"], true); unset($m["v"], $m["tags"]["series"], $m["tags"]["seriesPart"], $m["tags"]["grouping"]);
        $db->executeStatement("UPDATE *PREFIX*abs_tracks SET meta = ? WHERE id = ?", [json_encode($m), $r["id"]]);
        $db->executeStatement("UPDATE *PREFIX*abs_items SET series = NULL, series_seq = NULL, extra = NULL WHERE id = ?", [$r["item"]]);
      }`
    execFileSync(process.env.DOCKER || 'podman', ['exec', '-u', 'www-data', CONTAINER, 'php', '-r', php], { encoding: 'utf8' })
    assert.deepEqual(Object.values(await names()), ['', '', '', ''], 'setup: series gone')
    await rescan()
    assert.deepEqual(await names(), expected)
    await assertEdited()
    const after = await items()
    const same = (l) => l.map((i) => [i.id, i.media.duration, i.addedAt, i.media.numChapters]).sort()
    assert.deepEqual(same(after), same(before))
    await rescan()
    assert.deepEqual((await items()).map((i) => i.updatedAt).sort(), after.map((i) => i.updatedAt).sort(), 'read once, not on every scan')
    for (const dir of Object.keys(files)) await dav('DELETE', `${LIB}/Ann/${dir}`)
    await rescan()
  })
  await check('ebooks: formats, ebook-only books, the one to read, booklets next to books', async () => {
    await reset()
    const epub = makeEpub({ title: 'Paper Title', author: 'Eve Pen', series: 'Inks', seq: '2', cover: true })
    for (const d of ['Ann/Both', 'Eve', 'Eve/Only', 'Eve/Shelf', 'Eve/Shelf/Inner']) await dav('MKCOL', `${LIB}/${d}`)
    for (const [p, body] of Object.entries({
      'Ann/Both/a.mp3': fixture('plain.mp3'), 'Ann/Both/a.pdf': '%PDF-1.4', 'Ann/Both/a.epub': epub, 'Ann/Both/a.txt': 'no',
      'Eve/Only/only.epub': epub, 'Eve/Only/only.pdf': '%PDF-1.4',
      'Eve/Shelf/x.epub': makeEpub({ title: 'X' }), 'Eve/Shelf/y.mobi': 'mobi', 'Eve/Shelf/map.pdf': '%PDF', 'Eve/Shelf/Inner/in.mp3': fixture('plain.mp3'),
      'Root.epub': makeEpub({ title: 'Rooted', author: 'R' }),
    })) await dav('PUT', `${LIB}/${p}`, { body })
    await rescan()
    const list = async () => Object.fromEntries((await items()).filter((i) => i.id !== book.id).map((i) => [i.relPath, i]))
    let l = await list()
    assert.deepEqual(Object.keys(l).sort(), ['Ann/Both', 'Eve/Only', 'Eve/Shelf/Inner', 'Eve/Shelf/x.epub', 'Eve/Shelf/y.mobi', 'Root.epub'])
    assert.deepEqual(Object.fromEntries(Object.entries(l).map(([k, i]) => [k, [i.media.metadata.title, i.media.ebookFormat, i.media.numTracks]])), {
      'Ann/Both': ['Plain', 'epub', 1], 'Eve/Only': ['Paper Title', 'epub', 0], 'Eve/Shelf/Inner': ['Plain', null, 1], 'Eve/Shelf/x.epub': ['X', 'epub', 0], 'Eve/Shelf/y.mobi': ['y', 'mobi', 0], 'Root.epub': ['Rooted', 'epub', 0],
    })
    const only = l['Eve/Only']
    assert.deepEqual([only.media.metadata.authorName, only.media.metadata.seriesName, !!only.media.coverPath, only.numFiles], ['Eve Pen', 'Inks #2', true, 2])
    const both = (await api('GET', `/api/items/${l['Ann/Both'].id}?expanded=1`)).json
    const files = both.libraryFiles.filter((f) => f.fileType === 'ebook')
    assert.deepEqual(files.map((f) => [f.metadata.filename, f.isSupplementary]), [['a.epub', false], ['a.pdf', true]])
    const raw = (p) => fetch(base + p, { headers: { authorization: `Bearer ${token}` } })
    assert.ok(Buffer.from(await (await raw(`/api/items/${both.id}/ebook`)).arrayBuffer()).equals(epub))
    const pdf = files[1].ino
    assert.equal(await (await raw(`/api/items/${both.id}/ebook/${pdf}`)).text(), '%PDF-1.4')
    assert.equal((await raw(`/api/items/${both.id}/file/${pdf}/download`)).status, 200)
    assert.equal((await raw(`/api/items/${only.id}/ebook/${pdf}`)).status, 404, 'a file of another book')
    assert.equal((await api('PATCH', `/api/items/${both.id}/ebook/${pdf}/status`)).status, 200)
    await rescan()
    const now = (await api('GET', `/api/items/${both.id}?expanded=1`)).json
    assert.equal(now.media.ebookFile.ebookFormat, 'pdf')
    await api('PATCH', `/api/items/${both.id}/ebook/${pdf}/status`)
    assert.equal((await api('GET', `/api/items/${both.id}?expanded=1`)).json.media.ebookFile, null)
    assert.equal((await api('PATCH', `/api/items/${both.id}/ebook/999999/status`)).status, 400)
    // Reading progress of an ebook-only book.
    await api('PATCH', `/api/me/progress/${only.id}`, { ebookProgress: 0.4, ebookLocation: 'epubcfi(/6/2)' })
    const shelves = (await api('GET', `/api/libraries/${(await lib()).id}/personalized`)).json
    assert.deepEqual(shelves.find((s) => s.id === 'continue-reading').entities.map((e) => e.id), [only.id])
    const stamp = async () => (await items()).map((i) => [i.id, i.updatedAt]).sort()
    const a = await stamp()
    await rescan()
    assert.deepEqual(await stamp(), a, 'an unchanged library is not written again')
    for (const p of ['Ann/Both', 'Eve', 'Root.epub']) await dav('DELETE', `${LIB}/${p}`)
    await rescan()
  })
  await check('CrossPoint Sync calls never rescan a stale library; browsing in an app does', async () => {
    await reset()
    await dav('MKCOL', `${LIB}/Ann/Two`)
    await dav('PUT', `${LIB}/Ann/Two/two.mp3`, { body: fixture('chapters.mp3') })
    occ('user:setting', user, 'abscompat', 'last_scan', '--delete')
    assert.equal((await settings('GET', '')).lastScan, 0, 'setup: scan aged')
    const id = (await lib()).id
    for (const p of ['/api/me', '/api/libraries', `/api/libraries/${id}/search?q=edited`, '/api/me/items-in-progress', `/api/items/${book.id}?expanded=1`, `/api/me/progress/${book.id}`]) {
      assert.equal((await api('GET', p)).status, 200, p)
    }
    await api('PATCH', `/api/me/progress/${book.id}`, { progress: 0.5 })
    assert.equal((await settings('GET', '')).lastScan, 0, 'a CrossPoint call rescanned')
    assert.equal((await items()).length, 2, 'browsing should pick up the new book')
    assert.ok((await settings('GET', '')).lastScan > 0)
  })
} finally {
  await dav('MOVE', LIB + 'X', { to: LIB, ok: [201, 204, 404, 412] })
  await cleanup()
}
console.log(`\n${passes} passed, ${fails} failed`)
process.exit(fails ? 1 : 0)
