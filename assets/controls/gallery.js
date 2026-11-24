/**
 * gallery: ordered list of images from the media library, stores attachment IDs.
 * Order is changed in the media library gallery view (drag and drop); "maxItems" limits the number of images.
 */
import { wp, el, c, addControl } from 'wp-field/common'

const { MediaUpload, MediaUploadCheck } = wp.blockEditor

function Thumb({ id, onRemove }) {
	const media = wp.data.useSelect((select) => select('core').getMedia(id), [id])
	const src = media && ((media.media_details?.sizes?.thumbnail || {}).source_url || media.source_url)
	return el(
		'li',
		null,
		src ? el('img', { src, alt: '' }) : el(c.Spinner),
		el(c.Button, { className: 'wp-field-gallery__remove', size: 'small', icon: 'no-alt', label: 'Retirer', onClick: onRemove })
	)
}

function GalleryField({ def, label, help, value, onChange }) {
	const ids = (Array.isArray(value) ? value : []).map(Number).filter(Boolean)
	const max = def.maxItems || Infinity
	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			MediaUploadCheck,
			null,
			el(MediaUpload, {
				allowedTypes: ['image'],
				multiple: true,
				gallery: true,
				value: ids,
				onSelect: (items) => onChange(items.map((m) => m.id).slice(0, max)),
				render: ({ open }) =>
					el(
						'div',
						{ className: 'wp-field-gallery' },
						ids.length ? el('ul', { className: 'wp-field-gallery__list' }, ids.map((id) => el(Thumb, { key: id, id, onRemove: () => onChange(ids.filter((x) => x !== id)) }))) : null,
						el(
							'div',
							{ className: 'wp-field-gallery__actions' },
							el(c.Button, { variant: 'secondary', size: 'compact', onClick: open }, ids.length ? 'Modifier la galerie' : 'Créer une galerie'),
							ids.length ? el(c.Button, { variant: 'tertiary', isDestructive: true, size: 'compact', onClick: () => onChange([]) }, 'Vider') : null
						),
						Number.isFinite(max) && ids.length >= max ? el('p', { className: 'wp-field-gallery__max' }, max + ' images maximum.') : null
					),
			})
		)
	)
}

addControl('gallery', GalleryField)
