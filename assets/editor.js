/**
 * wp-field — block editor (script module, no build step): block edit, inspector, server preview, child blocks, meta panels.
 * Controls are separate modules (assets/controls/*.js); fields rendering and shared helpers in common.js.
 * Public APIs only, no unlock() of private APIs. See class/field.php for the attribute vocabulary.
 */
import { wp, el, Fragment, useEffect, useRef, useState, useMemo, c, Fields, groupFields, hasVisible, controlOf } from 'wp-field/common'

const { addFilter } = wp.hooks
const { BlockControls, InspectorControls, InnerBlocks, useBlockProps, useInnerBlocksProps } = wp.blockEditor
const { useSelect } = wp.data
const { useDisabled } = wp.compose

// data passed by PHP (filter script_module_data_wp-field)
const data = JSON.parse(document.getElementById('wp-script-module-data-wp-field')?.textContent || '{}')
const metaFields = data.meta || {}

// native PHP blocks (autoRegister) exposed by core
const autoRegistered = () => window.__unstableAutoRegisterBlocks || []

// attribute keys handled by this layer (same list as field::KEYS)
const KEYS = ['Edit', 'vpEdit', 'vpFields', 'elements', 'description', 'isValid', 'vpShowIf', 'vpGroup', 'vpStep', 'vpPostType', 'vpClass', 'vpAllowedTypes']

const handles = (settings) =>
	!!settings.allowedBlocks ||
	!!(settings.supports && settings.supports.vpInnerBlocks) ||
	Object.values(settings.attributes || {}).some((def) => def && KEYS.some((k) => k in def))

// ---------------------------------------------------------------------------
// Edit

// editable fields: the block's own attributes, not those added by supports (style, className, metadata…)
const editableFields = (attributes) =>
	Object.fromEntries(Object.entries(attributes || {}).filter(([, def]) => def && (def.label || def.Edit || def.vpEdit) && !def.source && def.role !== 'local'))

// same rules as field::classes() in PHP
const classes = (attributes, values) =>
	Object.entries(attributes || {})
		.filter(([, def]) => def && def.vpClass)
		.map(([k, def]) => {
			const v = values[k] !== undefined ? values[k] : def.default
			if (typeof v === 'boolean') return v ? def.vpClass : ''
			return v !== undefined && v !== '' && v !== null ? def.vpClass.replace('%s', v) : ''
		})
		.filter(Boolean)
		.join(' ')

// Block settings: inspector panels, and an expanded view (wide side panel) opened from the block toolbar.
// Fields are grouped by "vpGroup": one inspector panel per group, one tab per group in the expanded view.
// Only one of them renders the fields at a time (a single TinyMCE instance per field).
function Settings({ settings, attributes, setAttributes }) {
	const [expanded, setExpanded] = useState(false)
	const [tab, setTab] = useState(null)
	const fields = editableFields(settings.attributes)
	if (!Object.keys(fields).length) return null
	const defs = settings.attributes
	// groups with at least one visible field
	const groups = groupFields(fields, 'Réglages').filter(([, group]) => hasVisible(group, defs, attributes))
	const form = (group) => el(Fields, { fields: group, defs, values: attributes, onChange: setAttributes })
	const close = () => setExpanded(false)
	const expandButton = el(c.Button, { key: 'expand', className: 'wp-field-expand', variant: 'secondary', size: 'compact', icon: 'editor-expand', onClick: () => setExpanded(true) }, 'Vue étendue')

	return el(
		Fragment,
		null,
		el(BlockControls, { group: 'other' }, el(c.ToolbarButton, { icon: 'edit', label: 'Vue étendue', onClick: () => setExpanded(true) })),
		el(
			InspectorControls,
			null,
			expanded
				? el(c.PanelBody, { title: groups[0] ? groups[0][0] : 'Réglages' }, el('p', { className: 'wp-field-expanded-notice' }, 'Champs ouverts dans la vue étendue.'))
				: groups.map(([title, group], i) =>
						el(c.PanelBody, { key: title, title, initialOpen: i === 0 }, i === 0 ? expandButton : null, form(group))
				  )
		),
		expanded
			? el(
					c.Modal,
					{
						title: settings.title,
						className: 'wp-field-expanded',
						overlayClassName: 'wp-field-expanded-overlay',
						isDismissible: false,
						onRequestClose: close,
						headerActions: el(c.Button, { variant: 'primary', onClick: close }, 'Terminé'),
					},
					groups.length > 1
						? el(
								c.TabPanel,
								{
									className: 'wp-field-tabs',
									tabs: groups.map(([title]) => ({ name: title, title })),
									initialTabName: tab && groups.some(([title]) => title === tab) ? tab : groups[0][0],
									onSelect: setTab,
								},
								(current) => el('div', { key: current.name, className: 'wp-field-tab' }, form(groups.find(([title]) => title === current.name)[1]))
						  )
						: groups[0]
						  ? form(groups[0][1])
						  : null
			  )
			: null
	)
}

// ---------------------------------------------------------------------------
// Server preview
// The render.php HTML is converted to React elements: the root receives the block props (useBlockProps),
// which avoids a wrapper <div> (a child <li> block stays the direct child of its <ul>).
// In mixed mode, the element containing the <wp-field-inner-blocks> placeholder receives the InnerBlocks props.

const MARKER = 'wp-field-inner-blocks'
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'])
const ATTR = { class: 'className', for: 'htmlFor', srcset: 'srcSet', tabindex: 'tabIndex', colspan: 'colSpan', rowspan: 'rowSpan', readonly: 'readOnly', crossorigin: 'crossOrigin', 'xlink:href': 'xlinkHref', referrerpolicy: 'referrerPolicy', allowfullscreen: 'allowFullScreen', frameborder: 'frameBorder' }

const parseStyle = (css) =>
	Object.fromEntries(
		css
			.split(';')
			.map((d) => d.split(/:(.*)/s).map((s) => s && s.trim()))
			.filter(([k, v]) => k && v)
			.map(([k, v]) => [k.startsWith('--') ? k : k.replace(/-([a-z])/g, (_, l) => l.toUpperCase()), v])
	)

const toProps = (node) => {
	const props = {}
	for (const { name, value } of node.attributes) {
		if (name === 'style') props.style = parseStyle(value)
		else props[ATTR[name] || name] = value
	}
	return props
}

const merge = (a, b) => ({
	...a,
	...b,
	className: [a.className, b.className].filter(Boolean).join(' ') || undefined,
	style: a.style || b.style ? { ...a.style, ...b.style } : undefined,
})

function convert(node, key, root, inner) {
	if (node.nodeType === 3) return node.data
	if (node.nodeType !== 1 || node.tagName === 'SCRIPT') return null
	const tag = node.tagName.toLowerCase()
	let props = { ...toProps(node), key }
	if (root) props = merge(props, root)
	if (inner && [...node.children].some((n) => n.tagName.toLowerCase() === MARKER)) {
		// child blocks container: its own children are replaced by InnerBlocks
		const { children, ...innerProps } = inner
		return el(tag, merge(props, innerProps), children)
	}
	if (inner && node.querySelector(MARKER)) {
		return el(tag, props, [...node.childNodes].map((n, i) => convert(n, i, null, inner)))
	}
	if (VOID.has(tag)) return el(tag, props)
	return el(tag, { ...props, dangerouslySetInnerHTML: { __html: node.innerHTML } })
}

function useRendered(name, attributes, postId) {
	const [state, setState] = useState({ html: null })
	const json = JSON.stringify(attributes)
	const first = useRef(true)
	useEffect(() => {
		let alive = true
		const timer = setTimeout(
			() =>
				wp
					.apiFetch({
						path: wp.url.addQueryArgs('/wp/v2/block-renderer/' + name, { context: 'edit', post_id: postId }),
						// POST: a rich text does not necessarily fit in a URL
						method: 'POST',
						data: { attributes: JSON.parse(json) },
					})
					.then((r) => alive && setState({ html: r.rendered || '' }))
					.catch((e) => alive && setState({ html: '', error: e.message })),
			first.current ? 0 : 300
		)
		first.current = false
		return () => {
			alive = false
			clearTimeout(timer)
		}
	}, [name, json, postId])
	return state
}

// significant root nodes (no comments, scripts nor whitespace)
const rootNodes = (html) =>
	[...new DOMParser().parseFromString(html, 'text/html').body.childNodes].filter(
		(n) => (n.nodeType === 1 && n.tagName !== 'SCRIPT') || (n.nodeType === 3 && n.data.trim())
	)

function Rendered({ html, blockProps, inner }) {
	const nodes = useMemo(() => (html ? rootNodes(html) : []), [html])
	if (nodes.length === 1 && nodes[0].nodeType === 1) return convert(nodes[0], 0, blockProps, inner)
	return el('div', blockProps, nodes.map((n, i) => convert(n, i, null, inner)))
}

const placeholder = (blockProps, label, error) =>
	el('div', blockProps, el(c.Placeholder, { label, instructions: error || 'Renseigner les champs dans la barre latérale.' }))

function Preview({ name, settings, attributes, context }) {
	const disabledRef = useDisabled()
	const blockProps = useBlockProps({ ref: disabledRef })
	const { html, error } = useRendered(name, attributes, context && context.postId)
	if (html === null) return el('div', blockProps, el(c.Spinner))
	if (!html.trim()) return placeholder(blockProps, settings.title, error)
	return el(Rendered, { html, blockProps })
}

// mixed mode: server render + editable child blocks in place of $content
function Hybrid({ name, settings, attributes, context }) {
	const opts = settings.supports.vpInnerBlocks
	const blockProps = useBlockProps({
		// preview links must not navigate the editor iframe
		onClickCapture: (e) => {
			const a = e.target.closest('a')
			if (a && !a.isContentEditable) e.preventDefault()
		},
	})
	const innerProps = useInnerBlocksProps(
		{},
		{
			template: opts.template,
			templateLock: opts.templateLock ?? false,
			orientation: opts.orientation,
			renderAppender: opts.appender === 'button' ? InnerBlocks.ButtonBlockAppender : undefined,
		}
	)
	const { html, error } = useRendered(name, attributes, context && context.postId)
	if (!html || !html.includes(MARKER)) {
		// not rendered yet, or render.php did not output $content: children stay editable
		return el('div', blockProps, html === null ? el(c.Spinner) : error ? el(c.Notice, { status: 'error', isDismissible: false }, error) : null, el('div', innerProps))
	}
	return el(Rendered, { html, blockProps, inner: innerProps })
}

function Nested({ settings, attributes, clientId }) {
	const opts = (settings.supports && settings.supports.vpInnerBlocks) || {}
	// zone block ("zone" attribute): its settings come from the parent, supports.vpInnerBlocks.zones[name]
	const zone = useSelect(
		(select) => {
			if (!attributes.zone) return null
			const be = select('core/block-editor')
			const parent = wp.blocks.getBlockType(be.getBlockName(be.getBlockRootClientId(clientId)) || '')
			return (parent && parent.supports && parent.supports.vpInnerBlocks && (parent.supports.vpInnerBlocks.zones || {})[attributes.zone]) || null
		},
		[attributes.zone, clientId]
	)
	const conf = { ...opts, ...(zone || {}) }
	const innerOptions = {
		allowedBlocks: conf.allowedBlocks,
		template: conf.template,
		templateLock: conf.templateLock ?? false,
		orientation: conf.orientation,
		renderAppender: conf.appender === 'button' ? InnerBlocks.ButtonBlockAppender : undefined,
	}
	const blockProps = useBlockProps({ className: [opts.className, classes(settings.attributes, attributes)].filter(Boolean).join(' ') })
	// "wrapper": false: children directly in the block wrapper (no intermediate div)
	if (opts.wrapper === false) return el('div', useInnerBlocksProps(blockProps, innerOptions))
	return el('div', blockProps, el('div', useInnerBlocksProps({ className: opts.wrapper || 'container' }, innerOptions)))
}

// ---------------------------------------------------------------------------
// Post type native fields (post meta), declared in PHP with field::meta()

// same sanitization as field::css_value()
const cssValue = (def, v) => {
	if (v === undefined || v === null || v === '' || typeof v === 'object') return ''
	if (controlOf(def) === 'color') return /^#([0-9a-f]{3}){1,2}$/i.test(v) ? v : ''
	return String(v).replace(/[^\w\s#%.,()\-\/]/gu, '')
}

// "css" rules of meta fields, applied to the canvas (iframe) and updated on each change
function useMetaCss(fields, meta) {
	const css = Object.entries(fields || {})
		.filter(([, def]) => def.css)
		.map(([key, def]) => {
			const v = cssValue(def, meta && meta[key])
			return v ? def.css.replace('%s', v) : ''
		})
		.filter(Boolean)
		.join('\n')
	useEffect(() => {
		const apply = () => {
			const iframe = document.querySelector('iframe[name="editor-canvas"]')
			const doc = iframe && iframe.contentDocument
			// non-iframed editor (meta boxes): body becomes the editing area
			const target = doc && doc.head ? doc : document
			const rules = target === document ? css.replace(/\bbody\b/g, '.editor-styles-wrapper') : css
			let style = target.getElementById('wp-field-css')
			if (!style) {
				style = target.createElement('style')
				style.id = 'wp-field-css'
				target.head.appendChild(style)
			}
			style.textContent = rules
		}
		apply()
		// the iframe can be recreated (tablet/mobile preview, template change): apply again
		const observer = new MutationObserver(() => {
			const doc = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument
			if (doc && doc.head && !doc.getElementById('wp-field-css')) apply()
		})
		observer.observe(document.body, { childList: true, subtree: true })
		return () => observer.disconnect()
	}, [css])
}

function MetaPanels() {
	const postType = useSelect((select) => select('core/editor').getCurrentPostType(), [])
	const [meta, setMeta] = wp.coreData.useEntityProp('postType', postType, 'meta')
	const fields = metaFields[postType]
	useMetaCss(fields, meta)
	if (!fields || !meta) return null
	return el(
		Fragment,
		null,
		groupFields(fields, 'Options').map(([title, group]) =>
			el(
				wp.editor.PluginDocumentSettingPanel,
				{ key: title, name: 'wp-field-' + title.toLowerCase().replace(/[^a-z0-9]+/g, '-'), title },
				el(Fields, { fields: group, defs: fields, values: meta, onChange: (patch) => setMeta({ ...meta, ...patch }) })
			)
		)
	)
}
if (Object.keys(metaFields).length) wp.plugins.registerPlugin('wp-field-meta', { render: MetaPanels })

addFilter('blocks.registerBlockType', 'wp-field/blocks', (settings, name) => {
	if (!autoRegistered().includes(name) || !handles(settings)) return settings

	const nested = !!settings.allowedBlocks || !!(settings.supports && settings.supports.vpInnerBlocks)
	const hybrid = nested && !!(settings.supports && settings.supports.vpInnerBlocks && settings.supports.vpInnerBlocks.preview)
	const Canvas = hybrid ? Hybrid : nested ? Nested : Preview
	return {
		...settings,
		usesContext: Array.from(new Set([...(settings.usesContext || []), 'postId'])),
		edit: (props) =>
			el(
				Fragment,
				null,
				el(Settings, { settings, attributes: props.attributes, setAttributes: props.setAttributes }),
				el(Canvas, { name, settings, attributes: props.attributes, context: props.context, clientId: props.clientId })
			),
		// a dynamic block with children must still serialize its children
		save: nested ? () => el(InnerBlocks.Content) : () => null,
	}
})
