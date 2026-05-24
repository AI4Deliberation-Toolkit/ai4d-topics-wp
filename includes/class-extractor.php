<?php
/**
 * Extractor — post-matching predicate (Task 5).
 *
 * Task 6 will add the publish-hook handler (run() + on_transition_post_status())
 * and API call orchestration. v1 has no automated retry (spec D8); on failure,
 * the post will be marked with _ai4d_extraction_error meta and a log event
 * appended (Task 6); recovery is via the per-article admin re-extract button.
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
}
