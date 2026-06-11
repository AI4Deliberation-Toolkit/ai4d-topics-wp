<?php
/**
 * Plugin Name: AI4D Topics
 * Description: AI-powered topic extraction for opengov consultations. Calls the AI4D backend on article publish and renders topics on the public page.
 * Version:     0.1.11
 * Author:      Konnektable
 * License:     EUPL-1.2
 * License URI: https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AI4D_TOPICS_VERSION', '0.1.11' );
define( 'AI4D_TOPICS_PLUGIN_FILE', __FILE__ );
define( 'AI4D_TOPICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-settings.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-log.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-api-client.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-extractor.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-renderer.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-admin.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-retry-cron.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-bulk-reextract.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/uninstall-cleanup.php';

/**
 * Bootstrap — register hooks for the v1 plugin.
 */
function ai4d_topics_bootstrap(): void {
    $extractor = new AI4D_Topics_Extractor();
    $renderer  = new AI4D_Topics_Renderer();
    $admin     = new AI4D_Topics_Admin( $extractor );

    // Extraction
    add_action( 'transition_post_status', array( $extractor, 'on_transition_post_status' ), 10, 3 );

    // Render
    add_filter( 'the_content', array( $renderer, 'filter_content' ), 20 );

    // Admin
    if ( is_admin() ) {
        add_action( 'admin_menu', array( $admin, 'register_settings_page' ) );
        add_action( 'admin_menu', array( $admin, 'register_log_viewer_page' ) );
        add_action( 'admin_init', array( $admin, 'register_metabox_for_current_screen' ) );
        add_action( 'admin_post_ai4d_topics_reextract', array( $admin, 'handle_reextract_action_and_redirect' ) );
        add_action( 'admin_post_ai4d_topics_clear', array( $admin, 'handle_clear_action_and_redirect' ) );

        $post_type = AI4D_Topics_Settings::get( 'post_type' );
        add_filter( 'manage_' . $post_type . '_posts_columns', array( $admin, 'register_column' ) );
        add_action( 'manage_' . $post_type . '_posts_custom_column', array( $admin, 'render_column' ), 10, 2 );

        $bulk = new AI4D_Topics_Bulk_Reextract();
        add_action( 'admin_menu', array( $bulk, 'register_page' ) );
        add_action( 'wp_ajax_ai4d_topics_bulk_preview', array( $bulk, 'ajax_preview' ) );
        add_action( 'wp_ajax_ai4d_topics_bulk_start', array( $bulk, 'ajax_start' ) );
        add_action( 'wp_ajax_ai4d_topics_bulk_step', array( $bulk, 'ajax_step' ) );
        add_action( 'wp_ajax_ai4d_topics_bulk_cancel', array( $bulk, 'ajax_cancel' ) );

        add_action( 'admin_enqueue_scripts', function ( $hook ) use ( $bulk ) {
            // Scope the bulk JS to its own Tools screen only.
            if ( $hook !== 'tools_page_' . AI4D_Topics_Bulk_Reextract::PAGE_SLUG ) {
                return;
            }
            wp_enqueue_script(
                'ai4d-topics-bulk',
                plugins_url( 'assets/bulk.js', AI4D_TOPICS_PLUGIN_FILE ),
                array( 'jquery' ),
                AI4D_TOPICS_VERSION,
                true
            );
            wp_localize_script( 'ai4d-topics-bulk', 'AI4DBulk', array(
                'ajaxurl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( AI4D_Topics_Bulk_Reextract::NONCE ),
                'progress' => $bulk->get_progress(),
            ) );
        } );
    }

    // Styles
    add_action( 'wp_enqueue_scripts', function () {
        wp_enqueue_style( 'ai4d-topics-public', plugins_url( 'assets/public.css', AI4D_TOPICS_PLUGIN_FILE ), array(), AI4D_TOPICS_VERSION );
    } );

    if ( is_admin() ) {
        add_action( 'admin_enqueue_scripts', function () {
            wp_enqueue_style( 'ai4d-topics-admin', plugins_url( 'assets/admin.css', AI4D_TOPICS_PLUGIN_FILE ), array(), AI4D_TOPICS_VERSION );
        } );
    }
}
add_action( 'plugins_loaded', 'ai4d_topics_bootstrap' );

/**
 * On-upgrade migrations.
 *
 * Tracks the last-seen plugin version in an option and runs version-gated
 * migrations on first request after an upgrade.
 */
function ai4d_topics_maybe_migrate(): void {
    $stored = get_option( 'ai4d_topics_db_version', null );
    if ( $stored === AI4D_TOPICS_VERSION ) {
        return;
    }

    // 0.1.10: default shape changed from 'hierarchy' to 'compact'. Bump sites
    // that were on the old default; preserve explicit picks (chips, parents_only).
    if ( $stored === null || version_compare( (string) $stored, '0.1.10', '<' ) ) {
        $opts = get_option( AI4D_Topics_Settings::OPTION_KEY, array() );
        if ( is_array( $opts ) && ( $opts['display_shape'] ?? null ) === 'hierarchy' ) {
            $opts['display_shape'] = 'compact';
            update_option( AI4D_Topics_Settings::OPTION_KEY, $opts );
        }
    }

    update_option( 'ai4d_topics_db_version', AI4D_TOPICS_VERSION );
}
add_action( 'plugins_loaded', 'ai4d_topics_maybe_migrate', 5 );
