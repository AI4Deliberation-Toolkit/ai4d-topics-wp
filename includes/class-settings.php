<?php
/**
 * Settings module — defaults, sanitization, and option access.
 *
 * Per spec D10 (adaptability), every "what to process" and "how to display"
 * decision is exposed as a setting so site structural changes don't require
 * code changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Settings {

    const OPTION_KEY = 'ai4d_topics_settings';

    const ALLOWED_SHAPES = array( 'hierarchy', 'chips', 'parents_only' );
    const ALLOWED_POSITIONS = array( 'end', 'before' );
    const ALLOWED_UNINSTALL = array( 'keep_meta', 'remove_everything' );

    public function defaults(): array {
        return array(
            'backend_url'         => '',
            'post_type'           => 'post',
            'include_tag'         => '',
            'exclude_tags'        => array( 'deliberation' ),
            'allowed_statuses'    => array( 'publish' ),
            'allowed_authors'     => array(),
            'dry_run'             => false,
            'display_shape'       => 'hierarchy',
            'display_top_n'       => 5,
            'display_position'    => 'end',
            'display_show_header' => true,
            'display_header_text' => 'Θεματικές ενότητες',
            'uninstall_behavior'  => 'keep_meta',
        );
    }

    public static function get_all(): array {
        $instance = new self();
        $saved    = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return array_merge( $instance->defaults(), $saved );
    }

    public static function get( string $key ) {
        $all = self::get_all();
        return $all[ $key ] ?? null;
    }

    public static function sanitize( array $input ): array {
        $instance = new self();
        $defaults = $instance->defaults();
        $merged   = array_merge( $defaults, $input );

        // backend_url: must be http(s); strip trailing slash; else reset to default
        $url = is_scalar( $merged['backend_url'] ) ? trim( (string) $merged['backend_url'] ) : '';
        if ( $url !== '' && ! preg_match( '#^https?://#i', $url ) ) {
            $url = '';
        }
        $merged['backend_url'] = rtrim( $url, '/' );

        // post_type: lowercase, sanitize as key
        $merged['post_type'] = is_scalar( $merged['post_type'] ) ? sanitize_key( (string) $merged['post_type'] ) : '';
        if ( $merged['post_type'] === '' ) {
            $merged['post_type'] = $defaults['post_type'];
        }

        // include_tag: trim + lowercase (slug-like)
        $merged['include_tag'] = is_scalar( $merged['include_tag'] ) ? strtolower( trim( (string) $merged['include_tag'] ) ) : '';

        // exclude_tags: accept string (comma-separated) or array; trim, lowercase, dedupe
        $tags = $merged['exclude_tags'];
        if ( is_string( $tags ) ) {
            $tags = explode( ',', $tags );
        }
        if ( ! is_array( $tags ) ) {
            $tags = array();
        }
        $tags = array_map( function ( $t ) {
            return is_scalar( $t ) ? strtolower( trim( (string) $t ) ) : '';
        }, $tags );
        $tags = array_filter( $tags, function ( $t ) { return $t !== ''; } );
        $merged['exclude_tags'] = array_values( array_unique( $tags ) );

        // allowed_statuses: array of post status keys; default to publish
        $statuses = $merged['allowed_statuses'];
        if ( ! is_array( $statuses ) || empty( $statuses ) ) {
            $statuses = $defaults['allowed_statuses'];
        }
        $merged['allowed_statuses'] = array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );

        // allowed_authors: array of int user IDs
        $authors = $merged['allowed_authors'];
        if ( ! is_array( $authors ) ) {
            $authors = array();
        }
        // array_filter drops 0s — absint('foo') === 0 and user ID 0 is invalid in WP.
        $merged['allowed_authors'] = array_values( array_unique( array_filter( array_map( 'absint', $authors ) ) ) );

        // dry_run: bool
        $merged['dry_run'] = (bool) $merged['dry_run'];

        // display_shape
        if ( ! in_array( $merged['display_shape'], self::ALLOWED_SHAPES, true ) ) {
            $merged['display_shape'] = $defaults['display_shape'];
        }

        // display_top_n: clamp to 1..20
        $n = (int) $merged['display_top_n'];
        $merged['display_top_n'] = max( 1, min( 20, $n ) );

        // display_position
        if ( ! in_array( $merged['display_position'], self::ALLOWED_POSITIONS, true ) ) {
            $merged['display_position'] = $defaults['display_position'];
        }

        // display_show_header: bool
        $merged['display_show_header'] = (bool) $merged['display_show_header'];

        // display_header_text: plain text, no HTML. Consumers MUST esc_html() on output.
        $merged['display_header_text'] = is_scalar( $merged['display_header_text'] )
            ? sanitize_text_field( (string) $merged['display_header_text'] )
            : '';

        // uninstall_behavior
        if ( ! in_array( $merged['uninstall_behavior'], self::ALLOWED_UNINSTALL, true ) ) {
            $merged['uninstall_behavior'] = $defaults['uninstall_behavior'];
        }

        // Restrict to declared schema — drops any unknown keys from $input.
        return array_intersect_key( $merged, $defaults );
    }
}
