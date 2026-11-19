// Settings endpoints (session-less Basic auth + OCS-APIRequest header, as API clients do).
//   node dev/settings-e2e.mjs
import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'

const env = Object.fromEntries(
  readFileSync(process.env.ENV_FILE || new URL('./test-instance.env', import.meta.url), 'utf8')
    .split('\n').filter((l) => l && !l.startsWith('#')).map((l) => l.split(/=(.*)/s).slice(0, 2)),
)
const base = env.NC_URL + '/apps/abscompat'
const auth = (u, p) => 'Basic ' + Buffer.from(`${u}:${p}`).toString('base64')
const ADMIN = auth(env.NC_ADMIN_USER, env.NC_ADMIN_PASSWORD)
const PLAIN = auth(env.NC_PLAIN_USER, env.NC_PLAIN_PASSWORD)
async function call(who, method, path, body) {
  const res = await fetch(base + path, { method, headers: { authorization: who, 'OCS-APIRequest': 'true', 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
  return { status: res.status, json: await res.json().catch(() => null) }
}
let fails = 0
async function check(name, fn) {
  try { await fn(); console.log('  ok  ', name) } catch (e) { fails++; console.log('  FAIL', name, '\n       ', e.message) }
}

await check('GET /settings shows libraries with counts', async () => {
  const r = await call(ADMIN, 'GET', '/settings')
  assert.equal(r.status, 200)
  const lib = r.json.libraries.find((l) => l.path === '/Audiobooks')
  assert.ok(lib.found); assert.equal(lib.books, 4); assert.ok(lib.duration > 300)
})
await check('PUT folders: own list, missing folder flagged; empty list refused; DELETE resets', async () => {
  let r = await call(ADMIN, 'PUT', '/settings/folders', { folders: ['/Audiobooks', 'Nope/', '../etc', '/'] })
  assert.equal(r.status, 200)
  assert.deepEqual(r.json.folders, ['/Audiobooks', '/Nope'])
  assert.equal(r.json.usesDefault, false)
  assert.equal(r.json.libraries.find((l) => l.path === '/Nope').found, false)
  assert.equal((await call(ADMIN, 'PUT', '/settings/folders', { folders: [] })).status, 400)
  r = await call(ADMIN, 'DELETE', '/settings/folders')
  assert.equal(r.json.usesDefault, true)
  assert.deepEqual(r.json.folders, ['/Audiobooks'])
})
await check('rescan returns complete + state', async () => {
  const r = await call(ADMIN, 'POST', '/settings/rescan')
  assert.equal(r.status, 200); assert.equal(r.json.complete, true); assert.equal(r.json.items, 4)
  assert.ok(r.json.state.lastScan > 0)
})
await check('admin default folders apply to users without their own list', async () => {
  let r = await call(ADMIN, 'PUT', '/settings/admin', { defaultFolders: ['/Books', '/Audiobooks'] })
  assert.deepEqual(r.json.defaultFolders, ['/Books', '/Audiobooks'])
  r = await call(PLAIN, 'GET', '/settings')
  assert.deepEqual(r.json.folders, ['/Books', '/Audiobooks'])
  assert.equal(r.json.usesDefault, true)
  r = await call(ADMIN, 'PUT', '/settings/admin', { defaultFolders: [] })
  assert.deepEqual(r.json.defaultFolders, ['/Audiobooks'])
})
await check('non-admin cannot change the admin default', async () => {
  const r = await call(PLAIN, 'PUT', '/settings/admin', { defaultFolders: ['/x'] })
  assert.ok(r.status === 403 || r.status === 401, String(r.status))
})
await check('sign out all devices revokes logins but keeps API keys', async () => {
  const login = await fetch(base + '/login', { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ username: env.NC_PLAIN_USER, password: env.NC_PLAIN_PASSWORD }) })
  const access = (await login.json()).user.accessToken
  const key = (await call(PLAIN, 'POST', '/settings/apikeys', { name: 'k' })).json.token
  const me = (t) => fetch(base + '/api/me', { headers: { authorization: `Bearer ${t}` } }).then((r) => r.status)
  assert.equal(await me(access), 200); assert.equal(await me(key), 200)
  assert.equal((await call(PLAIN, 'DELETE', '/settings/devices')).status, 200)
  assert.equal(await me(access), 401); assert.equal(await me(key), 200)
  const s = await call(PLAIN, 'GET', '/settings')
  for (const tok of s.json.tokens) await call(PLAIN, 'DELETE', `/settings/tokens/${tok.id}`)
  assert.equal(await me(key), 401)
})
await check('a user cannot revoke another user\'s key', async () => {
  const s = await call(ADMIN, 'GET', '/settings')
  const id = s.json.tokens[0]?.id
  if (id === undefined) return
  assert.equal((await call(PLAIN, 'DELETE', `/settings/tokens/${id}`)).status, 404)
})
console.log(fails ? `\n${fails} failed` : '\nall passed')
process.exit(fails ? 1 : 0)
