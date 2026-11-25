// The link to OPDS+: ebooks for audiobooks, the ebook library, and that OPDS+ is left exactly as it was.
// Needs OPDS+ installed in the container (dev/test-matrix.sh does it when OPDS_DIR is set).
//   node dev/opds-e2e.mjs
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'
import { makeEpub } from './epub.mjs'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const CONTAINER = process.env.NC_CONTAINER || 'abs-nc34', D = process.env.DOCKER || 'podman'
const user = env.NC_PLAIN_USER, base = env.NC_URL + '/apps/abscompat'
const auth = { authorization: 'Basic ' + Buffer.from(`${user}:${env.NC_PLAIN_PASSWORD}`).toString('base64') }
const fixture = (n) => readFileSync(new URL(`../abscompat/tests/fixtures/${n}`, import.meta.url))
const exec = (...a) => execFileSync(D, ['exec', '-u', 'www-data', CONTAINER, ...a], { encoding: 'utf8', maxBuffer: 1 << 26 })
const occ = (...a) => exec('php', 'occ', ...a)
const LIB = '/OpLib', BOOKS = '/OpBooks'
async function dav(method, path, body, ok = [201, 204]) {
  const r = await fetch(`${env.NC_URL}/remote.php/dav/files/${user}` + path.split('/').map(encodeURIComponent).join('/'), { method, headers: auth, body })
  assert.ok(ok.includes(r.status), `${method} ${path}: ${r.status}`)
}
async function web(method, path, body) {
  const r = await fetch(base + path, { method, headers: { ...auth, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, json: await r.json().catch(() => null), type: r.headers.get('content-type') }
}
let token
const raw = (method, path, body) => fetch(base + path, { method, headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
const api = async (method, path, body) => { const r = await raw(method, path, body); return { status: r.status, json: await r.json().catch(() => null) } }
const rescan = () => web('POST', '/settings/rescan')
const items = async (lib) => (await api('GET', `/api/libraries/${lib}/items?limit=0`)).json.results

/** Everything OPDS+ has: its tables, settings, jobs, and stored files with their times. */
const snapshot = () => exec('php', '-r', `define("OC_CONSOLE", 1); require "/var/www/html/lib/base.php"; $db = \\OCP\\Server::get(\\OCP\\IDBConnection::class); $o = [];
  foreach (["SELECT * FROM *PREFIX*opds_books ORDER BY id", "SELECT * FROM *PREFIX*opds_terms ORDER BY id", "SELECT * FROM *PREFIX*preferences WHERE appid = 'opds' ORDER BY userid, configkey", "SELECT * FROM *PREFIX*appconfig WHERE appid = 'opds' ORDER BY configkey", "SELECT id, class, argument, last_run FROM *PREFIX*jobs WHERE class LIKE '%Opds%' ORDER BY id"] as $q) { $o[] = $db->executeQuery($q)->fetchAll(); }
  echo json_encode($o, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);`) + exec('sh', '-c', 'find /var/www/html/data/appdata_*/opds -printf "%p %s %T@\\n" 2>/dev/null | sort')

let fails = 0, passes = 0
async function check(name, fn) {
  try { await fn(); passes++; console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 8).join(' ')) }
}
async function cleanup() {
  for (const p of [LIB, BOOKS]) await dav('DELETE', p, undefined, [204, 404])
  await web('DELETE', '/settings/folders')
  await web('PUT', '/settings/opds', { links: true, library: false })
  for (const t of (await web('GET', '/settings')).json.tokens) await web('DELETE', `/settings/tokens/${t.id}`)
}

await cleanup()
try {
  for (const p of [LIB, `${LIB}/Ann Lee`, `${LIB}/Ann Lee/Match`, `${LIB}/Ann Lee/Own`, `${LIB}/Ann Lee/Lone`, BOOKS]) await dav('MKCOL', p)
  for (const p of ['Match/m.mp3', 'Own/o.mp3', 'Lone/l.mp3']) await dav('PUT', `${LIB}/Ann Lee/${p}`, fixture('series-grouping.mp3'))
  const match = makeEpub({ title: 'Match', author: 'Ann Lee', cover: true })
  await dav('PUT', `${BOOKS}/match.epub`, match)
  await dav('PUT', `${BOOKS}/match.pdf`, '%PDF-1.4')
  await dav('PUT', `${BOOKS}/own.epub`, makeEpub({ title: 'Own', author: 'Ann Lee' }))
  await dav('PUT', `${BOOKS}/other.epub`, makeEpub({ title: 'Lone', author: 'Somebody Else' }))
  await dav('PUT', `${BOOKS}/free.epub`, makeEpub({ title: 'Free Book', author: 'Zoe Q', series: 'Row', seq: '3', cover: true }))
  await dav('PUT', `${BOOKS}/note.txt`, 'a text file')
  const own = makeEpub({ title: 'x' })
  await dav('PUT', `${LIB}/Ann Lee/Own/inside.epub`, own)
  occ('user:setting', user, 'opds', 'root', BOOKS)
  occ('opds:scan', user)
  await web('PUT', '/settings/folders', { folders: [LIB] })
  token = (await web('POST', '/settings/apikeys', { name: 'opds-e2e' })).json.token
  const before = snapshot()
  assert.match(before, /Free Book/, 'setup: OPDS+ has the books')
  await rescan()
  const lib = (await api('GET', '/api/libraries')).json.libraries
  const title = {}
  const full = async (t) => (await api('GET', `/api/items/${title[t]}?expanded=1`)).json
  await check('an audiobook without an ebook gets the one with its title and author', async () => {
    assert.equal(lib.length, 1, 'no ebook library unless asked for')
    // All three have the same tags, so give them their titles.
    for (const i of await items(lib[0].id)) {
      const name = i.relPath.split('/').pop()
      title[name] = i.id
      await api('PATCH', `/api/items/${i.id}/media`, { metadata: { title: name, authors: [{ name: 'Ann Lee' }], series: [] } })
    }
    await rescan()
    const m = await full('Match')
    assert.deepEqual(m.libraryFiles.filter((f) => f.fileType === 'ebook').map((f) => [f.metadata.filename, f.isSupplementary]), [['match.epub', false], ['match.pdf', true]])
    assert.equal(m.media.ebookFile.ebookFormat, 'epub')
    assert.ok(Buffer.from(await (await raw('GET', `/api/items/${title.Match}/ebook`)).arrayBuffer()).equals(match))
  })
  await check('its own ebook stays; another author\'s book does not match', async () => {
    assert.ok(Buffer.from(await (await raw('GET', `/api/items/${title.Own}/ebook`)).arrayBuffer()).equals(own))
    assert.equal((await full('Lone')).media.ebookFile, null)
  })
  await check('choose another ebook, none, or the automatic one again', async () => {
    const found = (await web('GET', '/web/opds?q=lone%20somebody')).json.books
    assert.deepEqual(found.map((b) => [b.title, b.author, b.formats]), [['Lone', 'Somebody Else', ['EPUB']]])
    const set = (fileId) => web('PUT', `/web/items/${title.Lone}/ebook`, { fileId, opds: true })
    assert.deepEqual((await set(found[0].fileId)).json.ebooks.map((e) => e.name), ['other.epub'])
    await rescan()
    assert.equal((await full('Lone')).media.ebookFile.ino, String(found[0].fileId))
    assert.deepEqual((await set(0)).json.ebooks, [])
    await rescan()
    assert.equal((await full('Lone')).media.ebookFile, null)
    assert.deepEqual((await set(null)).json.ebooks, [])
    assert.equal((await set(123456789)).status, 404)
  })
  await check('the ebook library: the other books, with details and covers; progress works', async () => {
    const s = await web('PUT', '/settings/opds', { links: true, library: true })
    assert.deepEqual(s.json.opds, { available: true, links: true, library: true })
    await rescan()
    const libs = (await api('GET', '/api/libraries')).json.libraries
    assert.deepEqual(libs.map((l) => [l.id === 'opds', l.settings.coverAspectRatio]), [[false, 1], [true, 0]])
    const list = await items('opds')
    assert.deepEqual(list.map((i) => i.media.metadata.title).sort(), ['Free Book', 'Lone', 'Own'])
    const free = list.find((i) => i.media.metadata.title === 'Free Book')
    assert.deepEqual([free.media.metadata.authorName, free.media.metadata.seriesName, free.media.ebookFormat, free.media.numTracks], ['Zoe Q', 'Row #3', 'epub', 0])
    const cover = await raw('GET', `/api/items/${free.id}/cover`)
    assert.deepEqual([cover.status, cover.headers.get('content-type')], [200, 'image/png'])
    assert.equal((await raw('GET', `/api/items/${free.id}/ebook`)).status, 200)
    await api('PATCH', `/api/me/progress/${free.id}`, { ebookProgress: 0.5, ebookLocation: 'epubcfi(/6/4)' })
    assert.equal((await api('GET', `/api/me/progress/${free.id}`)).json.ebookProgress, 0.5)
    await api('PATCH', `/api/items/${free.id}/media`, { metadata: { title: 'My Name' }, tags: ['t'] })
    await rescan()
    const again = (await items('opds')).find((i) => i.id === free.id)
    assert.deepEqual([again.media.metadata.title, again.media.tags], ['My Name', ['t']])
    const stamp = async () => (await items('opds')).map((i) => [i.id, i.updatedAt]).sort()
    const a = await stamp()
    await rescan()
    assert.deepEqual(await stamp(), a, 'not written again')
    assert.equal((await web('GET', '/web/library')).json.books.filter((b) => b.libraryId === 'opds').length, 3)
  })
  await check('another user sees nothing of it', async () => {
    const admin = { authorization: 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64'), 'OCS-APIRequest': 'true' }
    const r = await (await fetch(base + '/web/opds?q=free', { headers: admin })).json()
    assert.deepEqual(r.books, [])
  })
  await check('switched off again: the library and the links are gone', async () => {
    await web('PUT', '/settings/opds', { links: false, library: false })
    await rescan()
    assert.equal((await api('GET', '/api/libraries')).json.libraries.length, 1)
    assert.equal((await full('Match')).media.ebookFile, null)
    await web('PUT', '/settings/opds', { links: true, library: false })
    await rescan()
    assert.ok((await full('Match')).media.ebookFile)
  })
  await check('OPDS+ has not changed in any way', async () => assert.equal(snapshot(), before))
  await check('OPDS+ switched off: links disappear with the next scan, nothing breaks', async () => {
    occ('app:disable', 'opds')
    try {
      for (let i = 0; i < 20 && (await web('GET', '/settings')).json.opds.available; i++) await new Promise((r) => setTimeout(r, 500))
      assert.equal((await rescan()).status, 200)
      assert.equal((await full('Match')).media.ebookFile, null)
      assert.equal((await full('Own')).media.ebookFile.ebookFormat, 'epub')
    } finally {
      occ('app:enable', 'opds')
    }
  })
} finally {
  await cleanup()
}
console.log(`\n${passes} passed, ${fails} failed`)
process.exit(fails ? 1 : 0)
