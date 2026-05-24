<?php
/**
 * Renderer — the_content filter, label resolution, HTML building.
 *
 * Per spec § Flows (Render) and § Safety invariants.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Renderer {

    const TRANSIENT_KEY = 'ai4d_topics_taxonomy';
    const STALE_OPTION  = 'ai4d_topics_taxonomy_stale';
    const TTL_SECONDS   = HOUR_IN_SECONDS;

    private $api_client;

    public function __construct( ?AI4D_Topics_API_Client $api_client = null ) {
        $this->api_client = $api_client ?: new AI4D_Topics_API_Client();
    }

    public function load_taxonomy(): array {
        // 1. Fresh cache (transient).
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) && isset( $cached['payload'] ) ) {
            return $cached['payload'];
        }

        // 2. Fetch from backend. Defensive merge so a future client-side refactor
        // that drops a key doesn't cause PHP notices here.
        $result = array_merge(
            array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'unknown' ),
            $this->api_client->get_taxonomy()
        );

        if ( $result['not_modified'] === true ) {
            // 304 means our cached etag is still valid; reuse the stale option.
            $stale = get_option( self::STALE_OPTION, null );
            if ( is_array( $stale ) && isset( $stale['payload'] ) ) {
                set_transient( self::TRANSIENT_KEY, $stale, self::TTL_SECONDS );
                return $stale['payload'];
            }
            // Pathological: the transient existed (we sent If-None-Match) but the
            // stale option is gone. Clear the transient so the next request omits
            // If-None-Match and repopulates both stores via a fresh 200.
            delete_transient( self::TRANSIENT_KEY );
            return array( 'topics' => array() );
        }

        if ( $result['error'] === null && is_array( $result['payload'] ) ) {
            $entry = array( 'payload' => $result['payload'], 'etag' => $result['etag'] );
            set_transient( self::TRANSIENT_KEY, $entry, self::TTL_SECONDS );
            update_option( self::STALE_OPTION, $entry, false );
            return $result['payload'];
        }

        // 3. Backend error → stale fallback.
        $stale = get_option( self::STALE_OPTION, null );
        if ( is_array( $stale ) && isset( $stale['payload'] ) ) {
            return $stale['payload'];
        }

        // 4. No cache at all → empty (renderer will fall back to slug-as-label).
        return array( 'topics' => array() );
    }

    public function resolve_label( string $slug, array $taxonomy ): string {
        foreach ( $taxonomy['topics'] ?? array() as $t ) {
            if ( isset( $t['slug'] ) && $t['slug'] === $slug ) {
                return $t['label'] ?? $slug;
            }
        }
        return $slug;
    }

    public function build_html( array $topics, array $taxonomy, array $settings ): string {
        if ( empty( $topics ) ) {
            return '';
        }

        $shape    = $settings['display_shape'];
        $top_n    = (int) $settings['display_top_n'];
        $header   = $settings['display_show_header'] ? $settings['display_header_text'] : '';

        switch ( $shape ) {
            case 'chips':
                return $this->render_chips( $topics, $taxonomy, $top_n, $header );
            case 'parents_only':
                return $this->render_parents_only( $topics, $taxonomy, $header );
            case 'hierarchy':
            default:
                return $this->render_hierarchy( $topics, $taxonomy, $header );
        }
    }

    private function render_chips( array $topics, array $taxonomy, int $top_n, string $header ): string {
        $leaves = array_values( array_filter( $topics, function ( $t ) {
            return ( $t['kind'] ?? 'leaf' ) === 'leaf';
        } ) );
        $leaves = array_slice( $leaves, 0, $top_n );

        if ( empty( $leaves ) ) {
            return '';
        }

        $items = array();
        foreach ( $leaves as $leaf ) {
            $label = $this->resolve_label( $leaf['slug'], $taxonomy );
            $items[] = '<span class="ai4d-topics-chip">' . esc_html( $label ) . '</span>';
        }

        $header_html = $header !== '' ? '<h3 class="ai4d-topics-header">' . esc_html( $header ) . '</h3>' : '';
        return '<div class="ai4d-topics-block ai4d-topics-chips">' . $header_html . implode( ' ', $items ) . '</div>';
    }

    private function render_parents_only( array $topics, array $taxonomy, string $header ): string {
        $parents = array_values( array_filter( $topics, function ( $t ) {
            return ( $t['kind'] ?? '' ) === 'parent';
        } ) );

        if ( empty( $parents ) ) {
            return '';
        }

        $items = array();
        foreach ( $parents as $p ) {
            $label = $this->resolve_label( $p['slug'], $taxonomy );
            $items[] = '<span class="ai4d-topics-chip">' . esc_html( $label ) . '</span>';
        }

        $header_html = $header !== '' ? '<h3 class="ai4d-topics-header">' . esc_html( $header ) . '</h3>' : '';
        return '<div class="ai4d-topics-block ai4d-topics-parents-only">' . $header_html . implode( ' ', $items ) . '</div>';
    }

    private function render_hierarchy( array $topics, array $taxonomy, string $header ): string {
        $parents = array();
        $leaves_by_parent = array();
        foreach ( $topics as $t ) {
            $kind = $t['kind'] ?? 'leaf';
            if ( $kind === 'parent' ) {
                $parents[ $t['slug'] ] = $t;
            } else {
                // Note: leaves with no matching parent get bucketed under '__orphan__' but
                // are never rendered (the outer loop walks $parents only). The backend's
                // current contract always emits both a parent and leaf record together,
                // so this is correct for v1. If that changes, render orphans separately.
                $parent_slug = $t['parent'] ?? '__orphan__';
                $leaves_by_parent[ $parent_slug ][] = $t;
            }
        }

        if ( empty( $parents ) ) {
            return '';
        }

        $blocks = array();
        foreach ( $parents as $slug => $parent ) {
            $parent_label = $this->resolve_label( $slug, $taxonomy );
            $leaf_items = array();
            foreach ( $leaves_by_parent[ $slug ] ?? array() as $leaf ) {
                $leaf_label = $this->resolve_label( $leaf['slug'], $taxonomy );
                $leaf_items[] = '<li>' . esc_html( $leaf_label ) . '</li>';
            }
            $blocks[] = '<li class="ai4d-topics-parent"><strong>' . esc_html( $parent_label ) . '</strong>'
                        . ( $leaf_items ? '<ul>' . implode( '', $leaf_items ) . '</ul>' : '' )
                        . '</li>';
        }

        $header_html = $header !== '' ? '<h3 class="ai4d-topics-header">' . esc_html( $header ) . '</h3>' : '';
        return '<div class="ai4d-topics-block ai4d-topics-hierarchy">' . $header_html
             . '<ul class="ai4d-topics-list">' . implode( '', $blocks ) . '</ul></div>';
    }
}
