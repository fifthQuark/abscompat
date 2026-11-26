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

	let data = { libraries: [], books: [], lists: [], opds: false, streaming: false, covers: { auto: false, online: false } }
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
			const text = [b.title, b.subtitle, b.author, ...seriesOf(b).map((x) => x.name), b.narrator, ...b.tags, ...b.genres].filter(Boolean).join(' ').toLocaleLowerCase()
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
			book.ebookOnly && h('span.abs-card-sub', null, t('Ebook')),
			second && h('span.abs-card-sub', { dir: 'auto' }, second),
			!(opts && opts.inSeries) && book.series && h('span.abs-card-sub', { dir: 'auto' }, book.series + (book.seriesSeq ? ' #' + book.seriesSeq : '')),
		)
	}
	const grid = (books, opts) => h('div.abs-grid', null, books.map((b) => card(b, opts)))
	function section(title, books, opts) {
		const done = books.filter((b) => status(b) === 'finished').length
		return h('section.abs-section', null,
			h('h3', null, h('span', { dir: 'auto' }, title),
				h('span.abs-count', null, n('%n book', '%n books', books.length) + (opts && opts.inSeries && done ? ', ' + t('{n} finished', { n: done }) : '')),
				opts && opts.inSeries && h('button.abs-series-edit', { type: 'button', 'aria-label': t('Edit series {name}', { name: title }), onclick: () => Series.open(title) }, t('Edit'))),
			opts && opts.row ? h('div.abs-row', null, books.map((b) => card(b))) : grid(books, opts))
	}

	// ----- series -----

	const seriesKey = (name) => String(name || '').trim().toLocaleLowerCase()
	const seriesOf = (b) => [...(b.series ? [{ name: b.series, seq: b.seriesSeq }] : []), ...(b.moreSeries || [])]
	const seriesNames = () => [...new Map(data.books.flatMap(seriesOf).map((x) => [seriesKey(x.name), x.name])).values()].sort(natural)
	/** The books as members of each of their series: copies whose series and number are those of that series. */
	const perSeries = (books) => books.flatMap((b) => seriesOf(b).map((x) => ({ ...b, series: x.name, seriesSeq: x.seq })))

	/** The next book of each series with a finished book and none in progress, as in the apps. */
	function nextInSeries(books) {
		const groups = new Map()
		for (const b of perSeries(books)) {
			const key = b.libraryId + '/' + seriesKey(b.series)
			groups.set(key, [...(groups.get(key) || []), b])
		}
		const out = []
		for (const g of groups.values()) {
			const next = g.sort(inSeries).find((b) => status(b) !== 'finished')
			if (!next || !g.some((b) => status(b) === 'finished') || g.some((b) => status(b) !== 'finished' && b.progress && b.progress.currentTime > 0)) continue
			out.push([Math.max(...g.map((b) => (b.progress ? b.progress.lastUpdate : 0))), next])
		}
		const ids = new Set()
		return out.sort((a, b) => b[0] - a[0]).map((x) => x[1]).filter((b) => !ids.has(b.id) && ids.add(b.id))
	}

	const Series = (function () {
		async function save(books) {
			const r = await api('PUT', '/web/series', { books })
			for (const x of r.books) {
				const b = data.books.find((y) => y.id === x.id)
				if (b) Object.assign(b, x)
			}
			render()
		}
		const nameInput = (value) => h('input', { type: 'text', value: value || '', maxlength: '500', dir: 'auto', list: 'abs-series-names' })
		const seqInput = (value, label) => h('input.abs-series-no', { type: 'text', value: value || '', maxlength: '20', inputmode: 'decimal', 'aria-label': label })
		const names = () => h('datalist', { id: 'abs-series-names' }, seriesNames().map((x) => h('option', { value: x })))

		/** The next free number, when the series has numbers. */
		function nextNumber(books) {
			const nums = books.map((b) => parseFloat(b.seriesSeq)).filter((x) => !isNaN(x))
			return nums.length ? String(Math.floor(Math.max(...nums)) + 1) : books.length ? '' : '1'
		}

		/** A whole series: its name, numbers and books. Without a name, a new one. */
		function open(current) {
			if (!loaded) return
			const d = dialog('abs-series-all', t('Series'), () => {})
			d.dataset.backdrop = 'off'
			const name = nameInput(current)
			const status = h('p.abs-muted', { role: 'status' })
			const list = h('ul.abs-series-list')
			const find = h('input', { type: 'search', placeholder: t('Search title or author'), 'aria-label': t('Add books'), dir: 'auto' })
			const found = h('ul.abs-series-list')
			const saveButton = h('button.primary', { type: 'submit' }, t('Save'))
			// What is in the dialog; nothing is stored before Save.
			const isIn = (b) => seriesOf(b).some((x) => seriesKey(x.name) === seriesKey(current))
			const rows = perSeries(data.books).filter((b) => current && seriesKey(b.series) === seriesKey(current)).sort(inSeries)
				.map((b) => ({ book: data.books.find((x) => x.id === b.id), seq: b.seriesSeq || '' }))
			const removed = []
			const note = h('textarea', { rows: 3, maxlength: '20000', dir: 'auto' })
			let oldNote = ''
			if (current) {
				api('GET', '/web/series/description?name=' + encodeURIComponent(current)).then((r) => { note.value = oldNote = r.description }).catch(() => { /* stays empty */ })
			}
			/** The book's series after saving: this one set, moved or taken out, the others as they are. */
			const after = (b, name, seq) => {
				const list = seriesOf(b).map((x) => (current && seriesKey(x.name) === seriesKey(current) ? (name ? { name, seq } : null) : x)).filter(Boolean)
				if (name && !(current && isIn(b))) list.push({ name, seq })
				const unique = [...new Map(list.map((x) => [seriesKey(x.name), x])).values()]
				return { id: b.id, series: unique.length ? unique[0].name : '', seriesSeq: unique.length ? unique[0].seq || '' : '', moreSeries: unique.slice(1) }
			}

			function show() {
				list.replaceChildren(...rows.map((r) => {
					const seq = seqInput(r.seq, t('Number of {title}', { title: r.book.title }))
					seq.addEventListener('input', () => { r.seq = seq.value })
					return h('li', null, seq, h('span.abs-series-title', { dir: 'auto' }, r.book.title),
						h('button', { type: 'button', 'aria-label': t('Remove {title}', { title: r.book.title }), onclick: () => {
							rows.splice(rows.indexOf(r), 1)
							if (current && isIn(r.book)) removed.push(r.book)
							show()
							search()
						} }, t('Remove')))
				}))
				if (!rows.length) list.replaceChildren(h('li.abs-muted', null, t('No books here.')))
				saveButton.disabled = !rows.length && !removed.length
			}

			function search() {
				const words = find.value.toLocaleLowerCase().split(/\s+/).filter(Boolean)
				// Without words: books without a series by the authors already in this one, or just without a series.
				const authors = new Set(rows.flatMap((r) => r.book.authors))
				const hits = data.books.filter((b) => {
					if (rows.some((r) => r.book === b)) return false
					if (!words.length) return (!b.series || removed.includes(b)) && (!authors.size || b.authors.some((a) => authors.has(a)))
					const text = [b.title, b.author, ...seriesOf(b).map((x) => x.name)].filter(Boolean).join(' ').toLocaleLowerCase()
					return words.every((w) => text.includes(w))
				}).sort((x, y) => natural(x.author || '￿', y.author || '￿') || byTitle(x, y))
				found.replaceChildren(...hits.slice(0, 30).map((b) => h('li', null,
					h('span.abs-series-title', { dir: 'auto' }, b.title, h('span.abs-muted', null, [b.author, b.series && !removed.includes(b) && t('also in {series}', { series: b.series })].filter(Boolean).join(' · '))),
					h('button', { type: 'button', 'aria-label': t('Add {title}', { title: b.title }), onclick: () => {
						const own = removed.indexOf(b)
						if (own >= 0) removed.splice(own, 1)
						rows.push({ book: b, seq: own >= 0 && b.seriesSeq ? b.seriesSeq : nextNumber(rows.map((r) => ({ seriesSeq: r.seq }))) })
						show()
						search()
					} }, t('Add')))))
				if (!hits.length && words.length) found.replaceChildren(h('li.abs-muted', null, t('Nothing matches your search.')))
				if (hits.length > 30) found.append(h('li.abs-muted', null, t('Search to see more books.')))
			}
			find.addEventListener('input', search)
			find.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') e.preventDefault()
			})

			d.append(closeButton(() => d.shut()), h('h3', null, current ? t('Edit series') : t('New series')),
				h('form', { onsubmit: async (e) => {
					e.preventDefault()
					const series = name.value.trim()
					if (rows.length && !series) {
						status.className = 'abs-error'
						status.textContent = t('Enter the name of the series.')
						return name.focus()
					}
					saveButton.disabled = true
					status.className = 'abs-muted'
					status.textContent = t('Saving…')
					const books = [...rows.map((r) => after(r.book, series, r.seq)), ...removed.map((b) => after(b, '', ''))]
					try {
						if (series && (note.value !== oldNote || (current && series !== current))) await api('PUT', '/web/series/description', { name: series, description: note.value })
						if (current && oldNote && seriesKey(series) !== seriesKey(current)) await api('PUT', '/web/series/description', { name: current, description: '' })
						for (let i = 0; i < books.length; i += 200) await save(books.slice(i, i + 200))
						d.shut()
					} catch (err) {
						status.className = 'abs-error'
						status.textContent = err.message
						saveButton.disabled = false
					}
				} },
				h('label.abs-field', null, h('span', null, t('Series')), name), names(),
				h('label.abs-field', null, h('span', null, t('Description')), note),
				h('h4', null, t('Books')), list,
				h('h4', null, t('Add books')), find, found,
				h('div.abs-foot', null, status, h('p.abs-muted', null, t('Stored in Nextcloud; your files are not changed.')), saveButton)))
			show()
			search()
			d.showModal()
			if (!current) name.focus()
		}

		return { open, names }
	})()

	// ----- views -----

	const VIEWS = { home: t('Home'), books: t('All books'), authors: t('Authors'), series: t('Series'), narrators: t('Narrators'), lists: t('Lists'), stats: t('Statistics') }

	function renderHome(books) {
		const out = []
		const cont = books.filter((b) => status(b) === 'progress' && !b.progress.hidden).sort(SORTS.listened[1]).slice(0, 20)
		if (cont.length) out.push(section(t('Continue listening'), cont, { row: true }))
		const next = nextInSeries(books).slice(0, 20)
		if (next.length) out.push(section(t('Continue series'), next, { row: true }))
		const added = [...books].sort(SORTS.added[1]).slice(0, 20)
		out.push(section(t('Recently added'), added, { row: true }))
		const started = new Set(data.books.filter((b) => status(b) !== 'new').flatMap((b) => seriesOf(b).map((x) => seriesKey(x.name))))
		const first = new Set([...new Map(perSeries(books).sort(inSeries).reverse().map((b) => [seriesKey(b.series), b])).values()].filter((b) => !started.has(seriesKey(b.series))).map((b) => b.id))
		const unread = books.filter((b) => status(b) === 'new' && (!b.series || first.has(b.id))).sort(byTitle)
		if (unread.length) out.push(section(t('Discover'), unread))
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
		const tags = [...new Set(data.books.flatMap((b) => b.tags))].sort(natural)
		const shown = books.filter((b) => ui.filter === 'all' || (ui.filter === 'audio' ? !b.ebookOnly : ui.filter === 'ebook' ? b.hasEbook : status(b) === ui.filter))
			.filter((b) => !tags.includes(ui.tag) || b.tags.includes(ui.tag)).sort((SORTS[ui.sort] || SORTS.title)[1])
		const change = (k) => (v) => {
			ui[k] = v
			remember()
			render()
		}
		return [
			h('div.abs-tools', null,
				select(t('Sort'), Object.entries(SORTS).map(([k, v]) => [k, v[0]]), ui.sort, change('sort')),
				select(t('Show'), [['all', t('All')], ['new', t('Not started')], ['progress', t('In progress')], ['finished', t('Finished')], ['audio', t('Audiobooks')], ['ebook', t('With ebook')]], ui.filter, change('filter')),
				tags.length ? select(t('Tag'), [['', t('All')], ...tags.map((x) => [x, x])], tags.includes(ui.tag) ? ui.tag : '', (v) => {
					ui.tag = v
					render()
				}) : null,
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

	function renderSeries(books) {
		const inOne = books.some((b) => b.series)
		return [
			h('div.abs-tools', null, h('button', { type: 'button', onclick: () => Series.open('') }, t('New series')),
				!inOne && h('span.abs-muted', null, t('None of these books is in a series yet.'))),
			...(inOne ? renderGroups(perSeries(books), (b) => [b.series], null, inSeries, { inSeries: true }) : []),
		]
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
				h('button.abs-covers', { type: 'button', onclick: () => Covers.openAll() }, t('Covers')),
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
				: view === 'series' ? renderSeries(books)
					: view === 'narrators' ? renderGroups(books.filter((b) => !b.ebookOnly), (b) => b.narrators, t('Unknown narrator'), bySeries)
						: view === 'lists' ? Lists.render(books)
							: view === 'stats' ? Stats.render()
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
		/** Run a change of this book and show the result. */
		const change = async (fn) => {
			try {
				changedBook(await fn())
			} catch (e) {
				return toast(e.message, true)
			}
			if (bookDialog === d) showBook(id)
		}
		d.replaceChildren(...kids([
			closeButton(() => d.shut()),
			h('div.abs-book-top', null,
				h('span.abs-cover-wrap', null, cover(b)),
				h('div.abs-book-info', null,
					h('h3', { dir: 'auto' }, b.title),
					b.subtitle && h('p.abs-muted', { dir: 'auto' }, b.subtitle),
					h('dl', null,
						line(t('Author'), b.author),
						line(t('Series'), seriesOf(b).map((x) => x.name + (x.seq ? ' #' + x.seq : '')).join(', ')),
						line(t('Narrator'), b.narrator),
						line(t('Year'), b.year),
						line(t('Genre'), b.genres.join(', ')),
						line(t('Tags'), b.tags.join(', ')),
						line(t('Publisher'), b.publisher),
						line(t('Language'), b.language),
						line(b.ebookOnly ? t('Size') : t('Length'), (b.ebookOnly ? '' : fmtDuration(b.duration) + ' · ') + fmtSize(b.size))),
					st === 'finished' ? h('p', null, t('Finished'))
						: st === 'progress' ? h('div.abs-book-progress', null, progressBar(b.progress.progress),
							h('span', null, b.ebookOnly ? t('{pct}% read', { pct: Math.round(b.progress.progress * 100) }) : t('{pct}% listened, {left} left', { pct: Math.round(b.progress.progress * 100), left: fmtDuration(left) })))
							: h('p.abs-muted', null, t('Not started')),
					h('p.abs-book-actions', null,
						data.streaming && !b.ebookOnly && h('button.primary', { type: 'button', onclick: () => {
							d.shut()
							Player.play(b)
						} }, st === 'progress' ? t('Continue') : t('Play')),
						h('a.button', { href: OC.generateUrl('/f/' + b.id) }, t('Show in Files')),
						h('button', { type: 'button', onclick: () => editBook(b) }, t('Edit details')),
						h('button', { type: 'button', onclick: () => Lists.choose(b) }, t('Add to list')),
						b.coverEditable && h('button', { type: 'button', onclick: () => Covers.openBook(b) }, b.hasCover ? t('Change cover') : t('Find cover'))))),
			b.description && h('p.abs-desc', { dir: 'auto' }, b.description),
			b.chapters.length > 1 && h('details', null, h('summary', null, n('%n chapter', '%n chapters', b.chapters.length)),
				h('ol.abs-list', null, b.chapters.map((c) => h('li', null, h('span', { dir: 'auto' }, c.title), h('span.abs-muted', null, fmtClock(c.start)))))),
			(b.ebooks.length > 0 || (data.opds && !b.ebookOnly)) && h('details', { open: b.ebookOnly }, h('summary', null, b.ebooks.length ? n('%n ebook file', '%n ebook files', b.ebooks.length) : t('Ebook')),
				h('ul.abs-list', null, b.ebooks.map((e) => h('li', null,
					h('span', { dir: 'auto' }, e.name, e.opds && h('span.abs-muted', null, ' · OPDS+')),
					h('span.abs-list-actions', null, h('span.abs-muted', null, fmtSize(e.size)),
						h('a.button', { href: OC.generateUrl('/f/' + e.id) }, t('Show in Files')),
						b.ebooks.length > 1 && (e.primary ? h('span.abs-muted', null, t('opens in apps')) : h('button', { type: 'button', onclick: () => change(() => api('PUT', '/web/items/' + b.id + '/ebook', { fileId: e.id })) }, t('Open this in apps'))))))),
				data.opds && !b.ebookOnly && (!b.ebooks.length || b.ebooks[0].opds) && h('p.abs-book-actions', null,
					h('button', { type: 'button', onclick: () => chooseEbook(b) }, t('Choose from OPDS+')),
					b.ebooks.length > 0 && h('button', { type: 'button', onclick: () => change(() => api('PUT', '/web/items/' + b.id + '/ebook', { fileId: 0, opds: true })) }, t('No ebook')))),
			b.bookmarks.length > 0 && h('details', { open: true }, h('summary', null, n('%n bookmark', '%n bookmarks', b.bookmarks.length)),
				h('ul.abs-list', null, b.bookmarks.sort((x, y) => x.time - y.time).map((m) => h('li', null,
					h('span', { dir: 'auto' }, m.title || t('Bookmark'), h('span.abs-muted', null, ' · ' + fmtClock(m.time))),
					h('span.abs-list-actions', null,
						h('button', { type: 'button', 'aria-label': t('Rename {title}', { title: m.title }), onclick: () => {
							const title = window.prompt(t('Name of the bookmark'), m.title)
							if (title !== null) change(() => api('PUT', '/web/items/' + b.id + '/bookmarks', { time: m.time, title }))
						} }, t('Rename')),
						h('button', { type: 'button', 'aria-label': t('Delete {title}', { title: m.title }), onclick: () => change(() => api('DELETE', '/web/items/' + b.id + '/bookmarks?time=' + m.time)) }, t('Delete'))))))),
			!b.ebookOnly && h('details', null, h('summary', null, n('%n audio file', '%n audio files', b.files.length)),
				h('ul.abs-list', null, b.files.map((f) => h('li', null, h('span', { dir: 'auto' }, f.name), h('span.abs-muted', null, fmtClock(f.duration) + ' · ' + fmtSize(f.size)))))),
		]))
	}

	/** Take over what the server answered for a book. */
	function changedBook(r) {
		const b = data.books.find((x) => x.id === r.id)
		if (b) for (const k of Object.keys(b)) if (k in r) b[k] = r[k]
		render()
	}

	function editBook(b) {
		const d = dialog('abs-edit', t('Edit details'), () => {})
		d.dataset.backdrop = 'off'
		const status = h('p.abs-muted', { role: 'status' })
		const save = h('button.primary', { type: 'submit' }, t('Save'))
		const f = {}
		const field = (key, label, value, attrs) => {
			f[key] = h(attrs && attrs.rows ? 'textarea' : 'input', { ...(attrs && attrs.rows ? {} : { type: 'text' }), maxlength: '500', dir: 'auto', ...attrs })
			f[key].value = value || ''
			return h('label.abs-field.abs-field-' + key, null, h('span', null, label), f[key])
		}
		const check = (key, label, value) => {
			f[key] = h('input', { type: 'checkbox', checked: !!value })
			return h('label.abs-check', null, f[key], h('span', null, label))
		}
		const series = seriesOf(b).map((x) => ({ ...x }))
		const seriesBox = h('div')
		const showSeries = (focus) => {
			seriesBox.replaceChildren(...series.map((x, i) => {
				const name = h('input', { type: 'text', value: x.name || '', maxlength: '500', dir: 'auto', list: 'abs-series-names', 'aria-label': t('Series'), oninput: (e) => { x.name = e.target.value } })
				const seq = h('input.abs-series-no', { type: 'text', value: x.seq || '', maxlength: '20', 'aria-label': t('No. in series'), placeholder: t('No.'), oninput: (e) => { x.seq = e.target.value } })
				name.addEventListener('change', () => {
					const known = seriesNames().find((y) => seriesKey(y) === seriesKey(name.value))
					if (!known) return
					name.value = x.name = known
					const nums = perSeries(data.books).filter((y) => y.id !== b.id && seriesKey(y.series) === seriesKey(known)).map((y) => parseFloat(y.seriesSeq)).filter((y) => !isNaN(y))
					if (!seq.value && nums.length) seq.value = x.seq = String(Math.floor(Math.max(...nums)) + 1)
				})
				return h('div.abs-series-row', null, name, seq, h('button', { type: 'button', 'aria-label': t('Remove series {name}', { name: x.name || '' }), onclick: () => {
					series.splice(i, 1)
					showSeries()
				} }, t('Remove')))
			}), h('button', { type: 'button', onclick: () => {
				series.push({ name: '', seq: '' })
				showSeries(true)
			} }, series.length ? t('Add another series') : t('Add to series')))
			if (focus) seriesBox.querySelectorAll('.abs-series-row input[list]')[series.length - 1].focus()
		}
		const options = (id, list) => h('datalist', { id }, [...new Set(list)].sort(natural).map((x) => h('option', { value: x })))
		const split = (v) => v.split(/[,;]/).map((x) => x.trim()).filter(Boolean)
		d.append(closeButton(() => d.shut()), h('h3', null, t('Edit details')), h('form', { onsubmit: async (e) => {
			e.preventDefault()
			const used = series.filter((x) => x.name.trim())
			save.disabled = true
			status.className = 'abs-muted'
			status.textContent = t('Saving…')
			const metadata = {
				series: used.length ? used[0].name : '', seriesSeq: used.length ? used[0].seq || '' : '', moreSeries: used.slice(1),
				genres: split(f.genres.value), tags: split(f.tags.value), explicit: f.explicit.checked, abridged: f.abridged.checked,
			}
			for (const k of ['title', 'subtitle', 'author', 'narrator', 'year', 'publisher', 'language', 'isbn', 'asin', 'description']) metadata[k] = f[k].value
			try {
				changedBook(await api('PUT', '/web/items/' + b.id, { metadata }))
				d.shut()
				if (bookDialog) showBook(b.id)
			} catch (err) {
				status.className = 'abs-error'
				status.textContent = err.message
				save.disabled = false
			}
		} },
		h('div.abs-fields', null,
			field('title', t('Title'), b.title, { required: true }),
			field('subtitle', t('Subtitle'), b.subtitle),
			field('author', t('Author'), b.author, { list: 'abs-edit-authors' }),
			field('narrator', t('Narrator'), b.narrator, { list: 'abs-edit-narrators' })),
		h('h4', null, t('Series')), seriesBox,
		h('div.abs-fields', null,
			field('genres', t('Genres, separated by commas'), b.genres.join(', ')),
			field('tags', t('Tags, separated by commas'), b.tags.join(', ')),
			field('year', t('Year'), b.year, { maxlength: '16' }),
			field('language', t('Language'), b.language, { maxlength: '64' }),
			field('publisher', t('Publisher'), b.publisher, { maxlength: '255' }),
			field('isbn', 'ISBN', b.isbn, { maxlength: '32' }),
			field('asin', 'ASIN', b.asin, { maxlength: '32' }),
			field('description', t('Description'), b.description, { rows: 5, maxlength: '20000' })),
		check('explicit', t('Explicit'), b.explicit), check('abridged', t('Abridged'), b.abridged),
		options('abs-edit-authors', data.books.flatMap((x) => x.authors)), options('abs-edit-narrators', data.books.flatMap((x) => x.narrators)), Series.names(),
		h('div.abs-foot', null, status, h('p.abs-muted', null, t('Stored in Nextcloud; your files are not changed.')), save)))
		showSeries()
		d.showModal()
	}

	/** Give an audiobook an ebook from OPDS+. */
	function chooseEbook(b) {
		const d = dialog('abs-ebook', t('Choose from OPDS+'), () => {})
		const q = h('input', { type: 'search', value: b.title, dir: 'auto', 'aria-label': t('Search title or author'), placeholder: t('Search title or author') })
		const list = h('ul.abs-series-list')
		const status = h('p.abs-muted', { role: 'status' })
		const use = async (fileId) => {
			try {
				changedBook(await api('PUT', '/web/items/' + b.id + '/ebook', { fileId, opds: true }))
				d.shut()
				if (bookDialog) showBook(b.id)
			} catch (e) {
				status.textContent = e.message
			}
		}
		let timer
		const search = async () => {
			try {
				const r = await api('GET', '/web/opds?q=' + encodeURIComponent(q.value))
				status.textContent = r.books.length ? '' : t('Nothing matches your search.')
				list.replaceChildren(...r.books.map((x) => h('li', null,
					h('span.abs-series-title', { dir: 'auto' }, x.title, h('span.abs-muted', null, [x.author, x.formats.join(', ')].filter(Boolean).join(' · '))),
					h('button', { type: 'button', 'aria-label': t('Use {title}', { title: x.title }), onclick: () => use(x.fileId) }, t('Use')))))
			} catch (e) {
				status.textContent = e.message
			}
		}
		q.addEventListener('input', () => {
			clearTimeout(timer)
			timer = setTimeout(search, 250)
		})
		d.append(closeButton(() => d.shut()), h('h3', { dir: 'auto' }, b.title), q,
			h('p', null, h('button', { type: 'button', onclick: () => use(null) }, t('Find it automatically'))), status, list)
		search()
		d.showModal()
	}

	// ----- player (only with streaming on) -----

	const Player = (function () {
		const SYNC_EVERY = 15
		let bar = null
		let audio = null
		let s = null // { book, sessionId, tracks, chapters, duration, index }
		let listened = 0
		let lastTick = null
		let timer = null
		let busy = false
		const els = {}

		const now = () => (s ? s.tracks[s.index].start + (audio.currentTime || 0) : 0)
		const chapterAt = (sec) => (s ? [...s.chapters].reverse().find((c) => sec >= c.start) : null)

		function build() {
			audio = new Audio()
			audio.preload = 'auto'
			audio.addEventListener('timeupdate', tick)
			audio.addEventListener('play', () => { lastTick = null; show() })
			audio.addEventListener('pause', () => { show(); sync(false) })
			audio.addEventListener('ended', next)
			audio.addEventListener('error', () => {
				if (!s) return
				toast(t('The audio could not be loaded. Is streaming still on?'), true)
				show()
			})
			const btn = (label, text, onclick, cls) => h('button' + (cls || ''), { type: 'button', 'aria-label': label, title: label, onclick }, text)
			els.cover = h('span.abs-player-cover')
			els.title = h('a.abs-player-title', { dir: 'auto', href: '#' })
			els.chapter = h('span.abs-player-chapter', { dir: 'auto' })
			els.play = btn(t('Play'), '▶', () => (audio.paused ? audio.play().catch((e) => toast(e.message, true)) : audio.pause()), '.primary.abs-player-play')
			els.seek = h('input.abs-player-seek', { type: 'range', min: '0', step: '1', 'aria-label': t('Position'), oninput: () => { els.time.textContent = fmtClock(+els.seek.value) + ' / ' + fmtClock(s.duration) }, onchange: () => seek(+els.seek.value) })
			els.time = h('span.abs-player-time')
			els.speed = h('select', { 'aria-label': t('Speed'), onchange: () => { audio.playbackRate = +els.speed.value; remember() } },
				[0.75, 1, 1.1, 1.25, 1.5, 1.75, 2].map((x) => h('option', { value: String(x), selected: x === 1 }, x + '×')))
			bar = h('div.abs-player', { role: 'region', 'aria-label': t('Player') },
				els.cover,
				h('div.abs-player-text', null, els.title, els.chapter),
				h('div.abs-player-controls', null,
					btn(t('Back 30 seconds'), '−30', () => seek(now() - 30)), els.play, btn(t('Forward 30 seconds'), '+30', () => seek(now() + 30))),
				h('div.abs-player-bar', null, els.seek, els.time),
				els.speed,
				btn(t('Close player'), '✕', () => stop(), '.abs-player-close'))
			root.append(bar)
			root.classList.add('abs-has-player')
			window.addEventListener('pagehide', () => sync(true, true))
			if ('mediaSession' in navigator) {
				const ms = navigator.mediaSession
				ms.setActionHandler('play', () => audio.play())
				ms.setActionHandler('pause', () => audio.pause())
				ms.setActionHandler('seekbackward', () => seek(now() - 30))
				ms.setActionHandler('seekforward', () => seek(now() + 30))
			}
			try {
				const r = +localStorage.getItem('abscompat-speed')
				if (r) els.speed.value = String(r)
			} catch (e) { /* storage unavailable */ }
		}
		function remember() {
			try {
				localStorage.setItem('abscompat-speed', els.speed.value)
			} catch (e) { /* storage unavailable */ }
		}

		function show() {
			if (!s) return
			const sec = now()
			els.play.textContent = audio.paused ? '▶' : '❚❚'
			els.play.setAttribute('aria-label', audio.paused ? t('Play') : t('Pause'))
			els.play.title = els.play.getAttribute('aria-label')
			els.seek.max = String(Math.floor(s.duration))
			if (document.activeElement !== els.seek) els.seek.value = String(Math.floor(sec))
			els.time.textContent = fmtClock(sec) + ' / ' + fmtClock(s.duration)
			const c = chapterAt(sec)
			els.chapter.textContent = c && s.chapters.length > 1 ? c.title : s.book.author || ''
		}

		function tick() {
			const t0 = performance.now()
			if (!audio.paused && lastTick !== null) listened += Math.min(5, (t0 - lastTick) / 1000) * audio.playbackRate
			lastTick = audio.paused ? null : t0
			show()
			if (listened >= SYNC_EVERY) sync(false)
		}

		async function sync(close, beacon) {
			if (!s || busy) return
			const body = { currentTime: now(), timeListened: listened, close: !!close }
			listened = 0
			const b = data.books.find((x) => x.id === s.book.id)
			if (b) {
				b.progress = Object.assign(b.progress || {}, { currentTime: body.currentTime, progress: s.duration ? body.currentTime / s.duration : 0, isFinished: false, hidden: false, lastUpdate: Date.now() })
			}
			try {
				await fetch(url('/web/sessions/' + s.sessionId + '/sync'), { method: 'POST', keepalive: !!beacon, headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken }, body: JSON.stringify(body) })
			} catch (e) { /* the next sync carries the position */ }
		}

		function load(index, at, playing) {
			s.index = index
			const tr = s.tracks[index]
			if (!audio.src.endsWith(tr.url)) audio.src = tr.url
			const set = () => {
				audio.currentTime = Math.max(0, at - tr.start)
				if (playing) audio.play().catch(() => show())
			}
			if (audio.readyState >= 1) set()
			else audio.addEventListener('loadedmetadata', set, { once: true })
		}

		function seek(sec) {
			if (!s) return
			sec = Math.max(0, Math.min(s.duration - 0.5, sec))
			const i = Math.max(0, s.tracks.findIndex((tr) => sec >= tr.start && sec < tr.start + tr.duration))
			load(i, sec, !audio.paused)
			show()
		}

		function next() {
			if (s.index + 1 < s.tracks.length) return load(s.index + 1, s.tracks[s.index + 1].start, true)
			sync(true)
			const b = data.books.find((x) => x.id === s.book.id)
			if (b) b.progress = Object.assign(b.progress || {}, { isFinished: true, progress: 1 })
			render()
			show()
		}

		async function play(b) {
			if (s && s.book.id === b.id) return audio.paused && audio.play()
			if (s) await stop()
			let r
			try {
				r = await api('POST', '/web/items/' + encodeURIComponent(b.id) + '/play')
			} catch (e) {
				return toast(e.message, true)
			}
			if (!bar) build()
			bar.hidden = false
			root.classList.add('abs-has-player')
			s = { book: b, sessionId: r.sessionId, tracks: r.tracks, chapters: r.chapters, duration: r.duration, index: 0 }
			listened = 0
			els.cover.replaceChildren(cover(b))
			els.title.textContent = b.title
			els.title.onclick = (e) => {
				e.preventDefault()
				showBook(b.id)
			}
			if ('mediaSession' in navigator && window.MediaMetadata) {
				navigator.mediaSession.metadata = new MediaMetadata({ title: b.title, artist: b.author || '', album: b.series || '', artwork: b.hasCover ? [{ src: url('/web/items/' + b.id + '/cover') + '?v=' + b.updatedAt }] : [] })
			}
			audio.playbackRate = +els.speed.value
			const start = r.startTime >= r.duration - 1 ? 0 : r.startTime
			const i = Math.max(0, r.tracks.findIndex((tr) => start >= tr.start && start < tr.start + tr.duration))
			load(i, start, true)
			show()
		}

		async function stop() {
			if (!s) return
			audio.pause()
			await sync(true)
			s = null
			audio.removeAttribute('src')
			audio.load()
			bar.hidden = true
			root.classList.remove('abs-has-player')
			render()
		}

		return { play, stop }
	})()

	// ----- playlists and collections -----

	const Lists = (function () {
		const KINDS = { collection: t('Collections'), playlist: t('Playlists') }
		const put = (l) => {
			const i = data.lists.findIndex((x) => x.id === l.id)
			if (i < 0) data.lists.push(l)
			else data.lists[i] = l
			data.lists.sort((a, b) => natural(a.name, b.name))
			render()
		}

		function render_(books) {
			const byId = new Map(books.map((b) => [b.id, b]))
			const out = [h('div.abs-tools', null,
				h('button', { type: 'button', onclick: () => edit(null, 'collection') }, t('New collection')),
				h('button', { type: 'button', onclick: () => edit(null, 'playlist') }, t('New playlist')),
				!data.lists.length && h('span.abs-muted', null, t('Collections group books. Playlists are for listening in a certain order; the app can make them too.')))]
			for (const [kind, label] of Object.entries(KINDS)) {
				const lists = data.lists.filter((l) => l.kind === kind)
				if (lists.length) out.push(h('h3.abs-kind', null, label))
				for (const l of lists) {
					const list = l.items.map((id) => byId.get(id)).filter(Boolean)
					out.push(h('section.abs-section', null,
						h('h3', null, h('span', { dir: 'auto' }, l.name), h('span.abs-count', null, n('%n book', '%n books', list.length)),
							h('button.abs-series-edit', { type: 'button', 'aria-label': t('Edit {name}', { name: l.name }), onclick: () => edit(l) }, t('Edit'))),
						l.description && h('p.abs-muted', { dir: 'auto' }, l.description),
						h('div.abs-row', null, list.map((b) => card(b)))))
				}
			}
			return out
		}

		function edit(l, kind, first) {
			kind = l ? l.kind : kind
			const d = dialog('abs-list-edit', KINDS[kind], () => {})
			d.dataset.backdrop = 'off'
			const libs = data.libraries.filter((x) => x.id)
			let libraryId = l ? l.libraryId : first ? first.libraryId : ui.library || (libs[0] || {}).id
			const name = h('input', { type: 'text', value: l ? l.name : '', maxlength: '200', required: true, dir: 'auto' })
			const desc = h('textarea', { rows: 2, maxlength: '20000', dir: 'auto' })
			desc.value = (l && l.description) || ''
			const status = h('p.abs-muted', { role: 'status' })
			const save = h('button.primary', { type: 'submit' }, t('Save'))
			const list = h('ul.abs-series-list')
			const find = h('input', { type: 'search', placeholder: t('Search title or author'), 'aria-label': t('Add books'), dir: 'auto', oninput: () => search(), onkeydown: (e) => e.key === 'Enter' && e.preventDefault() })
			const found = h('ul.abs-series-list')
			let items = l ? [...l.items] : first ? [first.id] : []
			const book = (id) => data.books.find((b) => b.id === id)

			function show() {
				const move = (i, by) => {
					const [x] = items.splice(i, 1)
					items.splice(i + by, 0, x)
					show()
				}
				list.replaceChildren(...items.map((id, i) => {
					const b = book(id)
					return b && h('li', null, h('span.abs-series-title', { dir: 'auto' }, b.title, h('span.abs-muted', null, b.author)),
						h('button', { type: 'button', disabled: i === 0, 'aria-label': t('Move {title} up', { title: b.title }), onclick: () => move(i, -1) }, '↑'),
						h('button', { type: 'button', disabled: i === items.length - 1, 'aria-label': t('Move {title} down', { title: b.title }), onclick: () => move(i, 1) }, '↓'),
						h('button', { type: 'button', 'aria-label': t('Remove {title}', { title: b.title }), onclick: () => {
							items.splice(i, 1)
							show()
							search()
						} }, t('Remove')))
				}).filter(Boolean))
				if (!items.length) list.replaceChildren(h('li.abs-muted', null, t('No books here.')))
			}
			function search() {
				const words = find.value.toLocaleLowerCase().split(/\s+/).filter(Boolean)
				const hits = data.books.filter((b) => {
					if (b.libraryId !== libraryId || items.includes(b.id)) return false
					const text = [b.title, b.author, b.series].filter(Boolean).join(' ').toLocaleLowerCase()
					return words.every((w) => text.includes(w))
				}).sort(byTitle)
				found.replaceChildren(...hits.slice(0, 30).map((b) => h('li', null, h('span.abs-series-title', { dir: 'auto' }, b.title, h('span.abs-muted', null, b.author)),
					h('button', { type: 'button', 'aria-label': t('Add {title}', { title: b.title }), onclick: () => {
						items.push(b.id)
						show()
						search()
					} }, t('Add')))))
				if (hits.length > 30) found.append(h('li.abs-muted', null, t('Search to see more books.')))
			}

			d.append(...kids([closeButton(() => d.shut()), h('h3', null, l ? t('Edit list') : kind === 'playlist' ? t('New playlist') : t('New collection')),
				h('form', { onsubmit: async (e) => {
					e.preventDefault()
					save.disabled = true
					try {
						const body = { kind, libraryId, name: name.value, description: desc.value, items }
						put(await api(l ? 'PUT' : 'POST', '/web/lists' + (l ? '/' + l.id : ''), body))
						d.shut()
					} catch (err) {
						status.className = 'abs-error'
						status.textContent = err.message
						save.disabled = false
					}
				} }, h('label.abs-field', null, h('span', null, t('Name')), name),
				h('label.abs-field', null, h('span', null, t('Description')), desc),
				!l && !first && libs.length > 1 ? select(t('Library'), libs.map((x) => [x.id, x.name]), libraryId, (v) => {
					libraryId = v
					items = []
					show()
					search()
				}) : null,
				h('h4', null, t('Books')), list, h('h4', null, t('Add books')), find, found,
				h('div.abs-foot', null, status, l && h('button', { type: 'button', onclick: async () => {
					if (!window.confirm(t('Delete "{name}"? The books stay.', { name: l.name }))) return
					try {
						await api('DELETE', '/web/lists/' + l.id)
						data.lists = data.lists.filter((x) => x.id !== l.id)
						d.shut()
						render()
					} catch (err) {
						status.textContent = err.message
					}
				} }, t('Delete list')), save))]))
			show()
			search()
			d.showModal()
			if (!l) name.focus()
		}

		/** Tick the lists a book is on. */
		function choose(b) {
			const d = dialog('abs-list-choose', t('Add to list'), () => {})
			const box = h('div')
			const show = () => box.replaceChildren(...data.lists.filter((l) => l.libraryId === b.libraryId).map((l) => {
				const input = h('input', { type: 'checkbox', checked: l.items.includes(b.id), onchange: async () => {
					input.disabled = true
					try {
						put(await api('PUT', '/web/lists/' + l.id, { name: l.name, description: l.description || '', items: input.checked ? [...l.items, b.id] : l.items.filter((x) => x !== b.id) }))
					} catch (e) {
						toast(e.message, true)
					}
					show()
				} })
				return h('label.abs-check', null, input, h('span', { dir: 'auto' }, l.name, h('span.abs-muted', null, ' · ' + (l.kind === 'playlist' ? t('Playlist') : t('Collection')))))
			}))
			const create = (kind) => () => {
				d.shut()
				edit(null, kind, b)
			}
			d.append(closeButton(() => d.shut()), h('h3', { dir: 'auto' }, b.title), box, h('p.abs-book-actions', null,
				h('button', { type: 'button', onclick: create('collection') }, t('New collection')),
				h('button', { type: 'button', onclick: create('playlist') }, t('New playlist'))))
			show()
			d.showModal()
		}

		return { render: render_, choose }
	})()

	// ----- statistics -----

	const Stats = (function () {
		let year = new Date().getFullYear()
		let cache = null
		const hours = (sec) => (sec < 3600 ? t('{m} min', { m: Math.round(sec / 60) }) : fmtDuration(sec))

		async function load_() {
			const want = year
			try {
				const r = await api('GET', '/web/stats?year=' + want)
				if (want !== year) return
				cache = { year: want, r }
			} catch (e) {
				cache = { year: want, error: e.message }
			}
			if (view === 'stats') render()
		}

		function render_() {
			if (!cache || cache.year !== year) {
				load_()
				return [h('p.abs-none', null, t('Loading…'))]
			}
			if (cache.error) return [h('p.abs-error', null, cache.error)]
			const { r } = cache
			const y = r.year
			const now = new Date().getFullYear()
			const row = (label, value) => value && h('div', null, h('dt', null, label), h('dd', { dir: 'auto' }, value))
			const top = (list, key) => list.map((x) => x[key] + ' (' + hours(x.time) + ')').join(', ')
			const week = [...Array(7)].map((_, i) => {
				const d = new Date()
				d.setDate(d.getDate() - 6 + i)
				const key = [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-')
				return [d.toLocaleDateString(undefined, { weekday: 'short' }), r.days[key] || 0]
			})
			const max = Math.max(1, ...week.map((x) => x[1]))
			return [
				h('section.abs-section', null, h('h3', null, t('Last 7 days')),
					h('div.abs-week', { role: 'img', 'aria-label': week.map(([d, s]) => d + ': ' + hours(s)).join(', ') }, week.map(([d, s]) => {
						const bar = h('span.abs-week-bar')
						bar.style.height = Math.round(s / max * 90) + 'px'
						return h('div', null, h('span.abs-muted', null, s ? hours(s) : ''), bar, h('span', null, d))
					})),
					h('p.abs-muted', null, t('{total} listened in all, {today} today.', { total: hours(r.total), today: hours(r.today) }))),
				h('section.abs-section', null, h('h3', null, t('Year in review')),
					h('div.abs-tools', null, select(t('Year'), [...Array(3)].map((_, i) => [String(now - i), String(now - i)]), String(year), (v) => {
						year = +v
						render()
					})),
					h('dl.abs-stats', null,
						row(t('Time listened'), hours(y.totalListeningTime)), row(t('Books listened to'), String(y.numBooksListened)), row(t('Books finished'), String(y.numBooksFinished)),
						row(t('Sessions'), String(y.totalListeningSessions)), row(t('Top authors'), top(y.topAuthors, 'name')), row(t('Top genres'), top(y.topGenres, 'genre')),
						row(t('Top narrator'), y.mostListenedNarrator && top([y.mostListenedNarrator], 'name')),
						row(t('Busiest month'), y.mostListenedMonth && new Date(y.year || year, y.mostListenedMonth.month, 1).toLocaleDateString(undefined, { month: 'long' }) + ' (' + hours(y.mostListenedMonth.time) + ')'),
						row(t('Longest book finished'), y.longestAudiobookFinished && y.longestAudiobookFinished.title + ' (' + hours(y.longestAudiobookFinished.duration) + ')'))),
				h('p.abs-muted', null, t('Listening sessions are kept for two years.')),
			]
		}

		return { render: render_ }
	})()

	// ----- covers -----

	const Covers = (function () {
		/** Show a changed cover without reloading the library. */
		function changed(id, r) {
			const b = data.books.find((x) => x.id === id)
			if (b) {
				b.hasCover = r.hasCover
				b.coverChosen = r.coverChosen
				b.updatedAt = r.updatedAt
			}
			render()
		}

		const missing = () => data.books.filter((b) => !b.hasCover && !b.ebookOnly)
		const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

		function openAll() {
			if (!loaded) return
			let stop = false
			let running = false
			const d = dialog('abs-cover-all', t('Covers'), () => { stop = true })
			const line = h('p', { role: 'status' })
			const bar = h('span.abs-bar-fill')
			const barBox = h('span.abs-bar.abs-upload-bar', { hidden: true }, bar)
			const list = h('div.abs-grid')
			const start = h('button.primary', { type: 'button', onclick: () => (running ? (stop = true) : run()) })
			const auto = h('input', { type: 'checkbox', checked: data.covers.auto, onchange: async () => {
				auto.disabled = true
				try {
					data.covers.auto = (await api('PUT', '/web/covers/auto', { enabled: auto.checked })).auto
					if (data.covers.auto && missing().length && !running) run()
				} catch (e) {
					auto.checked = data.covers.auto
					toast(e.message, true)
				}
				auto.disabled = false
			} })

			function show() {
				const n0 = missing()
				if (!running) line.textContent = n0.length ? n('%n book has no cover.', '%n books have no cover.', n0.length) : t('Every book has a cover.')
				start.textContent = running ? t('Stop') : t('Find missing covers')
				start.disabled = !running && (!n0.length || !data.covers.online)
				list.replaceChildren(...n0.map((b) => h('button.abs-cover-pick', { type: 'button', disabled: running, title: t('Choose a cover for {title}', { title: b.title }), onclick: () => openBook(b, show) },
					cover(b), h('span.abs-card-title', { dir: 'auto' }, b.title), b.author && h('span.abs-card-sub', { dir: 'auto' }, b.author))))
			}

			async function run() {
				running = true
				stop = false
				let found = 0
				const todo = missing()
				barBox.hidden = false
				show()
				let error = ''
				for (let i = 0; i < todo.length && !stop; i++) {
					line.textContent = t('Looking up {n} of {total}: {title}', { n: i + 1, total: todo.length, title: todo[i].title })
					bar.style.width = (i / todo.length * 100) + '%'
					try {
						const r = await api('POST', '/web/items/' + todo[i].id + '/cover/auto')
						if (r.hasCover) {
							found++
							changed(todo[i].id, r)
						}
					} catch (e) {
						if (e.code !== 'own') {
							error = e.message
							break
						}
					}
					// The search service allows about 20 lookups a minute.
					if (i + 1 < todo.length && !stop) await wait(4000)
				}
				running = false
				barBox.hidden = true
				show()
				const left = missing().length
				line.textContent = [n('%n cover found.', '%n covers found.', found), left ? t('For the others, click a book to choose a cover yourself.') : '', error].filter(Boolean).join(' ')
			}

			d.append(...kids([closeButton(() => d.shut()), h('h3', null, t('Covers')),
				data.covers.online ? null : h('p.abs-error', null, t('This server has no internet connection, so covers can only be uploaded.')),
				line, barBox, h('p', null, start),
				h('label.abs-check', null, auto, h('span', null, t('Find covers for books without one automatically'))),
				h('p.abs-muted', null, t('Covers come from Apple\'s audiobook search. For each book without a cover, this server sends its title and author there. A cover is only used when both match. Covers are made square and stored in Nextcloud; your files are not changed.')),
				list]))
			show()
			d.showModal()
		}

		function openBook(b, after) {
			const d = dialog('abs-cover-one', t('Choose cover'), () => after && after())
			const q = h('input', { type: 'search', value: [b.title, b.authors && b.authors[0]].filter(Boolean).join(' '), maxlength: '200', dir: 'auto', 'aria-label': t('Search covers') })
			const status = h('p.abs-muted', { role: 'status' })
			const results = h('div.abs-grid')
			const busy = (on) => {
				for (const x of d.querySelectorAll('button:not(.abs-close), input')) x.disabled = on
			}

			async function use(fn) {
				busy(true)
				status.textContent = t('Saving…')
				try {
					const r = await fn()
					changed(b.id, r)
					d.shut()
					if (bookDialog) showBook(b.id)
				} catch (e) {
					status.textContent = e.message
					busy(false)
				}
			}

			async function search() {
				busy(true)
				status.textContent = t('Searching…')
				results.replaceChildren()
				try {
					const r = await api('POST', '/web/covers/search', { q: q.value })
					status.textContent = r.results.length ? t('Click the right cover.') : t('Nothing found. Try other words, for example only the title.')
					results.replaceChildren(...r.results.map((c) => {
						const label = [c.title, c.author, c.year].filter(Boolean).join(', ')
						return h('button.abs-cover-pick', { type: 'button', title: label, 'aria-label': label, onclick: () => use(() => api('POST', '/web/items/' + b.id + '/cover', { url: c.url, sig: c.sig })) },
							h('span.abs-cover', null, h('img', { src: url('/web/covers/preview') + '?url=' + encodeURIComponent(c.thumb) + '&sig=' + c.thumbSig, alt: '', loading: 'lazy' })),
							h('span.abs-card-title', { dir: 'auto' }, c.title), h('span.abs-card-sub', { dir: 'auto' }, [c.author, c.year].filter(Boolean).join(' · ')))
					}))
				} catch (e) {
					status.textContent = e.message
				}
				busy(false)
			}

			async function upload(file) {
				const form = new FormData()
				form.append('image', file)
				const res = await fetch(url('/web/items/' + b.id + '/cover/upload'), { method: 'POST', headers: { requesttoken: OC.requestToken }, body: form })
				const r = await res.json().catch(() => ({}))
				if (!res.ok) throw new Error(r.error || r.message || t('The server did not answer ({status})', { status: res.status }))
				return r
			}

			d.append(...kids([closeButton(() => d.shut()), h('h3', { dir: 'auto' }, b.title),
				data.covers.online && h('form.abs-cover-search', { onsubmit: (e) => {
					e.preventDefault()
					search()
				} }, q, h('button.primary', { type: 'submit' }, t('Search'))),
				data.covers.online && h('p.abs-muted', null, t('The words above are sent to Apple\'s audiobook search.')),
				h('div.abs-cover-tools', null,
					h('button', { type: 'button', onclick: () => {
						const input = h('input', { type: 'file', accept: '.jpg,.jpeg,.png,.webp' })
						input.addEventListener('change', () => input.files[0] && use(() => upload(input.files[0])))
						input.click()
					} }, t('Upload an image')),
					b.coverChosen && h('button', { type: 'button', onclick: () => use(() => api('DELETE', '/web/items/' + b.id + '/cover')) }, b.coverInFiles ? t('Use the cover from the files again') : t('Remove cover')),
					h('span.abs-muted', null, b.coverInFiles && !b.coverChosen
						? t('Images are made square. The cover in the book\'s files stays as it is and comes back when you remove the new one.')
						: t('Images are made square.'))),
				status, results]))
			d.showModal()
		}

		/** After an upload, when automatic covers are on. */
		async function afterUpload(ids) {
			if (!data.covers.auto || !data.covers.online) return
			for (const id of ids) {
				const b = data.books.find((x) => x.id === id)
				if (!b || b.hasCover) continue
				try {
					const r = await api('POST', '/web/items/' + id + '/cover/auto')
					if (r.hasCover) changed(id, r)
				} catch (e) {
					return
				}
				await wait(4000)
			}
		}

		return { openAll, openBook, afterUpload }
	})()

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
				h('datalist', { id: 'abs-series' }, seriesNames().map((x) => h('option', { value: x }))),
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
			const ids = []
			for (const x of queue) {
				if (signal.aborted) break
				x.state = 'uploading'
				x.message = t('Starting…')
				update()
				try {
					ids.push((await uploadOne(x, signal)).id)
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
				Covers.afterUpload(ids)
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
			return api('POST', '/web/upload/finish', { libraryPath: x.plan.library, folder: x.plan.folder, metadata: metaOf(x) })
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
