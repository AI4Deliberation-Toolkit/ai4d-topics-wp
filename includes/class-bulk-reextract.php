<?php
/**
 * Bulk re-extract — durable queue, set builder, preview, step processor,
 * admin page, and AJAX handlers. Admin-only; registered under is_admin().
 *
 * Per spec docs/superpowers/specs/2026-06-10-wp-plugin-bulk-reextract-design.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Bulk_Reextract {

    const OPTION_IDS      = 'ai4d_topics_bulk_ids';
    const OPTION_PROGRESS = 'ai4d_topics_bulk_progress';
    const NONCE           = 'ai4d_topics_bulk';
    const PAGE_SLUG       = 'ai4d-topics-bulk';
    const CHUNK_MAX       = 5;    // max posts processed per AJAX request
    const TIME_BUDGET     = 20;   // soft wall-clock seconds per AJAX request

    private $extractor;

    public function __construct( ?AI4D_Topics_Extractor $extractor = null ) {
        $this->extractor = $extractor ?: new AI4D_Topics_Extractor();
    }

    private function default_progress(): array {
        return array(
            'mode'       => '',
            'cursor'     => 0,
            'total'      => 0,
            'succeeded'  => 0,
            'skipped'    => 0,
            'failed'     => 0,
            'status'     => 'idle',  // idle | running | done | cancelled
            'started_by' => 0,
            'started_at' => 0,
            'updated_at' => 0,
        );
    }

    public function get_progress(): array {
        $p = get_option( self::OPTION_PROGRESS, null );
        return is_array( $p ) ? array_merge( $this->default_progress(), $p ) : $this->default_progress();
    }

    private function write_progress( array $p ): void {
        update_option( self::OPTION_PROGRESS, $p, false );
    }
}
