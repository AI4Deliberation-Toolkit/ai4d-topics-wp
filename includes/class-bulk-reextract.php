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

    /**
     * Build the set of post IDs to process. The query mirrors should_process()'s
     * gate (post_type, allowed_statuses, allowed_authors, exclude/include tags) so
     * excluded posts are never enqueued or touched. should_process() inside run()
     * remains the authoritative run-time re-check.
     *
     * Note: should_process()'s backend_url check is intentionally NOT mirrored here.
     * With an unconfigured backend this still returns the full eligible set, which
     * run() then skips per post — so a count derived from this set would overstate
     * what actually gets processed until the backend URL is set.
     *
     * @param string $mode 'all' | 'extracted'
     * @return int[]
     */
    public function build_id_set( string $mode ): array {
        $settings = AI4D_Topics_Settings::get_all();

        $args = array(
            'post_type'      => $settings['post_type'],
            'post_status'    => $settings['allowed_statuses'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );

        if ( ! empty( $settings['allowed_authors'] ) ) {
            $args['author__in'] = array_map( 'intval', $settings['allowed_authors'] );
        }

        $tax_query = array();
        if ( ! empty( $settings['exclude_tags'] ) ) {
            $tax_query[] = array(
                'taxonomy' => 'post_tag',
                'field'    => 'slug',
                'terms'    => $settings['exclude_tags'],
                'operator' => 'NOT IN',
            );
        }
        if ( $settings['include_tag'] !== '' ) {
            $tax_query[] = array(
                'taxonomy' => 'post_tag',
                'field'    => 'slug',
                'terms'    => array( $settings['include_tag'] ),
                'operator' => 'IN',
            );
        }
        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query;
        }

        if ( $mode === 'extracted' ) {
            $args['meta_query'] = array(
                array( 'key' => '_ai4d_topics', 'compare' => 'EXISTS' ),
            );
        }

        $ids = get_posts( $args );
        return array_map( 'intval', $ids );
    }
}
