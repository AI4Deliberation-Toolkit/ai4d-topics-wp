<?php
/**
 * Plugin Name: AI4D Topics
 * Description: AI-powered topic extraction for opengov consultations.
 * Version:     0.1.0
 * Author:      Konnekt
 * License:     Proprietary
 * Requires PHP: 7.4
 * Requires at least: 5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AI4D_TOPICS_VERSION', '0.1.0' );
define( 'AI4D_TOPICS_PLUGIN_FILE', __FILE__ );
define( 'AI4D_TOPICS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-settings.php';
require_once AI4D_TOPICS_PLUGIN_DIR . 'includes/class-log.php';
