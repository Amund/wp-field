/**
 * wysiwyg: TinyMCE visual editor (classic editor, loaded by wp_enqueue_editor()), stores HTML.
 */
import { wp, el, c, useEffect, useRef, addControl } from 'wp-field/common'

function WysiwygField({ label, help, value, onChange }) {
	const id = 'wp-field-wysiwyg-' + wp.compose.useInstanceId(WysiwygField)
	const onChangeRef = useRef(onChange)
	onChangeRef.current = onChange

	useEffect(() => {
		const editor = wp.oldEditor || wp.editor
		if (!editor || !editor.initialize) return
		editor.initialize(id, {
			tinymce: {
				wpautop: true,
				toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,removeformat',
				block_formats: 'Paragraphe=p;Titre 3=h3;Titre 4=h4',
				setup: (ed) => ed.on('input change keyup undo redo', () => onChangeRef.current(ed.getContent())),
			},
			// visual editor only: no "Visual / Code" tabs
			quicktags: false,
			mediaButtons: false,
		})
		return () => editor.remove(id)
	}, [id])

	// value changed outside of this editor (undo, other view): update TinyMCE
	useEffect(() => {
		const ed = window.tinymce && window.tinymce.get(id)
		if (ed && !ed.hasFocus() && ed.getContent() !== (value || '')) ed.setContent(value || '')
	}, [id, value])

	return el(
		c.BaseControl,
		{ id, label, help, __nextHasNoMarginBottom: true },
		el('textarea', { id, className: 'wp-field-wysiwyg', defaultValue: value || '', rows: 8 })
	)
}

addControl('wysiwyg', WysiwygField)
