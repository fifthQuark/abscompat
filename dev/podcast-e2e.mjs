// Podcasts through rePod's backend, GPodderSync: subscriptions are read, positions go both ways,
// episodes are fetched from the publisher. Needs GPodderSync installed in the container
// (dev/test-matrix.sh does it when GPODDER_DIR is set).
//   node dev/podcast-e2e.mjs
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import assert from 'node:assert/strict'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const CONTAINER = process.env.NC_CONTAINER || 'abs-nc34', D = process.env.DOCKER || 'podman'
const user = env.NC_PLAIN_USER, base = env.NC_URL + '/apps/abscompat'
const basic = { authorization: 'Basic ' + Buffer.from(`${user}:${env.NC_PLAIN_PASSWORD}`).toString('base64') }
const exec = (args, input, opts = []) => execFileSync(D, ['exec', '-i', ...opts, CONTAINER, ...args], { encoding: 'utf8', input })
// The feed is served by the container's own web server, so Nextcloud may fetch it from localhost.
const FEED = 'http://localhost/abs-test-feed.html'
const feed = (n) => `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/">
<channel><title>Test Pod</title><itunes:author>Pod Person</itunes:author><description>About &amp; more</description>
<itunes:image href="https://img.example.org/pod.jpg"/><itunes:category text="Science"/><language>en</language>
${[...Array(n)].map((_, i) => `<item><title>Episode ${i + 1}</title><guid>guid-${i + 1}</guid><pubDate>${new Date(Date.UTC(2026, 0, i + 1)).toUTCString()}</pubDate>
<enclosure url="https://media.example.org/ep${i + 1}.mp3" type="audio/mpeg" length="${1000 * (i + 1)}"/><itunes:duration>${i === 0 ? '1:40' : '200'}</itunes:duration>
<content:encoded><![CDATA[<p>Show notes ${i + 1}</p>]]></content:encoded></item>`).join('\n')}
<item><title>No audio</title><guid>x</guid></item></channel></rss>`
const gpodder = async (method, path, body) => {
  const r = await fetch(`${env.NC_URL}/index.php/apps/gpoddersync${path}`, { method, headers: { ...basic, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  assert.equal(r.status, 200, `${method} ${path}: ${r.status}`)
  return r.json()
}
const positions = async () => Object.fromEntries((await gpodder('GET', '/episode_action?since=0')).actions.map((a) => [a.episode, [a.position, a.total]]))
const web = (method, path, body) => fetch(base + path, { method, headers: { ...basic, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }).then(async (r) => ({ status: r.status, json: await r.json().catch(() => null) }))
let token
const raw = (method, path, body, headers = {}) => fetch(base + path, { method, redirect: 'manual', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json', ...headers }, body: body ? JSON.stringify(body) : undefined })
const api = async (method, path, body) => { const r = await raw(method, path, body); return { status: r.status, json: await r.json().catch(() => null) } }

let fails = 0, passes = 0
async function check(name, fn) {
  try { await fn(); passes++; console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message.split('\n').slice(0, 8).join(' ')) }
}

exec(['sh', '-c', 'cat > /var/www/html/abs-test-feed.html && chown www-data: /var/www/html/abs-test-feed.html'], feed(3))
exec(['php', 'occ', 'config:system:set', 'allow_local_remote_servers', '--value', 'true', '--type', 'boolean'], undefined, ['-u', 'www-data'])
// A newly enabled app answers 404 until the web server's app cache expires.
for (let i = 0; i < 40; i++) {
  if ((await fetch(`${env.NC_URL}/index.php/apps/gpoddersync/subscriptions?since=0`, { headers: basic })).status === 200) break
  await new Promise((r) => setTimeout(r, 1000))
}
try {
  await web('PUT', '/settings/podcasts', { enabled: true })
  await gpodder('POST', '/subscription_change/create', { add: [FEED], remove: [] })
  await gpodder('POST', '/episode_action/create', [{ podcast: FEED, episode: 'https://media.example.org/ep2.mp3', guid: 'guid-2', action: 'play', timestamp: '2026-02-01T10:00:00', started: 0, position: 40, total: 200 }])
  token = (await web('POST', '/settings/apikeys', { name: 'podcast-e2e' })).json.token
  let pod, eps
  await check('the subscriptions are a podcast library, in the shape the app expects', async () => {
    const s = (await web('GET', '/settings')).json.podcasts
    assert.deepEqual(s, { available: true, on: true })
    const libs = (await api('GET', '/api/libraries')).json.libraries
    const lib = libs.find((l) => l.id === 'podcasts')
    assert.deepEqual([lib.mediaType, lib.name, libs.at(-1).id], ['podcast', 'Podcasts', 'podcasts'])
    const items = (await api('GET', '/api/libraries/podcasts/items?limit=20&minified=1')).json
    assert.equal(items.total, 1)
    pod = items.results[0]
    assert.deepEqual([pod.mediaType, pod.media.metadata.title, pod.media.metadata.author, pod.media.metadata.feedUrl, pod.media.numEpisodes, pod.media.autoDownloadEpisodes], ['podcast', 'Test Pod', 'Pod Person', FEED, 3, false])
    assert.ok(!('audioFiles' in pod.media) && !('authorName' in pod.media.metadata), 'no book keys, or the app takes it for a book')
    const full = (await api('GET', `/api/items/${pod.id}?expanded=1`)).json
    eps = full.media.episodes
    assert.deepEqual(eps.map((e) => [e.title, e.duration, e.index]), [['Episode 3', 200, 3], ['Episode 2', 200, 2], ['Episode 1', 100, 1]])
    assert.deepEqual([eps[0].audioTrack.contentUrl, eps[0].audioFile.ino, eps[0].enclosure.url, eps[0].description], [`/api/items/${pod.id}/file/${eps[0].id}`, eps[0].id, 'https://media.example.org/ep3.mp3', '<p>Show notes 3</p>'])
  })
  const ep2 = () => eps.find((e) => e.title === 'Episode 2')
  await check('positions from GPodderSync reach the app', async () => {
    const me = (await api('GET', '/api/me')).json.mediaProgress.find((m) => m.episodeId === ep2().id)
    assert.deepEqual([me.libraryItemId, me.currentTime, me.duration, me.isFinished, me.progress], [pod.id, 40, 200, false, 0.2])
    assert.equal((await api('GET', `/api/me/progress/${pod.id}/${ep2().id}`)).json.currentTime, 40)
    assert.equal((await api('GET', `/api/me/progress/${pod.id}/${eps[0].id}`)).status, 404)
    const going = (await api('GET', '/api/me/items-in-progress')).json.libraryItems
    assert.deepEqual(going.filter((i) => i.recentEpisode).map((i) => i.recentEpisode.id), [ep2().id])
  })
  await check('an episode can be taken off Continue Listening, and comes back when played', async () => {
    const id = `mp_${pod.id}-${ep2().id}`
    const progress = async () => (await api('GET', '/api/me')).json.mediaProgress.find((m) => m.episodeId === ep2().id)
    const going = async () => (await api('GET', '/api/me/items-in-progress')).json.libraryItems.filter((i) => i.recentEpisode).map((i) => i.recentEpisode.id)
    const shelf = async () => (await api('GET', '/api/libraries/podcasts/personalized')).json.find((s) => s.id === 'continue-listening')?.entities.map((i) => i.recentEpisode.id) ?? []
    const r = await api('GET', `/api/me/progress/${id}/remove-from-continue-listening`)
    assert.equal(r.status, 200)
    assert.equal(r.json.mediaProgress.find((m) => m.id === id).hideFromContinueListening, true, 'answers with the user, as ABS does')
    assert.deepEqual([await going(), await shelf()], [[], []])
    assert.equal((await api('GET', `/api/me/progress/${pod.id}/${ep2().id}`)).json.hideFromContinueListening, true)
    assert.deepEqual((await positions())['https://media.example.org/ep2.mp3'], [40, 200], 'GPodderSync is not touched')
    assert.equal((await api('GET', `/api/me/progress/mp_${pod.id}-${eps[0].id}/remove-from-continue-listening`)).status, 404, 'nothing to hide without a position')
    await api('PATCH', `/api/me/progress/${pod.id}/${ep2().id}`, { currentTime: 45, duration: 200 })
    assert.equal((await progress()).hideFromContinueListening, false)
    assert.deepEqual([await going(), await shelf()], [[ep2().id], [ep2().id]])
    await api('PATCH', `/api/me/progress/${pod.id}/${ep2().id}`, { hideFromContinueListening: true })
    assert.deepEqual(await going(), [])
    await api('PATCH', `/api/me/progress/${pod.id}/${ep2().id}`, { hideFromContinueListening: false, currentTime: 40 })
    assert.deepEqual([(await progress()).hideFromContinueListening, (await progress()).currentTime, await going()], [false, 40, [ep2().id]])
  })
  await check('positions from the app are saved in GPodderSync, as AntennaPod reads them', async () => {
    assert.equal((await api('PATCH', `/api/me/progress/${pod.id}/${eps[0].id}`, { currentTime: 50, duration: 200, progress: 0.25 })).status, 200)
    assert.deepEqual((await positions())['https://media.example.org/ep3.mp3'], [50, 200])
    await api('PATCH', `/api/me/progress/${pod.id}/${eps[0].id}`, { isFinished: true })
    assert.deepEqual((await positions())['https://media.example.org/ep3.mp3'], [200, 200], 'finished is position = total')
    assert.equal((await api('GET', `/api/me/progress/${pod.id}/${eps[0].id}`)).json.isFinished, true)
    await api('PATCH', `/api/me/progress/${pod.id}/${eps[0].id}`, { isFinished: false })
    assert.deepEqual((await positions())['https://media.example.org/ep3.mp3'], [0, 200])
    assert.equal((await api('DELETE', `/api/me/progress/mp_${pod.id}-${ep2().id}`)).status, 200)
    assert.deepEqual((await positions())['https://media.example.org/ep2.mp3'], [0, 200])
    assert.equal((await api('PATCH', `/api/me/progress/${pod.id}/e000000000000`, { currentTime: 1 })).status, 404)
  })
  await check('playing and downloading go straight to the publisher, without streaming', async () => {
    const s = (await api('POST', `/api/items/${pod.id}/play/${ep2().id}`, { mediaPlayer: 'exo-player', deviceInfo: { deviceId: 'p1' } })).json
    assert.deepEqual([s.mediaType, s.episodeId, s.libraryItemId, s.displayTitle, s.audioTracks.length], ['podcast', ep2().id, pod.id, 'Episode 2', 1])
    const where = async (r) => [r.status, r.headers.get('location')]
    const target = 'https://media.example.org/ep2.mp3'
    assert.deepEqual(await where(await fetch(`${base}/public/session/${s.id}/track/1`, { redirect: 'manual' })), [303, target])
    assert.equal((await fetch(`${base}/public/session/${s.id}/track/2`, { redirect: 'manual' })).status, 404)
    assert.deepEqual(await where(await raw('GET', `/api/items/${pod.id}/file/${ep2().id}/download`)), [303, target])
    assert.deepEqual(await where(await fetch(`${base}/api/items/${pod.id}/file/${ep2().id}?token=${token}`, { redirect: 'manual' })), [303, target])
    assert.deepEqual(await where(await raw('GET', `/api/items/${pod.id}/cover`)), [303, 'https://img.example.org/pod.jpg'])
    assert.equal((await raw('GET', `/api/items/${pod.id}/file/e000000000000`)).status, 404)
    await api('POST', `/api/session/${s.id}/sync`, { currentTime: 30, timeListened: 10, duration: 200 })
    assert.deepEqual((await positions())[target], [30, 200])
    await api('POST', `/api/session/${s.id}/close`, { currentTime: 35, timeListened: 5 })
    assert.equal((await fetch(`${base}/public/session/${s.id}/track/1`, { redirect: 'manual' })).status, 404, 'closed')
  })
  await check('offline listening synced later: newer wins, older is left alone', async () => {
    const session = (at, t) => ({ id: 'local-' + at, libraryItemId: pod.id, episodeId: eps[2].id, currentTime: t, timeListening: 5, duration: 100, updatedAt: at, startedAt: at })
    let r = await api('POST', '/api/session/local-all', { sessions: [session(Date.now(), 60)], deviceInfo: { deviceId: 'p1' } })
    assert.deepEqual(r.json.results.map((x) => [x.success, x.progressSynced]), [[true, true]])
    assert.deepEqual((await positions())['https://media.example.org/ep1.mp3'], [60, 100])
    r = await api('POST', '/api/session/local-all', { sessions: [session(Date.now() - 86400000, 10)] })
    assert.deepEqual((await positions())['https://media.example.org/ep1.mp3'], [60, 100])
  })
  await check('home shelves, newest episodes, search', async () => {
    const shelves = (await api('GET', '/api/libraries/podcasts/personalized?minified=1')).json
    assert.deepEqual(shelves.map((s) => [s.id, s.type]), [['continue-listening', 'episode'], ['newest-episodes', 'episode'], ['recently-added', 'podcast']])
    for (const s of shelves) for (const e of s.entities) assert.ok(s.type === 'podcast' || e.recentEpisode?.id, s.id)
    const recent = (await api('GET', '/api/libraries/podcasts/recent-episodes?limit=2&page=0')).json
    assert.deepEqual([recent.episodes.length, recent.episodes[0].libraryItemId, recent.episodes[0].podcast.metadata.title], [2, pod.id, 'Test Pod'])
    const found = (await api('GET', '/api/libraries/podcasts/search?q=test%20pod')).json
    assert.deepEqual(found.podcast.map((x) => x.libraryItem.id), [pod.id])
    assert.deepEqual((await api('GET', '/api/libraries/podcasts/collections')).json.results, [])
  })
  await check('another user has no podcast library and cannot reach these', async () => {
    const login = await (await fetch(base + '/login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ username: env.NC_ADMIN_USER, password: env.NC_ADMIN_PASSWORD }) })).json()
    const t2 = login.user.accessToken
    const other = (p) => fetch(base + p, { headers: { authorization: `Bearer ${t2}` }, redirect: 'manual' })
    assert.ok(!(await (await other('/api/libraries')).json()).libraries.some((l) => l.id === 'podcasts'))
    for (const p of [`/api/items/${pod.id}`, `/api/items/${pod.id}/file/${eps[0].id}/download`, `/api/me/progress/${pod.id}/${eps[0].id}`]) assert.equal((await other(p)).status, 404, p)
    await fetch(base + '/logout', { method: 'POST', headers: { authorization: `Bearer ${t2}` } })
  })
  await check('switched off: no podcast library', async () => {
    assert.equal((await web('PUT', '/settings/podcasts', { enabled: false })).json.podcasts.on, false)
    assert.ok(!(await api('GET', '/api/libraries')).json.libraries.some((l) => l.id === 'podcasts'))
    assert.equal((await api('GET', `/api/items/${pod.id}`)).status, 404)
    await web('PUT', '/settings/podcasts', { enabled: true })
  })
  await check('without subscriptions there is no podcast library', async () => {
    await gpodder('POST', '/subscription_change/create', { add: [], remove: [FEED] })
    assert.ok(!(await api('GET', '/api/libraries')).json.libraries.some((l) => l.id === 'podcasts'))
  })
} finally {
  await gpodder('POST', '/subscription_change/create', { add: [], remove: [FEED] }).catch(() => {})
  for (const t of ((await web('GET', '/settings')).json?.tokens || [])) await web('DELETE', `/settings/tokens/${t.id}`)
  exec(['rm', '-f', '/var/www/html/abs-test-feed.html'])
}
console.log(`\n${passes} passed, ${fails} failed`)
process.exit(fails ? 1 : 0)
