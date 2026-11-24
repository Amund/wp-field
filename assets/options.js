/**
 * wp-field — options page (admin, outside of the block editor), declared in PHP with field::options_page() and field::option().
 * Values are read and saved through GET / POST /wp-field/v1/options/{page}, with the capability of the page.
 * Languages (field_languages / field_language filters, e.g. Polylang): the page edits the language of the admin bar switcher.
 * In a language, each translatable field inherits the default value until it is changed; "own" lists the fields with a value
 * of their own, sent with the values so that the server knows what to store for the language and what to inherit.
 */
import { wp, el, Fragment, useEffect, useState, c, Fields, groupFields, hasVisible, setupStandalone } from 'wp-field/common'

const data = JSON.parse(document.getElementById('wp-script-module-data-wp-field/options')?.textContent || '{}')
const fields = data.fields || {}
const languages = data.languages || {}
const language = data.language || ''
const path = wp.url.addQueryArgs('/wp-field/v1/options/' + data.page, language ? { lang: language } : {})
const translatable = (def) => !!language && def.vpTranslatable !== false

setupStandalone()

// selected tab, kept in the URL hash (reload, shared link)
const hashTab = () => decodeURIComponent(location.hash.slice(1))
const selectTab = (name) => history.replaceState(null, '', '#' + encodeURIComponent(name))

// fields grouped by "vpGroup" / "panel": one card per group, or one tab per group (field::options_page(…, ['tabs' => true | 'vertical']))
function Groups({ values, onChange, after }) {
	const groups = groupFields(fields, data.tabs ? 'Général' : '').filter(([, group]) => hasVisible(group, fields, values))
	const form = (group) => el(Fields, { fields: group, defs: fields, values, onChange, after })
	if (data.tabs && groups.length > 1) {
		const names = groups.map(([title]) => title)
		return el(
			c.TabPanel,
			{
				className: 'wp-field-options__tabs is-' + data.tabs,
				orientation: data.tabs,
				tabs: names.map((name) => ({ name, title: name })),
				initialTabName: names.includes(hashTab()) ? hashTab() : names[0],
				onSelect: selectTab,
			},
			(tab) => el(c.Card, { key: tab.name, className: 'wp-field-options__panel' }, el(c.CardBody, null, form(groups.find(([title]) => title === tab.name)[1])))
		)
	}
	return groups.map(([title, group]) =>
		el(
			c.Card,
			{ key: title, className: 'wp-field-options__panel' },
			title ? el(c.CardHeader, null, el('h2', null, title)) : null,
			el(c.CardBody, null, form(group))
		)
	)
}

// state: values shown, default values, fields with a value of their own for the language
const snapshot = (state) => JSON.stringify([state.values, [...state.own].sort()])

function OptionsPage() {
	const [state, setState] = useState(null)
	const [saved, setSaved] = useState('')
	const [busy, setBusy] = useState(false)
	const [notice, setNotice] = useState(null)
	const dirty = !!state && snapshot(state) !== saved
	const load = (r) => {
		const next = { values: r.values, defaults: r.defaults, own: new Set(r.own) }
		setState(next)
		setSaved(snapshot(next))
	}

	useEffect(() => {
		wp.apiFetch({ path })
			.then(load)
			.catch((e) => setNotice({ status: 'error', text: e.message }))
	}, [])

	// unsaved changes: warn before leaving the page (also when switching language in the admin bar)
	useEffect(() => {
		if (!dirty) return
		const warn = (e) => {
			e.preventDefault()
			e.returnValue = ''
		}
		window.addEventListener('beforeunload', warn)
		return () => window.removeEventListener('beforeunload', warn)
	}, [dirty])

	// a change in a language gives the field a value of its own
	const onChange = (patch) =>
		setState((s) => {
			const own = new Set(s.own)
			Object.keys(patch).forEach((key) => translatable(fields[key] || {}) && own.add(key))
			return { ...s, values: { ...s.values, ...patch }, own }
		})
	const inherit = (key) =>
		setState((s) => {
			const own = new Set(s.own)
			own.delete(key)
			return { ...s, values: { ...s.values, [key]: s.defaults[key] }, own }
		})

	// under each translatable field: inherited default value, or value of its own with a way back
	const after = (name, def) =>
		!translatable(def)
			? null
			: state.own.has(name)
			  ? el(
						'p',
						{ className: 'wp-field-language is-own' },
						'Valeur propre à ' + languages[language] + ' · ',
						el(c.Button, { variant: 'link', onClick: () => inherit(name) }, 'Revenir à la valeur par défaut')
			    )
			  : el('p', { className: 'wp-field-language' }, 'Valeur par défaut (toutes les langues)')

	const save = () => {
		setBusy(true)
		setNotice(null)
		wp.apiFetch({ path, method: 'POST', data: { values: state.values, own: [...state.own] } })
			.then((r) => {
				load(r)
				setNotice({ status: 'success', text: 'Options enregistrées.' })
			})
			.catch((e) => {
				const details = e.data && e.data.details ? Object.values(e.data.details).join(' ') : ''
				setNotice({ status: 'error', text: [e.message, details].filter(Boolean).join(' : ') })
			})
			.finally(() => setBusy(false))
	}

	const languageNotice = !Object.keys(languages).length
		? null
		: el(
				c.Notice,
				{ status: language ? 'info' : 'warning', isDismissible: false, className: 'wp-field-options__language' },
				language
					? ['Vous modifiez les options pour la langue ', el('strong', { key: 'l' }, languages[language]), '. Les champs non modifiés reprennent la valeur par défaut.']
					: ['Vous modifiez les valeurs par défaut, utilisées par toutes les langues qui n’ont pas de valeur propre.'],
				' Utilisez le sélecteur de langue de la barre d’administration pour changer de langue.'
		  )

	return el(
		Fragment,
		null,
		el('h1', null, data.title + (language ? ' — ' + languages[language] : '')),
		languageNotice,
		notice ? el(c.Notice, { status: notice.status, onRemove: () => setNotice(null) }, notice.text) : null,
		!state
			? notice
				? null
				: el(c.Spinner)
			: el(
					'form',
					{
						onSubmit: (e) => {
							e.preventDefault()
							save()
						},
					},
					el(Groups, { values: state.values, onChange, after }),
					el(
						'div',
						{ className: 'wp-field-options__actions' },
						el(c.Button, { variant: 'primary', type: 'submit', isBusy: busy, disabled: busy || !dirty, __next40pxDefaultSize: true }, 'Enregistrer'),
						dirty ? el('span', { className: 'wp-field-options__dirty' }, 'Modifications non enregistrées') : null
					)
			  )
	)
}

const root = document.getElementById('wp-field-options')
if (root) wp.element.createRoot(root).render(el(OptionsPage))
