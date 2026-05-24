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

    private $api_client;

    public function __construct( ?AI4D_Topics_API_Client $api_client = null ) {
        $this->api_client = $api_client ?: new AI4D_Topics_API_Client();
    }

    public function should_process( int $post_id ): bool {
        $settings = AI4D_Topics_Settings::get_all();

        if ( ! $settings['backend_url'] ) {
            return false;
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        if ( $post->post_type !== $settings['post_type'] ) {
            return false;
        }

        if ( ! in_array( $post->post_status, $settings['allowed_statuses'], true ) ) {
            return false;
        }

        if ( ! empty( $settings['allowed_authors'] )
             && ! in_array( (int) $post->post_author, $settings['allowed_authors'], true ) ) {
            return false;
        }

        $tag_slugs = wp_list_pluck( wp_get_post_tags( $post_id ), 'slug' );
        $tag_slugs = array_map( 'strtolower', $tag_slugs );

        // Exclude tags take precedence.
        if ( ! empty( array_intersect( $tag_slugs, $settings['exclude_tags'] ) ) ) {
            return false;
        }

        // If include_tag is set, the post must have it.
        if ( $settings['include_tag'] !== '' && ! in_array( $settings['include_tag'], $tag_slugs, true ) ) {
            return false;
        }

        return true;
    }

    public function run( int $post_id ): void {
        if ( ! $this->should_process( $post_id ) ) {
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

        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( $result['topics'] ) );
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
    }

    public function on_transition_post_status( string $new_status, string $old_status, $post ): void {
        if ( $new_status !== 'publish' ) {
            return;
        }
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }
        $this->run( $post->ID );
    }
}
