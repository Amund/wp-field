![Static Badge](https://img.shields.io/badge/wordpress-plugin-blue)

# wp-field

**wp-field** adds fields to native PHP blocks (`supports.autoRegister`, WordPress 7.0+) and to post types (post meta). Fields are declared in code (`block.json`, PHP) and edited in the block editor. There is no build step and no field definitions in the database.

It is a **temporary layer** over WordPress core, filling the gaps until core catches up. Re-evaluate it at each major WordPress version.

## Features

- Rich controls for block attributes and post meta: text, email, URL, textarea, rich text (TinyMCE), number, range, toggle, select, radio, button group, color (free or fixed palette), date, image, gallery, file, oEmbed video, link, post object, relationship, term(s), user(s), repeater.
- Conditional display of a field, according to the values of other fields.
- Field groups: inspector panels, tabs in the expanded view.
- Expanded view: the block fields in a wide side panel, opened from the block toolbar (or the inspector).
- Child blocks (InnerBlocks) for native PHP blocks, including mixed mode: the server render is shown in the editor, with editable child blocks in place of `$content`.
- Several child block areas in one block (zones).
- Options pages in the admin, with the same fields, and a value per language (Polylang built in, other plugins through two filters).
- Term and user meta, on the classic term and profile screens.
- Post meta panels in the editor document sidebar, with an optional CSS rule applied on front and in the editor canvas.
- Custom controls from a theme or plugin, through one PHP filter and one JS filter.
- Values are stored as WordPress stores them natively: block attributes in the post content, one `wp_postmeta` row per meta field.

## Requirements

- PHP 8.1 or later
- WordPress 7.0 or later

## Installation

### With Composer

1. Add the repository to your `composer.json` file:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Amund/wp-field.git"
        }
    ]
}
```

2. Install the plugin:

```sh
composer require amund/wp-field
```

It is installed as a must-use plugin: blocks depending on it cannot lose their editing interface because the plugin was deactivated.

## Block fields

A block stays a native PHP block. Fields are attributes with a few extra keys:

```json
{
    "supports": { "autoRegister": true },
    "attributes": {
        "title": { "type": "string", "label": "Title", "default": "" },
        "intro": { "type": "string", "label": "Intro", "vpEdit": "wysiwyg", "default": "" },
        "columns": { "type": "string", "label": "Columns", "Edit": "toggleGroup", "enum": ["2", "3", "4"], "default": "3", "vpClass": "has-columns-%s" },
        "variant": { "type": "string", "label": "Style", "elements": [{ "value": "light", "label": "Light" }, { "value": "dark", "label": "Dark" }], "default": "light", "description": "Background of the block." },
        "related": { "label": "Related", "vpEdit": "relationship", "vpPostType": ["post", "page"], "maxItems": 5 }
    }
}
```

As long as the attributes only use core keys (`type`, `default`, `enum`, `label`) and the block has no child blocks, core generates the settings panel alone. Otherwise, wp-field takes over for this block.

### Vocabulary

The vocabulary is modeled on **DataForm**, the form component core uses for the auto-generated block settings (still private in WordPress 7.1): DataForm keys keep their names and meaning, and wp-field renders them until DataForm is public. Keys prefixed with `vp` cover what DataForm does not provide (yet): searching a block.json for `vp` shows what still depends on wp-field.

| Key | Origin | Description |
|---|---|---|
| `type`, `default`, `enum`, `label` | core | as in core (`enum` alone: choices labelled by their values) |
| `Edit` | DataForm | control: `text` `textarea` `email` `url` `telephone` `integer` `number` `toggle` `checkbox` `select` `radio` `toggleGroup` `color` `date`. Default: deduced from `type` and choices (`text`, `number`, `toggle`, `select`) |
| `elements` | DataForm | choices `[{"value": "dark", "label": "Dark"}]` for `select` / `radio` / `toggleGroup`, palette for `color`; `enum` is deduced for server validation |
| `description` | DataForm | help text |
| `isValid` | DataForm | `{"required": true}`: required field, flagged in the editor, enforced on options pages |
| `minimum`, `maximum`, `maxItems`, `pattern` | JSON Schema | validated by WordPress server side |
| `vpEdit` | wp-field | control not provided by DataForm: `wysiwyg` (TinyMCE) `range` `image` `gallery` `file` `oembed` `link` `post` `relationship` `term` `terms` `user` `users` `repeater`, or a custom control |
| `vpStep` | wp-field | step of `number` / `range` |
| `vpGroup` | wp-field | group title: one inspector panel per group, one tab per group in the expanded view (fields without group: "Réglages"); for post meta and options, one panel / card per group |
| `vpShowIf` | wp-field | `{"other": value}` or `{"other": [values]}`: field shown only when every condition matches (DataForm uses an `isVisible` function, which JSON cannot hold) |
| `vpAllowedTypes` | wp-field | `file`: accepted MIME types or families, e.g. `["application/pdf"]` (default: all) |
| `vpFields` | wp-field | `repeater`: sub-fields `{"name": {…}}`, same vocabulary (`vpShowIf` relative to the row, `isValid.required` checked in each row) |
| `vpRowLabel` | wp-field | `repeater`: sub-field whose value titles each row (default: first text value of the row) |
| `vpTaxonomy` | wp-field | `term` / `terms`: taxonomy or list of taxonomies (default: all public taxonomies) |
| `vpRole` | wp-field | `user` / `users`: role or list of roles (default: all) |
| `vpPostType` | wp-field | `post` / `relationship` / `link`: post type or list of post types (default: all public types). `link` suggests published contents only, like the ACF link field: no terms, post formats nor media |
| `vpClass` | wp-field | CSS class added to the block; `"%s"` is replaced by the value, a boolean adds the class when true |

When DataForm becomes public, fields will be rendered with it, and each `vpEdit` control moves to `Edit` as soon as DataForm provides it. Stored values do not depend on wp-field.

The JSON type is deduced from the control: `image`, `file`, `post`, `term` and `user` store an ID (`integer`), `gallery`, `relationship`, `terms` and `users` an ordered list of IDs (`maxItems` limits them), `oembed` a URL, `repeater` a list of objects (one per row, keyed by sub-field), `link` an object `{url, title, target}`, `date` a `YYYY-MM-DD` string. `email` and `url` (absolute `http(s)://`) are strings checked by a `pattern`, empty allowed: escape them on output (`esc_url()`, `antispambot()`).

### Conditional display

```json
"layout": { "type": "string", "Edit": "toggleGroup", "enum": ["simple", "detail"], "default": "simple" },
"gallery": { "label": "Gallery", "vpEdit": "gallery", "vpShowIf": { "layout": "detail" } },
"note": { "type": "string", "label": "Note", "vpShowIf": { "layout": ["detail", "full"], "hasNote": true } }
```

Conditions compare the current value (or default) of another field of the same block or post type: a single value must be equal, a list means one of them, several conditions must all match. The condition only hides the field in the editor: its value is kept, so `render.php` decides what to output (e.g. no gallery when `layout` is `simple`).

### Repeater

```json
"figures": {
    "label": "Key figures", "vpEdit": "repeater", "maxItems": 4, "vpRowLabel": "title",
    "vpFields": {
        "value": { "type": "integer", "label": "Value", "default": 0 },
        "title": { "type": "string", "label": "Title", "default": "" },
        "detail": { "type": "string", "label": "Detail", "vpEdit": "wysiwyg", "default": "" }
    }
}
```

Rows can be added, collapsed, reordered and removed. In `render.php`, the value is a list of arrays: `foreach ($attributes['figures'] as $row) { $row['title'] … }`. For blocks, child blocks (InnerBlocks) are usually a better fit, since each item is then a block of its own; the repeater is mostly useful for post meta, options, terms and users, which have no child blocks.

### Render helpers

```php
$intro = field::wysiwyg($attributes['intro'] ?? '');       // sanitized, wpautop
$related = field::posts($attributes['related'] ?? []);      // published WP_Post[], in the chosen order
$class = ['my-block', ...field::classes($block->name, $attributes)]; // vpClass classes
$terms = field::terms($attributes['topics'] ?? []);              // WP_Term[], in the chosen order
$users = field::users($attributes['authors'] ?? []);             // WP_User[], in the chosen order
$video = field::oembed($attributes['video'] ?? '');              // embed HTML (cached by WordPress), '' if not embeddable
```

## Child blocks

```json
{
    "allowedBlocks": ["my/card"],
    "supports": {
        "autoRegister": true,
        "vpInnerBlocks": { "preview": true, "orientation": "horizontal", "appender": "button", "template": [["my/card"], ["my/card"]] }
    }
}
```

`render.php` outputs `$content`, as any dynamic block.

| Key | Description |
|---|---|
| `template`, `templateLock`, `orientation` | passed to InnerBlocks |
| `appender` | `"button"`: compact + button instead of the default paragraph |
| `preview` | `true`: the editor shows the server render, with child blocks in place of `$content`. `$content` must be the only content of its parent element. |
| `className` | class of the block in the editor, without `preview` |
| `wrapper` | class of the children container without `preview` (default `container`), or `false` |
| `zones` | settings of each zone, see below |

### Zones

A block has a single list of child blocks. For several areas, children are zone blocks (a block with a `zone` attribute), set by a locked template of the parent:

```json
"allowedBlocks": ["my/zone"],
"supports": { "autoRegister": true, "vpInnerBlocks": {
    "templateLock": "all",
    "template": [["my/zone", {"zone": "media"}], ["my/zone", {"zone": "text"}]],
    "zones": {
        "media": { "allowedBlocks": ["my/image"], "template": [["my/image", {}]], "templateLock": "all" },
        "text": { "allowedBlocks": ["my/wysiwyg", "my/button"] }
    }
}}
```

The zone block itself:

```json
{
    "name": "my/zone",
    "supports": { "autoRegister": true, "inserter": false, "lock": false, "vpInnerBlocks": { "wrapper": false } },
    "attributes": { "zone": { "type": "string", "default": "", "vpClass": "%s" } }
}
```

with `render.php`: `echo $content;`. Only the zone name is saved in the content: changing a zone config applies to existing content. Blocks meant for zones declare `"ancestor": ["my/zone"]` (not `parent`, which would bypass the `allowedBlocks` of each zone).

In the parent `render.php`:

```php
['media' => $media, 'text' => $text] = field::zones($block, ['media', 'text']);
```

## Post meta

```php
add_action('init', function () {
    field::meta('page', 'background', [
        'label' => 'Background', 'panel' => 'Appearance', 'Edit' => 'color',
        'elements' => [['value' => '#ffffff', 'label' => 'White'], ['value' => '#111111', 'label' => 'Night']],
        'default' => '',
        'css' => 'body { background-color: %s; }',
    ]);
});
```

Same vocabulary as block attributes, plus `panel` (panel title, default `Options`; `vpGroup` works too) and `css` (rule added on front for singular views of this post type, and in the editor canvas). Read the value with `get_post_meta($post_id, 'background', true)`.

## Term and user meta

```php
add_action('init', function () {
    field::term_meta('category', 'category_color', ['label' => 'Color', 'Edit' => 'color', 'default' => '']);
    field::term_meta('category', 'category_image', ['label' => 'Image', 'vpEdit' => 'image']);
    field::user_meta('user_job', ['label' => 'Job', 'vpGroup' => 'Author', 'default' => '', 'isValid' => ['required' => true]]);
});
```

Same vocabulary as block attributes; one card per group. The fields are added to the classic screens (term add and edit forms, profile and user edit screens) and posted with the form, then validated against their schema: invalid values are not saved. On the profile, an invalid value or a missing required field is reported as a profile error. Values are also registered for the REST API (`register_term_meta()` / `register_meta('user', …)`).

Read a value with `field::get_term_meta($term_id, 'category_color')` or `field::get_user_meta($user_id, 'user_job')`, typed according to the field.

User choices (`user` / `users` controls) are searched through `GET /wp-field/v1/users` (display names, for users who can `edit_posts`): core `/wp/v2/users` only lists authors of published contents to users without `list_users`.

## Options pages

```php
add_action('init', function () {
    field::options_page('site', [
        'title' => 'Site options',
        'capability' => 'edit_posts',
        'tabs' => 'vertical',
    ]);
    field::option('site', 'vp_phone', ['label' => 'Phone', 'panel' => 'Contact', 'Edit' => 'telephone', 'default' => '', 'isValid' => ['required' => true]]);
    field::option('site', 'vp_page_404', ['label' => '404 page', 'panel' => 'Pages', 'vpEdit' => 'post', 'vpPostType' => 'page']);
    field::option('site', 'vp_banner', ['label' => 'Show a banner', 'type' => 'boolean', 'Edit' => 'toggle', 'default' => false]);
    field::option('site', 'vp_banner_text', ['label' => 'Banner text', 'vpEdit' => 'wysiwyg', 'default' => '', 'vpShowIf' => ['vp_banner' => true]]);
});
```

`field::options_page()` arguments: `title`, `menu_title` (default: title), `parent` (parent menu slug such as `options-general.php` for a submenu, default: top-level menu), `icon`, `position`, `capability` (default `manage_options`), `tabs` (`true` or `'horizontal'`: one tab per group instead of one card per group, like ACF tab fields; `'vertical'`: tabs in a column on the left, stacked above the fields on small screens; the selected tab is kept in the URL hash, all tabs are saved together).

`field::option()` takes the same vocabulary as block attributes; fields are grouped in one card per `panel` (or `vpGroup`). Each field is one row of `wp_options`, named after its key: prefix the keys with the project prefix (`vp_…`) and never use a core option name. A generic prefix such as `site_` is not enough: core owns `site_logo` and `site_icon` (registered again during REST requests, it would replace the field registration).

Read a value with `field::get_option('vp_banner')`: the value is typed according to the field (`get_option()` returns `"1"` / `""` for a boolean).

The page is rendered with the same controls as the block editor, and saved through `GET` / `POST /wp-field/v1/options/{page}` with the capability of the page (the core `/wp/v2/settings` endpoint would require `manage_options`). Values are validated against the field schema (type, `enum` / `elements`, `pattern`, `maxItems`…) and `isValid.required` before saving; unknown keys are ignored.

### Languages

With a multilingual plugin, each option can have a value per language, like the "ACF Options for Polylang" plugin:

- the default value ("all languages") is used by every language without a value of its own;
- in a language, a field keeps the default value until it is changed; "Revenir à la valeur par défaut" removes the value of the language;
- `'vpTranslatable' => false` makes an option common to all languages (e.g. a logo).

Storage: `vp_phone` holds the default value, `vp_phone__en` the value of English, only when one was set. `field::get_option('vp_phone')` returns the value of the current language, or the default value; `field::get_option('vp_phone', 'en')` reads a given language (`''`: default value).

With **Polylang**, the language being edited is the one of the admin bar language switcher, as with ACF Options for Polylang, and the front language is the displayed language: nothing to configure.

Any other source (another plugin, a future core API) plugs into two filters:

```php
add_filter('field_languages', fn(array $languages) => ['fr' => 'Français', 'en' => 'English']); // slug => name, none: no languages
add_filter('field_language', fn(string $language) => my_current_language());                    // '' = all languages (default value)
```

Post meta and term meta need no language handling: a translated post or term is a post or term of its own, with its own values.

## Controls

Each control is a script module in `assets/controls/` (`basic.js` groups the thin wrappers of WordPress components: text, email, url, telephone, textarea, integer, number, range, toggle, checkbox, select, radio, toggleGroup). All files of this folder are loaded automatically: adding a control is adding a file. Shared helpers are in the `wp-field/common` module.

A control registers itself through the public filter `field.controls`, with the `addControl()` helper:

```js
import { el, c, addControl } from 'wp-field/common'

addControl('date', ({ common, value, onChange }) => el(c.TextControl, { ...common, type: 'date', value: value || '', onChange }))
```

The component receives `{name, def, label, help, common, value, onChange}`: `def` is the attribute definition, `common` the standard props of a WordPress control.

If the stored value is not a string, its JSON type is set in `field::normalize()`.

### Custom controls

A theme or plugin adds (or replaces) controls the same way, without modifying wp-field.

PHP, type of the stored value (not needed for a string):

```php
add_filter('field_schema', function (array $schema, string $control): array {
    if ($control === 'icons') {
        $schema['type'] = 'array';
        $schema['items'] = ['type' => 'string'];
    }
    return $schema;
}, 10, 2);
```

JS, interface, as a script module depending on `wp-field/common`:

```php
wp_enqueue_script_module('my-controls', "$url/controls.js", ['wp-field/common'], $version);
```

```js
import { el, c, addControl } from 'wp-field/common'

addControl('icons', IconsField, 10)
```

Built-in controls use priority 5: a control registered with the same name and the default priority 10 replaces them. Without importing the helpers, the raw filter works as well: `wp.hooks.addFilter('field.controls', 'my/controls', (controls) => ({ ...controls, icons: IconsField }))`.

## Migration from ACF blocks

WP-CLI commands (loaded with WP-CLI only, ACF must be active to read the ACF data):

```sh
wp field acf list                                   # ACF blocks used in contents, with counts
wp field acf scaffold vupar/media                   # "attributes" of the equivalent native block.json, to review
wp field acf convert vupar/media --to=vupar/media-native --dry-run
wp field acf convert vupar/media --to=vupar/media-native [--post_type=page] [--include=12,34]
wp field acf restore [--include=12]                 # puts back the content before the first conversion
wp field acf restore --forget                       # deletes the backups once the migration is validated
```

Workflow for each ACF block:

1. `scaffold` prints the attributes of the native block: ACF field types become wp-field controls (`Edit` / `vpEdit`), choices become `elements`, instructions `description`, required `isValid`, max `maxItems`, post types `vpPostType`, simple `==` conditions `vpShowIf`, tabs `vpGroup`. Groups and clones displayed as a group are flattened into `{parent}_{sub}` attributes (e.g. `media_type`, `media_vimeo_poster`).
2. Write the native block: this `block.json` and a `render.php` reading `$attributes` instead of `get_field()`.
3. `convert --dry-run` shows the attributes of each converted block; `convert` rewrites the contents. Only the converted blocks change in the content (other blocks are serialized back as they were); the attributes of block supports (align, anchor, className…) are kept, ACF ones (`name`, `data`, `mode`) are dropped.

`--to` defaults to the same block name, when the native block replaces the ACF one under the same name (convert first, then swap the block folder).

ACF values are read through the ACF API, whatever their storage (field names of older ACF blocks, field keys of recent ones), then converted to the wp-field storage: booleans, integer IDs, lists of IDs, `{url, title, target}` links, `YYYY-MM-DD` dates, repeater rows. Not supported (reported, skipped): flexible content, multiple choices (`checkbox`, multiple `select`, kept as a list of values without an equivalent control), conditions other than a single group of `==` rules.

Revisions are disabled on many sites: the content before the first conversion is kept in a post meta (`_wp_field_acf_backup`), which `restore` puts back, and `restore --forget` deletes.

## At each WordPress version

wp-field fills gaps of core: check what core now provides, and drop what it covers.

- **`window.__unstableAutoRegisterBlocks`**: unstable API listing the native PHP blocks. If it changes, blocks lose their wp-field editing interface (stored values and front rendering do not depend on it). Test the "Démo des champs" block and the other native blocks in the editor.
- **Block Fields** (core roadmap): media, link and rich text fields for PHP-only blocks. When available, migrate the matching `vpEdit` block by block (stored values stay the same).
- **InnerBlocks for PHP-only blocks**: out of core scope so far. A native syntax would replace `supports.vpInnerBlocks`.
- **DataForm** (`@wordpress/dataviews`): the form component core uses for the auto-generated block settings, and the direction of the WordPress admin UI. It is private for now (core uses it through `unlock()`, which wp-field never does). Once public, render the fields with DataForm and port the controls as DataForm field types: the closest alignment with core UX.

## Limits

- Relies on `window.__unstableAutoRegisterBlocks`, an unstable core API: check the editor at each WordPress version. Stored values and front rendering do not depend on it.
- Zone and child block rules are enforced in the editor only.
- Editor labels are in French.
