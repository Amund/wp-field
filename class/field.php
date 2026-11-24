<?php

/**
 * Fields for native PHP blocks (supports.autoRegister, WordPress 7.0+) and for post types (post meta),
 * declared in block.json / PHP, edited in the block editor, without build step nor field definitions in database.
 * Temporary layer over WordPress core: to re-evaluate at each major WordPress version (see README, "At each WordPress version"):
 * __unstableAutoRegisterBlocks, core Block Fields, InnerBlocks for PHP-only blocks, DataForm once public.
 *
 * BLOCKS
 * A block stays a native block (block.json + render.php, "supports": {"autoRegister": true}).
 * As long as its attributes only use core keys (type, default, enum, label), core handles its settings alone.
 * As soon as an attribute declares another key of the vocabulary below (or the block declares "allowedBlocks" / supports.vpInnerBlocks),
 * this layer takes over in the editor:
 * - core settings panel is disabled for this block (no duplicate);
 * - our controls are rendered in the inspector (assets/editor.js);
 * - with "allowedBlocks", the block accepts child blocks (core or custom), editable in the canvas.
 *
 * supports.vpInnerBlocks:
 *   template, templateLock, orientation : passed to InnerBlocks
 *   appender  : "button" for a compact + button instead of the default paragraph
 *   className : class of the block wrapper in the editor (when preview is false)
 *   wrapper   : class of the children container (default "container"), or false to put children directly in the block
 *   zones     : several child block areas (block with a "zone" attribute, e.g. vupar/zone): the parent template sets
 *               {"zone": "name"} items (locked template), and zones.name = {allowedBlocks, template, templateLock, orientation, appender}
 *               configures each zone. Only the name is saved in content: changing a zone config applies to existing content.
 *               Front: field::zones($block) returns the HTML of each zone.
 *   preview   : true = the editor shows the server render of the block, with child blocks in place of $content
 *               ($content must be the only content of its parent element);
 *               false (default) = the editor only shows child blocks, in <div class="{className}"><div class="{wrapper}">
 *
 * POST META
 * field::meta('page', 'key', [...]) on init: same vocabulary, panel in the editor document sidebar.
 *
 * TERM AND USER META
 * field::term_meta('category', 'key', [...]) / field::user_meta('key', [...]) on init: same vocabulary, fields on the classic
 * term (add / edit) and profile screens. Front: field::get_term_meta($term_id, 'key') / field::get_user_meta($user_id, 'key').
 *
 * OPTIONS PAGES
 * field::options_page('slug', [...]) + field::option('slug', 'key', [...]) on init: admin page with the same fields,
 * one wp_options row per field (named "key": project prefix, e.g. vp_, never a core option name such as site_logo). Front: field::get_option('key').
 * Languages (Polylang built in, or filters field_languages / field_language): a value per language ("key__en"), inheriting
 * the default value until set; "vpTranslatable": false for a value common to all languages.
 *
 * VOCABULARY of an attribute / meta field / option, modeled on DataForm (the form component of core, still private):
 * - core keys: type, default, enum, label;
 * - DataForm keys, same names and meaning (rendered by this layer until DataForm is public):
 *   Edit        : DataForm control: text | textarea | email | url | telephone | integer | number | toggle | checkbox
 *                 | select | radio | toggleGroup | color | date (default from type / elements: text, number, toggle, select)
 *   elements    : choices [{"value": "dark", "label": "Sombre"}] (select, radio, toggleGroup; color: fixed palette);
 *                 "enum" is deduced for server validation ("enum" alone also works, labelled by its values)
 *   description : help text
 *   isValid     : {"required": true}: required field (flagged in the editor, enforced on options pages)
 * - JSON Schema keys (validated by WordPress server side): minimum, maximum (number / range), maxItems (gallery, relationship), pattern;
 * - keys of this layer, prefixed with "vp", for what DataForm does not provide (yet):
 *   vpEdit     : wp-field control: wysiwyg (TinyMCE) | range | image | gallery | file | oembed | link | post | relationship
 *                | term | terms | user | users | repeater, or a custom control
 *   vpFields   : repeater: sub-fields {"name": {…}}, same vocabulary (vpShowIf relative to the row); stores a list of objects
 *   vpRowLabel : repeater: sub-field whose value titles each row (default: first text value of the row)
 *   vpStep     : step (number / range)
 *   vpGroup    : group title: one inspector panel per group (first one open), one tab per group in the expanded view;
 *                fields without group go to "Réglages". Post meta and options: panel / card per group ("panel" also accepted)
 *   vpShowIf   : {"other": value | [values]}: field shown in the editor only when every condition matches
 *                (current value or default of the other field); a hidden field keeps its value, render.php decides what to output
 *   vpAllowedTypes : file: MIME types or families accepted, e.g. ["application/pdf"] (default: all)
 *   vpTaxonomy : term / terms: taxonomy or list of taxonomies (default: all public taxonomies)
 *   vpRole     : user / users: role or list of roles (default: all)
 *   vpPostType : post / relationship / link: type or list of types (default: all public types); published content only
 *                (link suggestions: no terms, post formats nor media, as the ACF link field)
 *                post = one ID (integer), relationship = ordered list of IDs
 *   vpClass    : CSS class added to the block, in the editor (InnerBlocks mode) and on front via field::classes()
 *                boolean: added when true; string/number: "%s" replaced by the value
 * When DataForm becomes public: render the fields with it, and move each vpEdit control to Edit as soon as DataForm provides it.
 * When core provides an equivalent, migrate block by block: drop the prefix and adapt the format.
 *
 * MIGRATION FROM ACF BLOCKS (WP-CLI): wp field acf list | scaffold <block> | convert <block> [--to] [--dry-run] | restore,
 * see class/field_acf_cli.php and the README.
 *
 * CUSTOM CONTROLS (theme or plugin), without modifying this layer:
 * - PHP, stored type: add_filter('field_schema', fn(array $schema, string $control) => ...);
 *   the schema describes the value (type, items, pattern…), WordPress validates it on preview and on meta save.
 * - JS, interface: wp.hooks.addFilter('field.controls', 'namespace', (controls) => ({...controls, name: Component}));
 *   the component receives {name, def, label, help, common, value, onChange} (common: standard props of a WordPress control).
 *   Built-in controls (assets/controls/*.js) use the same filter with priority 5: a control with the same name replaces them.
 *   Load the script as a module depending on "wp-field/common", whose helpers it can import:
 *   wp_enqueue_script_module('my-controls', $url, ['wp-field/common']);
 *   import { el, c, addControl } from 'wp-field/common'; addControl('name', Component, 10);
 */
final class field
{
    /** Attribute keys handled by this layer (beyond type / default / enum / label, which core handles alone) */
    const KEYS = ['Edit', 'vpEdit', 'vpFields', 'elements', 'description', 'isValid', 'vpShowIf', 'vpGroup', 'vpStep', 'vpPostType', 'vpClass', 'vpAllowedTypes'];

    /** Placeholder replaced by child blocks in the editor preview (supports.vpInnerBlocks.preview) */
    const INNER_BLOCKS_MARKER = '<wp-field-inner-blocks></wp-field-inner-blocks>';

    /** Is the current request a block preview for the editor (block-renderer endpoint)? */
    private static bool $previewing = false;

    /** @var array<string, array<string, array<string, mixed>>> meta fields by post type */
    private static array $meta = [];

    /** @var array<string, array<string, mixed>> options pages, by slug */
    private static array $pages = [];

    /** @var array<string, array<string, array<string, mixed>>> option fields, by options page */
    private static array $options = [];

    /** @var array<string, array<string, array<string, mixed>>> term meta fields, by taxonomy */
    private static array $term_meta = [];

    /** @var array<string, array<string, mixed>> user meta fields */
    private static array $user_meta = [];

    /** Options page being displayed (admin) */
    private static string $current_page = '';

    static function init(): void
    {
        self::$unset = new stdClass();
        add_action('plugins_loaded', [self::class, 'polylang']);
        // after core marking (priority 5)
        add_filter('register_block_type_args', [self::class, 'register_block_type_args'], 6, 2);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueue']);
        add_action('wp_head', [self::class, 'meta_css']);
        // module data: <script type="application/json" id="wp-script-module-data-wp-field">
        add_filter('script_module_data_wp-field', fn(array $data) => ['meta' => self::$meta] + $data);
        // options pages
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('rest_api_init', [self::class, 'rest_routes']);
        // term and user meta: classic admin screens (edit-tags.php, term.php, profile.php, user-edit.php)
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_screen'], 5);
        add_action('show_user_profile', [self::class, 'user_form']);
        add_action('edit_user_profile', [self::class, 'user_form']);
        add_action('personal_options_update', [self::class, 'save_user']);
        add_action('edit_user_profile_update', [self::class, 'save_user']);
        add_action('user_profile_update_errors', [self::class, 'user_errors'], 10, 3);
        add_filter('script_module_data_wp-field/options', fn(array $data) => [
            'page' => self::$current_page,
            'title' => self::$pages[self::$current_page]['title'] ?? '',
            'languages' => self::languages(),
            'language' => self::language(),
            // false | "horizontal" | "vertical" (true = "horizontal")
            'tabs' => match (self::$pages[self::$current_page]['tabs'] ?? false) {
                'vertical' => 'vertical',
                false, null, '' => false,
                default => 'horizontal',
            },
            'fields' => self::$options[self::$current_page] ?? [],
        ] + $data);
        add_filter('rest_request_before_callbacks', function ($response, $handler, WP_REST_Request $request) {
            if (str_starts_with($request->get_route(), '/wp/v2/block-renderer/')) self::$previewing = true;
            return $response;
        }, 10, 3);
    }

    /** Is the block handled by this layer? */
    static function handles(array $args): bool
    {
        if (empty($args['supports']['autoRegister'])) return false;
        if (!empty($args['allowed_blocks']) || !empty($args['supports']['vpInnerBlocks'])) return true;
        foreach ((array) ($args['attributes'] ?? []) as $schema) {
            if (array_intersect(self::KEYS, array_keys((array) $schema))) return true;
        }
        return false;
    }

    static function register_block_type_args(array $args, string $name): array
    {
        if (!self::handles($args)) return $args;

        foreach ($args['attributes'] as $key => $schema) {
            // we take over: no "Settings" panel generated by core
            unset($schema['autoGenerateControl']);
            // the block-renderer endpoint validates attributes: each control needs a consistent type
            $args['attributes'][$key] = self::normalize($schema);
        }

        // mixed preview: server render of the block, in which the editor puts child blocks in place of $content
        if (!empty($args['supports']['vpInnerBlocks']['preview']) && !empty($args['render_callback'])) {
            $render = $args['render_callback'];
            $args['render_callback'] = static function ($attributes, $content, $block) use ($render) {
                if (self::$previewing && $content === '') $content = self::INNER_BLOCKS_MARKER;
                return $render($attributes, $content, $block);
            };
        }

        return $args;
    }

    /** Control of a field: "vpEdit" (wp-field control) or "Edit" (DataForm control name) */
    static function control(array $field): string
    {
        return (string) ($field['vpEdit'] ?? $field['Edit'] ?? '');
    }

    /** Is a field value empty, for isValid.required? */
    static function is_empty(mixed $value): bool
    {
        if (is_array($value)) return !array_filter($value, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== []);
        return $value === null || $value === '' || $value === 0 || $value === false;
    }

    /**
     * isValid.required of a field, and of the sub-fields of each repeater row: '' when valid, else the message.
     * Sub-fields hidden by vpShowIf in their row are not required.
     */
    static function missing(array $field, mixed $value): string
    {
        if (!empty($field['isValid']['required']) && self::is_empty($value)) return 'champ obligatoire.';
        if (self::control($field) !== 'repeater') return '';
        foreach (array_values((array) $value) as $i => $row) {
            foreach ((array) ($field['vpFields'] ?? []) as $key => $sub) {
                if (!self::visible($sub, (array) $field['vpFields'], (array) $row)) continue;
                $missing = self::missing($sub, ((array) $row)[$key] ?? null);
                if ($missing !== '') return sprintf('ligne %d, %s : %s', $i + 1, $sub['label'] ?? $key, $missing);
            }
        }
        return '';
    }

    /** vpShowIf of a field against sibling values (same rules as in the editor) */
    static function visible(array $field, array $fields, array $values): bool
    {
        foreach ((array) ($field['vpShowIf'] ?? []) as $key => $expected) {
            $current = array_key_exists($key, $values) ? $values[$key] : ($fields[$key]['default'] ?? null);
            if (is_array($expected) ? !in_array($current, $expected, true) : $current !== $expected) return false;
        }
        return true;
    }

    /** JSON type (and items/default) deduced from the control */
    static function normalize(array $schema): array
    {
        // DataForm "elements" ([{value, label}]): "enum" for server validation (not for a color palette, which stays optional)
        $control = self::control($schema);
        if (!empty($schema['elements']) && !isset($schema['enum']) && $control !== 'color') {
            $schema['enum'] = array_column($schema['elements'], 'value');
        }
        switch ($control) {
            // list of rows: each row is an object of sub-fields ("vpFields"), with the same vocabulary
            case 'repeater':
                $fields = [];
                foreach ((array) ($schema['vpFields'] ?? []) as $key => $sub) $fields[$key] = self::normalize((array) $sub);
                $schema['vpFields'] = $fields;
                $schema['type'] = 'array';
                $schema['items'] = ['type' => 'object', 'properties' => array_map([self::class, 'schema'], $fields)];
                $schema['default'] ??= [];
                break;
            case 'relationship':
                $schema['type'] = 'array';
                $schema['items'] = ['type' => 'integer'];
                $schema['default'] ??= [];
                break;
            case 'gallery':
            case 'terms':
            case 'users':
                $schema['type'] = 'array';
                $schema['items'] = ['type' => 'integer'];
                $schema['default'] ??= [];
                break;
            case 'link':
                $schema['type'] = 'object';
                break;
            case 'post':
            case 'term':
            case 'user':
            case 'image':
            case 'file':
                $schema['type'] = 'integer';
                break;
            case 'color':
                $schema['type'] = 'string';
                break;
            // empty value allowed: the JSON Schema "format" keyword (email, uri) would reject it
            case 'email':
                $schema['type'] = 'string';
                $schema['pattern'] ??= '^([^@\\s]+@[^@\\s]+\\.[^@\\s]+)?$';
                break;
            case 'url':
                $schema['type'] = 'string';
                $schema['pattern'] ??= '^(https?://\\S+)?$';
                break;
            case 'oembed':
                $schema['type'] = 'string';
                $schema['pattern'] ??= '^(https?://\\S+)?$';
                break;
            case 'date':
                $schema['type'] = 'string';
                $schema['pattern'] ??= '^(\d{4}-\d{2}-\d{2})?$';
                break;
        }
        // custom controls (theme or plugin): JSON type (items, pattern…) of the stored value
        $schema = apply_filters('field_schema', $schema, $control);
        $schema['type'] ??= 'string';
        return $schema;
    }

    /**
     * Native field (post meta) on one or several post types, edited in the editor document sidebar.
     * Same vocabulary as block attributes, plus:
     *   panel : panel title (default "Options");
     *   css   : CSS rule where "%s" receives the value, added on front (singular views of this type) and in the editor canvas,
     *           e.g. 'body { background-color: %s }'.
     * To call on init. Front: get_post_meta($post_id, $key, true).
     *
     * @param string|string[] $post_types
     * @param array<string, mixed> $field
     */
    static function meta(string|array $post_types, string $key, array $field): void
    {
        $field = self::normalize($field);
        foreach ((array) $post_types as $post_type) {
            // the editor reads and saves meta through the REST API, which only exposes them with this support
            add_post_type_support($post_type, 'custom-fields');
            register_post_meta($post_type, $key, self::meta_args($field, fn($allowed, $meta_key, $post_id) => current_user_can('edit_post', $post_id)));
            self::$meta[$post_type][$key] = $field;
        }
    }

    /**
     * REST schema of a normalized field (meta and options): type, items, validation rules.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    static function schema(array $field): array
    {
        $schema = ['type' => $field['type']];
        if ($field['type'] === 'array') $schema['items'] = $field['items'];
        if ($field['type'] === 'object') $schema['additionalProperties'] = true;
        foreach (['enum', 'minimum', 'maximum', 'maxItems', 'pattern'] as $rule) {
            if (isset($field[$rule]) && !($rule === 'enum' && self::control($field) === 'color')) $schema[$rule] = $field[$rule];
        }
        return $schema;
    }

    /**
     * Options page in the admin menu, with the fields declared by field::option() for this page.
     * To call on init. Args:
     *   title      : page title (default "Options")
     *   menu_title : menu label (default: title)
     *   parent     : parent menu slug for a submenu (e.g. "options-general.php", "themes.php"), or null for a top-level menu
     *   icon       : top-level menu icon (default "dashicons-admin-generic")
     *   position   : menu position
     *   capability : capability to view and save the page (default "manage_options")
     *   tabs       : true (or "horizontal") = one tab per group (vpGroup / panel) instead of one card per group,
     *                "vertical" = tabs in a column on the left (default false: cards)
     *
     * @param array<string, mixed> $args
     */
    static function options_page(string $slug, array $args = []): void
    {
        $args += ['title' => 'Options', 'parent' => null, 'icon' => 'dashicons-admin-generic', 'position' => null, 'capability' => 'manage_options', 'tabs' => false];
        $args['menu_title'] ??= $args['title'];
        self::$pages[$slug] = $args;
    }

    /**
     * Option field (one wp_options row, named $key) on an options page. Same vocabulary as block attributes, plus
     * panel (panel title on the page). To call on init. Front: field::get_option($key), typed according to the field.
     *
     * @param array<string, mixed> $field
     */
    static function option(string $page, string $key, array $field): void
    {
        $field = self::normalize($field);
        register_setting("wp-field-$page", $key, [
            'type' => $field['type'],
            'default' => $field['default'] ?? null,
            'show_in_rest' => false,
            'sanitize_callback' => self::control($field) === 'wysiwyg' ? 'wp_kses_post' : null,
        ]);
        self::$options[$page][$key] = $field;
    }

    /** Value of an option declared by field::option(), typed according to its field (e.g. boolean instead of "1") */
    static function get_option(string $key, ?string $language = null): mixed
    {
        $field = self::option_field($key);
        if (!$field) return get_option($key);
        $language ??= self::language();
        // translatable option: the value of the language, when one was set, otherwise the default value
        if ($language !== '' && self::translatable($field)) {
            $own = get_option(self::language_key($key, $language), self::$unset);
            if ($own !== self::$unset) return self::typed($field, $own);
        }
        return self::typed($field, get_option($key));
    }

    /** Field of an option declared by field::option() */
    private static function option_field(string $key): ?array
    {
        foreach (self::$options as $fields) {
            if (isset($fields[$key])) return $fields[$key];
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------
    // Languages: a value per language on options pages ("vpTranslatable": false for a value shared by all languages).
    // The languages come from filters, so that any multilingual plugin (or core) can provide them:
    //   field_languages: [slug => name] (none: no language handling);
    //   field_language : current language slug, '' for all languages (admin: language being edited, front: displayed language).
    // Built-in adapter: Polylang (its admin bar language switcher chooses the language being edited).

    /** Marker of an option that does not exist (get_option() default) */
    private static object $unset;

    /** @return array<string, string> languages, slug => name */
    static function languages(): array
    {
        return (array) apply_filters('field_languages', []);
    }

    /** Current language slug, '' for all languages (or without languages) */
    static function language(): string
    {
        if (!self::languages()) return '';
        return (string) apply_filters('field_language', '');
    }

    static function translatable(array $field): bool
    {
        return ($field['vpTranslatable'] ?? true) !== false && (bool) self::languages();
    }

    /** Option holding the value of a language: "key__en" */
    static function language_key(string $key, string $language): string
    {
        return $key . '__' . sanitize_key($language);
    }

    /** Polylang adapter */
    static function polylang(): void
    {
        if (!function_exists('pll_languages_list')) return;
        add_filter('field_languages', function (array $languages) {
            foreach ((array) pll_languages_list(['fields' => '']) as $language) {
                if ($language instanceof PLL_Language) $languages[$language->slug] = $language->name;
            }
            return $languages;
        });
        // admin: language of the admin bar switcher (false = all languages); front: displayed language
        add_filter('field_language', fn(string $language) => (string) (pll_current_language() ?: $language));
    }

    static function admin_menu(): void
    {
        foreach (self::$pages as $slug => $page) {
            $render = fn() => print('<div class="wrap"><div id="wp-field-options" class="wp-field-options"></div></div>');
            $menu_slug = "wp-field-$slug";
            $hook = $page['parent']
                ? add_submenu_page($page['parent'], $page['title'], $page['menu_title'], $page['capability'], $menu_slug, $render, $page['position'])
                : add_menu_page($page['title'], $page['menu_title'], $page['capability'], $menu_slug, $render, $page['icon'], $page['position']);
            if (!$hook) continue;
            add_action("load-$hook", function () use ($slug) {
                self::$current_page = $slug;
                // media views before scripts enqueued on admin_enqueue_scripts that extend them (as on core media pages)
                wp_enqueue_media();
                add_action('admin_enqueue_scripts', [self::class, 'enqueue_options']);
            });
        }
    }

    /** GET / POST /wp-field/v1/options/{page}: values of an options page, with its own capability */
    static function rest_routes(): void
    {
        register_rest_route('wp-field/v1', '/users', [
            'methods' => 'GET',
            'callback' => [self::class, 'rest_users'],
            'permission_callback' => fn() => current_user_can('edit_posts'),
        ]);
        register_rest_route('wp-field/v1', '/options/(?P<page>[\w-]+)', [
            [
                'methods' => 'GET',
                'callback' => fn(WP_REST_Request $request) => self::options_values($request['page'], self::request_language($request)),
                'permission_callback' => [self::class, 'can_edit_options'],
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'save_options'],
                'permission_callback' => [self::class, 'can_edit_options'],
            ],
        ]);
    }

    /**
     * GET /wp-field/v1/users?search=&include=&role=: users for the user / users controls, as {id, title, subtype}.
     * Display names only, for any editor (core /wp/v2/users only lists authors of published contents without list_users).
     *
     * @return array<int, array<string, mixed>>
     */
    static function rest_users(WP_REST_Request $request): array
    {
        $args = ['number' => min(100, (int) ($request['per_page'] ?: 20)), 'orderby' => 'display_name', 'fields' => 'all'];
        if ($request['search']) {
            $args['search'] = '*' . $request['search'] . '*';
            $args['search_columns'] = ['display_name', 'user_login', 'user_nicename'];
        }
        if ($request['include']) {
            $args['include'] = wp_parse_id_list($request['include']);
            $args['orderby'] = 'include';
        }
        if ($request['role']) $args['role__in'] = wp_parse_list($request['role']);
        return array_map(fn(WP_User $user) => [
            'id' => $user->ID,
            'title' => $user->display_name,
            'subtype' => translate_user_role(wp_roles()->role_names[$user->roles[0] ?? ''] ?? ''),
        ], get_users($args));
    }

    static function can_edit_options(WP_REST_Request $request): bool
    {
        $page = self::$pages[$request['page']] ?? null;
        return $page && current_user_can($page['capability']);
    }

    /** @return array<string, mixed> */
    static function options_values(string $page, string $language = ''): array
    {
        $values = $defaults = $own = [];
        foreach (self::$options[$page] ?? [] as $key => $field) {
            $defaults[$key] = self::get_option($key, '');
            $values[$key] = self::get_option($key, $language);
            if ($language !== '' && self::translatable($field) && get_option(self::language_key($key, $language), self::$unset) !== self::$unset) $own[] = $key;
        }
        return ['values' => $values, 'defaults' => $defaults, 'own' => $own, 'language' => $language];
    }

    /** Language of an options request ("lang" parameter), '' for all languages or an unknown language */
    private static function request_language(WP_REST_Request $request): string
    {
        $language = (string) $request->get_param('lang');
        return array_key_exists($language, self::languages()) ? $language : '';
    }

    /**
     * POST {values, lang, own}: without language, values are the default values; with a language, "own" lists the translatable
     * fields with a value of their own for this language (the others inherit the default value), non-translatable fields
     * are shared by all languages.
     */
    static function save_options(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $page = (string) $request['page'];
        $language = self::request_language($request);
        $fields = self::$options[$page] ?? [];
        $values = (array) ($request->get_param('values') ?? []);
        $own = array_map('strval', (array) ($request->get_param('own') ?? []));
        $clean = [];
        foreach ($values as $key => $value) {
            if (!isset($fields[$key])) continue;
            $schema = self::schema($fields[$key]);
            $valid = rest_validate_value_from_schema($value, $schema, $key);
            if (is_wp_error($valid)) return new WP_Error('wp_field_invalid_option', $fields[$key]['label'] ?? $key, ['status' => 400, 'details' => [$key => $valid->get_error_message()]]);
            $clean[$key] = self::sanitize($fields[$key], rest_sanitize_value_from_schema($value, $schema, $key));
        }
        // where each value goes: default option, option of the language, or none (inherits the default value)
        $writes = $deletes = [];
        foreach ($fields as $key => $field) {
            $per_language = $language !== '' && self::translatable($field);
            if (!$per_language) {
                if (array_key_exists($key, $clean)) $writes[$key] = $clean[$key];
            } elseif (in_array($key, $own, true) && array_key_exists($key, $clean)) {
                $writes[self::language_key($key, $language)] = $clean[$key];
            } elseif (!in_array($key, $own, true)) {
                $deletes[] = self::language_key($key, $language);
            }
        }
        // isValid.required (DataForm): checked on the resulting values of the page
        foreach ($fields as $key => $field) {
            $per_language = $language !== '' && self::translatable($field);
            $target = $per_language ? self::language_key($key, $language) : $key;
            $value = array_key_exists($target, $writes) ? $writes[$target] : self::get_option($key, $per_language && !in_array($target, $deletes, true) ? $language : '');
            $missing = self::missing($field, $value);
            if ($missing !== '') return new WP_Error('wp_field_required_option', $field['label'] ?? $key, ['status' => 400, 'details' => [$key => $missing]]);
        }
        foreach ($writes as $option => $value) {
            // update_option() does nothing for a missing option whose new value is false: create it
            if (get_option($option, self::$unset) === self::$unset) add_option($option, $value);
            else update_option($option, $value);
        }
        foreach ($deletes as $option) delete_option($option);
        return rest_ensure_response(self::options_values($page, $language));
    }

    /** wp_kses_post() on wysiwyg values, including in repeater rows */
    static function sanitize(array $field, mixed $value): mixed
    {
        $control = self::control($field);
        if ($control === 'wysiwyg' && is_string($value)) return wp_kses_post($value);
        if ($control === 'repeater' && is_array($value)) {
            foreach ($value as $i => $row) {
                foreach ((array) ($field['vpFields'] ?? []) as $key => $sub) {
                    if (is_array($row) && array_key_exists($key, $row)) $value[$i][$key] = self::sanitize($sub, $row[$key]);
                }
            }
        }
        return $value;
    }

    /**
     * Native field on the terms of one or several taxonomies (term meta), edited on the term add and edit screens.
     * Same vocabulary as block attributes (vpGroup / panel: one card per group). To call on init.
     * Front: field::get_term_meta($term_id, $key), typed according to the field.
     *
     * @param string|string[] $taxonomies
     * @param array<string, mixed> $field
     */
    static function term_meta(string|array $taxonomies, string $key, array $field): void
    {
        $field = self::normalize($field);
        foreach ((array) $taxonomies as $taxonomy) {
            register_term_meta($taxonomy, $key, self::meta_args($field, fn($allowed, $meta_key, $term_id) => current_user_can('edit_term', $term_id)));
            if (!isset(self::$term_meta[$taxonomy])) {
                add_action("{$taxonomy}_add_form_fields", fn() => self::term_form($taxonomy, 0));
                add_action("{$taxonomy}_edit_form", fn(WP_Term $term) => self::term_form($taxonomy, $term->term_id));
                add_action("created_{$taxonomy}", fn(int $term_id) => self::save_term($taxonomy, $term_id));
                add_action("edited_{$taxonomy}", fn(int $term_id) => self::save_term($taxonomy, $term_id));
            }
            self::$term_meta[$taxonomy][$key] = $field;
        }
    }

    /**
     * Native field on users (user meta), edited on the profile screens. Same vocabulary as block attributes. To call on init.
     * Front: field::get_user_meta($user_id, $key), typed according to the field.
     *
     * @param array<string, mixed> $field
     */
    static function user_meta(string $key, array $field): void
    {
        $field = self::normalize($field);
        register_meta('user', $key, self::meta_args($field, fn($allowed, $meta_key, $user_id) => current_user_can('edit_user', $user_id)));
        self::$user_meta[$key] = $field;
    }

    /** @return array<string, mixed> register_meta() arguments of a normalized field */
    private static function meta_args(array $field, callable $auth): array
    {
        $args = [
            'type' => $field['type'],
            'single' => true,
            'show_in_rest' => ['schema' => self::schema($field)],
            'auth_callback' => $auth,
            'sanitize_callback' => self::control($field) === 'wysiwyg' ? 'wp_kses_post' : null,
        ];
        // a default must match the type (register_meta() notice otherwise): only when declared
        if (array_key_exists('default', $field)) $args['default'] = $field['default'];
        return $args;
    }

    /** Stored value typed according to its field (e.g. boolean instead of "1") */
    static function typed(array $field, mixed $value): mixed
    {
        return rest_sanitize_value_from_schema($value === false ? ($field['default'] ?? null) : $value, self::schema($field));
    }

    static function get_term_meta(int $term_id, string $key): mixed
    {
        $term = get_term($term_id);
        $field = $term instanceof WP_Term ? (self::$term_meta[$term->taxonomy][$key] ?? null) : null;
        $value = get_term_meta($term_id, $key, true);
        return $field ? self::typed($field, $value) : $value;
    }

    static function get_user_meta(int $user_id, string $key): mixed
    {
        $value = get_user_meta($user_id, $key, true);
        return isset(self::$user_meta[$key]) ? self::typed(self::$user_meta[$key], $value) : $value;
    }

    /** Fields container of a classic admin form, rendered by assets/form.js; values posted as JSON in "wp_field" */
    private static function form(array $fields, array $values): void
    {
        wp_nonce_field('wp_field_save', 'wp_field_nonce');
        printf(
            '<div class="wp-field-form" data-fields="%s"><input type="hidden" name="wp_field" value="%s"></div>',
            esc_attr((string) wp_json_encode($fields)),
            esc_attr((string) wp_json_encode((object) $values))
        );
    }

    private static function term_form(string $taxonomy, int $term_id): void
    {
        $fields = self::$term_meta[$taxonomy] ?? [];
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $term_id ? self::get_term_meta($term_id, $key) : self::typed($field, $field['default'] ?? null);
        }
        self::form($fields, $values);
    }

    static function user_form(WP_User $user): void
    {
        if (!self::$user_meta) return;
        $values = [];
        foreach (array_keys(self::$user_meta) as $key) $values[$key] = self::get_user_meta($user->ID, $key);
        self::form(self::$user_meta, $values);
    }

    /**
     * Posted values of a classic form, validated against the fields (invalid and unknown values are ignored).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [values, errors by key]
     */
    private static function posted(array $fields): array
    {
        if (!isset($_POST['wp_field'], $_POST['wp_field_nonce']) || !wp_verify_nonce(sanitize_key($_POST['wp_field_nonce']), 'wp_field_save')) return [[], []];
        $posted = json_decode(wp_unslash((string) $_POST['wp_field']), true);
        $clean = $errors = [];
        foreach ((array) $posted as $key => $value) {
            if (!isset($fields[$key])) continue;
            $schema = self::schema($fields[$key]);
            $valid = rest_validate_value_from_schema($value, $schema, $key);
            if (is_wp_error($valid)) {
                $errors[$key] = ($fields[$key]['label'] ?? $key) . ' : ' . $valid->get_error_message();
                continue;
            }
            $clean[$key] = rest_sanitize_value_from_schema($value, $schema, $key);
            $missing = self::missing($fields[$key], $clean[$key]);
            if ($missing !== '') $errors[$key] = ($fields[$key]['label'] ?? $key) . ' : ' . $missing;
        }
        return [$clean, $errors];
    }

    private static function save_term(string $taxonomy, int $term_id): void
    {
        if (!current_user_can('edit_term', $term_id)) return;
        [$values] = self::posted(self::$term_meta[$taxonomy] ?? []);
        foreach ($values as $key => $value) update_term_meta($term_id, $key, $value);
    }

    static function save_user(int $user_id): void
    {
        if (!current_user_can('edit_user', $user_id)) return;
        [$values, $errors] = self::posted(self::$user_meta);
        foreach ($values as $key => $value) {
            if (!isset($errors[$key])) update_user_meta($user_id, $key, $value);
        }
    }

    /** Profile: invalid or missing required values are reported as profile errors (the other fields are saved) */
    static function user_errors(WP_Error $errors, bool $update, stdClass $user): void
    {
        foreach (self::posted(self::$user_meta)[1] as $key => $message) $errors->add("wp_field_$key", esc_html($message));
    }

    /** Assets of the classic screens with term or user fields */
    static function enqueue_screen(string $hook): void
    {
        $screen = get_current_screen();
        $terms = in_array($hook, ['edit-tags.php', 'term.php'], true) && $screen && !empty(self::$term_meta[$screen->taxonomy]);
        $users = in_array($hook, ['profile.php', 'user-edit.php'], true) && self::$user_meta;
        if (!$terms && !$users) return;
        self::enqueue_fields(['wp-media-utils']);
        wp_enqueue_style('wp-components');
        wp_enqueue_style('wp-block-editor');
        self::enqueue_module('wp-field/form', 'form.js');
    }

    /** Meta value made safe for a stylesheet */
    static function css_value(array $field, mixed $value): string
    {
        if (!is_scalar($value) || $value === '') return '';
        if (self::control($field) === 'color') return (string) sanitize_hex_color((string) $value);
        // nothing to escape the declaration: no ; { } < > nor quotes
        return (string) preg_replace('/[^\w\s#%.,()\-\/]/u', '', (string) $value);
    }

    /** "css" rules of the meta fields of the displayed content */
    static function meta_css(): void
    {
        if (!is_singular()) return;
        $id = (int) get_queried_object_id();
        $css = '';
        foreach (self::$meta[get_post_type($id)] ?? [] as $key => $field) {
            if (empty($field['css'])) continue;
            $value = self::css_value($field, get_post_meta($id, $key, true));
            if ($value !== '') $css .= str_replace('%s', $value, $field['css']) . "\n";
        }
        if ($css !== '') echo "<style id=\"wp-field-css\">\n$css</style>\n";
    }

    /** Block editor: blocks, inspector, meta panels */
    static function enqueue(): void
    {
        self::enqueue_fields(['wp-blocks', 'wp-plugins', 'wp-editor']);
        self::enqueue_module('wp-field', 'editor.js');
    }

    /** Options page (admin): the same fields, outside of the block editor */
    static function enqueue_options(): void
    {
        self::enqueue_fields(['wp-media-utils']);
        // components and link control styles, loaded by the block editor elsewhere
        wp_enqueue_style('wp-components');
        wp_enqueue_style('wp-block-editor');
        self::enqueue_module('wp-field/options', 'options.js');
    }

    /**
     * Shared by the block editor and the options pages: media, TinyMCE, editor packages, controls, styles.
     *
     * @param string[] $handles additional classic scripts
     */
    private static function enqueue_fields(array $handles): void
    {
        $dir = dirname(__DIR__) . '/assets';
        $url = plugins_url('assets', dirname(__DIR__) . '/wp-field.php');
        wp_enqueue_media();
        // TinyMCE for wysiwyg fields
        wp_enqueue_editor();
        // editor packages are still classic scripts: we load them, the modules read window.wp
        foreach (['wp-block-editor', 'wp-components', 'wp-element', 'wp-hooks', 'wp-data', 'wp-core-data', 'wp-api-fetch', 'wp-url', 'wp-compose', ...$handles] as $handle) {
            wp_enqueue_script($handle);
        }
        // shared helpers and fields rendering, imported as "wp-field/common" (versioned through the import map)
        wp_register_script_module('wp-field/common', "$url/common.js", [], (string) filemtime("$dir/common.js"));
        // one module per control file, each registering itself through the "field.controls" filter
        foreach (glob("$dir/controls/*.js") ?: [] as $file) {
            $name = basename($file, '.js');
            wp_enqueue_script_module("wp-field/controls/$name", "$url/controls/$name.js", ['wp-field/common'], (string) filemtime($file));
        }
        wp_enqueue_style('wp-field', "$url/editor.css", [], (string) filemtime("$dir/editor.css"));
    }

    private static function enqueue_module(string $id, string $file): void
    {
        $dir = dirname(__DIR__) . '/assets';
        $url = plugins_url('assets', dirname(__DIR__) . '/wp-field.php');
        wp_enqueue_script_module($id, "$url/$file", ['wp-field/common'], (string) filemtime("$dir/$file"));
    }

    /**
     * CSS classes deduced from attributes declaring "vpClass" (same as in the editor).
     *
     * @param array<string, mixed> $attributes
     * @return string[]
     */
    static function classes(string $block_name, array $attributes): array
    {
        $type = WP_Block_Type_Registry::get_instance()->get_registered($block_name);
        if (!$type) return [];
        $class = [];
        foreach ((array) $type->attributes as $key => $schema) {
            if (empty($schema['vpClass'])) continue;
            $value = $attributes[$key] ?? ($schema['default'] ?? null);
            if (is_bool($value)) {
                if ($value) $class[] = $schema['vpClass'];
            } elseif (is_scalar($value) && $value !== '') {
                $class[] = str_replace('%s', sanitize_html_class((string) $value), $schema['vpClass']);
            }
        }
        return $class;
    }

    /** HTML of a wysiwyg field: sanitized and formatted like the_content */
    static function wysiwyg(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : wpautop(wp_kses_post($value));
    }

    /**
     * Contents of a post / relationship field: published, in the chosen order, without contents deleted since.
     *
     * @return WP_Post[]
     */
    static function posts(mixed $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) return [];
        return get_posts([
            'post__in' => $ids,
            'orderby' => 'post__in',
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => count($ids),
            'ignore_sticky_posts' => true,
        ]);
    }

    /**
     * Terms of a term / terms field, in the chosen order, without terms deleted since.
     *
     * @return WP_Term[]
     */
    static function terms(mixed $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) return [];
        $terms = get_terms(['include' => $ids, 'orderby' => 'include', 'hide_empty' => false, 'taxonomy' => get_taxonomies()]);
        return is_array($terms) ? array_values($terms) : [];
    }

    /**
     * Users of a user / users field, in the chosen order, without users deleted since.
     *
     * @return WP_User[]
     */
    static function users(mixed $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        return $ids ? get_users(['include' => $ids, 'orderby' => 'include']) : [];
    }

    /** Embed HTML of an oembed field (YouTube, Vimeo… URL), cached by WordPress, or '' */
    static function oembed(mixed $url): string
    {
        $url = trim((string) $url);
        if ($url === '') return '';
        $html = $GLOBALS['wp_embed']->shortcode([], $url);
        // not embeddable: WordPress returns a link
        return str_starts_with(trim($html), '<a ') ? '' : $html;
    }

    /**
     * Rendered HTML of each zone of a block with zones (supports.vpInnerBlocks.zones), by zone name.
     * Names not listed in $names are ignored; listed names without content are empty strings.
     *
     * @param string[] $names
     * @return array<string, string>
     */
    static function zones(WP_Block $block, array $names): array
    {
        $zones = array_fill_keys($names, '');
        foreach ($block->inner_blocks as $inner) {
            $name = (string) ($inner->attributes['zone'] ?? '');
            if (array_key_exists($name, $zones)) $zones[$name] .= $inner->render();
        }
        return $zones;
    }
}
