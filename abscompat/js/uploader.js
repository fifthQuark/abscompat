/* Audiobookshelf Minus: uploads one file into the user's files over WebDAV, in chunks so that
 * large audiobooks are not limited by PHP's upload size. */
(function () {
	'use strict'

	const CHUNK = 10 * 1024 * 1024
	const RETRIES = 3

	const enc = (path) => path.split('/').map(encodeURIComponent).join('/')
	const dav = () => OC.linkToRemote('dav')
	const uid = () => encodeURIComponent(OC.getCurrentUser().uid)
	const fileUrl = (path) => dav() + '/files/' + uid() + enc(path)
	const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

	class UploadError extends Error {
		constructor(message, status) {
			super(message)
			this.status = status
		}
	}

	function request(method, url, { headers = {}, body = null, signal, onProgress } = {}) {
		return new Promise((resolve, reject) => {
			const xhr = new XMLHttpRequest()
			xhr.open(method, url)
			xhr.setRequestHeader('requesttoken', OC.requestToken)
			for (const k of Object.keys(headers)) xhr.setRequestHeader(k, headers[k])
			if (onProgress) xhr.upload.onprogress = (e) => onProgress(e.loaded)
			const abort = () => xhr.abort()
			if (signal) {
				if (signal.aborted) return reject(new DOMException('Cancelled', 'AbortError'))
				signal.addEventListener('abort', abort)
			}
			const done = () => signal && signal.removeEventListener('abort', abort)
			xhr.onload = () => { done(); resolve(xhr) }
			xhr.onerror = () => { done(); reject(new UploadError('Connection lost', 0)) }
			xhr.onabort = () => { done(); reject(new DOMException('Cancelled', 'AbortError')) }
			xhr.send(body)
		})
	}

	const ok = (xhr) => xhr.status >= 200 && xhr.status < 300
	function fail(xhr) {
		const m = /<s:message>([^<]*)<\/s:message>/.exec(xhr.responseText || '')
		const el = document.createElement('textarea')
		el.innerHTML = m ? m[1] : ''
		return new UploadError(el.value || (xhr.status === 507 ? 'Not enough free space' : 'Upload failed (' + xhr.status + ')'), xhr.status)
	}
	const passing = (status) => status === 0 || status === 423 || status === 429 || status >= 500 && status !== 507

	/** Repeat on lost connections and busy servers; the same chunk can safely be sent again. */
	async function retry(fn, signal) {
		for (let i = 0; ; i++) {
			let err
			try {
				const xhr = await fn()
				if (ok(xhr)) return xhr
				err = fail(xhr)
			} catch (e) {
				if (e.name === 'AbortError') throw e
				err = e
			}
			if (i >= RETRIES || !passing(err.status) || (signal && signal.aborted)) throw err
			await wait(1000 * 2 ** i)
		}
	}

	/** Drop the stored chunks. The server may still be closing an interrupted one, so try again. */
	async function discard(tmp) {
		for (let i = 0; i < 4; i++) {
			await wait(i ? 2000 : 300)
			const xhr = await request('DELETE', tmp).catch(() => null)
			if (xhr && (ok(xhr) || xhr.status === 404)) return
		}
	}

	async function remoteSize(path) {
		const xhr = await request('HEAD', fileUrl(path))
		return ok(xhr) ? Number(xhr.getResponseHeader('Content-Length')) : -1
	}

	/**
	 * @param {Blob} file
	 * @param {string} path absolute path in the user's files; its folder exists
	 * @param {{signal?: AbortSignal, onProgress?: (bytes: number) => void}} opts
	 */
	async function upload(file, path, { signal, onProgress = () => {} } = {}) {
		const headers = { 'Content-Type': 'application/octet-stream' }
		if (file.lastModified) headers['X-OC-Mtime'] = String(Math.floor(file.lastModified / 1000))

		if (file.size <= CHUNK) {
			await retry(() => request('PUT', fileUrl(path), { headers, body: file, signal, onProgress }), signal)
			onProgress(file.size)
			return
		}

		const id = 'abscompat-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10)
		const tmp = dav() + '/uploads/' + uid() + '/' + id
		const target = { Destination: new URL(fileUrl(path), window.location.href).href, 'OC-Total-Length': String(file.size) }
		await retry(() => request('MKCOL', tmp, { headers: target, signal }), signal)
		try {
			const count = Math.ceil(file.size / CHUNK)
			for (let n = 0; n < count; n++) {
				const start = n * CHUNK
				const body = file.slice(start, Math.min(file.size, start + CHUNK))
				await retry(() => request('PUT', tmp + '/' + (n + 1), {
					headers: { ...target, 'Content-Type': 'application/octet-stream' },
					body,
					signal,
					onProgress: (b) => onProgress(start + b),
				}), signal)
				onProgress(start + body.size)
			}
			const move = { ...target, Overwrite: 'F' }
			if (headers['X-OC-Mtime']) move['X-OC-Mtime'] = headers['X-OC-Mtime']
			const xhr = await request('MOVE', tmp + '/.file', { headers: move, signal }).catch((e) => {
				if (e.name === 'AbortError') throw e
				return { status: 0, responseText: '' }
			})
			if (!ok(xhr)) {
				// Joining a big file can outlast a proxy's time limit while the server carries on.
				if (![0, 502, 503, 504, 524].includes(xhr.status)) throw fail(xhr)
				for (let i = 0; ; i++) {
					await wait(5000)
					if (signal && signal.aborted) throw new DOMException('Cancelled', 'AbortError')
					if (await remoteSize(path).catch(() => -1) === file.size) break
					if (i >= 24) throw fail(xhr)
				}
			}
		} catch (e) {
			discard(tmp)
			throw e
		}
	}

	async function remove(path) {
		const xhr = await request('DELETE', fileUrl(path))
		if (!ok(xhr) && xhr.status !== 404) throw fail(xhr)
	}

	window.AbsUploader = { upload, remove }
})()
