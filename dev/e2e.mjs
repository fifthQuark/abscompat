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

async function run(prefix) {
  const base = env.NC_URL + prefix
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
    // Direct-play URL with ?token= works too
    const dp = await fetch(`${base}/api/items/${m4b.id}/file/${ino}?token=${token.value}`, { headers: { range: 'bytes=0-3' } })
    assert.equal(dp.status, 206)
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
