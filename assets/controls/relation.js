/**
 * Choice of objects by search, single (stores an ID) or multiple (stores an ordered list of IDs, "maxItems" limits it):
 * - post / relationship : published contents, all public types or "vpPostType": "page" | ["page", "post"] (/wp/v2/search)
 * - term / terms        : taxonomy terms, all public taxonomies or "vpTaxonomy": "category" | […] (/wp/v2/search)
 * - user / users        : users, all roles or "vpRole": "editor" | […] (/wp-field/v1/users, capability edit_posts)
 */
import { wp, el, c, useEffect, useRef, useState, addControl } from 'wp-field/common'

const list = (value) => [].concat(value || []).join(',')
const decode = (s) => {
	const t = document.createElement('textarea')
	t.innerHTML = s
	return t.value
}

// object sources: search and fetch by IDs, results as {id, title, subtype}
const search = (type) => (def, query) => ({ type, subtype: list(type === 'post' ? def.vpPostType : def.vpTaxonomy) || 'any', ...query })
const SOURCES = {
	post: { path: '/wp/v2/search', query: search('post'), filterKey: 'vpPostType' },
	term: { path: '/wp/v2/search', query: search('term'), filterKey: 'vpTaxonomy' },
	user: { path: '/wp-field/v1/users', query: (def, query) => ({ role: list(def.vpRole) || undefined, ...query }), filterKey: 'vpRole' },
}
const request = (source, def, query) =>
	wp.apiFetch({ path: wp.url.addQueryArgs(source.path, source.query(def, { ...query, _fields: 'id,title,type,subtype' })) }).then((items) =>
		items.map((item) => ({ id: item.id, title: decode(item.title || '') || '#' + item.id, subtype: item.subtype || (['post', 'term'].includes(item.type) ? '' : item.type || '') }))
		// term search returns the taxonomy as "type", post search the post type as "subtype"
	)

// titles of already chosen objects, kept between renders (by source)
const titles = { post: new Map(), term: new Map(), user: new Map() }
function useTitles(kind, def, ids) {
	const [, refresh] = useState(0)
	const cache = titles[kind]
	const missing = ids.filter((id) => !cache.has(id))
	const key = missing.join(',')
	useEffect(() => {
		if (!missing.length) return
		// fetch by IDs without the search filters, to show an object chosen before a filter change
		request(SOURCES[kind], {}, { include: key, per_page: 100 }).then((items) => {
			items.forEach((item) => cache.set(item.id, item))
			// deleted or unpublished object: shown as such rather than silently removed
			missing.forEach((id) => cache.has(id) || cache.set(id, { id, title: '#' + id + ' (introuvable ou non publié)', subtype: '' }))
			refresh((n) => n + 1)
		})
	}, [key])
	return (id) => cache.get(id) || { id, title: '…', subtype: '' }
}

function useSearch(kind, def, text) {
	const [results, setResults] = useState([])
	const [loading, setLoading] = useState(false)
	const filter = list(def[SOURCES[kind].filterKey])
	useEffect(() => {
		if (text === null) return
		let alive = true
		setLoading(true)
		const timer = setTimeout(() => {
			request(SOURCES[kind], def, { search: text, per_page: 20 })
				.then((items) => {
					if (!alive) return
					items.forEach((item) => titles[kind].set(item.id, item))
					setResults(items.map((item) => item.id))
				})
				.finally(() => alive && setLoading(false))
		}, 250)
		return () => {
			alive = false
			clearTimeout(timer)
		}
	}, [text, filter])
	return { results, loading }
}

// The component itself opens the search: a click on the field (or Enter / Space on it) shows the search input,
// a click outside, Escape or a choice (single, or maximum reached) closes it. No add / replace button nor line:
// closed, the field only shows the chosen objects (or stays empty, like an empty input).
function Picker({ kind, def, label, help, value, onChange, multiple }) {
	const ids = (multiple ? (Array.isArray(value) ? value : []) : value ? [value] : []).map(Number)
	const max = multiple ? def.maxItems || Infinity : 1
	const canAdd = !multiple || ids.length < max
	const [text, setText] = useState('')
	const [open, setOpen] = useState(false)
	const ref = useRef()
	const title = useTitles(kind, def, ids)
	const { results, loading } = useSearch(kind, def, open ? text : null)
	const filter = [].concat(def[SOURCES[kind].filterKey] || [])
	const showType = filter.length !== 1
	const set = (next) => onChange(multiple ? next : next[0] || 0)
	const move = (i, d) => {
		const next = ids.slice()
		next.splice(i + d, 0, next.splice(i, 1)[0])
		set(next)
	}
	const close = () => {
		setOpen(false)
		setText('')
	}
	const add = (id) => {
		set(multiple ? [...ids, id] : [id])
		if (!multiple || ids.length + 1 >= max) close()
		else setTimeout(focusSearch)
	}
	const openSearch = () => canAdd && setOpen(true)
	const meta = (id) => (showType && title(id).subtype ? el('span', { className: 'wp-field-relation__type' }, title(id).subtype) : null)
	// buttons inside the field act on their own, without opening the search
	const stop = (handler) => (e) => {
		e.stopPropagation()
		handler()
	}

	// search input focused on opening, and again after a choice that keeps it open (the clicked result disappears)
	const focusSearch = () => ref.current?.querySelector('.wp-field-relation__search input')?.focus()
	useEffect(() => {
		if (!open) return
		focusSearch()
		// a click outside closes the search, even when the focus is no longer inside
		const outside = (e) => ref.current && !ref.current.contains(e.target) && close()
		document.addEventListener('mousedown', outside)
		return () => document.removeEventListener('mousedown', outside)
	}, [open])

	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			'div',
			{
				ref,
				className: 'wp-field-relation' + (open ? ' is-open' : '') + (canAdd ? ' can-add' : ''),
				onClick: openSearch,
				onBlur: (e) => ref.current && !ref.current.contains(e.relatedTarget) && close(),
				// the field itself is focusable: Enter / Space opens the search, Escape closes it
				tabIndex: canAdd && !open ? 0 : undefined,
				role: canAdd && !open ? 'button' : undefined,
				'aria-label': canAdd && !open ? label + ' : rechercher' : undefined,
				onKeyDown: (e) => {
					if (e.key === 'Escape' && open) {
						e.stopPropagation()
						close()
						// keyboard: the focus goes back to the field, ready to open the search again
						setTimeout(() => ref.current?.focus())
					} else if (!open && e.target === ref.current && (e.key === 'Enter' || e.key === ' ')) {
						e.preventDefault()
						openSearch()
					}
				},
			},
			ids.length
				? el(
						'ul',
						{ className: 'wp-field-relation__selected' },
						ids.map((id, i) =>
							el(
								'li',
								{ key: id },
								el('span', { className: 'wp-field-relation__title' }, title(id).title),
								meta(id),
								multiple && ids.length > 1 ? el(c.Button, { size: 'small', icon: 'arrow-up-alt2', label: 'Monter', disabled: i === 0, onClick: stop(() => move(i, -1)) }) : null,
								multiple && ids.length > 1 ? el(c.Button, { size: 'small', icon: 'arrow-down-alt2', label: 'Descendre', disabled: i === ids.length - 1, onClick: stop(() => move(i, 1)) }) : null,
								el(c.Button, { size: 'small', icon: 'no-alt', label: 'Retirer', isDestructive: true, onClick: stop(() => set(ids.filter((x) => x !== id))) })
							)
						)
				  )
				: null,
			open
				? el(
						'div',
						{ className: 'wp-field-relation__search' },
						el(c.SearchControl, { value: text, onChange: setText, placeholder: 'Rechercher…', __nextHasNoMarginBottom: true }),
						el(
							'ul',
							{ className: 'wp-field-relation__results' },
							loading && !results.length ? el('li', null, el(c.Spinner)) : null,
							!loading && !results.length ? el('li', { className: 'is-empty' }, 'Aucun résultat') : null,
							results
								.filter((id) => !ids.includes(id))
								.map((id) =>
									el(
										'li',
										{ key: id },
										el('button', { type: 'button', onClick: stop(() => add(id)) }, el('span', { className: 'wp-field-relation__title' }, title(id).title), meta(id))
									)
								)
						)
				  )
				: null
		)
	)
}

addControl('post', (props) => el(Picker, { ...props, kind: 'post', multiple: false }))
addControl('relationship', (props) => el(Picker, { ...props, kind: 'post', multiple: true }))
addControl('term', (props) => el(Picker, { ...props, kind: 'term', multiple: false }))
addControl('terms', (props) => el(Picker, { ...props, kind: 'term', multiple: true }))
addControl('user', (props) => el(Picker, { ...props, kind: 'user', multiple: false }))
addControl('users', (props) => el(Picker, { ...props, kind: 'user', multiple: true }))
