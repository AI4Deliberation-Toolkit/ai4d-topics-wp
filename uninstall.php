<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-api-client.php';
require_once __DIR__ . '/includes/class-extractor.php';
require_once __DIR__ . '/includes/uninstall-cleanup.php';

$mode = AI4D_Topics_Settings::get( 'uninstall_behavior' ) ?? 'keep_meta';
ai4d_topics_uninstall_cleanup( $mode );
