/**
 * wp-field — shared helpers and fields rendering (module "wp-field/common"),
 * used by the block editor, the options pages and each control.
 * Editor packages are not (yet) published as modules: we read the classic window.wp.* globals.
 */
export const wp = window.wp
export const { createElement: el, Fragment, useEffect, useRef, useState, useMemo } = wp.element
export const c = wp.components

/**
 * Registers a control ("Edit" or "vpEdit" value) through the public filter "field.controls".
 * Built-in controls use priority 5: a theme or plugin control with the same name (default priority 10) replaces them.
 * The component receives {name, def, label, help, common, value, onChange}.
 */
export const addControl = (name, Component, priority = 5) =>
	wp.hooks.addFilter('field.controls', 'wp-field/' + name, (controls) => ({ ...controls, [name]: Component }), priority)

/** Choices of a field: DataForm "elements" [{value, label}], or "enum" labelled by its values */
export const options = (def) =>
	(def.elements || (def.enum || []).map((value) => ({ value, label: String(value) }))).map((o) => ({ value: String(o.value), label: String(o.label ?? o.value) }))

/** Control of a field: "vpEdit" (wp-field control) or "Edit" (DataForm control name), else deduced from type and choices */
export const controlOf = (def) =>
	def.vpEdit ||
	def.Edit ||
	(def.elements || def.enum ? 'select' : def.type === 'boolean' ? 'toggle' : def.type === 'integer' || def.type === 'number' ? 'number' : 'text')

/** Empty value, for isValid.required (same rules as field::is_empty()) */
export const isEmpty = (v) =>
	v === undefined || v === null || v === '' || v === 0 || v === false || (Array.isArray(v) ? !v.some((x) => x !== '' && x !== null && x !== 0) : typeof v === 'object' && !Object.values(v).some(Boolean))

/** enum of numbers: the value goes back to a number */
export const cast = (def, value) => (def.type === 'integer' || def.type === 'number' ? Number(value) : value)

// ---------------------------------------------------------------------------
// Fields
// Each control (assets/controls/*.js, theme or plugin) registers itself through the filter "field.controls".

export function Field({ name, def, value, onChange, after }) {
	const required = !!(def.isValid && def.isValid.required)
	const label = (def.label || name) + (required ? ' *' : '')
	const help = def.description
	const common = { label, help, __nextHasNoMarginBottom: true, __next40pxDefaultSize: true }
	const controls = wp.hooks.applyFilters('field.controls', {})
	const Control = controls[controlOf(def)] || controls.text
	// a single element: one item of the fields list (spacing and separators)
	return el(
		'div',
		{ className: 'wp-field' },
		el(Control, { name, def, label, help, common, value, onChange }),
		required && isEmpty(value) ? el('p', { className: 'wp-field-required' }, 'Champ obligatoire.') : null,
		after ? after(name, def) : null
	)
}

// "vpShowIf": {"other": value | [values]}: the field is shown only when every condition matches
// (current value of the other field, or its default). A hidden field keeps its value.
export const visible = (def, defs, values) =>
	Object.entries(def.vpShowIf || {}).every(([key, expected]) => {
		const current = values[key] !== undefined ? values[key] : defs[key] && defs[key].default
		return Array.isArray(expected) ? expected.includes(current) : current === expected
	})

// defs: every field definition, for the defaults used by conditions (default: the displayed fields);
// after(name, def): optional element displayed under each field (e.g. language status on options pages)
export function Fields({ fields, defs = fields, values, onChange, after }) {
	return el(
		c.__experimentalVStack,
		{ spacing: 4, className: 'wp-field-list' },
		Object.entries(fields)
			.filter(([, def]) => visible(def, defs, values))
			.map(([name, def]) => el(Field, { key: name, name, def, value: values[name], onChange: (v) => onChange({ [name]: v }), after }))
	)
}

/**
 * Fields grouped by "vpGroup" ("panel" is accepted for post meta and options), in order of first appearance.
 * Fields without group go to the fallback group. Returns [[title, {name: def}], …].
 */
export const groupFields = (fields, fallback = '') => {
	const groups = new Map()
	Object.entries(fields).forEach(([name, def]) => {
		const title = def.vpGroup || def.panel || fallback
		if (!groups.has(title)) groups.set(title, {})
		groups.get(title)[name] = def
	})
	return [...groups]
}

/** Does a group have at least one visible field (vpShowIf)? */
export const hasVisible = (group, defs, values) => Object.values(group).some((def) => visible(def, defs, values))

/**
 * Outside of the block editor (options pages, term and user screens): media library for MediaUpload
 * (MediaUploadCheck requires the "mediaUpload" setting), link suggestions for LinkControl.
 */
export function setupStandalone() {
	wp.hooks.addFilter('editor.MediaUpload', 'wp-field/media-upload', () => wp.mediaUtils.MediaUpload)
	wp.data.dispatch('core/block-editor').updateSettings({
		mediaUpload: (args) => wp.mediaUtils.uploadMedia(args),
		__experimentalFetchLinkSuggestions: (search, options) => wp.coreData.__experimentalFetchLinkSuggestions(search, options, {}),
	})
}

/** Fields grouped in cards (one per vpGroup / panel; a group without title has no header) */
export function FieldCards({ fields, values, onChange }) {
	return groupFields(fields)
		.filter(([, group]) => hasVisible(group, fields, values))
		.map(([title, group]) =>
			el(
				c.Card,
				{ key: title, className: 'wp-field-options__panel' },
				title ? el(c.CardHeader, null, el('h2', null, title)) : null,
				el(c.CardBody, null, el(Fields, { fields: group, defs: fields, values, onChange }))
			)
		)
}
