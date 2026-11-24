<?php

/**
 * Migration from ACF blocks to native wp-field blocks.
 *
 * ACF values are read through the ACF API (both storages of ACF block data: field names, and field keys of recent ACF
 * versions), then converted to the wp-field storage:
 * - group, and clone displayed as a group: flattened attributes "{parent}_{sub}" (e.g. media_type, media_vimeo_poster);
 * - repeater: list of rows; true_false: boolean; image, file, post_object, user, taxonomy (single): integer;
 *   gallery, relationship and multiple post_object / user / taxonomy: list of integers; date_picker (Ymd): YYYY-MM-DD;
 *   link: {url, title, target}; number / range: number; other fields: string;
 * - flexible_content: not supported (reported, skipped).
 *
 * Workflow for an ACF block:
 * 1. wp field acf scaffold <block>: attributes for the native block.json (to review), with their wp-field controls;
 * 2. write the native block (block.json + render.php reading $attributes instead of get_field());
 * 3. wp field acf convert <block> [--to=<native block>] --dry-run, then without --dry-run: content updated; the content
 *    before the first conversion is kept (post meta), "wp field acf restore" puts it back, "--forget" deletes the backups.
 */
final class field_acf_cli
{
    /** Post meta holding the content before the first conversion */
    const BACKUP = '_wp_field_acf_backup';

    /**
     * Lists the ACF blocks used in contents, with their number of occurrences and of contents.
     *
     * ## OPTIONS
     *
     * [--post_type=<types>]
     * : Post types, comma separated (default: all).
     *
     * @subcommand list
     */
    public function list_(array $args, array $assoc): void
    {
        $this->require_acf();
        $counts = [];
        foreach ($this->posts(null, $assoc) as $post) {
            foreach ($this->acf_blocks(parse_blocks($post->post_content)) as $block) {
                $name = $block['blockName'];
                $counts[$name] ??= ['block' => $name, 'occurrences' => 0, 'contents' => []];
                $counts[$name]['occurrences']++;
                $counts[$name]['contents'][$post->ID] = true;
            }
        }
        $rows = array_map(fn($c) => ['block' => $c['block'], 'occurrences' => $c['occurrences'], 'contents' => count($c['contents'])], array_values($counts));
        if (!$rows) {
            WP_CLI::success('Aucun bloc ACF dans les contenus.');
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, ['block', 'occurrences', 'contents']);
    }

    /**
     * Prints the "attributes" of a native block.json equivalent to an ACF block, to review before use.
     *
     * ## OPTIONS
     *
     * <block>
     * : ACF block name, e.g. vupar/media.
     *
     * @subcommand scaffold
     */
    public function scaffold(array $args): void
    {
        $this->require_acf();
        [$block] = $args;
        $notes = [];
        $attributes = $this->attributes($this->fields($block), '', '', $notes);
        WP_CLI::line((string) wp_json_encode(['attributes' => $attributes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (array_unique($notes) as $note) WP_CLI::warning($note);
    }

    /**
     * Converts the ACF blocks of contents into native blocks (attributes in the wp-field storage).
     *
     * ## OPTIONS
     *
     * <block>
     * : ACF block name, e.g. vupar/media.
     *
     * [--to=<block>]
     * : Native block name (default: same name, when the native block replaces the ACF one).
     *
     * [--post_type=<types>]
     * : Post types, comma separated (default: all).
     *
     * [--include=<ids>]
     * : Content IDs, comma separated.
     *
     * [--dry-run]
     * : Show the conversion without saving.
     *
     * @subcommand convert
     */
    public function convert(array $args, array $assoc): void
    {
        $this->require_acf();
        [$block] = $args;
        $to = (string) ($assoc['to'] ?? $block);
        $dry = !empty($assoc['dry-run']);
        $fields = $this->fields($block);
        $notes = [];
        $total = $contents = 0;
        foreach ($this->posts($block, $assoc) as $post) {
            $count = 0;
            $blocks = $this->convert_blocks(parse_blocks($post->post_content), $block, $to, $fields, $post->ID, $count, $notes, $dry);
            if (!$count) continue;
            $total += $count;
            $contents++;
            if ($dry) {
                WP_CLI::line(sprintf('#%d %s : %d bloc(s)', $post->ID, $post->post_title, $count));
                continue;
            }
            // content before the first conversion, kept for "wp field acf restore" (revisions are often disabled)
            add_post_meta($post->ID, self::BACKUP, wp_slash($post->post_content), true);
            // wp_update_post() expects slashed data
            $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_content' => serialize_blocks($blocks)]), true);
            if (is_wp_error($result)) WP_CLI::warning(sprintf('#%d : %s', $post->ID, $result->get_error_message()));
            else WP_CLI::line(sprintf('#%d %s : %d bloc(s) converti(s)', $post->ID, $post->post_title, $count));
        }
        foreach (array_unique($notes) as $note) WP_CLI::warning($note);
        if (!$total) {
            WP_CLI::success(sprintf('Aucun bloc %s à convertir.', $block));
            return;
        }
        if ($dry) WP_CLI::success(sprintf('%d bloc(s) dans %d contenu(s) : rien n\'a été enregistré (--dry-run).', $total, $contents));
        else WP_CLI::success(sprintf('%d bloc(s) %s convertis en %s dans %d contenu(s). Contenu d\'origine sauvegardé : wp field acf restore pour revenir en arrière.', $total, $block, $to, $contents));
    }

    /**
     * Restores the contents as they were before their first conversion, or forgets the backups once the migration is validated.
     *
     * ## OPTIONS
     *
     * [--include=<ids>]
     * : Content IDs, comma separated (default: all converted contents).
     *
     * [--forget]
     * : Delete the backups instead of restoring them (migration validated).
     *
     * @subcommand restore
     */
    public function restore(array $args, array $assoc): void
    {
        global $wpdb;
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP)));
        if (!empty($assoc['include'])) $ids = array_intersect($ids, wp_parse_id_list($assoc['include']));
        if (!$ids) {
            WP_CLI::success('Aucune sauvegarde de conversion.');
            return;
        }
        foreach ($ids as $id) {
            if (empty($assoc['forget'])) {
                $result = wp_update_post(wp_slash(['ID' => $id, 'post_content' => (string) get_post_meta($id, self::BACKUP, true)]), true);
                if (is_wp_error($result)) {
                    WP_CLI::warning(sprintf('#%d : %s', $id, $result->get_error_message()));
                    continue;
                }
            }
            delete_post_meta($id, self::BACKUP);
        }
        WP_CLI::success(sprintf(empty($assoc['forget']) ? '%d contenu(s) restauré(s).' : '%d sauvegarde(s) supprimée(s).', count($ids)));
    }

    // ------------------------------------------------------------------------------------------------

    private function require_acf(): void
    {
        if (!function_exists('acf_get_field_groups')) WP_CLI::error('ACF doit être actif pour lire les blocs ACF.');
    }

    /** @return WP_Post[] contents containing a block (or any block) */
    private function posts(?string $block, array $assoc): array
    {
        global $wpdb;
        $where = $block ? $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like('<!-- wp:' . $block . ' ') . '%') : "post_content LIKE '%<!-- wp:%'";
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE $where AND post_type NOT IN ('revision') AND post_status NOT IN ('auto-draft', 'trash')";
        if (!empty($assoc['post_type'])) {
            $types = array_map('trim', explode(',', (string) $assoc['post_type']));
            $sql .= ' AND post_type IN (' . implode(',', array_map(fn($t) => $wpdb->prepare('%s', $t), $types)) . ')';
        }
        if (!empty($assoc['include'])) $sql .= ' AND ID IN (' . implode(',', wp_parse_id_list($assoc['include'])) . ')';
        return array_map('get_post', $wpdb->get_col($sql));
    }

    /** ACF blocks of a block tree (blocks with ACF data, at any depth) */
    private function acf_blocks(array $blocks): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (isset($block['attrs']['data']) && isset($block['attrs']['name'])) $found[] = $block;
            $found = array_merge($found, $this->acf_blocks($block['innerBlocks'] ?? []));
        }
        return $found;
    }

    /** ACF fields of a block (all field groups located on it) */
    private function fields(string $block): array
    {
        $fields = [];
        foreach (acf_get_field_groups(['block' => $block]) as $group) $fields = array_merge($fields, acf_get_fields($group));
        if (!$fields) WP_CLI::error(sprintf('Aucun groupe de champs ACF pour le bloc %s.', $block));
        return $fields;
    }

    /** Short name of a field (clone sub-fields carry the name prefixed by ACF in "name", the original one in "_name") */
    private function short(array $field): string
    {
        return (string) ($field['_name'] ?? $field['name']);
    }

    /** Is a field a container whose sub-fields become flattened attributes? */
    private function flattened(array $field): bool
    {
        return $field['type'] === 'group' || ($field['type'] === 'clone' && ($field['display'] ?? '') === 'group');
    }

    // ------------------------------------------------------------------------------------------------
    // Scaffold

    /**
     * wp-field attributes of ACF fields.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param string[] $notes
     * @return array<string, array<string, mixed>>
     */
    private function attributes(array $fields, string $prefix, string $group, array &$notes, array &$names = []): array
    {
        $attributes = $children = [];
        foreach ($fields as $field) {
            if ($field['type'] === 'tab') {
                $group = (string) $field['label'];
                continue;
            }
            if (in_array($field['type'], ['message', 'accordion'], true)) continue;
            $name = $prefix . $this->short($field);
            $names[$field['key']] = $name;
            if ($this->flattened($field) || $field['type'] === 'clone') {
                $sub_group = $group ?: (string) $field['label'];
                $sub = $this->attributes($field['sub_fields'] ?? [], $this->flattened($field) ? $name . '_' : $prefix, $sub_group, $notes, $names);
                $children[$field['key']] = array_keys($sub);
                $attributes += $sub;
                continue;
            }
            $attribute = $this->attribute($field, $notes);
            if ($attribute === null) continue;
            if ($group !== '') $attribute['vpGroup'] = $group;
            $attributes[$name] = $attribute;
        }
        // conditional logic: simple "==" rules on fields of the same block (one group of rules: AND)
        foreach ($fields as $field) {
            $name = $names[$field['key']] ?? null;
            $logic = $field['conditional_logic'] ?? [];
            // a condition on a flattened container applies to each of its attributes
            $targets = isset($children[$field['key']]) ? $children[$field['key']] : ($name && isset($attributes[$name]) ? [$name] : []);
            if (!$logic || !$targets) continue;
            $show = [];
            foreach ((array) ($logic[0] ?? []) as $rule) {
                if (($rule['operator'] ?? '') !== '==' || !isset($names[$rule['field']])) {
                    $show = null;
                    break;
                }
                $show[$names[$rule['field']]] = $rule['value'] === '1' && $this->type_of($names[$rule['field']], $attributes) === 'boolean' ? true : $rule['value'];
            }
            if ($show && count($logic) === 1) {
                foreach ($targets as $target) $attributes[$target]['vpShowIf'] = ($attributes[$target]['vpShowIf'] ?? []) + $show;
            } else {
                $notes[] = sprintf('%s : logique conditionnelle non convertie (seules les règles "==" sur un seul groupe le sont).', $name ?: $field['name']);
            }
        }
        return $attributes;
    }

    private function type_of(string $name, array $attributes): string
    {
        return (string) ($attributes[$name]['type'] ?? '');
    }

    /** wp-field attribute of one ACF field, or null when not supported */
    private function attribute(array $field, array &$notes): ?array
    {
        // a label is needed for the field to be editable (ACF fields can hide theirs)
        $a = ['label' => (string) $field['label'] !== '' ? (string) $field['label'] : ucfirst(str_replace('_', ' ', $this->short($field)))];
        $multiple = !empty($field['multiple']) || in_array($field['field_type'] ?? '', ['checkbox', 'multi_select'], true);
        switch ($field['type']) {
            case 'text':
            case 'password':
                $a['type'] = 'string';
                break;
            case 'textarea':
            case 'email':
            case 'url':
            case 'color_picker':
                $a['Edit'] = ['textarea' => 'textarea', 'email' => 'email', 'url' => 'url', 'color_picker' => 'color'][$field['type']];
                break;
            case 'number':
            case 'range':
                // whole numbers (no decimal step, min or max): integer
                $decimal = fn($v) => is_numeric($v) && (float) $v != (int) $v;
                $a['type'] = $decimal($field['step'] ?? '') || $decimal($field['min'] ?? '') || $decimal($field['max'] ?? '') ? 'number' : 'integer';
                if ($field['type'] === 'range') $a['vpEdit'] = 'range';
                foreach (['min' => 'minimum', 'max' => 'maximum', 'step' => 'vpStep'] as $from => $to) {
                    if (isset($field[$from]) && $field[$from] !== '') $a[$to] = 0 + $field[$from];
                }
                break;
            case 'true_false':
                $a += ['type' => 'boolean', 'Edit' => 'toggle'];
                break;
            case 'select':
            case 'radio':
            case 'button_group':
            case 'checkbox':
                $a['elements'] = array_map(fn($value, $label) => ['value' => (string) $value, 'label' => (string) $label], array_keys((array) $field['choices']), array_values((array) $field['choices']));
                if ($field['type'] === 'checkbox' || ($field['type'] === 'select' && $multiple)) {
                    $notes[] = sprintf('%s : choix multiple ACF converti en liste de valeurs, sans contrôle wp-field équivalent (à remplacer, par ex. par un répéteur ou un contrôle maison).', $field['name']);
                    $a['type'] = 'array';
                    $a['items'] = ['type' => 'string'];
                } else {
                    $a['Edit'] = ['select' => 'select', 'radio' => 'radio', 'button_group' => 'toggleGroup'][$field['type']];
                }
                break;
            case 'date_picker':
                $a['Edit'] = 'date';
                break;
            case 'wysiwyg':
            case 'oembed':
            case 'image':
            case 'file':
            case 'gallery':
            case 'link':
                $a['vpEdit'] = $field['type'];
                if ($field['type'] === 'gallery' && !empty($field['max'])) $a['maxItems'] = (int) $field['max'];
                if ($field['type'] === 'file' && !empty($field['mime_types'])) $notes[] = sprintf('%s : types de fichier ACF (%s) à reporter dans vpAllowedTypes (types MIME).', $field['name'], $field['mime_types']);
                break;
            case 'post_object':
            case 'page_link':
            case 'relationship':
                $a['vpEdit'] = $field['type'] === 'relationship' || $multiple ? 'relationship' : 'post';
                if (!empty($field['post_type'])) $a['vpPostType'] = array_values((array) $field['post_type']);
                if (!empty($field['max'])) $a['maxItems'] = (int) $field['max'];
                break;
            case 'taxonomy':
                $a['vpEdit'] = in_array($field['field_type'] ?? '', ['checkbox', 'multi_select'], true) ? 'terms' : 'term';
                if (!empty($field['taxonomy'])) $a['vpTaxonomy'] = (string) $field['taxonomy'];
                break;
            case 'user':
                $a['vpEdit'] = $multiple ? 'users' : 'user';
                if (!empty($field['role'])) $a['vpRole'] = array_values((array) $field['role']);
                break;
            case 'repeater':
                $sub_notes = [];
                $a['vpEdit'] = 'repeater';
                if (!empty($field['max'])) $a['maxItems'] = (int) $field['max'];
                $a['vpFields'] = array_map(function ($sub) {
                    unset($sub['vpGroup']);
                    return $sub;
                }, $this->attributes($field['sub_fields'] ?? [], '', '', $sub_notes));
                $notes = array_merge($notes, $sub_notes);
                break;
            default:
                $notes[] = sprintf('%s : type ACF "%s" non pris en charge, champ ignoré.', $field['name'], $field['type']);
                return null;
        }
        if (!empty($field['instructions'])) $a['description'] = (string) $field['instructions'];
        if (!empty($field['required'])) $a['isValid'] = ['required' => true];
        $default = $field['default_value'] ?? null;
        if ($default !== null && $default !== '' && $default !== []) $a['default'] = $this->value($field, $default, $notes);
        elseif (in_array($a['type'] ?? '', ['string'], true) || in_array($a['Edit'] ?? '', ['textarea', 'email', 'url', 'color', 'date'], true) || in_array($a['vpEdit'] ?? '', ['wysiwyg', 'oembed'], true)) $a['default'] = '';
        elseif (($a['type'] ?? '') === 'boolean') $a['default'] = false;
        return $a;
    }

    // ------------------------------------------------------------------------------------------------
    // Values

    /**
     * Converted block tree: matching ACF blocks become native blocks; other blocks are kept as they are.
     */
    private function convert_blocks(array $blocks, string $from, string $to, array $fields, int $post_id, int &$count, array &$notes, bool $dry): array
    {
        foreach ($blocks as $i => $block) {
            if (!empty($block['innerBlocks'])) {
                $blocks[$i]['innerBlocks'] = $this->convert_blocks($block['innerBlocks'], $from, $to, $fields, $post_id, $count, $notes, $dry);
            }
            if ($block['blockName'] !== $from || !isset($block['attrs']['data'])) continue;
            $id = 'wp_field_acf_' . $post_id . '_' . $count;
            acf_setup_meta((array) $block['attrs']['data'], $id, true);
            $attributes = [];
            foreach ($fields as $field) $attributes += $this->values($field, get_field($field['key'], $id, false), '', $notes);
            acf_reset_meta($id);
            // keep the attributes of block supports (align, className, anchor, lock, metadata…), not the ACF ones
            $keep = array_diff_key($block['attrs'], array_flip(['name', 'data', 'mode', 'hasAcfError']));
            $attributes = array_filter($attributes, fn($value) => $value !== null) + $keep;
            if ($dry) WP_CLI::line(sprintf('  %s → %s %s', $from, $to, wp_json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
            $blocks[$i]['blockName'] = $to;
            $blocks[$i]['attrs'] = $attributes;
            $count++;
        }
        return $blocks;
    }

    /**
     * Attributes of one ACF field and its raw value (containers are flattened).
     *
     * @return array<string, mixed>
     */
    private function values(array $field, mixed $raw, string $prefix, array &$notes): array
    {
        if (in_array($field['type'], ['tab', 'message', 'accordion'], true)) return [];
        $name = $prefix . $this->short($field);
        if ($this->flattened($field) || $field['type'] === 'clone') {
            $values = [];
            $raw = is_array($raw) ? $raw : [];
            foreach ($field['sub_fields'] ?? [] as $sub) {
                $values += $this->values($sub, $raw[$sub['key']] ?? null, $this->flattened($field) ? $name . '_' : $prefix, $notes);
            }
            return $values;
        }
        if ($field['type'] === 'flexible_content') {
            $notes[] = sprintf('%s : contenu flexible ACF non pris en charge, valeur ignorée.', $name);
            return [];
        }
        return [$name => $this->value($field, $raw, $notes)];
    }

    /** One raw ACF value in the wp-field storage (null: no value, the attribute keeps its default) */
    private function value(array $field, mixed $raw, array &$notes): mixed
    {
        $ids = fn($value) => array_values(array_filter(array_map('intval', (array) $value)));
        $multiple = !empty($field['multiple']) || in_array($field['field_type'] ?? '', ['checkbox', 'multi_select'], true);
        switch ($field['type']) {
            case 'true_false':
                return $raw === null || $raw === '' ? null : (bool) $raw;
            case 'number':
            case 'range':
                return $raw === null || $raw === '' ? null : 0 + $raw;
            case 'image':
            case 'file':
                return $raw ? (int) $raw : null;
            case 'gallery':
            case 'relationship':
                return $ids($raw);
            case 'post_object':
            case 'page_link':
            case 'user':
            case 'taxonomy':
                $single = $field['type'] === 'taxonomy' ? !in_array($field['field_type'] ?? '', ['checkbox', 'multi_select'], true) : !$multiple;
                if ($field['type'] === 'page_link' && $raw && !is_numeric(is_array($raw) ? reset($raw) : $raw)) {
                    $notes[] = sprintf('%s : lien de page ACF vers une URL d\'archive, non converti.', $field['name']);
                    return null;
                }
                return $single ? (($ids($raw)[0] ?? 0) ?: null) : $ids($raw);
            case 'date_picker':
                return is_string($raw) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) ? "$m[1]-$m[2]-$m[3]" : ($raw ? (string) $raw : null);
            case 'link':
                return is_array($raw) && !empty($raw['url']) ? ['url' => (string) $raw['url'], 'title' => (string) ($raw['title'] ?? ''), 'target' => (string) ($raw['target'] ?? '')] : null;
            case 'checkbox':
                return array_values(array_map('strval', (array) $raw));
            case 'select':
                return $multiple ? array_values(array_map('strval', (array) $raw)) : ($raw === null ? null : (string) (is_array($raw) ? reset($raw) : $raw));
            case 'repeater':
                $rows = [];
                foreach (is_array($raw) ? array_values($raw) : [] as $row) {
                    $values = [];
                    foreach ($field['sub_fields'] ?? [] as $sub) $values += $this->values($sub, $row[$sub['key']] ?? null, '', $notes);
                    $rows[] = array_filter($values, fn($value) => $value !== null);
                }
                return $rows;
            default:
                return $raw === null ? null : (is_scalar($raw) ? (string) $raw : $raw);
        }
    }
}
