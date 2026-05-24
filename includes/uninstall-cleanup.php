<?php
/**
 * Uninstall cleanup function — extracted so it can be tested and reused.
 * Called from uninstall.php (the WP-invoked entry point) and exposed to tests.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function ai4d_topics_uninstall_cleanup( string $mode ): void {
    delete_option( 'ai4d_topics_settings' );
    delete_option( 'ai4d_topics_log' );
    delete_option( 'ai4d_topics_taxonomy_stale' );
    delete_option( 'ai4d_topics_retry_queue' );
    delete_transient( 'ai4d_topics_taxonomy' );

    if ( $mode === 'remove_everything' ) {
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( AI4D_Topics_Extractor::POST_META_KEYS ), '%s' ) );
        $sql = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from constant
        $wpdb->query( $wpdb->prepare( $sql, AI4D_Topics_Extractor::POST_META_KEYS ) );
    }
}
