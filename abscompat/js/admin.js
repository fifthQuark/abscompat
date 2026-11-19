/* Audiobookshelf Compatible: admin settings (plain JS, no build step). */
(function () {
	'use strict'

	const APP = 'abscompat'
	const t = (s) => (window.t ? window.t(APP, s) : s)
	const url = OC.generateUrl('/apps/' + APP + '/settings/admin')

	async function call(method, body) {
		const res = await fetch(url, {
			method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: body ? JSON.stringify(body) : undefined,
		})
		const data = await res.json().catch(() => ({}))
		if (!res.ok) throw new Error(data.error || data.message || res.statusText)
		return data
	}

	document.addEventListener('DOMContentLoaded', () => {
		const area = document.getElementById('abscompat-default-folders')
		const status = document.getElementById('abscompat-admin-status')
		call('GET').then((d) => { area.value = d.defaultFolders.join('\n') }).catch((e) => { status.textContent = e.message })
		document.getElementById('abscompat-save-defaults').addEventListener('click', async () => {
			try {
				const d = await call('PUT', { defaultFolders: area.value.split('\n').map((s) => s.trim()).filter(Boolean) })
				area.value = d.defaultFolders.join('\n')
				status.textContent = t('Saved')
			} catch (e) {
				status.textContent = e.message
			}
		})
	})
})()
