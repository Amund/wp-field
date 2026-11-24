/**
 * wp-field — fields in a classic admin form (term add / edit, user profile), declared with field::term_meta() / field::user_meta().
 * Each .wp-field-form container holds the fields (data-fields) and a hidden input "wp_field" with the values as JSON,
 * posted with the form and saved in PHP.
 */
import { wp, el, useEffect, useState, FieldCards, setupStandalone } from 'wp-field/common'

setupStandalone()

function Form({ fields, initial, input }) {
	const [values, setValues] = useState(initial)
	useEffect(() => {
		input.value = JSON.stringify(values)
	}, [values])
	// term add form (edit-tags.php): saved through ajax, then emptied by WordPress: back to the initial values
	useEffect(() => {
		if (!input.closest('#addtag') || !window.jQuery) return
		const reset = (event, xhr, settings) => {
			if (String(settings.data || '').includes('action=add-tag') && !String(xhr.responseText || '').includes('wp_error')) setValues(initial)
		}
		window.jQuery(document).on('ajaxSuccess', reset)
		return () => window.jQuery(document).off('ajaxSuccess', reset)
	}, [])
	return el(FieldCards, { fields, values, onChange: (patch) => setValues((v) => ({ ...v, ...patch })) })
}

document.querySelectorAll('.wp-field-form').forEach((container) => {
	const input = container.querySelector('input[name="wp_field"]')
	const fields = JSON.parse(container.dataset.fields || '{}')
	const initial = JSON.parse(input.value || '{}')
	const root = document.createElement('div')
	container.appendChild(root)
	wp.element.createRoot(root).render(el(Form, { fields, initial, input }))
})
