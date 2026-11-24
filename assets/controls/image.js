/**
 * image: media library, stores the attachment ID.
 */
import { wp, el, c, addControl } from 'wp-field/common'

const { MediaUpload, MediaUploadCheck } = wp.blockEditor

function ImageField({ label, help, value, onChange }) {
	const media = wp.data.useSelect((select) => (value ? select('core').getMedia(value) : null), [value])
	const src = media && ((media.media_details?.sizes?.medium || {}).source_url || media.source_url)
	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			MediaUploadCheck,
			null,
			el(MediaUpload, {
				allowedTypes: ['image'],
				value,
				onSelect: (m) => onChange(m.id),
				render: ({ open }) =>
					el(
						'div',
						{ className: 'wp-field-image' },
						value
							? el('button', { type: 'button', className: 'wp-field-image__preview', onClick: open }, src ? el('img', { src, alt: '' }) : el(c.Spinner))
							: null,
						el(
							'div',
							{ className: 'wp-field-image__actions' },
							el(c.Button, { variant: 'secondary', onClick: open, size: 'compact' }, value ? 'Remplacer' : 'Choisir une image'),
							value ? el(c.Button, { variant: 'tertiary', isDestructive: true, size: 'compact', onClick: () => onChange(0) }, 'Retirer') : null
						)
					),
			})
		)
	)
}

addControl('image', ImageField)
