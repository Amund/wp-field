/**
 * Simple controls, thin wrappers of WordPress components:
 * DataForm control names: text, email, url, telephone, textarea, integer, number, toggle, checkbox, select, radio, toggleGroup;
 * wp-field: range.
 */
import { el, c, addControl, options, cast } from 'wp-field/common'

addControl('text', ({ common, value, onChange }) => el(c.TextControl, { ...common, value: value || '', onChange }))

addControl('email', ({ common, value, onChange }) => el(c.TextControl, { ...common, type: 'email', value: value || '', onChange }))

addControl('url', ({ common, value, onChange }) => el(c.TextControl, { ...common, type: 'url', placeholder: 'https://', value: value || '', onChange }))

addControl('telephone', ({ common, value, onChange }) => el(c.TextControl, { ...common, type: 'tel', value: value || '', onChange }))

addControl('textarea', ({ common, value, onChange }) => el(c.TextareaControl, { ...common, value: value || '', onChange }))

const NumberField = ({ def, common, value, onChange }) =>
	el(c.TextControl, {
		...common,
		type: 'number',
		min: def.minimum,
		max: def.maximum,
		step: def.vpStep,
		value: value ?? '',
		onChange: (v) => onChange(v === '' ? undefined : Number(v)),
	})
addControl('number', NumberField)
addControl('integer', NumberField)

addControl('range', ({ def, common, value, onChange }) => el(c.RangeControl, { ...common, min: def.minimum, max: def.maximum, step: def.vpStep, value, onChange }))

addControl('checkbox', ({ label, help, value, onChange }) => el(c.CheckboxControl, { label, help, __nextHasNoMarginBottom: true, checked: !!value, onChange }))

addControl('toggle', ({ label, help, value, onChange }) => el(c.ToggleControl, { label, help, __nextHasNoMarginBottom: true, checked: !!value, onChange }))

addControl('select', ({ def, common, value, onChange }) =>
	el(c.SelectControl, { ...common, value: String(value ?? ''), options: options(def), onChange: (v) => onChange(cast(def, v)) })
)

addControl('radio', ({ def, label, help, value, onChange }) =>
	el(c.RadioControl, { label, help, selected: String(value ?? ''), options: options(def), onChange: (v) => onChange(cast(def, v)) })
)

addControl('toggleGroup', ({ def, common, value, onChange }) =>
	el(
		c.__experimentalToggleGroupControl,
		{ ...common, isBlock: true, value: String(value ?? ''), onChange: (v) => onChange(cast(def, v)) },
		options(def).map((o) => el(c.__experimentalToggleGroupControlOption, { key: o.value, value: o.value, label: o.label }))
	)
)
