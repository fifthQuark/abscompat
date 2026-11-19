// Runs CrossPoint Sync's real Audiobookshelf connector against the local abscompat server.
//   CROSSPOINT_DIR=/path/to/crosspoint-sync npx tsx dev/crosspoint-e2e.mts
// (run with the tsx from the crosspoint-sync checkout, after `npm ci` there)
import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'
import { pathToFileURL } from 'node:url'

const dir = process.env.CROSSPOINT_DIR
if (!dir) throw new Error('set CROSSPOINT_DIR')
const { audiobookshelfConnector: c, absTracks } = await import(pathToFileURL(`${dir}/src/connectors/audiobookshelf.ts`).href)

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const basic = 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64')
const http = (url: string, init: RequestInit) => fetch(url, init)

// API key from the settings endpoint (as the user would on the settings page).
const keyRes = await fetch(`${env.NC_URL}/apps/abscompat/settings/apikeys`, {
  method: 'POST', headers: { authorization: basic, 'content-type': 'application/json', 'OCS-APIRequest': 'true' }, body: JSON.stringify({ name: 'CrossPoint Sync (e2e)' }),
})
assert.equal(keyRes.status, 200, await keyRes.clone().text())
const token = (await keyRes.json()).token as string

let fails = 0
async function check(name: string, fn: () => Promise<void>) {
  try { await fn(); console.log('  ok  ', name) } catch (e: any) { fails++; console.log('  FAIL', name, '\n       ', e.message) }
}

for (const server of [`${env.NC_URL}/apps/abscompat/`, env.NC_URL.replace(/^http:\/\//, 'http://') + '/index.php/apps/abscompat']) {
  console.log(`\n== CrossPoint connector -> ${server}`)
  const cred = { server, token }
  let match: any

  await check('validate()', async () => {
    const r = await c.validate(cred, http)
    assert.equal(r.ok, true, r.error)
    assert.match(r.accountLabel, new RegExp(env.NC_ADMIN_USER))
  })
  await check('validate() with a bad key says invalid API key', async () => {
    const r = await c.validate({ server, token: 'abs_wrong' }, http)
    assert.equal(r.ok, false); assert.equal(r.error, 'invalid API key')
  })
  await check('match() an EPUB to the audiobook by title + author', async () => {
    match = await c.match(cred, { document: 'doc1', title: 'The First Tale', author: 'Ada Author', filename: 'The First Tale.epub' }, http)
    assert.ok(match, 'no match')
    assert.ok(Math.abs(Number(match.externalEdition) - 120) < 1.5, 'duration cached: ' + match.externalEdition)
  })
  await check('search() for the manual picker', async () => {
    const r = await c.search(cred, 'Rising', http)
    assert.equal(r.length, 1); assert.equal(r[0].author, 'Bob Writer')
  })
  await check('absTracks(): chapters for calibration', async () => {
    const r = await absTracks(cred, match.externalId, http)
    assert.deepEqual(r.tracks.map((t: any) => t.name), ['Opening', 'The Middle', 'The End'])
    assert.ok(Math.abs(r.total_ms - 120000) < 1500)
  })
  await check('push() reading 40% -> audiobook currentTime 48s', async () => {
    const r = await c.push(cred, match, { kind: 'progress', document: 'doc1', percentage: 0.4, timestamp: Math.floor(Date.now() / 1000) }, http)
    assert.equal(r.ok, true, r.error)
    const p = await (await fetch(`${server.replace(/\/$/, '')}/api/me/progress/${match.externalId}`, { headers: { authorization: `Bearer ${token}` } })).json()
    assert.ok(Math.abs(p.currentTime - 48) < 1, JSON.stringify(p))
    assert.equal(p.ebookProgress, 0.4)
    assert.equal(p.isFinished, false)
  })
  await check('listCurrentlyReading() shows the book', async () => {
    const r = await c.listCurrentlyReading(cred, http)
    assert.ok(r.some((b: any) => b.externalId === match.externalId))
  })
  await check('pullProgress() after the phone listened to 90s', async () => {
    const before = Date.now() - 1
    // The phone syncs an offline session (what the ABS app sends on reconnect).
    const res = await fetch(`${server.replace(/\/$/, '')}/api/session/local-all`, {
      method: 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
      body: JSON.stringify({ sessions: [{ id: `play_local_${Date.now()}`, libraryItemId: match.externalId, currentTime: 90, timeListening: 42, updatedAt: Date.now(), duration: 120 }], deviceInfo: {} }),
    })
    assert.equal((await res.json()).results[0].progressSynced, true)
    const ch = await c.pullProgress(cred, match, http, before)
    assert.ok(ch, 'no inbound change')
    assert.ok(Math.abs(ch.percentage - 0.75) < 0.01, 'pct ' + ch.percentage)
    assert.equal(ch.finished, false)
    assert.equal(await c.pullProgress(cred, match, http, Date.now() + 1000), null)
  })
  await check('push() finished', async () => {
    const r = await c.push(cred, match, { kind: 'finished', document: 'doc1', percentage: 1, timestamp: Math.floor(Date.now() / 1000) }, http)
    assert.equal(r.ok, true)
    const ch = await c.pullProgress(cred, match, http, 0)
    assert.equal(ch.finished, true)
  })
  await check('push() with a progress string falls back cleanly when the item has no EPUB', async () => {
    await fetch(`${server.replace(/\/$/, '')}/api/me/progress/${match.externalId}`, { method: 'PATCH', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: JSON.stringify({ isFinished: false }) })
    const r = await c.push(cred, match, { kind: 'progress', document: 'doc1', percentage: 0.1, progress: '/body/DocFragment[2]/body/p[1]', timestamp: 0 }, http)
    assert.equal(r.ok, true, r.error)
  })
  await check('push() with a KOReader xpath resolves an ebookLocation CFI from the item\'s EPUB', async () => {
    const m2 = await c.match(cred, { document: 'doc2', title: 'Rising', author: 'Bob Writer', filename: null }, http)
    assert.ok(m2)
    const r = await c.push(cred, m2, { kind: 'progress', document: 'doc2', percentage: 0.2, progress: '/body/DocFragment[1]/body/p[1]', timestamp: 0 }, http)
    assert.equal(r.ok, true, r.error)
    const p = await (await fetch(`${server.replace(/\/$/, '')}/api/me/progress/${m2.externalId}`, { headers: { authorization: `Bearer ${token}` } })).json()
    assert.equal(p.ebookProgress, 0.2)
    assert.ok(typeof p.ebookLocation === 'string' && p.ebookLocation.startsWith('epubcfi('), 'ebookLocation ' + p.ebookLocation)
  })
}

// Remove the key this run created.
const st = await (await fetch(`${env.NC_URL}/apps/abscompat/settings`, { headers: { authorization: basic, 'OCS-APIRequest': 'true' } })).json()
for (const tok of st.tokens.filter((x: any) => x.name === 'CrossPoint Sync (e2e)')) {
  await fetch(`${env.NC_URL}/apps/abscompat/settings/tokens/${tok.id}`, { method: 'DELETE', headers: { authorization: basic, 'OCS-APIRequest': 'true' } })
}
console.log(fails ? `\n${fails} failed` : '\nall passed')
process.exit(fails ? 1 : 0)
