/* Audiobookshelf Compatible: personal settings (plain JS, no build step). */
(function () {
	'use strict'

	const APP = 'abscompat'
	const t = (s, vars) => (window.t ? window.t(APP, s, vars) : s.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)))
	const n = (s, p, count, vars) => (window.n ? window.n(APP, s, p, count, vars) : (count === 1 ? s : p).replace('%n', count))
	const url = (path) => OC.generateUrl('/apps/' + APP + path)
	const $ = (sel) => document.querySelector(sel)

	function toast(msg, error) {
		if (window.OCP && OCP.Toast) {
			error ? OCP.Toast.error(msg) : OCP.Toast.success(msg)
		} else {
			window.alert(msg)
		}
	}

	async function request(method, path, body) {
		const res = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body ? JSON.stringify(body) : undefined,
		})
		const data = await res.json().catch(() => ({}))
		return { res, data }
	}

	/** API call; asks for the password once when the server requires a fresh confirmation. */
	async function api(method, path, body) {
		let { res, data } = await request(method, path, body)
		if (res.status === 403 && /password confirmation/i.test(data.message || '') && OC.PasswordConfirmation) {
			await new Promise((resolve, reject) => OC.PasswordConfirmation.requirePasswordConfirmation(resolve, {}, reject))
			;({ res, data } = await request(method, path, body))
		}
		if (!res.ok) {
			throw new Error(data.error || data.message || res.statusText)
		}
		return data
	}

	function fmtDate(sec) {
		return sec ? new Date(sec * 1000).toLocaleString() : '–'
	}

	function fmtDuration(seconds) {
		const h = Math.floor(seconds / 3600)
		const m = Math.round((seconds % 3600) / 60)
		return h ? t('{h} h {m} min', { h, m }) : t('{m} min', { m })
	}

	function fmtSize(bytes) {
		return OC.Util && OC.Util.humanFileSize ? OC.Util.humanFileSize(bytes) : Math.round(bytes / 1048576) + ' MB'
	}

	let state = null

	function el(tag, cls, text) {
		const e = document.createElement(tag)
		if (cls) e.className = cls
		if (text !== undefined) e.textContent = text
		return e
	}

	function renderLibraries() {
		const list = $('#abscompat-libraries')
		list.textContent = ''
		for (const lib of state.libraries) {
			const li = el('li')
			li.appendChild(el('span', 'abscompat-path', lib.path))
			if (lib.found) {
				const meta = [n('%n book', '%n books', lib.books)]
				if (lib.books) meta.push(fmtDuration(lib.duration), fmtSize(lib.size))
				li.appendChild(el('span', 'abscompat-meta', meta.join(' · ')))
			} else {
				li.appendChild(el('span', 'abscompat-meta abscompat-missing', t('Folder not found')))
			}
			const rm = el('button', 'button', t('Remove'))
			rm.type = 'button'
			rm.setAttribute('aria-label', t('Remove {folder}', { folder: lib.path }))
			rm.disabled = state.folders.length <= 1
			rm.title = rm.disabled ? t('At least one folder is needed') : ''
			rm.addEventListener('click', () => saveFolders(state.folders.filter((f) => f !== lib.path)))
			li.appendChild(rm)
			list.appendChild(li)
		}
		const note = $('#abscompat-default-note')
		note.hidden = !state.usesDefault
		note.textContent = state.usesDefault ? t('Using the default folders of this Nextcloud. Adding or removing a folder switches to your own list.') : ''
		$('#abscompat-reset-folders').hidden = state.usesDefault
		setStatus(state.lastScan ? t('Last scan: {time}', { time: fmtDate(state.lastScan) }) : '')
	}

	function renderTokens() {
		const list = state.tokens
		const tbody = $('#abscompat-tokens tbody')
		tbody.textContent = ''
		$('#abscompat-no-tokens').hidden = list.length > 0
		$('#abscompat-tokens').hidden = list.length === 0
		$('#abscompat-revoke-devices').hidden = !list.some((x) => x.kind === 'device')
		for (const tok of list) {
			const tr = el('tr')
			for (const c of [tok.name, tok.kind === 'apikey' ? t('API key') : t('Device'), fmtDate(tok.createdAt), fmtDate(tok.lastUsed)]) {
				tr.appendChild(el('td', '', c))
			}
			const td = el('td')
			const btn = el('button', 'button', tok.kind === 'apikey' ? t('Delete') : t('Sign out'))
			btn.type = 'button'
			btn.setAttribute('aria-label', (tok.kind === 'apikey' ? t('Delete') : t('Sign out')) + ' ' + tok.name)
			btn.addEventListener('click', () => {
				OC.dialogs.confirm(
					tok.kind === 'apikey'
						? t('Delete the API key "{name}"? Services using it stop syncing.', { name: tok.name })
						: t('Sign out "{name}"? The app needs to log in again.', { name: tok.name }),
					t('Audiobookshelf'),
					async (ok) => {
						if (!ok) return
						btn.disabled = true
						try {
							await api('DELETE', '/settings/tokens/' + tok.id)
							$('#abscompat-new-key').hidden = true
							await load()
						} catch (e) {
							toast(e.message, true)
							btn.disabled = false
						}
					},
					true,
				)
			})
			td.appendChild(btn)
			tr.appendChild(td)
			tbody.appendChild(tr)
		}
	}

	function setStatus(msg) {
		$('#abscompat-scan-status').textContent = msg
	}

	async function load() {
		state = await api('GET', '/settings')
		renderLibraries()
		renderTokens()
	}

	let scanning = false
	async function rescan() {
		if (scanning) return
		scanning = true
		const btn = $('#abscompat-rescan')
		btn.disabled = true
		try {
			// Each call scans for a limited time; unchanged books are skipped, so repeat until done.
			for (let i = 0; i < 100; i++) {
				const r = await api('POST', '/settings/rescan')
				state = r.state
				renderLibraries()
				setStatus(r.complete ? n('Scan finished: %n book', 'Scan finished: %n books', r.items) : t('Scanning… {n} books so far', { n: r.items }))
				if (r.complete) break
			}
		} catch (e) {
			setStatus('')
			toast(e.message, true)
		}
		btn.disabled = false
		scanning = false
	}

	async function saveFolders(folders) {
		try {
			state = await api('PUT', '/settings/folders', { folders })
			renderLibraries()
			await rescan()
		} catch (e) {
			toast(e.message, true)
		}
	}

	function copy(selector, button) {
		const text = $(selector).textContent
		const done = () => {
			const old = button.textContent
			button.textContent = t('Copied')
			setTimeout(() => { button.textContent = old }, 1500)
		}
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, () => toast(t('Could not copy'), true))
		} else {
			const range = document.createRange()
			range.selectNodeContents($(selector))
			const sel = window.getSelection()
			sel.removeAllRanges()
			sel.addRange(range)
			toast(t('Press Ctrl+C to copy'))
		}
	}

	document.addEventListener('DOMContentLoaded', () => {
		let serverUrl = ''
		try {
			serverUrl = OCP.InitialState.loadState(APP, 'server-url')
		} catch (e) {
			serverUrl = window.location.origin + OC.generateUrl('/apps/' + APP)
		}
		$('#abscompat-server-url').textContent = serverUrl

		document.querySelectorAll('#abscompat-settings [data-copy]').forEach((b) => {
			b.addEventListener('click', () => copy(b.dataset.copy, b))
		})

		$('#abscompat-add-folder').addEventListener('click', () => {
			OC.dialogs.filepicker(t('Choose an audiobook folder'), (path) => {
				if (!path || path === '/') {
					toast(t('Choose a folder, not your whole file list'), true)
					return
				}
				if (state.folders.includes(path)) return
				saveFolders([...state.folders, path])
			}, false, 'httpd/unix-directory', true, OC.dialogs.FILEPICKER_TYPE_CHOOSE)
		})

		$('#abscompat-reset-folders').addEventListener('click', async () => {
			try {
				state = await api('DELETE', '/settings/folders')
				renderLibraries()
				await rescan()
			} catch (e) {
				toast(e.message, true)
			}
		})

		$('#abscompat-rescan').addEventListener('click', rescan)

		$('#abscompat-key-form').addEventListener('submit', async (ev) => {
			ev.preventDefault()
			const input = $('#abscompat-key-name')
			const name = input.value.trim() || t('API key')
			try {
				const r = await api('POST', '/settings/apikeys', { name })
				$('#abscompat-new-key-value').textContent = r.token
				$('#abscompat-new-key').hidden = false
				input.value = ''
				await load()
			} catch (e) {
				if (e && e.message) toast(e.message, true)
			}
		})

		$('#abscompat-revoke-devices').addEventListener('click', () => {
			OC.dialogs.confirm(t('Sign out all devices? Apps need to log in again. API keys keep working.'), t('Audiobookshelf'), async (ok) => {
				if (!ok) return
				try {
					await api('DELETE', '/settings/devices')
					await load()
					toast(t('All devices signed out'))
				} catch (e) {
					toast(e.message, true)
				}
			}, true)
		})

		load().catch((e) => toast(e.message, true))
	})
})()
