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

    const ALLOWED_SHAPES = array( 'compact', 'hierarchy', 'chips', 'parents_only' );
    const ALLOWED_UNINSTALL = array( 'keep_meta', 'remove_everything' );

    // 'direct' calls the toolkit at backend_url; 'gateway' calls it through the AI4D gateway.
    const MODES = array( 'direct', 'gateway' );

    // Write-only in the settings form: never rendered; an empty post keeps the stored value.
    const KEY_FIELDS = array( 'toolkit_key', 'gateway_key' );

    public function defaults(): array {
        return array(
            'connection_mode'     => 'direct',
            // The direct-mode Toolkit URL (key name kept from before 0.1.12).
            'backend_url'         => '',
            'toolkit_key'         => '',
            'gateway_url'         => '',
            'gateway_key'         => '',
            'post_type'           => 'post',
            'include_tag'         => '',
            'exclude_tags'        => array( 'deliberation' ),
            'allowed_statuses'    => array( 'publish' ),
            'allowed_authors'     => array(),
            'dry_run'             => false,
            'display_shape'       => 'compact',
            'display_top_n'       => 5,
            'display_min_score'   => 2.0,
            'display_show_header' => true,
            'display_header_text' => 'Θεματικές ενότητες',
            'display_ai_disclosure_text' => 'AI-generated',
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

    /**
     * The active connection: which base URL and key the API client uses.
     *
     * Read on every call, so a mode switch applies from the next call on.
     * Gateway mode never falls back to direct: an empty gateway URL or key
     * is reported as gateway_not_configured.
     *
     * @return array{mode: string, base_url: string, key: string, error: ?string}
     */
    public static function connection(): array {
        $s    = self::get_all();
        $mode = in_array( $s['connection_mode'], self::MODES, true ) ? $s['connection_mode'] : 'direct';

        if ( $mode === 'gateway' ) {
            $base_url = (string) $s['gateway_url'];
            $key      = (string) $s['gateway_key'];
            $error    = ( $base_url === '' || $key === '' ) ? 'gateway_not_configured' : null;
        } else {
            $base_url = (string) $s['backend_url'];
            $key      = (string) $s['toolkit_key'];
            $error    = $base_url === '' ? 'backend_url_not_configured' : null;
        }

        return array( 'mode' => $mode, 'base_url' => $base_url, 'key' => $key, 'error' => $error );
    }

    public static function sanitize( array $input ): array {
        $instance = new self();
        $defaults = $instance->defaults();
        $merged   = array_merge( $defaults, $input );

        $merged['backend_url'] = self::sanitize_url( $merged['backend_url'] );
        $merged['gateway_url'] = self::sanitize_url( $merged['gateway_url'] );

        if ( ! in_array( $merged['connection_mode'], self::MODES, true ) ) {
            $merged['connection_mode'] = $defaults['connection_mode'];
        }

        // Keys: WP replaces the whole option with this output, and the form
        // never renders a key, so carry the stored key over unless a new one
        // is typed or the clear box is ticked. Must stay idempotent: WP runs
        // this twice on the first save of the option.
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        foreach ( self::KEY_FIELDS as $field ) {
            $kept  = isset( $stored[ $field ] ) && is_string( $stored[ $field ] ) ? $stored[ $field ] : '';
            $typed = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';

            if ( $typed !== '' ) {
                // The key goes into an HTTP header: printable ASCII, no spaces or line breaks.
                if ( preg_match( '/^[\x21-\x7E]{1,256}\z/', $typed ) ) {
                    $merged[ $field ] = $typed;
                } else {
                    // Invalid typed key: keep the stored one, even with the clear box ticked.
                    $merged[ $field ] = $kept;
                    if ( function_exists( 'add_settings_error' ) ) {
                        add_settings_error(
                            self::OPTION_KEY,
                            $field . '_invalid',
                            ( $field === 'toolkit_key' ? 'Toolkit' : 'Gateway' ) . ' key not saved: it may contain only printable ASCII characters, no spaces or line breaks.'
                        );
                    }
                }
            } elseif ( ! empty( $input[ $field . '_clear' ] ) ) {
                $merged[ $field ] = '';
            } else {
                $merged[ $field ] = $kept;
            }
        }

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

        // display_min_score: float, clamp to 0..10. Hides leaves with score below
        // this on the public page. Default 2.0 (empirically the noise floor for
        // embedding-based extraction; tune per partner site).
        $min_score = (float) $merged['display_min_score'];
        $merged['display_min_score'] = max( 0.0, min( 10.0, $min_score ) );

        // display_show_header: bool
        $merged['display_show_header'] = (bool) $merged['display_show_header'];

        // display_header_text: plain text, no HTML. Consumers MUST esc_html() on output.
        $merged['display_header_text'] = is_scalar( $merged['display_header_text'] )
            ? sanitize_text_field( (string) $merged['display_header_text'] )
            : '';

        // display_ai_disclosure_text: plain text shown next to the header (empty disables it).
        // Consumers MUST esc_html() on output.
        $merged['display_ai_disclosure_text'] = is_scalar( $merged['display_ai_disclosure_text'] )
            ? sanitize_text_field( (string) $merged['display_ai_disclosure_text'] )
            : '';

        // uninstall_behavior
        if ( ! in_array( $merged['uninstall_behavior'], self::ALLOWED_UNINSTALL, true ) ) {
            $merged['uninstall_behavior'] = $defaults['uninstall_behavior'];
        }

        // Restrict to declared schema — drops any unknown keys from $input,
        // including the posted-only *_key_clear boxes.
        return array_intersect_key( $merged, $defaults );
    }

    /**
     * A base URL: must be http(s); trailing slash stripped; anything else becomes ''.
     */
    private static function sanitize_url( $value ): string {
        $url = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( $url !== '' && ! preg_match( '#^https?://#i', $url ) ) {
            $url = '';
        }
        return rtrim( $url, '/' );
    }
}
