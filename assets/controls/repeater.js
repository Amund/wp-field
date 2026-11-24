/**
 * repeater: list of rows, each row an object of sub-fields ("vpFields", same vocabulary; vpShowIf relative to the row).
 * Rows can be added, reordered, collapsed and removed; "maxItems" limits them, "vpRowLabel" names the sub-field titling each row.
 * For blocks, child blocks (InnerBlocks) are usually a better fit: the repeater is mostly for post meta, options, terms and users.
 */
import { el, c, useRef, useState, addControl, Fields } from 'wp-field/common'

// initial row: the defaults of the sub-fields
const emptyRow = (fields) =>
	Object.fromEntries(
		Object.entries(fields)
			.filter(([, def]) => def.default !== undefined)
			.map(([name, def]) => [name, def.default])
	)

const rowTitle = (def, fields, row, i) => {
	const name = def.vpRowLabel || Object.keys(fields).find((key) => typeof row[key] === 'string' && row[key].trim() && !/</.test(row[key]))
	const text = name && typeof row[name] === 'string' ? row[name].trim() : ''
	return (i + 1) + '. ' + (text || 'Ligne ' + (i + 1))
}

function RepeaterField({ def, label, help, value, onChange }) {
	const fields = def.vpFields || {}
	const rows = Array.isArray(value) ? value : []
	const max = def.maxItems || Infinity
	// stable React keys, kept in the order of the rows (no key stored in the value)
	const keys = useRef([])
	const next = useRef(0)
	while (keys.current.length < rows.length) keys.current.push(next.current++)
	keys.current.length = rows.length
	const [open, setOpen] = useState(() => new Set())
	const toggle = (key) => setOpen((s) => (s.has(key) ? new Set([...s].filter((k) => k !== key)) : new Set([...s, key])))

	const set = (nextRows, nextKeys) => {
		keys.current = nextKeys
		onChange(nextRows)
	}
	const update = (i, patch) => set(rows.map((row, j) => (j === i ? { ...row, ...patch } : row)), keys.current)
	const move = (i, d) => {
		const r = rows.slice()
		const k = keys.current.slice()
		r.splice(i + d, 0, r.splice(i, 1)[0])
		k.splice(i + d, 0, k.splice(i, 1)[0])
		set(r, k)
	}
	const remove = (i) => set(rows.filter((_, j) => j !== i), keys.current.filter((_, j) => j !== i))
	const add = () => {
		const key = next.current++
		set([...rows, emptyRow(fields)], [...keys.current, key])
		setOpen((s) => new Set([...s, key]))
	}

	return el(
		c.BaseControl,
		{ label, help, __nextHasNoMarginBottom: true },
		el(
			'div',
			{ className: 'wp-field-repeater' },
			rows.map((row, i) => {
				const key = keys.current[i]
				const isOpen = open.has(key)
				return el(
					'div',
					{ key, className: 'wp-field-repeater__row' + (isOpen ? ' is-open' : '') },
					el(
						'div',
						{ className: 'wp-field-repeater__header' },
						el(
							'button',
							{ type: 'button', className: 'wp-field-repeater__title', 'aria-expanded': isOpen, onClick: () => toggle(key) },
							el(c.Icon, { icon: isOpen ? 'arrow-down-alt2' : 'arrow-right-alt2', size: 16 }),
							rowTitle(def, fields, row, i)
						),
						rows.length > 1 ? el(c.Button, { size: 'small', icon: 'arrow-up-alt2', label: 'Monter', disabled: i === 0, onClick: () => move(i, -1) }) : null,
						rows.length > 1 ? el(c.Button, { size: 'small', icon: 'arrow-down-alt2', label: 'Descendre', disabled: i === rows.length - 1, onClick: () => move(i, 1) }) : null,
						el(c.Button, { size: 'small', icon: 'no-alt', label: 'Retirer la ligne', isDestructive: true, onClick: () => remove(i) })
					),
					isOpen ? el('div', { className: 'wp-field-repeater__body' }, el(Fields, { fields, values: row, onChange: (patch) => update(i, patch) })) : null
				)
			}),
			rows.length < max ? el(c.Button, { variant: 'secondary', size: 'compact', icon: 'plus', onClick: add }, 'Ajouter une ligne') : el('p', { className: 'wp-field-gallery__max' }, max + ' lignes maximum.')
		)
	)
}

addControl('repeater', RepeaterField)
