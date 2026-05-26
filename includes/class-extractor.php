<?php
/**
 * Extractor — post-matching predicate + publish-hook handler + API call orchestration.
 *
 * v1 has no automated retry (spec D8); on failure, the post is marked with
 * _ai4d_extraction_error meta and a log event appended; recovery is via the
 * per-article admin re-extract button (Task 11).
 *
 * Per spec § Flows (Extraction).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Extractor {

    /**
     * Canonical list of all post-meta keys this plugin writes. Referenced
     * by uninstall-cleanup.php to ensure the remove_everything mode stays
     * in sync with what the extractor writes.
     */
    const POST_META_KEYS = array(
        '_ai4d_topics',
        '_ai4d_extracted_at',
        '_ai4d_taxonomy_version',
        '_ai4d_extraction_error',
    );

    private $api_client;

    public function __construct( ?AI4D_Topics_API_Client $api_client = null ) {
        $this->api_client = $api_client ?: new AI4D_Topics_API_Client();
    }

    /**
     * Eligibility gate. Returns null when the post is eligible for extraction,
     * otherwise a short reason code identifying which check rejected it.
     *
     * Reason codes are stable strings (admins surface them in the log + metabox).
     * Adding a new reason: append a new constant value and document here.
     */
    public function should_process( int $post_id ): ?string {
        $settings = AI4D_Topics_Settings::get_all();

        if ( ! $settings['backend_url'] ) {
            return 'backend_url_not_configured';
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return 'post_not_found';
        }

        if ( $post->post_type !== $settings['post_type'] ) {
            return 'post_type_mismatch';
        }

        if ( ! in_array( $post->post_status, $settings['allowed_statuses'], true ) ) {
            return 'post_status_not_allowed';
        }

        if ( ! empty( $settings['allowed_authors'] )
             && ! in_array( (int) $post->post_author, $settings['allowed_authors'], true ) ) {
            return 'author_not_allowed';
        }

        $tag_slugs = wp_list_pluck( wp_get_post_tags( $post_id ), 'slug' );
        $tag_slugs = array_map( 'strtolower', $tag_slugs );

        // Exclude tags take precedence.
        if ( ! empty( array_intersect( $tag_slugs, $settings['exclude_tags'] ) ) ) {
            return 'excluded_tag_matched';
        }

        // If include_tag is set, the post must have it.
        if ( $settings['include_tag'] !== '' && ! in_array( $settings['include_tag'], $tag_slugs, true ) ) {
            return 'include_tag_missing';
        }

        return null;
    }

    public function run( int $post_id ): void {
        $skip_reason = $this->should_process( $post_id );
        if ( $skip_reason !== null ) {
            // Surface the reason in two places admins look:
            // - log: a `skipped` event lets them audit silent rejections
            // - post meta: the metabox displays this so the per-article view
            //   answers "why didn't anything happen?" without a log lookup
            update_post_meta( $post_id, '_ai4d_extraction_error', 'skipped: ' . $skip_reason );
            AI4D_Topics_Log::append( 'skipped', $post_id, $skip_reason );
            return;
        }

        $settings = AI4D_Topics_Settings::get_all();
        $post     = get_post( $post_id );

        // Defensive: should_process() called get_post() already, but the post could
        // theoretically be deleted between calls (e.g., manual re-extract racing a delete).
        if ( ! $post ) {
            return;
        }

        if ( $settings['dry_run'] ) {
            AI4D_Topics_Log::append( 'dry_run', $post_id, 'matched config but dry_run is enabled — no API call' );
            return;
        }

        $article_text = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
        $result = $this->api_client->extract( $post_id, $article_text );

        // Last-known-good policy: on failure we set the error meta and stop, but do NOT
        // clear _ai4d_topics. The renderer keeps showing the last successful extraction
        // (admins see the failure via the metabox / posts column). Manual re-extract
        // (Task 11) is the recovery path per spec D8.
        if ( $result['error'] !== null ) {
            update_post_meta( $post_id, '_ai4d_extraction_error', $result['error'] );
            AI4D_Topics_Log::append( 'failure', $post_id, 'extraction failed: ' . $result['error'] );
            return;
        }

        // wp_slash neutralizes WP's automatic wp_unslash() on meta writes. Without it,
        // JSON escape sequences (e.g. Δ for Greek Δ) get stripped — the stored
        // value becomes the literal string "u0394", and json_decode reads it back as
        // plain text instead of the intended character.
        update_post_meta( $post_id, '_ai4d_topics', wp_slash( wp_json_encode( $result['topics'] ) ) );
        update_post_meta( $post_id, '_ai4d_extracted_at', time() );
        if ( $result['taxonomy_version'] ) {
            update_post_meta( $post_id, '_ai4d_taxonomy_version', $result['taxonomy_version'] );
        }
        delete_post_meta( $post_id, '_ai4d_extraction_error' );

        AI4D_Topics_Log::append(
            'success',
            $post_id,
            sprintf( 'extracted %d topics (taxonomy %s)', count( $result['topics'] ), $result['taxonomy_version'] ?? 'n/a' )
        );

        // Warm the taxonomy cache so synthesized parent slugs resolve to localized
        // labels in the metabox without waiting for the first public-page view.
        // Renderer::load_taxonomy() handles fetch + cache + ETag + stale fallback.
        // Skip if already cached (the common case after the first extract).
        if ( ! get_transient( AI4D_Topics_Renderer::TRANSIENT_KEY ) ) {
            ( new AI4D_Topics_Renderer( $this->api_client ) )->load_taxonomy();
        }
    }

    public function on_transition_post_status( string $new_status, string $old_status, $post ): void {
        if ( $new_status !== 'publish' ) {
            return;
        }
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }
        // Fast-path: skip posts of other types silently. The publish hook fires for
        // every post-type transition (pages, CPTs, attachments). Without this
        // filter, run() would log a `skipped` event for each one and quickly
        // exhaust the 200-row ring buffer with noise.
        if ( $post->post_type !== AI4D_Topics_Settings::get( 'post_type' ) ) {
            return;
        }
        $this->run( $post->ID );
    }
}
