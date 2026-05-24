<?php
/**
 * Ring-buffer event log stored in wp_options.
 *
 * Capped at 200 entries (oldest dropped). Used by extractor + admin for
 * visibility into success/failure events. Per spec § Data model.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Log {

    const OPTION_KEY = 'ai4d_topics_log';
    const MAX_ENTRIES = 200;

    /**
     * Best-effort: concurrent appends can drop entries (option-level read-modify-write
     * has no native locking in WP). Acceptable for an advisory event log.
     * Callers should keep $message short — large strings × 200 entries bloats wp_options.
     */
    public static function append( string $type, int $post_id, string $message ): void {
        $events = self::get_all();
        array_unshift( $events, array(
            'timestamp' => time(),
            'type'      => $type,
            'post_id'   => $post_id,
            'message'   => $message,
        ) );
        if ( count( $events ) > self::MAX_ENTRIES ) {
            $events = array_slice( $events, 0, self::MAX_ENTRIES );
        }
        update_option( self::OPTION_KEY, $events, false );
    }

    public static function get_all(): array {
        $events = get_option( self::OPTION_KEY, array() );
        return is_array( $events ) ? $events : array();
    }

    public static function clear(): void {
        delete_option( self::OPTION_KEY );
    }
}
