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
    const BACKOFF_SECONDS = 300; // after a failed fetch, serve the stale list this long before retrying

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
            // Pathological: the client takes If-None-Match from the stale option, so
            // this is reached only if the server answered 304 to a request that
            // carried no matching ETag (or the option vanished in between). Clear
            // the transient so the next request repopulates both stores via a 200.
            delete_transient( self::TRANSIENT_KEY );
            return array( 'topics' => array() );
        }

        if ( $result['error'] === null && is_array( $result['payload'] ) ) {
            $entry = array( 'payload' => $result['payload'], 'etag' => $result['etag'] );
            set_transient( self::TRANSIENT_KEY, $entry, self::TTL_SECONDS );
            update_option( self::STALE_OPTION, $entry, false );
            return $result['payload'];
        }

        // 3. Backend error → stale fallback, and back off: serve the stale entry
        // from the transient for BACKOFF_SECONDS so an outage costs at most one
        // slow render per backoff period. With nothing cached, keep retrying so
        // the first good answer fills the cache.
        $stale = get_option( self::STALE_OPTION, null );
        if ( is_array( $stale ) && isset( $stale['payload'] ) ) {
            set_transient( self::TRANSIENT_KEY, $stale, self::BACKOFF_SECONDS );
            return $stale['payload'];
        }

        // 4. No cache at all → empty (renderer will fall back to slug-as-label).
        return array( 'topics' => array() );
    }

    /**
     * Resolve a topic slug to its localized label.
     *
     * Lookup order:
     *   1. $stored_label — the label the backend returned in the extract response
     *      (already localized, available for leaves immediately after extraction).
     *   2. Taxonomy transient — populated on first public-page render; used for
     *      parent slugs (which the backend doesn't include in extract responses)
     *      and as a fallback if the stored label was missing.
     *   3. The slug itself — last-resort fallback so renders never break.
     */
    public function resolve_label( string $slug, array $taxonomy, ?string $stored_label = null ): string {
        if ( $stored_label !== null && $stored_label !== '' ) {
            return $stored_label;
        }
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

        // Filter to above-threshold topics. Synthesized parents carry their top
        // leaf's score (api-client groups leaves by parent and the backend sorts
        // by score desc), so a parent only survives the filter if at least one
        // of its leaves does. The hierarchy view's "drop parent with no leaves"
        // logic is then implicit — they're already gone.
        $min_score = isset( $settings['display_min_score'] ) ? (float) $settings['display_min_score'] : 0.0;
        if ( $min_score > 0.0 ) {
            $topics = array_values( array_filter( $topics, function ( $t ) use ( $min_score ) {
                return (float) ( $t['score'] ?? 0 ) >= $min_score;
            } ) );
            if ( empty( $topics ) ) {
                return '';
            }
        }

        $shape       = $settings['display_shape'];
        $top_n       = (int) $settings['display_top_n'];
        $header_html = $this->build_header_html( $settings );

        switch ( $shape ) {
            case 'chips':
                return $this->render_chips( $topics, $taxonomy, $top_n, $header_html );
            case 'parents_only':
                return $this->render_parents_only( $topics, $taxonomy, $header_html );
            case 'hierarchy':
                return $this->render_hierarchy( $topics, $taxonomy, $header_html );
            case 'compact':
            default:
                return $this->render_compact( $topics, $taxonomy, $header_html );
        }
    }

    /**
     * Build the header HTML once for all display shapes. Includes an optional
     * "AI-generated" disclosure inline with the header so visitors know the
     * topic list isn't editorially curated. Empty disclosure text hides it.
     */
    private function build_header_html( array $settings ): string {
        if ( empty( $settings['display_show_header'] ) ) {
            return '';
        }
        $header_text = (string) ( $settings['display_header_text'] ?? '' );
        if ( $header_text === '' ) {
            return '';
        }
        $disclosure_text = (string) ( $settings['display_ai_disclosure_text'] ?? '' );
        $disclosure_html = $disclosure_text !== ''
            ? ' <span class="ai4d-topics-ai-disclosure">' . esc_html( $disclosure_text ) . '</span>'
            : '';
        return '<h3 class="ai4d-topics-header">' . esc_html( $header_text ) . $disclosure_html . '</h3>';
    }

    private function render_chips( array $topics, array $taxonomy, int $top_n, string $header_html ): string {
        $leaves = array_values( array_filter( $topics, function ( $t ) {
            return ( $t['kind'] ?? 'leaf' ) === 'leaf';
        } ) );
        $leaves = array_slice( $leaves, 0, $top_n );

        if ( empty( $leaves ) ) {
            return '';
        }

        $items = array();
        foreach ( $leaves as $leaf ) {
            $label = $this->resolve_label( $leaf['slug'], $taxonomy, $leaf['label'] ?? null );
            $items[] = '<span class="ai4d-topics-chip">' . esc_html( $label ) . '</span>';
        }

        return '<div class="ai4d-topics-block ai4d-topics-chips">' . $header_html . implode( ' ', $items ) . '</div>';
    }

    private function render_parents_only( array $topics, array $taxonomy, string $header_html ): string {
        $parents = array_values( array_filter( $topics, function ( $t ) {
            return ( $t['kind'] ?? '' ) === 'parent';
        } ) );

        if ( empty( $parents ) ) {
            return '';
        }

        $items = array();
        foreach ( $parents as $p ) {
            // Parents are synthesized by api-client and have no stored label; resolve
            // via taxonomy cache (or fall back to slug if cache is empty).
            $label = $this->resolve_label( $p['slug'], $taxonomy );
            $items[] = '<span class="ai4d-topics-chip">' . esc_html( $label ) . '</span>';
        }

        return '<div class="ai4d-topics-block ai4d-topics-parents-only">' . $header_html . implode( ' ', $items ) . '</div>';
    }

    public function filter_content( string $content ): string {
        $post = null;  // initialise so the catch block's $post->ID ?? 0 is safe even if try throws early
        try {
            // D5: only render on public single-post views. Archives/loops are out of scope.
            if ( ! is_singular() ) {
                return $content;
            }
            $post = $GLOBALS['post'] ?? null;
            if ( ! $post instanceof WP_Post ) {
                return $content;
            }

            $settings = AI4D_Topics_Settings::get_all();

            if ( $post->post_type !== $settings['post_type'] ) {
                return $content;
            }

            $raw = get_post_meta( $post->ID, '_ai4d_topics', true );
            if ( empty( $raw ) ) {
                return $content;
            }

            $topics = json_decode( $raw, true );
            if ( ! is_array( $topics ) ) {
                return $content;
            }

            $taxonomy = $this->load_taxonomy();
            $html     = $this->build_html( $topics, $taxonomy, $settings );

            if ( $html === '' ) {
                return $content;
            }

            // Topics always render above the article body so they sit just under
            // the title/byline (which most themes render outside the_content).
            return $html . $content;

        } catch ( \Throwable $e ) {
            // Safety invariant: render-path never breaks the page.
            if ( class_exists( 'AI4D_Topics_Log' ) ) {
                AI4D_Topics_Log::append( 'render_error', (int) ( $post->ID ?? 0 ), 'renderer exception: ' . $e->getMessage() );
            }
            return $content;
        }
    }

    /**
     * Compact hierarchy: one line per parent, leaves listed inline after the
     * parent label separated by commas. Same grouping logic as render_hierarchy
     * but flattened into "Parent: leaf, leaf, leaf" — easier to skim than the
     * nested-list view.
     */
    private function render_compact( array $topics, array $taxonomy, string $header_html ): string {
        $parents = array();
        $leaves_by_parent = array();
        foreach ( $topics as $t ) {
            $kind = $t['kind'] ?? 'leaf';
            if ( $kind === 'parent' ) {
                $parents[ $t['slug'] ] = $t;
            } else {
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
            $leaf_labels = array();
            foreach ( $leaves_by_parent[ $slug ] ?? array() as $leaf ) {
                $leaf_labels[] = esc_html( $this->resolve_label( $leaf['slug'], $taxonomy, $leaf['label'] ?? null ) );
            }
            $leaves_html = $leaf_labels
                ? ': <span class="ai4d-topics-leaves">' . implode( ', ', $leaf_labels ) . '</span>'
                : '';
            $blocks[] = '<li class="ai4d-topics-parent"><strong>' . esc_html( $parent_label ) . '</strong>'
                        . $leaves_html
                        . '</li>';
        }

        return '<div class="ai4d-topics-block ai4d-topics-compact">' . $header_html
             . '<ul class="ai4d-topics-list">' . implode( '', $blocks ) . '</ul></div>';
    }

    private function render_hierarchy( array $topics, array $taxonomy, string $header_html ): string {
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
            // Parent labels live in the taxonomy transient (no stored label here —
            // synthesized parents have label=null). Leaf labels are stored directly
            // by api-client from the extract response, so they render immediately.
            $parent_label = $this->resolve_label( $slug, $taxonomy );
            $leaf_items = array();
            foreach ( $leaves_by_parent[ $slug ] ?? array() as $leaf ) {
                $leaf_label = $this->resolve_label( $leaf['slug'], $taxonomy, $leaf['label'] ?? null );
                $leaf_items[] = '<li>' . esc_html( $leaf_label ) . '</li>';
            }
            $blocks[] = '<li class="ai4d-topics-parent"><strong>' . esc_html( $parent_label ) . '</strong>'
                        . ( $leaf_items ? '<ul>' . implode( '', $leaf_items ) . '</ul>' : '' )
                        . '</li>';
        }

        return '<div class="ai4d-topics-block ai4d-topics-hierarchy">' . $header_html
             . '<ul class="ai4d-topics-list">' . implode( '', $blocks ) . '</ul></div>';
    }
}
