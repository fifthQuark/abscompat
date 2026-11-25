// Scanner regressions: edits survive books going missing, and CrossPoint Sync never rescans.
// Uses plainuser and its own folders (uploaded over WebDAV), and occ to age the last scan.
//   node dev/scan-e2e.mjs      (NC_CONTAINER=abs-nc34, DOCKER=podman by default)
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'

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
  try { await fn(); passes++; console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 4).join(' ')) }
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
