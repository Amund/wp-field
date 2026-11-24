/**
 * oembed: URL of an embeddable content (YouTube, Vimeo…), stores the URL, with a preview through the core oEmbed proxy.
 * Front: field::oembed($url) returns the embed HTML (cached by WordPress).
 */
import { wp, el, c, useEffect, useState, addControl } from 'wp-field/common'

function usePreview(url) {
	const [state, setState] = useState({ html: '', loading: false, error: '' })
	useEffect(() => {
		if (!/^https?:\/\/\S+$/.test(url || '')) {
			setState({ html: '', loading: false, error: '' })
			return
		}
		let alive = true
		setState((s) => ({ ...s, loading: true }))
		const timer = setTimeout(() => {
			wp.apiFetch({ path: wp.url.addQueryArgs('/oembed/1.0/proxy', { url }) })
				.then((r) => alive && setState({ html: r.html || '', loading: false, error: r.html ? '' : 'Aperçu indisponible.' }))
				.catch(() => alive && setState({ html: '', loading: false, error: 'Contenu non intégrable : vérifier l’URL.' }))
		}, 500)
		return () => {
			alive = false
			clearTimeout(timer)
		}
	}, [url])
	return state
}

function OembedField({ common, value, onChange }) {
	const { html, loading, error } = usePreview(value)
	return el(
		'div',
		{ className: 'wp-field-oembed' },
		el(c.TextControl, { ...common, type: 'url', placeholder: 'https://www.youtube.com/watch?v=…', value: value || '', onChange }),
		loading ? el(c.Spinner) : null,
		html ? el('div', { className: 'wp-field-oembed__preview', dangerouslySetInnerHTML: { __html: html } }) : null,
		!loading && error ? el('p', { className: 'wp-field-required' }, error) : null
	)
}

addControl('oembed', OembedField)
