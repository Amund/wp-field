/**
 * link: URL or internal content, stores {url, title, target}.
 * Suggestions: published contents only, as the ACF link field (no terms, post formats nor media);
 * "vpPostType" limits them to a post type or a list of post types.
 */
import { wp, el, c, addControl } from 'wp-field/common'

function LinkField({ def, label, help, value, onChange }) {
	const link = value && value.url ? { url: value.url, title: value.title, opensInNewTab: value.target === '_blank' } : null
	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			'div',
			{ className: 'wp-field-link' },
			el(wp.blockEditor.LinkControl, {
				value: link,
				suggestionsQuery: { type: 'post', subtype: def.vpPostType ? [].concat(def.vpPostType).join(',') : undefined },
				settings: [{ id: 'opensInNewTab', title: 'Ouvrir dans un nouvel onglet' }],
				onChange: (v) => onChange({ url: v.url || '', title: v.title || '', target: v.opensInNewTab ? '_blank' : '' }),
				onRemove: () => onChange({}),
				forceIsEditingLink: !link,
			})
		)
	)
}

addControl('link', LinkField)
