<?php

/**
 * Plugin Name: Field
 * Plugin URI:        https://github.com/Amund/wp-field
 * Description:       Fields for native PHP blocks and post meta, declared in code and edited in the block editor. Temporary layer over WordPress core.
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Dimitri Avenel
 * License:           MIT
 */

if (!defined('ABSPATH')) {
    exit();
}

require_once 'class/field.php';

field::init();

// wp-cli: migration from ACF blocks (wp field acf list | scaffold | convert)
if (defined('WP_CLI') && constant('WP_CLI')) {
    require_once 'class/field_acf_cli.php';
    add_action('cli_init', fn() => WP_CLI::add_command('field acf', 'field_acf_cli'));
}
