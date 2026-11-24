/**
 * date: browser date picker, stores "YYYY-MM-DD" (or an empty string).
 */
import { el, c, addControl } from 'wp-field/common'

addControl('date', ({ common, value, onChange }) => el(c.TextControl, { ...common, type: 'date', value: value || '', onChange }))
