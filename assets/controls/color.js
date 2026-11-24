/**
 * color: fixed palette with "elements" [{value: "#hex", label}] (or "enum"), otherwise free picker. Stores a hex string.
 */
import { el, c, addControl, options } from 'wp-field/common'

function ColorField({ def, label, help, value, onChange }) {
	const colors = options(def).filter((o) => o.value).map((o) => ({ color: o.value, name: o.label }))
	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(c.ColorPalette, {
			colors,
			value: value || undefined,
			disableCustomColors: colors.length > 0,
			clearable: true,
			onChange: (v) => onChange(v || ''),
		})
	)
}

addControl('color', ColorField)
