// End-to-end check of the abscompat API against a running Nextcloud.
// Replays the calls the Audiobookshelf Android app and CrossPoint Sync make.
//   node dev/e2e.mjs            (reads dev/test-instance.env)
import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const PREFIXES = (process.env.PREFIXES || '/apps/abscompat,/index.php/apps/abscompat').split(',')
let failures = 0
let passes = 0

async function check(name, fn) {
  try {
    await fn()
    passes++
    console.log('  ok  ', name)
  } catch (e) {
    failures++
    console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 6).join('\n        '))
  }
}

function client(base, token) {
  const req = async (method, path, { body, headers = {}, raw = false } = {}) => {
    const h = { ...headers }
    if (token.value) h.authorization = `Bearer ${token.value}`
    if (body !== undefined) h['content-type'] = 'application/json; charset=utf-8'
    const res = await fetch(base + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined, redirect: 'manual' })
    if (raw) return res
    const text = await res.text()
    let json = null
    try { json = JSON.parse(text) } catch {}
    return { status: res.status, json, text, headers: res.headers }
  }
  return req
}

/** Non-null fields the app's Kotlin LibraryItem/Book/BookMetadata need. */
function assertKotlinLibraryItem(li, expanded) {
  for (const k of ['id', 'ino', 'libraryId', 'folderId', 'path', 'relPath', 'mtimeMs', 'ctimeMs', 'birthtimeMs', 'addedAt', 'updatedAt', 'isMissing', 'isInvalid', 'mediaType', 'media']) {
    assert.ok(li[k] !== undefined && li[k] !== null, `libraryItem.${k} missing`)
  }
  assert.equal(typeof li.id, 'string')
  assert.ok(!li.id.startsWith('li_'), 'id must not look like an old ABS id')
  const m = li.media
  assert.ok(Array.isArray(m.tags), 'media.tags')
  for (const k of ['episodes', 'autoDownloadEpisodes']) assert.ok(!(k in m), `book media must not have podcast key ${k}`)
  const md = m.metadata
  assert.equal(typeof md.title, 'string')
  assert.ok(Array.isArray(md.genres), 'metadata.genres')
  assert.equal(typeof md.explicit, 'boolean')
  for (const k of ['feedUrl', 'author']) assert.ok(!(k in md), `book metadata must not have podcast key ${k}`)
  if (expanded) {
    assert.ok(Array.isArray(md.authors) && Array.isArray(md.narrators), 'expanded authors/narrators')
    for (const af of m.audioFiles) {
      assert.equal(typeof af.index, 'number'); assert.equal(typeof af.ino, 'string')
      for (const k of ['filename', 'ext', 'path', 'relPath']) assert.equal(typeof af.metadata[k], 'string', `audioFile.metadata.${k}`)
    }
    for (const t of m.tracks) {
      for (const k of ['index', 'startOffset', 'duration']) assert.equal(typeof t[k], 'number', `track.${k}`)
      for (const k of ['title', 'contentUrl']) assert.equal(typeof t[k], 'string', `track.${k}`)
      assert.equal(t.isLocal, false)
    }
    for (const c of m.chapters) {
      for (const k of ['id', 'start', 'end']) assert.equal(typeof c[k], 'number', `chapter.${k}`)
    }
  }
}

const adminBasic = 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64')

async function run(prefix) {
  const base = env.NC_URL + prefix
  const webSettings = async (method, path, body) => {
    const r = await fetch(base + path, { method, headers: { authorization: adminBasic, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
    assert.equal(r.status, 200, `${method} ${path}: ${r.status}`)
    return r.json()
  }
  const streaming = async (enabled) => assert.equal((await webSettings('PUT', '/settings/streaming', { enabled })).streaming.on, enabled)
  console.log(`\n== ${base}`)
  const token = { value: null }
  const api = client(base, token)
  let refreshToken, libId, items, m4b, mp3book, rootmp3, book2

  await check('GET /status looks like ABS', async () => {
    const r = await api('GET', '/status')
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.isInit, true)
    assert.ok('language' in r.json)
    assert.deepEqual(r.json.authMethods, ['local'])
    assert.ok(r.json.serverVersion >= '2.26.0')
  })
  await check('GET /ping', async () => {
    const r = await api('GET', '/ping')
    assert.deepEqual(r.json, { success: true })
  })
  await check('POST /login with wrong password is 401', async () => {
    const r = await api('POST', '/login', { body: { username: env.NC_ADMIN_USER, password: 'wrong' } })
    assert.equal(r.status, 401)
  })
  await check('POST /login returns access + refresh tokens', async () => {
    const r = await api('POST', '/login', { body: { username: env.NC_ADMIN_USER, password: env.NC_ADMIN_PASSWORD }, headers: { 'x-return-tokens': 'true', 'user-agent': 'okhttp/4.12.0' } })
    assert.equal(r.status, 200, r.text)
    assert.ok(r.json.user.accessToken && r.json.user.refreshToken)
    assert.equal(r.json.user.username, env.NC_ADMIN_USER)
    assert.equal(r.json.user.permissions.download, true)
    assert.ok(r.json.serverSettings.version)
    assert.ok(r.json.userDefaultLibraryId)
    token.value = r.json.user.accessToken
    refreshToken = r.json.user.refreshToken
  })
  await check('POST /login accepts a Nextcloud app password', async () => {
    const r = await client(base, { value: null })('POST', '/login', { body: { username: env.NC_ADMIN_USER, password: env.NC_APP_PASSWORD } })
    assert.equal(r.status, 200, r.text)
    assert.ok(r.json.user.accessToken)
    await client(base, { value: r.json.user.accessToken })('POST', '/logout')
  })
  await check('POST /login refuses the account password when 2FA is on', async () => {
    const r = await client(base, { value: null })('POST', '/login', { body: { username: env.NC_TFA_USER, password: env.NC_TFA_PASSWORD } })
    assert.equal(r.status, 401)
  })
  await check('cookie-less request without token is 401', async () => {
    const r = await client(base, { value: null })('GET', '/api/me')
    assert.equal(r.status, 401)
  })
  await check('unknown Bearer token is 401, not a Nextcloud error', async () => {
    const r = await client(base, { value: 'abs_doesnotexist' })('GET', '/api/me')
    assert.equal(r.status, 401)
  })
  await check('POST /api/authorize', async () => {
    const r = await api('POST', '/api/authorize')
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.user.id, env.NC_ADMIN_USER)
  })
  await check('POST /auth/refresh (x-refresh-token) issues a new access token', async () => {
    const r = await client(base, { value: null })('POST', '/auth/refresh', { headers: { 'x-refresh-token': refreshToken } })
    assert.equal(r.status, 200, r.text)
    assert.ok(r.json.user.accessToken && r.json.user.accessToken !== token.value)
    assert.equal(r.json.user.refreshToken, refreshToken)
    token.value = r.json.user.accessToken
  })
  await check('GET /api/libraries (book libraries)', async () => {
    const r = await api('GET', '/api/libraries?include=stats')
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.libraries.length, 1)
    const lib = r.json.libraries[0]
    assert.equal(lib.mediaType, 'book')
    for (const k of ['id', 'name', 'folders', 'icon', 'mediaType']) assert.ok(lib[k] !== undefined)
    assert.equal(lib.stats.totalItems, 4)
    libId = lib.id
  })
  await check('GET /api/libraries/:id?include=filterdata', async () => {
    const r = await api('GET', `/api/libraries/${libId}?include=filterdata`)
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.library.id, libId)
    assert.equal(r.json.filterdata.bookCount, 4)
    assert.ok(r.json.filterdata.series.some((s) => s.name === 'Star Saga'))
  })
  await check('GET /api/libraries/:id/items (minified)', async () => {
    const r = await api('GET', `/api/libraries/${libId}/items?limit=100&minified=1&sort=media.metadata.title`)
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.total, 4)
    items = r.json.results
    for (const li of items) assertKotlinLibraryItem(li, false)
    m4b = items.find((i) => i.media.metadata.title === 'The First Tale')
    mp3book = items.find((i) => i.media.metadata.title === 'Rising')
    rootmp3 = items.find((i) => i.media.metadata.title === 'Short Story')
    book2 = items.find((i) => i.media.metadata.title === 'Falling')
    assert.ok(m4b && mp3book && rootmp3, 'titles: ' + items.map((i) => i.media.metadata.title).join(', '))
    assert.equal(m4b.media.metadata.authorName, 'Ada Author')
    assert.equal(m4b.media.metadata.narratorName, 'Nora Narrator')
    assert.equal(mp3book.media.metadata.seriesName, 'Star Saga #1')
    assert.equal(rootmp3.isFile, true)
  })
  await check('filter by series (base64 id) returns the series book', async () => {
    const r1 = await api('GET', `/api/libraries/${libId}?include=filterdata`)
    const ser = r1.json.filterdata.series.find((s) => s.name === 'Star Saga')
    const r = await api('GET', `/api/libraries/${libId}/items?minified=1&filter=series.${encodeURIComponent(Buffer.from(ser.id).toString('base64'))}&limit=1000`)
    assert.equal(r.json.total, 2)
    assert.deepEqual(r.json.results.map((i) => i.media.metadata.title), ['Rising', 'Falling'])
  })
  await check('GET /api/items/:id?expanded=1 for m4b (embedded chapters)', async () => {
    const r = await api('GET', `/api/items/${m4b.id}?expanded=1&include=rssfeed`)
    assert.equal(r.status, 200, r.text)
    assertKotlinLibraryItem(r.json, true)
    const m = r.json.media
    assert.ok(Math.abs(m.duration - 120) < 1.5, 'duration ' + m.duration)
    assert.deepEqual(m.chapters.map((c) => c.title), ['Opening', 'The Middle', 'The End'])
    assert.ok(Math.abs(m.chapters[1].start - 40) < 0.5)
    assert.ok(m.coverPath, 'embedded cover detected')
    assert.equal(m.tracks.length, 1)
  })
  await check('expanded mp3 book: 2 tracks with offsets, one chapter per file, ebook', async () => {
    const r = await api('GET', `/api/items/${mp3book.id}?expanded=1`)
    const m = r.json.media
    assert.equal(m.tracks.length, 2)
    assert.equal(m.tracks[0].startOffset, 0)
    assert.ok(Math.abs(m.tracks[1].startOffset - 60) < 1.5, 'offset ' + m.tracks[1].startOffset)
    assert.deepEqual(m.chapters.map((c) => c.title), ['Part 1', 'Part 2'])
    assert.equal(m.ebookFile.ebookFormat, 'epub')
    assert.ok(m.audioFiles.reduce((a, f) => a + f.duration, 0) > 118)
  })
  await check('root mp3: ID3v2 CHAP chapters', async () => {
    const r = await api('GET', `/api/items/${rootmp3.id}?expanded=1`)
    assert.deepEqual(r.json.media.chapters.map((c) => c.title), ['Part A', 'Part B'])
    assert.ok(Math.abs(r.json.media.chapters[1].start - 30) < 0.5)
  })
  await check('cover: folder cover.jpg (Bearer) and embedded art (?token=)', async () => {
    const r1 = await api('GET', `/api/items/${mp3book.id}/cover`, { raw: true })
    assert.equal(r1.status, 200)
    assert.match(r1.headers.get('content-type'), /image\/jpeg/)
    const r2 = await fetch(`${base}/api/items/${m4b.id}/cover?token=${token.value}`)
    assert.equal(r2.status, 200)
    assert.match(r2.headers.get('content-type'), /image\//)
    assert.ok((await r2.arrayBuffer()).byteLength > 100)
  })
  await check('download: whole file, Range resume, suffix range, 416', async () => {
    const ino = (await api('GET', `/api/items/${m4b.id}?expanded=1`)).json.media.audioFiles[0].ino
    const url = `/api/items/${m4b.id}/file/${ino}/download`
    const full = await api('GET', url, { raw: true, headers: { 'accept-encoding': 'identity' } })
    assert.equal(full.status, 200)
    const body = Buffer.from(await full.arrayBuffer())
    assert.equal(String(body.length), full.headers.get('content-length'))
    assert.equal(full.headers.get('accept-ranges'), 'bytes')
    assert.match(full.headers.get('content-disposition'), /attachment/)
    const part = await api('GET', url, { raw: true, headers: { range: 'bytes=1000-', 'accept-encoding': 'identity' } })
    assert.equal(part.status, 206)
    assert.equal(part.headers.get('content-range'), `bytes 1000-${body.length - 1}/${body.length}`)
    assert.ok(Buffer.from(await part.arrayBuffer()).equals(body.subarray(1000)))
    const mid = await api('GET', url, { raw: true, headers: { range: 'bytes=10-19' } })
    assert.ok(Buffer.from(await mid.arrayBuffer()).equals(body.subarray(10, 20)))
    const suf = await api('GET', url, { raw: true, headers: { range: 'bytes=-5' } })
    assert.ok(Buffer.from(await suf.arrayBuffer()).equals(body.subarray(body.length - 5)))
    const bad = await api('GET', url, { raw: true, headers: { range: `bytes=${body.length + 10}-` } })
    assert.equal(bad.status, 416)
    // A player's link (?token=) is streaming: refused until that is switched on.
    assert.equal((await fetch(`${base}/api/items/${m4b.id}/file/${ino}?token=${token.value}`)).status, 403)
    await streaming(true)
    const dp = await fetch(`${base}/api/items/${m4b.id}/file/${ino}?token=${token.value}`, { headers: { range: 'bytes=0-3' } })
    assert.equal(dp.status, 206)
    await streaming(false)
  })
  await check('download of a file outside the item is refused', async () => {
    const otherIno = (await api('GET', `/api/items/${mp3book.id}?expanded=1`)).json.media.audioFiles[0].ino
    const r = await api('GET', `/api/items/${m4b.id}/file/${otherIno}/download`)
    assert.equal(r.status, 404)
  })
  await check('ebook endpoint: EPUB for the mp3 book, 404 for m4b', async () => {
    const r = await api('GET', `/api/items/${mp3book.id}/ebook`, { raw: true })
    assert.equal(r.status, 200)
    const buf = Buffer.from(await r.arrayBuffer())
    assert.equal(buf.subarray(0, 2).toString(), 'PK')
    assert.equal((await api('GET', `/api/items/${m4b.id}/ebook`)).status, 404)
  })

  // ---- progress (fresh state per prefix) ----
  for (const it of [m4b, mp3book, rootmp3]) await api('DELETE', `/api/me/progress/${it.id}`)

  await check('GET /api/me/progress/:id is 404 before any progress', async () => {
    assert.equal((await api('GET', `/api/me/progress/${m4b.id}`)).status, 404)
  })
  await check('CrossPoint push: PATCH progress+currentTime+duration+ebookProgress', async () => {
    const r = await api('PATCH', `/api/me/progress/${m4b.id}`, { body: { progress: 0.25, duration: 120, currentTime: 30, ebookProgress: 0.25 } })
    assert.equal(r.status, 200, r.text)
    const p = (await api('GET', `/api/me/progress/${m4b.id}`)).json
    assert.equal(p.currentTime, 30); assert.equal(p.progress, 0.25); assert.equal(p.ebookProgress, 0.25)
    assert.equal(p.isFinished, false)
    assert.ok(p.lastUpdate > Date.now() - 60000)
  })
  await check('progress-only PATCH (no duration) also moves currentTime', async () => {
    await api('PATCH', `/api/me/progress/${m4b.id}`, { body: { progress: 0.5, ebookProgress: 0.5 } })
    const p = (await api('GET', `/api/me/progress/${m4b.id}`)).json
    assert.ok(Math.abs(p.currentTime - p.duration * 0.5) < 0.01, JSON.stringify(p))
  })
  await check('PATCH with isFinished:false ignores progress (like real ABS)', async () => {
    await api('PATCH', `/api/me/progress/${m4b.id}`, { body: { progress: 0.9, isFinished: false } })
    const p = (await api('GET', `/api/me/progress/${m4b.id}`)).json
    assert.equal(p.progress, 0.5)
  })
  await check('items-in-progress and continue-listening shelf', async () => {
    const r = await api('GET', '/api/me/items-in-progress')
    assert.deepEqual(r.json.libraryItems.map((i) => i.id), [m4b.id])
    assert.equal(typeof r.json.libraryItems[0].media.duration, 'number')
    const sh = await api('GET', `/api/libraries/${libId}/personalized?minified=1&include=rssfeed,numEpisodesIncomplete`)
    assert.equal(sh.status, 200, sh.text)
    const cl = sh.json.find((s) => s.id === 'continue-listening')
    assert.ok(cl && cl.type === 'book' && cl.entities[0].id === m4b.id)
    for (const s of sh.json) for (const k of ['id', 'label', 'total', 'type']) assert.ok(s[k] !== undefined)
  })
  await check('CrossPoint search by title', async () => {
    const r = await api('GET', `/api/libraries/${libId}/search?q=${encodeURIComponent('first tale')}`)
    assert.equal(r.json.book.length, 1)
    assert.equal(r.json.book[0].libraryItem.id, m4b.id)
    assert.equal(r.json.book[0].libraryItem.media.metadata.authorName, 'Ada Author')
  })
  await check('streaming is off until the user turns it on; Voice may still report sessions; downloads work', async () => {
    const play = (mediaPlayer) => api('POST', `/api/items/${mp3book.id}/play`, { body: { deviceInfo: { deviceId: 'dev0' }, forceDirectPlay: true, mediaPlayer } })
    assert.equal((await play('exo-player')).status, 403)
    const voice = await play('Voice')
    assert.equal(voice.status, 200)
    assert.equal((await fetch(`${base}/public/session/${voice.json.id}/track/1`)).status, 403)
    assert.equal((await api('POST', `/api/session/${voice.json.id}/close`, { body: {} })).status, 200)
    const ino = (await api('GET', `/api/items/${mp3book.id}?expanded=1`)).json.media.audioFiles[0].ino
    assert.equal((await api('GET', `/api/items/${mp3book.id}/file/${ino}/download`, { raw: true })).status, 200)
    assert.equal((await api('GET', `/api/items/${mp3book.id}/file/${ino}`, { raw: true })).status, 200)
  })
  await check('streaming: the apps\' track address works while the session is open, and only then', async () => {
    await streaming(true)
    const s = (await api('POST', `/api/items/${mp3book.id}/play`, { body: { deviceInfo: { deviceId: 'dev1' }, mediaPlayer: 'exo-player' } })).json
    const track = (i, headers, id = s.id) => fetch(`${base}/public/session/${id}/track/${i}`, { headers })
    const full = await track(2)
    assert.equal(full.status, 200)
    assert.equal(full.headers.get('content-type'), 'audio/mpeg')
    const all = Buffer.from(await full.arrayBuffer())
    const part = await track(2, { range: 'bytes=100-199' })
    assert.equal(part.status, 206)
    assert.ok(Buffer.from(await part.arrayBuffer()).equals(all.subarray(100, 200)))
    for (const i of ['0', '3', 'x', '-1']) assert.equal((await track(i)).status, 404, i)
    for (const id of ['nope', '00000000-0000-4000-a000-000000000000', s.id.toUpperCase() + 'x']) assert.equal((await track(1, {}, id)).status, 404, id)
    assert.equal((await api('GET', `/api/session/${s.id}`)).json.libraryItemId, mp3book.id)
    await streaming(false)
    assert.equal((await track(1)).status, 403, 'switching it off ends it')
    await streaming(true)
    assert.equal((await track(1)).status, 200)
    await api('POST', `/api/session/${s.id}/close`, { body: {} })
    assert.equal((await track(1)).status, 404, 'closed')
    // Signing out a device ends open streams.
    const s2 = (await api('POST', `/api/items/${mp3book.id}/play`, { body: { mediaPlayer: 'exo-player' } })).json
    const other = await client(base, { value: null })('POST', '/login', { body: { username: env.NC_ADMIN_USER, password: env.NC_ADMIN_PASSWORD } })
    assert.equal((await track(1, {}, s2.id)).status, 200)
    await client(base, { value: other.json.user.accessToken })('POST', '/logout')
    assert.equal((await track(1, {}, s2.id)).status, 404, 'signed out')
    await api('POST', `/api/session/${s2.id}/sync`, { body: { currentTime: 1, timeListened: 0 } })
    assert.equal((await track(1, {}, s2.id)).status, 200, 'an app that is still signed in goes on')
    await api('POST', `/api/session/${s2.id}/close`, { body: {} })
    await api('DELETE', `/api/me/progress/${mp3book.id}`)
  })
  await check('streaming session: play -> sync -> close updates progress', async () => {
    const r = await api('POST', `/api/items/${mp3book.id}/play`, { body: { deviceInfo: { deviceId: 'dev1', manufacturer: 'Test', model: 'Phone', sdkVersion: 34, clientVersion: '0.14.2' }, forceDirectPlay: true, mediaPlayer: 'exo-player', supportedMimeTypes: ['audio/mpeg'] } })
    assert.equal(r.status, 200, r.text)
    const s = r.json
    assert.equal(s.playMethod, 0)
    assert.equal(s.audioTracks.length, 2)
    assert.match(s.audioTracks[0].contentUrl, new RegExp(`^/api/items/${mp3book.id}/file/\\d+$`))
    for (const k of ['deviceId', 'manufacturer', 'model', 'clientVersion']) assert.equal(typeof s.deviceInfo[k], 'string')
    assert.equal(typeof s.deviceInfo.sdkVersion, 'number')
    assert.ok(s.mediaMetadata.authors, 'book metadata deduces as BookMetadata')
    assert.equal((await api('POST', `/api/session/${s.id}/sync`, { body: { currentTime: 70, timeListened: 10, duration: s.duration } })).status, 200)
    assert.equal((await api('POST', `/api/session/${s.id}/close`, { body: { currentTime: 75, timeListened: 5, duration: s.duration } })).status, 200)
    const p = (await api('GET', `/api/me/progress/${mp3book.id}`)).json
    assert.equal(p.currentTime, 75)
    assert.ok(Math.abs(p.progress - 75 / s.duration) < 0.001)
    assert.equal((await api('POST', `/api/session/nope/sync`, { body: { currentTime: 1 } })).status, 404)
    await streaming(false)
  })
  await check('admin can forbid streaming', async () => {
    await streaming(true)
    const admin = (allowStreaming) => webSettings('PUT', '/settings/admin', { allowStreaming })
    assert.equal((await admin(false)).allowStreaming, false)
    assert.equal((await api('POST', `/api/items/${mp3book.id}/play`, { body: { mediaPlayer: 'exo-player' } })).status, 403)
    assert.deepEqual((await webSettings('GET', '/settings')).streaming, { allowed: false, on: false })
    const refused = await fetch(base + '/settings/streaming', { method: 'PUT', headers: { authorization: adminBasic, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: '{"enabled":true}' })
    assert.equal(refused.status, 403)
    assert.equal((await admin(true)).allowStreaming, true)
    await streaming(false)
  })
  await check('offline phone sync: /api/session/local-all, newer local wins', async () => {
    const now = Date.now()
    const r = await api('POST', '/api/session/local-all', { body: { sessions: [{ id: 'play_local_1', libraryItemId: m4b.id, currentTime: 100, timeListening: 30, updatedAt: now, startedAt: now - 60000, duration: 120, playMethod: 3, mediaPlayer: 'exo-player', displayTitle: 'The First Tale' }], deviceInfo: { deviceId: 'dev1', manufacturer: 'T', model: 'P', sdkVersion: 34, clientVersion: '0.14.2' } } })
    assert.equal(r.status, 200, r.text)
    assert.deepEqual(r.json.results, [{ id: 'play_local_1', success: true, progressSynced: true }])
    const p = (await api('GET', `/api/me/progress/${m4b.id}`)).json
    assert.equal(p.currentTime, 100)
    assert.equal(p.lastUpdate, now)
  })
  await check('offline phone sync: older local session does not overwrite newer CrossPoint position', async () => {
    await api('PATCH', `/api/me/progress/${m4b.id}`, { body: { progress: 0.3, duration: 120, currentTime: 36 } })
    const old = Date.now() - 3600_000
    const r = await api('POST', '/api/session/local', { body: { id: 'play_local_2', libraryItemId: m4b.id, currentTime: 110, timeListening: 5, updatedAt: old, startedAt: old - 1000, duration: 120 } })
    assert.equal(r.status, 200)
    const p = (await api('GET', `/api/me/progress/${m4b.id}`)).json
    assert.equal(p.currentTime, 36)
  })
  await check('/api/me carries mediaProgress for the phone\'s reconnect sync', async () => {
    const r = await api('GET', '/api/me')
    const mp = r.json.mediaProgress.find((p) => p.libraryItemId === m4b.id)
    for (const k of ['id', 'libraryItemId', 'duration', 'progress', 'currentTime', 'isFinished', 'lastUpdate', 'startedAt']) assert.ok(mp[k] !== undefined && mp[k] !== null, k)
    assert.equal(mp.currentTime, 36)
  })
  await check('finishing: near the end auto-finishes; isFinished:true sets progress 1', async () => {
    await api('PATCH', `/api/me/progress/${rootmp3.id}`, { body: { currentTime: 85, duration: 90 } })
    let p = (await api('GET', `/api/me/progress/${rootmp3.id}`)).json
    assert.equal(p.isFinished, true); assert.equal(p.progress, 1); assert.ok(p.finishedAt)
    await api('PATCH', `/api/me/progress/${rootmp3.id}`, { body: { isFinished: false } })
    p = (await api('GET', `/api/me/progress/${rootmp3.id}`)).json
    assert.equal(p.isFinished, false); assert.equal(p.currentTime, 0)
    await api('PATCH', `/api/me/progress/${rootmp3.id}`, { body: { isFinished: true, progress: 1, ebookProgress: 1 } })
    p = (await api('GET', `/api/me/progress/${rootmp3.id}`)).json
    assert.equal(p.isFinished, true); assert.equal(p.progress, 1)
  })
  await check('Voice: POST /api/items/batch/get', async () => {
    const r = await api('POST', '/api/items/batch/get', { body: { libraryItemIds: [m4b.id, mp3book.id, 'nope'] } })
    assert.equal(r.json.libraryItems.length, 2)
  })
  await check('socket.io is refused quickly', async () => {
    const r = await api('GET', '/socket.io/?EIO=4&transport=websocket')
    assert.ok(r.status >= 400 && r.status < 500, String(r.status))
  })

  // ---- milestone 3 ----
  await check('series list: Star Saga with books in sequence order', async () => {
    const r = await api('GET', `/api/libraries/${libId}/series?minified=1&sort=name&limit=10000`)
    assert.equal(r.status, 200, r.text)
    assert.equal(r.json.total, 1)
    const s = r.json.results[0]
    for (const k of ['id', 'libraryId', 'name', 'addedAt', 'updatedAt']) assert.ok(s[k] !== undefined && s[k] !== null, k)
    assert.equal(s.name, 'Star Saga')
    assert.deepEqual(s.books.map((b) => b.media.metadata.title), ['Rising', 'Falling'])
    assert.deepEqual(s.books.map((b) => b.sequence), ['1', '2'])
    for (const b of s.books) assertKotlinLibraryItem(b, false)
    const d = await api('GET', `/api/series/${s.id}?include=progress`)
    assert.equal(d.status, 200, d.text)
    assert.equal(d.json.name, 'Star Saga')
    assert.deepEqual(d.json.progress.libraryItemIds, [mp3book.id, book2.id])
    assert.equal((await api('GET', '/api/series/ser_nope')).status, 404)
  })
  await check('authors list, author detail with items and series, image 404', async () => {
    const r = await api('GET', `/api/libraries/${libId}/authors`)
    assert.deepEqual(r.json.authors.map((a) => a.name), ['Ada Author', 'Bob Writer', 'Cleo Teller'])
    const bob = r.json.authors.find((a) => a.name === 'Bob Writer')
    for (const k of ['id', 'libraryId', 'name', 'addedAt', 'updatedAt']) assert.ok(bob[k] !== undefined && bob[k] !== null, k)
    assert.equal(bob.numBooks, 2)
    const d = await api('GET', `/api/authors/${bob.id}?include=items,series&library=${libId}`)
    assert.equal(d.status, 200, d.text)
    assert.equal(d.json.libraryItems.length, 2)
    assert.equal(d.json.series[0].name, 'Star Saga')
    assert.equal(d.json.series[0].items.length, 2)
    const filtered = await api('GET', `/api/libraries/${libId}/items?minified=1&filter=authors.${encodeURIComponent(Buffer.from(bob.id).toString('base64'))}&sort=media.metadata.title&limit=1000`)
    assert.equal(filtered.json.total, 2)
    assert.equal((await api('GET', `/api/authors/${bob.id}/image`)).status, 404)
  })
  await check('collapseseries=1 folds the series into one entry', async () => {
    const r = await api('GET', `/api/libraries/${libId}/items?limit=100&minified=1&collapseseries=1&sort=media.metadata.title`)
    assert.equal(r.json.total, 3)
    const c = r.json.results.find((i) => i.collapsedSeries)
    assert.ok(c, 'no collapsed entry')
    assert.equal(c.collapsedSeries.name, 'Star Saga')
    assert.equal(c.collapsedSeries.numBooks, 2)
    assert.deepEqual(c.collapsedSeries.libraryItemIds, [mp3book.id, book2.id])
  })
  await check('collapsed series count, also with an author filter; not inside a series', async () => {
    const bob = (await api('GET', `/api/libraries/${libId}/authors`)).json.authors.find((a) => a.name === 'Bob Writer')
    const enc = (s) => encodeURIComponent(Buffer.from(s).toString('base64'))
    const r = await api('GET', `/api/libraries/${libId}/items?limit=1000&minified=1&filter=authors.${enc(bob.id)}&sort=media.metadata.title&collapseseries=1`)
    assert.equal(r.json.total, 1)
    assert.equal(r.json.results[0].id, mp3book.id, 'the first book by number, not by title')
    assert.ok(!('seriesSequenceList' in r.json.results[0].collapsedSeries), 'the app would show it instead of the count')
    const sid = r.json.results[0].collapsedSeries.id
    const inside = await api('GET', `/api/libraries/${libId}/items?minified=1&limit=10&filter=series.${enc(sid)}&collapseseries=1`)
    assert.deepEqual(inside.json.results.map((i) => i.id), [mp3book.id, book2.id])
    assert.deepEqual(inside.json.results.map((i) => i.media.metadata.series), [1, 2].map((n) => ({ id: sid, name: 'Star Saga', sequence: String(n) })))
    for (const i of inside.json.results) assertKotlinLibraryItem(i, false)
    const none = await api('GET', `/api/libraries/${libId}/items?minified=1&filter=series.${enc('no-series')}`)
    assert.deepEqual(none.json.results.map((i) => i.id).sort(), [m4b.id, rootmp3.id].sort())
    assert.equal((await api('GET', `/api/libraries/${libId}/series/${sid}`)).json.name, 'Star Saga')
  })
  await check('search finds series with their books', async () => {
    const r = await api('GET', `/api/libraries/${libId}/search?q=star%20sag&limit=12`)
    assert.equal(r.json.series.length, 1)
    for (const k of ['id', 'libraryId', 'name', 'addedAt', 'updatedAt']) assert.ok(r.json.series[0].series[k] !== undefined && r.json.series[0].series[k] !== null, k)
    assert.deepEqual(r.json.series[0].books.map((b) => b.id), [mp3book.id, book2.id])
    for (const b of r.json.series[0].books) assertKotlinLibraryItem(b, false)
    assert.deepEqual((await api('GET', `/api/libraries/${libId}/search?q=rising`)).json.series, [])
  })
  await check('continue-series offers the next book once one is finished', async () => {
    const shelf = async () => (await api('GET', `/api/libraries/${libId}/personalized?minified=1`)).json.find((s) => s.id === 'continue-series')
    const clear = async () => { for (const b of [mp3book, book2]) await api('DELETE', `/api/me/progress/mp_${b.id}`) }
    await clear()
    assert.equal(await shelf(), undefined)
    await api('PATCH', `/api/me/progress/${mp3book.id}`, { body: { isFinished: true } })
    const s = await shelf()
    assert.deepEqual([s.type, s.total, s.entities.map((e) => e.id)], ['book', 1, [book2.id]])
    assert.equal(s.entities[0].media.metadata.series.sequence, '2')
    assertKotlinLibraryItem(s.entities[0], false)
    await api('PATCH', `/api/me/progress/${book2.id}`, { body: { currentTime: 2, duration: 100 } })
    assert.equal(await shelf(), undefined, 'now under continue-listening')
    await api('PATCH', `/api/me/progress/${book2.id}`, { body: { isFinished: true } })
    assert.equal(await shelf(), undefined, 'series finished')
    await clear()
  })
  await check('playlists: create, add, reorder, remove, gone when empty', async () => {
    const bad = [{}, { name: 'P', libraryId: 'x' }, { name: ' ', libraryId: libId }, { name: 'P', libraryId: libId, items: [{ libraryItemId: 'nope' }] }, { name: 'P', libraryId: libId, items: [{ libraryItemId: m4b.id, episodeId: 'e' }] }]
    for (const body of bad) assert.equal((await api('POST', '/api/playlists', { body })).status, 400, JSON.stringify(body))
    const p = (await api('POST', '/api/playlists', { body: { name: 'Road <b>trip</b>', libraryId: libId, items: [{ libraryItemId: m4b.id }] } })).json
    assert.equal(p.name, 'Road trip')
    for (const k of ['id', 'libraryId', 'userId', 'lastUpdate', 'createdAt']) assert.ok(p[k] !== undefined && p[k] !== null, k)
    const ids = (x) => x.items.map((i) => i.libraryItemId)
    assert.deepEqual(ids((await api('POST', `/api/playlists/${p.id}/item`, { body: { libraryItemId: mp3book.id } })).json), [m4b.id, mp3book.id])
    assert.deepEqual(ids((await api('POST', `/api/playlists/${p.id}/batch/add`, { body: { items: [{ libraryItemId: book2.id }, { libraryItemId: m4b.id }] } })).json), [m4b.id, mp3book.id, book2.id])
    assert.deepEqual(ids((await api('PATCH', `/api/playlists/${p.id}`, { body: { name: 'New', items: [book2, m4b, mp3book].map((b) => ({ libraryItemId: b.id })) } })).json), [book2.id, m4b.id, mp3book.id])
    const got = (await api('GET', `/api/playlists/${p.id}`)).json
    assert.equal(got.name, 'New')
    for (const i of got.items) assertKotlinLibraryItem(i.libraryItem, true)
    const page = (await api('GET', `/api/libraries/${libId}/playlists?limit=10&page=0`)).json
    assert.deepEqual([page.total, page.results[0].id], [1, p.id])
    assert.equal((await api('GET', `/api/libraries/${libId}?include=filterdata`)).json.numUserPlaylists, 1)
    assert.deepEqual(ids((await api('DELETE', `/api/playlists/${p.id}/item/${m4b.id}`)).json), [book2.id, mp3book.id])
    assert.deepEqual(ids((await api('POST', `/api/playlists/${p.id}/batch/remove`, { body: { items: [{ libraryItemId: book2.id }, { libraryItemId: mp3book.id }] } })).json), [])
    assert.equal((await api('GET', `/api/playlists/${p.id}`)).status, 404)
  })
  await check('collections: create, add, reorder, remove, to playlist, delete', async () => {
    assert.equal((await api('POST', '/api/collections', { body: { name: 'C', libraryId: libId, books: [] } })).status, 400)
    const c = (await api('POST', '/api/collections', { body: { name: 'Best', libraryId: libId, description: 'd', books: [m4b.id] } })).json
    const ids = (x) => x.books.map((b) => b.id)
    assert.deepEqual([c.description, ids(c)], ['d', [m4b.id]])
    assertKotlinLibraryItem(c.books[0], true)
    assert.deepEqual(ids((await api('POST', `/api/collections/${c.id}/book`, { body: { id: book2.id } })).json), [m4b.id, book2.id])
    assert.deepEqual(ids((await api('POST', `/api/collections/${c.id}/batch/add`, { body: { books: [mp3book.id] } })).json), [m4b.id, book2.id, mp3book.id])
    assert.deepEqual(ids((await api('PATCH', `/api/collections/${c.id}`, { body: { books: [mp3book.id, m4b.id, book2.id], description: '' } })).json), [mp3book.id, m4b.id, book2.id])
    assert.deepEqual(ids((await api('DELETE', `/api/collections/${c.id}/book/${m4b.id}`)).json), [mp3book.id, book2.id])
    assert.deepEqual((await api('GET', `/api/libraries/${libId}/collections?minified=1`)).json.results.map((x) => x.id), [c.id])
    const p = (await api('POST', `/api/playlists/collection/${c.id}`)).json
    assert.deepEqual([p.name, p.items.length], ['Best', 2])
    for (const x of [`/api/playlists/${p.id}`, `/api/collections/${c.id}`]) {
      assert.equal((await api('DELETE', x)).status, 200)
      assert.equal((await api('GET', x)).status, 404)
    }
  })
  await check('details: tags, several series, publisher and more; filters, search and lists know them', async () => {
    const id = rootmp3.id
    const before = (await api('GET', `/api/items/${id}?expanded=1`)).json.media.metadata
    const enc = (s) => encodeURIComponent(Buffer.from(s).toString('base64'))
    const r = await api('PATCH', `/api/items/${id}/media`, { body: { metadata: { subtitle: 'Sub', publisher: 'Pub & Co', language: 'English', isbn: '123', asin: 'B0', publishedYear: '1999', genres: ['Odd'], narrators: ['Zed Voice'], description: 'D', explicit: true, abridged: true, series: [{ name: 'Tales', sequence: '3' }, { name: 'Star Saga', sequence: '1.5' }] }, tags: ['Fav', 'Fav', ' '] } })
    assert.equal(r.status, 200, r.text)
    const m = r.json.libraryItem.media
    assertKotlinLibraryItem(r.json.libraryItem, true)
    assert.deepEqual([m.tags, m.metadata.publisher, m.metadata.language, m.metadata.isbn, m.metadata.asin, m.metadata.explicit, m.metadata.abridged, m.metadata.publishedYear, m.metadata.subtitle], [['Fav'], 'Pub & Co', 'English', '123', 'B0', true, true, '1999', 'Sub'])
    assert.equal(m.metadata.seriesName, 'Tales #3, Star Saga #1.5')
    const saga = (await api('GET', `/api/libraries/${libId}/series?limit=100`)).json.results.find((s) => s.name === 'Star Saga')
    assert.deepEqual(saga.books.map((b) => b.id), [mp3book.id, id, book2.id])
    const inSaga = (await api('GET', `/api/libraries/${libId}/items?minified=1&filter=series.${enc(saga.id)}`)).json.results
    assert.deepEqual(inSaga.map((b) => [b.id, b.media.metadata.series.sequence]), [[mp3book.id, '1'], [id, '1.5'], [book2.id, '2']])
    const fd = (await api('GET', `/api/libraries/${libId}/filterdata`)).json
    assert.ok(fd.tags.includes('Fav') && fd.publishers.includes('Pub & Co') && fd.languages.includes('English') && fd.publishedDecades.includes('1990') && fd.narrators.includes('Zed Voice'))
    for (const f of ['tags.Fav', 'publishers.Pub & Co', 'languages.English', 'publishedDecades.1990', 'narrators.Zed Voice', 'genres.Odd']) {
      const [g, v] = f.split('.')
      assert.deepEqual((await api('GET', `/api/libraries/${libId}/items?minified=1&filter=${g}.${enc(v)}`)).json.results.map((b) => b.id), [id], f)
    }
    const s = (q) => api('GET', `/api/libraries/${libId}/search?q=${q}`).then((x) => x.json)
    assert.deepEqual([(await s('fav')).tags, (await s('odd')).genres, (await s('zed')).narrators], [[{ name: 'Fav', numItems: 1 }], [{ name: 'Odd', numItems: 1 }], [{ name: 'Zed Voice', numBooks: 1 }]])
    const au = (await s('bob')).authors
    assert.deepEqual(au.map((a) => [a.name, a.numBooks]), [['Bob Writer', 2]])
    for (const k of ['id', 'libraryId', 'name', 'addedAt', 'updatedAt']) assert.ok(au[0][k] !== undefined && au[0][k] !== null, k)
    assert.ok((await api('GET', `/api/libraries/${libId}/narrators`)).json.narrators.some((x) => x.name === 'Zed Voice'))
    assert.ok((await api('GET', '/api/tags')).json.tags.includes('Fav') && (await api('GET', '/api/genres')).json.genres.includes('Odd'))
    // An edit of something else keeps all of it.
    await api('POST', '/api/items/batch/update', { body: [{ id, mediaPayload: { metadata: { title: 'Short Story' } } }] })
    const basic = { authorization: adminBasic, 'OCS-APIRequest': 'true' }
    await fetch(`${env.NC_URL}/apps/abscompat/settings/rescan`, { method: 'POST', headers: basic })
    const kept = (await api('GET', `/api/items/${id}?expanded=1`)).json.media
    assert.deepEqual([kept.tags, kept.metadata.seriesName, kept.metadata.publisher], [['Fav'], 'Tales #3, Star Saga #1.5', 'Pub & Co'])
    await api('PATCH', `/api/items/${id}/media`, { body: { metadata: { subtitle: before.subtitle, publisher: null, language: null, isbn: null, asin: null, publishedYear: before.publishedYear, genres: before.genres, narrators: before.narrators, description: before.description, explicit: false, abridged: false, series: [] }, tags: [] } })
  })
  await check('series: description, rename and hiding', async () => {
    const all = async () => (await api('GET', `/api/libraries/${libId}/series?limit=100`)).json.results
    const id = (await all())[0].id
    assert.equal((await api('PATCH', `/api/series/${id}`, { body: { description: 'About' } })).json.description, 'About')
    const r = (await api('PATCH', `/api/series/${id}`, { body: { name: 'Moon Saga' } })).json
    assert.deepEqual([r.name, r.description], ['Moon Saga', 'About'])
    assert.deepEqual((await all()).map((s) => [s.name, s.description, s.books.length]), [['Moon Saga', 'About', 2]])
    const back = (await api('PATCH', `/api/series/${r.id}`, { body: { name: 'Star Saga', description: '' } })).json
    assert.deepEqual([back.id, back.description], [id, null])
    await api('PATCH', `/api/me/progress/${mp3book.id}`, { body: { isFinished: true } })
    const shelf = async () => (await api('GET', `/api/libraries/${libId}/personalized`)).json.some((s) => s.id === 'continue-series')
    assert.equal(await shelf(), true)
    assert.deepEqual((await api('GET', `/api/me/series/${id}/remove-from-continue-listening`)).json.seriesHideFromContinueListening, [id])
    assert.equal(await shelf(), false)
    assert.deepEqual((await api('GET', `/api/me/series/${id}/readd-to-continue-listening`)).json.seriesHideFromContinueListening, [])
    assert.equal(await shelf(), true)
    assert.equal((await api('GET', '/api/me/series/ser_nope/remove-from-continue-listening')).status, 404)
    await api('DELETE', `/api/me/progress/${mp3book.id}`)
  })
  await check('discover, year in review, hiding a book from continue listening', async () => {
    const shelves = async () => (await api('GET', `/api/libraries/${libId}/personalized`)).json
    const d = (await shelves()).find((s) => s.id === 'discover')
    assert.ok(d.entities.some((b) => b.id === mp3book.id) && !d.entities.some((b) => b.id === book2.id), 'only the first book of a series')
    await api('PATCH', `/api/me/progress/${mp3book.id}`, { body: { currentTime: 5, duration: 100 } })
    const cont = async () => (await shelves()).find((s) => s.id === 'continue-listening')?.entities.some((b) => b.id === mp3book.id) === true
    assert.equal(await cont(), true)
    assert.equal((await api('GET', `/api/me/progress/mp_${mp3book.id}/remove-from-continue-listening`)).status, 200)
    assert.equal(await cont(), false)
    await api('DELETE', `/api/me/progress/${mp3book.id}`)
    const y = await api('GET', `/api/me/stats/year/${new Date().getFullYear()}`)
    assert.equal(y.status, 200, y.text)
    assert.ok(y.json.totalListeningTime >= 15 && y.json.totalListeningSessions > 0 && y.json.topAuthors.length > 0)
    for (const k of ['topGenres', 'booksWithCovers', 'finishedBooksWithCovers']) assert.ok(Array.isArray(y.json[k]), k)
    assert.equal((await api('GET', '/api/me/stats/year/abc')).status, 400)
    assert.ok(Array.isArray((await api('GET', '/api/me/progress')).json.mediaProgress) && Array.isArray((await api('GET', '/api/me/bookmarks')).json.bookmarks))
    assert.ok((await api('GET', `/api/libraries/${libId}/stats`)).json.totalItems === 4)
  })
  await check('home shelves include recent-series (series entities) and newest-authors', async () => {
    const r = await api('GET', `/api/libraries/${libId}/personalized?minified=1`)
    const ser = r.json.find((s) => s.id === 'recent-series')
    assert.ok(ser && ser.type === 'series' && ser.entities[0].books.length === 2)
    const au = r.json.find((s) => s.id === 'newest-authors')
    assert.ok(au && au.type === 'authors' && au.entities.length === 3)
    for (const e of au.entities) for (const k of ['id', 'libraryId', 'name', 'addedAt', 'updatedAt']) assert.ok(e[k] !== undefined && e[k] !== null, k)
  })
  await check('bookmarks: create, rename, list in /api/me, delete', async () => {
    const c = await api('POST', `/api/me/item/${m4b.id}/bookmark`, { body: { time: 42.5, title: 'Nice bit' } })
    assert.equal(c.status, 200, c.text)
    assert.deepEqual({ ...c.json, createdAt: 0 }, { libraryItemId: m4b.id, title: 'Nice bit', time: 42.5, createdAt: 0 })
    const u = await api('PATCH', `/api/me/item/${m4b.id}/bookmark`, { body: { time: 42.5, title: 'Renamed' } })
    assert.equal(u.json.title, 'Renamed')
    const me = await api('GET', '/api/me')
    assert.ok(me.json.bookmarks.some((b) => b.libraryItemId === m4b.id && b.time === 42.5 && b.title === 'Renamed'))
    assert.equal((await api('DELETE', `/api/me/item/${m4b.id}/bookmark/42.5`)).status, 200)
    assert.equal((await api('DELETE', `/api/me/item/${m4b.id}/bookmark/42.5`)).status, 404)
    assert.equal((await api('PATCH', `/api/me/item/${m4b.id}/bookmark`, { body: { time: 1, title: 'x' } })).status, 404)
  })
  await check('listening stats and sessions reflect the sessions above', async () => {
    const st = await api('GET', '/api/me/listening-stats')
    assert.equal(st.status, 200, st.text)
    assert.ok(st.json.totalTime >= 15, 'totalTime ' + st.json.totalTime)
    assert.ok(st.json.items[mp3book.id].timeListening >= 15)
    assert.ok(st.json.today >= 0)
    assert.ok(Array.isArray(st.json.recentSessions) && st.json.recentSessions.length > 0)
    const ls = await api('GET', '/api/me/listening-sessions?itemsPerPage=2&page=0')
    assert.equal(ls.json.sessions.length, 2)
    assert.ok(ls.json.total >= 3)
    assert.equal(typeof ls.json.sessions[0].displayTitle, 'string')
  })
  await check('Voice: PATCH /api/items/:id/media stores overrides that survive a rescan', async () => {
    const r = await api('PATCH', `/api/items/${rootmp3.id}/media`, { body: { metadata: { title: 'Short Story (Edited)', authors: [{ name: 'Cleo Teller' }, { name: 'Dee Co' }], series: [{ name: 'Tales', sequence: '3' }] } } })
    assert.equal(r.status, 200, `status ${r.status}: ${r.text.slice(0, 300)}`)
    assert.equal(r.json.updated, true)
    assert.equal(r.json.libraryItem.media.metadata.title, 'Short Story (Edited)')
    assert.equal(r.json.libraryItem.media.metadata.authorName, 'Cleo Teller, Dee Co')
    assert.equal(r.json.libraryItem.media.metadata.seriesName, 'Tales #3')
    const basic = 'Basic ' + Buffer.from(`${env.NC_ADMIN_USER}:${env.NC_ADMIN_PASSWORD}`).toString('base64')
    const scan = await fetch(`${env.NC_URL}/apps/abscompat/settings/rescan`, { method: 'POST', headers: { authorization: basic, 'OCS-APIRequest': 'true' } })
    assert.equal(scan.status, 200)
    let it = (await api('GET', `/api/items/${rootmp3.id}`)).json
    assert.equal(it.media.metadata.title, 'Short Story (Edited)')
    // Clearing the series via an empty list, and restoring the title
    await api('PATCH', `/api/items/${rootmp3.id}/media`, { body: { metadata: { title: 'Short Story', authors: [{ name: 'Cleo Teller' }], series: [] } } })
    it = (await api('GET', `/api/items/${rootmp3.id}?expanded=1`)).json
    assert.equal(it.media.metadata.seriesName, '')
    assert.deepEqual(it.media.metadata.series, [])
    assert.equal(it.media.metadata.authorName, 'Cleo Teller')
  })
  await check('another user sees none of the lists and cannot change them', async () => {
    const c = (await api('POST', '/api/collections', { body: { name: 'Mine', libraryId: libId, books: [m4b.id] } })).json
    const login = await client(base, { value: null })('POST', '/login', { body: { username: env.NC_PLAIN_USER, password: env.NC_PLAIN_PASSWORD } })
    const other = client(base, { value: login.json.user.accessToken })
    for (const [m, p] of [['GET', ''], ['PATCH', ''], ['DELETE', ''], ['POST', '/book'], ['POST', '/batch/remove']]) assert.equal((await other(m, `/api/collections/${c.id}${p}`, m === 'GET' ? {} : { body: { id: m4b.id, name: 'x', books: [m4b.id] } })).status, 404, m + p)
    assert.equal((await other('POST', '/api/collections', { body: { name: 'x', libraryId: libId, books: [m4b.id] } })).status, 400)
    assert.deepEqual((await other('GET', '/api/collections')).json.collections, [])
    assert.equal((await client(base, { value: null })('GET', '/api/collections')).status, 401)
    await other('POST', '/logout')
    await api('DELETE', `/api/collections/${c.id}`)
  })
  await check('user permissions allow update (metadata edits) and download', async () => {
    const me = await api('GET', '/api/me')
    assert.equal(me.json.permissions.update, true)
    assert.equal(me.json.permissions.download, true)
  })
  await check('POST /logout revokes the access token', async () => {
    assert.equal((await api('POST', '/logout')).status, 200)
    assert.equal((await api('GET', '/api/me')).status, 401)
  })
}

for (const p of PREFIXES) await run(p)
console.log(`\n${passes} passed, ${failures} failed`)
process.exit(failures ? 1 : 0)
