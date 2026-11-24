/**
 * file: any document of the media library (PDF…), stores the attachment ID.
 * "vpAllowedTypes": list of MIME types or families accepted by the media library, e.g. ["application/pdf"] (default: all).
 */
import { wp, el, c, addControl } from 'wp-field/common'

const { MediaUpload, MediaUploadCheck } = wp.blockEditor

const size = (bytes) => (bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(bytes / 1024)) + ' Ko')

function FileField({ def, label, help, value, onChange }) {
	const media = wp.data.useSelect((select) => (value ? select('core').getMedia(value) : null), [value])
	const name = media && (media.source_url || '').split('/').pop()
	const details = media && [media.mime_type, media.media_details?.filesize ? size(media.media_details.filesize) : ''].filter(Boolean).join(' · ')
	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			MediaUploadCheck,
			null,
			el(MediaUpload, {
				allowedTypes: def.vpAllowedTypes,
				value,
				onSelect: (m) => onChange(m.id),
				render: ({ open }) =>
					el(
						'div',
						{ className: 'wp-field-file' },
						value
							? el(
									'div',
									{ className: 'wp-field-file__current' },
									media ? el('a', { href: media.source_url, target: '_blank', rel: 'noreferrer' }, name) : el(c.Spinner),
									details ? el('span', { className: 'wp-field-file__details' }, details) : null
							  )
							: null,
						el(
							'div',
							{ className: 'wp-field-file__actions' },
							el(c.Button, { variant: 'secondary', size: 'compact', onClick: open }, value ? 'Remplacer' : 'Choisir un fichier'),
							value ? el(c.Button, { variant: 'tertiary', isDestructive: true, size: 'compact', onClick: () => onChange(0) }, 'Retirer') : null
						)
					),
			})
		)
	)
}

addControl('file', FileField)
