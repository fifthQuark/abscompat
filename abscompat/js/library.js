/* Audiobookshelf Minus: library page and upload form (plain JS, no build step). */
(function () {
	'use strict'

	const APP = 'abscompat'
	const t = (s, vars) => (window.t ? window.t(APP, s, vars, undefined, { escape: false }) : s.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)))
	const n = (s, p, count) => (window.n ? window.n(APP, s, p, count) : (count === 1 ? s : p).replace('%n', count))
	const url = (path) => OC.generateUrl('/apps/' + APP + path)
	const toast = (msg, error) => (window.OCP && OCP.Toast ? (error ? OCP.Toast.error(msg) : OCP.Toast.success(msg)) : window.alert(msg))
	const natural = (a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' })

	async function api(method, path, body) {
		const res = await fetch(url(path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body ? JSON.stringify(body) : undefined,
		})
		const data = await res.json().catch(() => ({}))
		if (!res.ok) {
			const e = new Error(data.error || data.message || t('The server did not answer ({status})', { status: res.status }))
			e.code = data.code
			e.data = data
			throw e
		}
		return data
	}

	/** h('div.cls', {attrs, onclick}, children...) */
	const kids = (list) => list.flat(3).filter((c) => c !== null && c !== undefined && c !== false && c !== '')
	function h(tag, attrs, ...children) {
		const [name, ...cls] = tag.split('.')
		const e = document.createElement(name)
		if (cls.length) e.className = cls.join(' ')
		for (const [k, v] of Object.entries(attrs || {})) {
			if (v === null || v === undefined || v === false) continue
			if (k.startsWith('on')) e.addEventListener(k.slice(2), v)
			else if (k in e && typeof v !== 'string') e[k] = v
			else e.setAttribute(k, v)
		}
		e.append(...kids(children))
		return e
	}

	function fmtDuration(sec) {
		const min = Math.round(sec / 60)
		const hrs = Math.floor(min / 60)
		return hrs ? t('{h} h {m} min', { h: hrs, m: min % 60 }) : t('{m} min', { m: min })
	}
	function fmtClock(sec) {
		const s = Math.floor(sec)
		const p = (x) => String(x).padStart(2, '0')
		return (s >= 3600 ? Math.floor(s / 3600) + ':' : '') + p(Math.floor(s / 60) % 60) + ':' + p(s % 60)
	}
	function fmtSize(b) {
		if (b >= 1e9) return (b / 1e9).toFixed(1) + ' GB'
		return b >= 1e6 ? Math.round(b / 1e6) + ' MB' : Math.max(1, Math.round(b / 1e3)) + ' KB'
	}

	let data = { libraries: [], books: [] }
	let loaded = false
	let view = 'home'
	const ui = { search: '', sort: 'title', filter: 'all', library: '' }
	try {
		Object.assign(ui, JSON.parse(localStorage.getItem('abscompat-view') || '{}'), { search: '' })
	} catch (e) { /* storage unavailable */ }
	const remember = () => {
		try {
			localStorage.setItem('abscompat-view', JSON.stringify({ sort: ui.sort, filter: ui.filter, library: ui.library }))
		} catch (e) { /* storage unavailable */ }
	}

	// ----- books -----

	const status = (b) => (b.progress && b.progress.isFinished ? 'finished' : b.progress && b.progress.progress > 0 ? 'progress' : 'new')
	const seqNum = (b) => (b.seriesSeq !== null && b.seriesSeq !== '' && !isNaN(parseFloat(b.seriesSeq)) ? parseFloat(b.seriesSeq) : Infinity)
	const byTitle = (a, b) => natural(a.sortTitle, b.sortTitle)
	const bySeries = (a, b) => natural(a.series || '￿', b.series || '￿') || seqNum(a) - seqNum(b) || byTitle(a, b)
	const inSeries = (a, b) => (seqNum(a) === seqNum(b) ? byTitle(a, b) : seqNum(a) - seqNum(b))
	const SORTS = {
		title: [t('Title'), byTitle],
		author: [t('Author'), (a, b) => natural(a.author || '￿', b.author || '￿') || bySeries(a, b)],
		added: [t('Recently added'), (a, b) => b.addedAt - a.addedAt || byTitle(a, b)],
		listened: [t('Recently listened'), (a, b) => (b.progress ? b.progress.lastUpdate : 0) - (a.progress ? a.progress.lastUpdate : 0) || byTitle(a, b)],
		longest: [t('Longest'), (a, b) => b.duration - a.duration || byTitle(a, b)],
		shortest: [t('Shortest'), (a, b) => a.duration - b.duration || byTitle(a, b)],
	}

	function visibleBooks() {
		const words = ui.search.toLocaleLowerCase().split(/\s+/).filter(Boolean)
		return data.books.filter((b) => {
			if (ui.library && data.libraries.some((l) => l.id === ui.library) && b.libraryId !== ui.library) return false
			if (!words.length) return true
			const text = [b.title, b.subtitle, b.author, b.series, b.narrator].filter(Boolean).join(' ').toLocaleLowerCase()
			return words.every((w) => text.includes(w))
		})
	}

	function placeholder(book) {
		let hash = 0
		for (const c of book.title) hash = (hash * 31 + c.codePointAt(0)) % 360
		const e = h('span.abs-cover-text', { 'aria-hidden': 'true' }, h('span', { dir: 'auto' }, book.title))
		e.style.background = 'hsl(' + hash + ' 40% 32%)'
		return e
	}
	function cover(book) {
		const box = h('span.abs-cover')
		if (!book.hasCover) {
			box.append(placeholder(book))
			return box
		}
		box.append(h('img', {
			src: url('/web/items/' + book.id + '/cover') + '?v=' + book.updatedAt,
			alt: '',
			loading: 'lazy',
			onerror: () => box.replaceChildren(placeholder(book)),
		}))
		return box
	}
	function progressBar(value) {
		const fill = h('span.abs-bar-fill')
		fill.style.width = Math.round(value * 100) + '%'
		return h('span.abs-bar', { 'aria-hidden': 'true' }, fill)
	}

	function card(book, opts) {
		const st = status(book)
		const pct = st === 'progress' ? Math.max(1, Math.round(book.progress.progress * 100)) : 0
		const label = [book.title, book.author, st === 'finished' ? t('Finished') : pct ? t('{pct}% listened', { pct }) : ''].filter(Boolean).join(', ')
		const second = opts && opts.inSeries ? (book.seriesSeq ? t('Book {n}', { n: book.seriesSeq }) : '') : book.author
		return h('a.abs-card', { href: '#/book/' + book.id, 'aria-label': label },
			h('span.abs-cover-wrap', null, cover(book),
				st === 'finished' && h('span.abs-badge', { title: t('Finished') }, '✓'),
				st === 'progress' && progressBar(book.progress.progress)),
			h('span.abs-card-title', { dir: 'auto' }, book.title),
			second && h('span.abs-card-sub', { dir: 'auto' }, second),
			!(opts && opts.inSeries) && book.series && h('span.abs-card-sub', { dir: 'auto' }, book.series + (book.seriesSeq ? ' #' + book.seriesSeq : '')),
		)
	}
	const grid = (books, opts) => h('div.abs-grid', null, books.map((b) => card(b, opts)))
	function section(title, books, opts) {
		return h('section.abs-section', null,
			h('h3', null, h('span', { dir: 'auto' }, title), h('span.abs-count', null, n('%n book', '%n books', books.length))),
			opts && opts.row ? h('div.abs-row', null, books.map((b) => card(b))) : grid(books, opts))
	}

	// ----- views -----

	const VIEWS = { home: t('Home'), books: t('All books'), authors: t('Authors'), series: t('Series') }

	function renderHome(books) {
		const out = []
		const cont = books.filter((b) => status(b) === 'progress' && !b.progress.hidden).sort(SORTS.listened[1]).slice(0, 20)
		if (cont.length) out.push(section(t('Continue listening'), cont, { row: true }))
		const added = [...books].sort(SORTS.added[1]).slice(0, 20)
		out.push(section(t('Recently added'), added, { row: true }))
		const unread = books.filter((b) => status(b) === 'new').sort(byTitle)
		if (unread.length) out.push(section(t('Not started'), unread))
		const done = books.filter((b) => status(b) === 'finished').sort(SORTS.listened[1])
		if (done.length) out.push(section(t('Finished'), done))
		return out
	}

	function select(label, options, value, onchange) {
		return h('label.abs-select', null, h('span', null, label),
			h('select', { onchange: (e) => onchange(e.target.value) },
				options.map(([v, text]) => h('option', { value: v, selected: v === value }, text))))
	}

	function renderBooks(books) {
		const shown = books.filter((b) => ui.filter === 'all' || status(b) === ui.filter).sort((SORTS[ui.sort] || SORTS.title)[1])
		const change = (k) => (v) => {
			ui[k] = v
			remember()
			render()
		}
		return [
			h('div.abs-tools', null,
				select(t('Sort'), Object.entries(SORTS).map(([k, v]) => [k, v[0]]), ui.sort, change('sort')),
				select(t('Show'), [['all', t('All')], ['new', t('Not started')], ['progress', t('In progress')], ['finished', t('Finished')]], ui.filter, change('filter')),
				h('span.abs-count', null, n('%n book', '%n books', shown.length))),
			shown.length ? grid(shown) : h('p.abs-none', null, t('No books here.')),
		]
	}

	function renderGroups(books, names, none, sort, opts) {
		const groups = new Map()
		const rest = []
		for (const b of books) {
			const list = names(b)
			if (!list.length) rest.push(b)
			for (const name of list) {
				const key = name.toLocaleLowerCase()
				if (!groups.has(key)) groups.set(key, { name, books: [] })
				groups.get(key).books.push(b)
			}
		}
		const out = [...groups.values()].sort((a, b) => natural(a.name, b.name)).map((g) => section(g.name, g.books.sort(sort), opts))
		if (rest.length && none) out.push(section(none, rest.sort(byTitle)))
		return out.length ? out : [h('p.abs-none', null, t('No books here.'))]
	}

	function empty() {
		const lib = data.libraries[0]
		return [h('div.abs-empty', null,
			h('h3', null, t('No audiobooks yet')),
			h('p', null, t('Drop audiobooks anywhere on this page, or choose them with the button below.')),
			lib && h('p.abs-muted', null, t('They are stored in {folder} in your files.', { folder: lib.path })),
			h('button.primary', { type: 'button', onclick: () => Upload.open() }, t('Upload audiobooks')))]
	}

	let root, main, tabs, libSelect

	function shell() {
		tabs = h('nav.abs-tabs', { 'aria-label': t('Views') }, Object.entries(VIEWS).map(([k, label]) => h('a', { href: '#/' + k, 'data-view': k }, label)))
		libSelect = h('span')
		main = h('div.abs-main')
		let timer
		root.replaceChildren(
			h('header.abs-head', null,
				h('h2', null, t('Audiobooks')),
				h('input.abs-search', {
					type: 'search',
					placeholder: t('Search title, author, series'),
					'aria-label': t('Search'),
					oninput: (e) => {
						clearTimeout(timer)
						timer = setTimeout(() => {
							ui.search = e.target.value
							render()
						}, 150)
					},
				}),
				libSelect,
				h('button.abs-rescan', { type: 'button', onclick: (e) => rescan(e.currentTarget) }, t('Rescan')),
				h('button.primary', { type: 'button', onclick: () => Upload.open() }, t('Upload'))),
			tabs, main,
			h('div.abs-dropmask', { 'aria-hidden': 'true' }, h('span', null, t('Drop to add audiobooks'))))
	}

	function render() {
		for (const a of tabs.children) {
			if (a.dataset.view === view) a.setAttribute('aria-current', 'page')
			else a.removeAttribute('aria-current')
		}
		const libs = data.libraries.filter((l) => l.id)
		libSelect.replaceChildren(...(libs.length > 1
			? [select(t('Library'), [['', t('All')], ...libs.map((l) => [l.id, libs.filter((x) => x.name === l.name).length > 1 ? l.path : l.name])], ui.library, (v) => {
				ui.library = v
				remember()
				render()
			})]
			: []))
		if (!loaded) return main.replaceChildren(h('p.abs-none', null, t('Loading…')))
		if (!data.books.length) return main.replaceChildren(...empty())
		const books = visibleBooks()
		if (!books.length) return main.replaceChildren(h('p.abs-none', null, ui.search ? t('Nothing matches your search.') : t('No books here.')))
		const out = view === 'books' || (view === 'home' && ui.search) ? renderBooks(books)
			: view === 'authors' ? renderGroups(books, (b) => b.authors, t('Unknown author'), bySeries)
				: view === 'series' ? renderGroups(books, (b) => (b.series ? [b.series] : []), null, inSeries, { inSeries: true })
					: renderHome(books)
		main.replaceChildren(...out)
	}

	async function load() {
		data = await api('GET', '/web/library')
		loaded = true
		render()
	}

	async function rescan(button) {
		button.disabled = true
		try {
			// Each call scans for a limited time; unchanged books are skipped, so repeat until done.
			for (let i = 0; i < 100; i++) {
				const r = await api('POST', '/settings/rescan')
				if (r.complete) break
			}
			await load()
			toast(n('Scan finished: %n book', 'Scan finished: %n books', data.books.length))
		} catch (e) {
			toast(e.message, true)
		}
		button.disabled = false
	}

	// ----- one book -----

	function dialog(cls, label, onclose) {
		const d = h('dialog.abs-dialog.' + cls, { 'aria-label': label })
		root.append(d)
		let gone = false
		const finish = () => {
			if (gone) return
			gone = true
			d.remove()
			onclose()
		}
		// Browsers delay the close event in background tabs, so shut() doesn't wait for it.
		d.addEventListener('close', finish)
		d.shut = () => {
			d.close()
			finish()
		}
		// A click on the backdrop has the dialog itself as target.
		d.addEventListener('mousedown', (e) => {
			if (e.target === d && d.dataset.backdrop !== 'off') d.shut()
		})
		return d
	}
	const closeButton = (onclick) => h('button.abs-close', { type: 'button', 'aria-label': t('Close'), onclick }, '✕')

	let bookDialog = null

	async function showBook(id) {
		if (bookDialog) bookDialog.shut()
		const d = bookDialog = dialog('abs-book', t('Book'), () => {
			if (bookDialog === d) bookDialog = null
			if (location.hash === '#/book/' + id) history.replaceState(null, '', '#/' + view)
		})
		d.append(closeButton(() => d.shut()), h('p.abs-none', null, t('Loading…')))
		d.showModal()
		let b
		try {
			b = await api('GET', '/web/items/' + encodeURIComponent(id))
		} catch (e) {
			d.shut()
			return toast(e.message, true)
		}
		if (!d.isConnected) return
		const st = status(b)
		const line = (label, value) => value && h('div', null, h('dt', null, label), h('dd', { dir: 'auto' }, value))
		const left = st === 'progress' ? Math.max(0, b.duration - b.progress.currentTime) : 0
		d.setAttribute('aria-label', b.title)
		d.replaceChildren(...kids([
			closeButton(() => d.shut()),
			h('div.abs-book-top', null,
				h('span.abs-cover-wrap', null, cover(b)),
				h('div.abs-book-info', null,
					h('h3', { dir: 'auto' }, b.title),
					b.subtitle && h('p.abs-muted', { dir: 'auto' }, b.subtitle),
					h('dl', null,
						line(t('Author'), b.author),
						line(t('Series'), b.series && b.series + (b.seriesSeq ? ' #' + b.seriesSeq : '')),
						line(t('Narrator'), b.narrator),
						line(t('Year'), b.year),
						line(t('Genre'), b.genres.join(', ')),
						line(t('Length'), fmtDuration(b.duration) + ' · ' + fmtSize(b.size)),
						line(t('Ebook'), b.hasEbook && t('Included'))),
					st === 'finished' ? h('p', null, t('Finished'))
						: st === 'progress' ? h('div.abs-book-progress', null, progressBar(b.progress.progress),
							h('span', null, t('{pct}% listened, {left} left', { pct: Math.round(b.progress.progress * 100), left: fmtDuration(left) })))
							: h('p.abs-muted', null, t('Not started')),
					h('p', null, h('a.button', { href: OC.generateUrl('/f/' + b.id) }, t('Show in Files'))))),
			b.description && h('p.abs-desc', { dir: 'auto' }, b.description),
			b.chapters.length > 1 && h('details', null, h('summary', null, n('%n chapter', '%n chapters', b.chapters.length)),
				h('ol.abs-list', null, b.chapters.map((c) => h('li', null, h('span', { dir: 'auto' }, c.title), h('span.abs-muted', null, fmtClock(c.start)))))),
			h('details', null, h('summary', null, n('%n audio file', '%n audio files', b.files.length)),
				h('ul.abs-list', null, b.files.map((f) => h('li', null, h('span', { dir: 'auto' }, f.name), h('span.abs-muted', null, fmtClock(f.duration) + ' · ' + fmtSize(f.size)))))),
		]))
	}

	// ----- upload -----

	const Upload = (function () {
		let d = null
		let drafts = []
		let list, footer, drop
		let libraryPath = ''
		let controller = null
		let reading = 0
		let nextId = 1

		const writable = () => data.libraries.filter((l) => l.writable)
		const open = () => {
			if (!d) build()
			if (!d.open) d.showModal()
		}

		function build() {
			drafts = []
			const libs = writable()
			if (!libs.some((l) => l.path === libraryPath)) {
				libraryPath = ((ui.library && libs.find((l) => l.id === ui.library)) || libs[0] || { path: '' }).path
			}
			d = dialog('abs-upload', t('Upload audiobooks'), () => {
				d = null
				drafts.forEach(release)
				drafts = []
			})
			d.dataset.backdrop = 'off'
			d.addEventListener('cancel', (e) => {
				e.preventDefault()
				close()
			})
			const pick = (folder) => {
				const input = h('input', { type: 'file', multiple: true })
				if (folder) input.webkitdirectory = true
				else input.accept = [...AbsTags.AUDIO, ...AbsTags.EBOOK, ...AbsTags.IMAGE].map((x) => '.' + x).join(',')
				input.addEventListener('change', () => add([...input.files].map((f) => ({ path: f.webkitRelativePath || f.name, file: f }))))
				input.click()
			}
			drop = h('div.abs-drop', null,
				h('p', null, t('Drop audiobook files or folders here')),
				h('div.abs-drop-buttons', null,
					h('button', { type: 'button', onclick: () => pick(false) }, t('Choose files')),
					h('button', { type: 'button', onclick: () => pick(true) }, t('Choose folder'))),
				h('p.abs-muted', null, t('Each folder becomes one book. Title, author, series and cover are filled in from the files; check them before uploading.')))
			list = h('div.abs-drafts')
			footer = h('div.abs-foot')
			d.append(closeButton(close), h('h3', null, t('Upload audiobooks')))
			if (!libs.length) {
				d.append(h('p', null, t('None of your library folders can be written to.')), footer)
				footer.append(h('button', { type: 'button', onclick: close }, t('Close')))
				return
			}
			if (libs.length > 1) {
				d.append(select(t('Library'), libs.map((l) => [l.path, l.path]), libraryPath, (v) => {
					libraryPath = v
					drafts.forEach((x) => x.state === 'edit' && check(x))
				}))
			}
			d.append(drop, h('datalist', { id: 'abs-authors' }, [...new Set(data.books.flatMap((b) => b.authors))].sort(natural).map((x) => h('option', { value: x }))),
				h('datalist', { id: 'abs-series' }, [...new Set(data.books.map((b) => b.series).filter(Boolean))].sort(natural).map((x) => h('option', { value: x }))),
				list, footer)
			update()
		}

		function close() {
			if (controller) return toast(t('Cancel the upload first.'), true)
			if (drafts.some((x) => x.state === 'failed')) return toast(t('An upload did not finish. Retry or remove it first.'), true)
			if (d) d.shut()
		}

		function release(x) {
			if (x.coverUrl) URL.revokeObjectURL(x.coverUrl)
			x.coverUrl = null
		}

		async function add(entries) {
			open()
			if (!entries.length || !writable().length) return
			const me = d
			reading++
			update()
			let books = []
			try {
				books = await AbsTags.group(entries)
			} finally {
				reading--
			}
			if (d !== me || !d) return
			if (!books.length) toast(t('No audio files found.'), true)
			for (const b of books) {
				const x = { id: nextId++, state: 'edit', book: b, done: new Set(), started: new Set(), message: '', check: null }
				drafts.push(x)
				setCover(x, b.image ? b.image.file : null, b.image ? b.image.path : '')
				list.append(buildCard(x))
				check(x)
			}
			update()
		}

		/** A chosen image is uploaded as cover.*; art inside the audio is only shown. */
		function setCover(x, file, name) {
			release(x)
			x.cover = file ? { file, path: name } : null
			const p = x.book.picture
			const blob = file || (p ? new Blob([p.data], { type: p.mime }) : null)
			x.coverUrl = blob ? URL.createObjectURL(blob) : null
			if (x.coverBox) renderCover(x)
		}
		function renderCover(x) {
			x.coverBox.replaceChildren(x.coverUrl ? h('img', { src: x.coverUrl, alt: '' }) : h('span.abs-cover-add', null, t('Add cover')))
		}

		const filesOf = (x) => [
			...x.book.audio.map((a) => ({ path: a.path, kind: 'audio', file: a.file })),
			...(x.book.ebook ? [{ path: x.book.ebook.path, kind: 'ebook', file: x.book.ebook.file }] : []),
			...(x.cover ? [{ path: x.cover.path, kind: 'cover', file: x.cover.file }] : []),
		]
		const sizeOf = (x) => filesOf(x).reduce((s, f) => s + f.file.size, 0)

		function buildCard(x) {
			const m = x.book.meta
			const field = (key, label, attrs) => {
				const input = h('input', { type: 'text', value: m[key], maxlength: '500', dir: 'auto', ...attrs, oninput: (e) => {
					m[key] = e.target.value
					clearTimeout(x.timer)
					x.timer = setTimeout(() => check(x), 300)
					update()
				} })
				return h('label.abs-field.abs-field-' + key, null, h('span', null, label), input)
			}
			x.coverBox = h('span.abs-cover')
			x.fields = h('fieldset.abs-fields', null,
				h('legend.hidden-visually', null, t('Details')),
				field('title', t('Title'), { required: true }),
				field('author', t('Author'), { list: 'abs-authors' }),
				field('series', t('Series'), { list: 'abs-series' }),
				field('seriesSeq', t('No. in series'), { maxlength: '20' }),
				field('narrator', t('Narrator')))
			x.coverButton = h('button.abs-cover-button', { type: 'button', 'aria-label': t('Choose cover'), title: t('Choose cover'), onclick: () => {
				const input = h('input', { type: 'file', accept: '.jpg,.jpeg,.png,.webp' })
				input.addEventListener('change', () => {
					const f = input.files[0]
					if (!f) return
					if (!/\.(jpe?g|png|webp)$/i.test(f.name)) return toast(t('Choose a JPEG, PNG or WebP image.'), true)
					setCover(x, f, f.name)
					check(x)
					renderInfo(x)
				})
				input.click()
			} }, x.coverBox)
			x.info = h('p.abs-muted')
			x.dest = h('p.abs-dest', { 'aria-live': 'polite' })
			x.bar = h('span.abs-bar-fill')
			x.barBox = h('span.abs-bar.abs-upload-bar', { hidden: true }, x.bar)
			x.actions = h('div.abs-draft-actions')
			x.el = h('article.abs-draft', null, x.coverButton, h('div.abs-draft-body', null, x.fields, x.info, x.dest, x.barBox, x.actions))
			renderCover(x)
			renderInfo(x)
			return x.el
		}

		function renderInfo(x) {
			x.info.textContent = [
				n('%n audio file', '%n audio files', x.book.audio.length),
				x.book.ebook && t('ebook'),
				x.cover && t('cover image'),
				fmtSize(sizeOf(x)),
			].filter(Boolean).join(' · ')
		}

		const metaOf = (x) => Object.fromEntries(Object.entries(x.book.meta).map(([k, v]) => [k, v.trim()]))
		const planBody = (x, dryRun) => ({ libraryPath, metadata: metaOf(x), files: filesOf(x).map(({ path, kind }) => ({ path, kind })), dryRun })

		/** Ask the server where this book would go; also catches a book that already exists. */
		async function check(x) {
			if (x.state !== 'edit') return
			const run = x.run = (x.run || 0) + 1
			let r
			try {
				r = { folder: (await api('POST', '/web/upload/plan', planBody(x, true))).folder }
			} catch (e) {
				r = { error: e.message, folder: e.data && e.data.folder }
			}
			if (run !== x.run || x.state !== 'edit') return
			x.check = r
			update()
		}

		function duplicates() {
			const seen = new Map()
			const dup = new Set()
			for (const x of drafts) {
				const f = x.state === 'edit' && x.check && x.check.folder && x.check.folder.toLowerCase()
				if (!f) continue
				if (seen.has(f)) dup.add(x)
				seen.set(f, x)
			}
			return dup
		}

		function update() {
			if (!d || !footer || !list) return
			const dup = duplicates()
			const busy = controller !== null
			for (const x of drafts) {
				const editable = x.state === 'edit' && !busy
				x.fields.disabled = !editable
				x.coverButton.disabled = !editable
				x.barBox.hidden = !['uploading', 'failed'].includes(x.state)
				x.el.dataset.state = x.state
				let error = ''
				let text = ''
				if (x.state === 'edit') {
					if (dup.has(x)) error = t('Another book here has the same author and title.')
					else if (x.check && x.check.error) error = x.check.error
					else if (x.check) text = '→ ' + x.check.folder
				} else if (x.state === 'uploading') {
					text = x.message
				} else if (x.state === 'failed') {
					error = x.message
				} else {
					text = t('Added to your library')
				}
				x.dest.textContent = error || text
				x.dest.classList.toggle('abs-error', !!error)
				const actions = []
				if (x.state === 'failed' && !busy) actions.push(h('button', { type: 'button', onclick: () => run([x]) }, t('Retry')))
				if (['edit', 'failed'].includes(x.state) && !busy) actions.push(h('button.abs-link', { type: 'button', onclick: () => removeDraft(x) }, t('Remove')))
				const sig = x.state + busy
				if (x.sig !== sig) {
					x.sig = sig
					x.actions.replaceChildren(...actions)
				}
			}
			const edit = drafts.filter((x) => x.state === 'edit')
			const ready = edit.length > 0 && !reading && edit.every((x) => x.check && !x.check.error) && !dup.size
			drop.hidden = busy
			footer.replaceChildren(...kids([
				h('span.abs-muted', { role: 'status' }, reading ? t('Reading files…') : ''),
				edit.length > 1 && !busy ? h('button', { type: 'button', onclick: combine }, t('Combine into one book')) : '',
				busy
					? h('button', { type: 'button', onclick: () => controller.abort() }, t('Cancel upload'))
					: h('button', { type: 'button', onclick: close }, drafts.length && !edit.length ? t('Close') : t('Cancel')),
				edit.length || !drafts.length
					? h('button.primary', { type: 'button', disabled: !ready || busy, onclick: () => run(edit) }, edit.length > 1 ? n('Upload %n book', 'Upload %n books', edit.length) : t('Upload'))
					: '',
			]))
		}

		function combine() {
			const edit = drafts.filter((x) => x.state === 'edit')
			const keep = edit[0]
			keep.book = AbsTags.merge(edit.map((x) => x.book))
			setCover(keep, keep.cover ? keep.cover.file : keep.book.image ? keep.book.image.file : null, keep.cover ? keep.cover.path : keep.book.image ? keep.book.image.path : '')
			for (const x of edit.slice(1)) drop1(x)
			renderInfo(keep)
			check(keep)
			update()
		}
		function drop1(x) {
			release(x)
			x.el.remove()
			drafts = drafts.filter((y) => y !== x)
		}

		/** Remove what a cancelled upload already stored: its own files, then folders left empty. */
		async function cleanup(x) {
			if (!x.plan) return
			const { folder, files, library } = x.plan
			// An interrupted file may still be stored, or keep the folder busy, a moment later.
			for (let i = 0; i < 4; i++) {
				if (i) await new Promise((resolve) => setTimeout(resolve, 1500))
				for (const f of x.started) await AbsUploader.remove(folder + '/' + files[f])
				if ((await api('POST', '/web/upload/cancel', { libraryPath: library, folder })).removed) break
			}
			x.done.clear()
			x.started.clear()
			x.plan = null
		}
		async function removeDraft(x) {
			try {
				await cleanup(x)
			} catch (e) {
				return toast(e.message, true)
			}
			drop1(x)
			update()
		}

		async function run(queue) {
			if (controller) return
			controller = new AbortController()
			const signal = controller.signal
			update()
			let added = 0
			for (const x of queue) {
				if (signal.aborted) break
				x.state = 'uploading'
				x.message = t('Starting…')
				update()
				try {
					await uploadOne(x, signal)
					x.state = 'done'
					added++
				} catch (e) {
					if (e.name === 'AbortError' || signal.aborted) {
						x.message = t('Cancelling…')
						update()
						try {
							await cleanup(x)
							x.state = 'edit'
							x.check = null
							check(x)
						} catch (e2) {
							x.state = 'failed'
							x.message = e2.message
						}
					} else {
						x.state = 'failed'
						x.message = e.message
					}
				}
				update()
			}
			controller = null
			update()
			if (added) {
				toast(n('%n book added', '%n books added', added))
				await load().catch((e) => toast(e.message, true))
				if (d && drafts.every((x) => x.state === 'done')) d.shut()
			}
		}

		async function uploadOne(x, signal) {
			const files = filesOf(x)
			if (!x.plan) {
				const p = await api('POST', '/web/upload/plan', planBody(x, false))
				x.plan = { ...p, library: libraryPath }
			}
			const total = files.reduce((s, f) => s + f.file.size, 0) || 1
			let before = 0
			for (let i = 0; i < files.length; i++) {
				const f = files[i]
				const show = (bytes) => {
					const done = Math.min(1, (before + bytes) / total)
					x.bar.style.width = (done * 100).toFixed(1) + '%'
					x.message = t('Uploading {pct}% ({done} of {total})', { pct: Math.floor(done * 100), done: fmtSize(before + bytes), total: fmtSize(total) })
					x.dest.textContent = x.message
				}
				if (!x.done.has(i)) {
					const path = x.plan.folder + '/' + x.plan.files[i]
					// A failed attempt may have been stored after all.
					if (x.started.has(i)) await AbsUploader.remove(path)
					x.started.add(i)
					await AbsUploader.upload(f.file, path, { signal, onProgress: show })
					x.done.add(i)
				}
				before += f.file.size
				show(0)
			}
			x.message = t('Adding to your library…')
			update()
			await api('POST', '/web/upload/finish', { libraryPath: x.plan.library, folder: x.plan.folder, metadata: metaOf(x) })
		}

		return { open, add, busy: () => controller !== null }
	})()

	// ----- drag and drop -----

	async function walk(entry, prefix, out) {
		if (entry.isFile) {
			const file = await new Promise((resolve, reject) => entry.file(resolve, reject))
			out.push({ path: prefix + entry.name, file })
			return
		}
		const reader = entry.createReader()
		for (;;) {
			// readEntries returns at most 100 entries per call.
			const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject))
			if (!batch.length) return
			for (const e of batch) await walk(e, prefix + entry.name + '/', out)
		}
	}

	function setupDrop() {
		const hasFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files')
		let depth = 0
		const mark = () => root.classList.toggle('abs-dragging', depth > 0)
		window.addEventListener('dragenter', (e) => {
			if (!hasFiles(e)) return
			depth++
			mark()
		})
		window.addEventListener('dragleave', (e) => {
			if (!hasFiles(e)) return
			depth = Math.max(0, depth - 1)
			mark()
		})
		window.addEventListener('dragover', (e) => {
			if (hasFiles(e)) e.preventDefault()
		})
		window.addEventListener('drop', async (e) => {
			if (!hasFiles(e)) return
			e.preventDefault()
			depth = 0
			mark()
			if (!loaded || Upload.busy()) return
			// Entries must be collected before the first await.
			const out = []
			const entries = []
			for (const i of e.dataTransfer.items) {
				const entry = i.webkitGetAsEntry && i.webkitGetAsEntry()
				const file = entry ? null : i.kind === 'file' && i.getAsFile()
				if (entry) entries.push(entry)
				else if (file) out.push({ path: file.name, file })
			}
			try {
				for (const x of entries) await walk(x, '', out)
			} catch (err) {
				return toast(t('Could not read the dropped files.'), true)
			}
			if (bookDialog) bookDialog.shut()
			Upload.add(out)
		})
		window.addEventListener('beforeunload', (e) => {
			if (Upload.busy()) e.preventDefault()
		})
	}

	function route() {
		const [, a, b] = /^#\/(\w+)(?:\/(\w+))?/.exec(location.hash) || []
		if (a === 'book' && b) return showBook(b)
		if (bookDialog) bookDialog.shut()
		view = a && a in VIEWS ? a : 'home'
		render()
	}

	document.addEventListener('DOMContentLoaded', () => {
		root = document.getElementById('abscompat-library')
		shell()
		setupDrop()
		window.addEventListener('hashchange', route)
		render()
		load().then(route, (e) => {
			main.replaceChildren(h('p.abs-none', null, t('Could not load your library: {error}', { error: e.message })),
				h('p', null, h('button', { type: 'button', onclick: () => location.reload() }, t('Try again'))))
		})
	})
})()
