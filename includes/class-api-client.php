<?php
/**
 * HTTP client for the backend.
 *
 * Wraps wp_remote_post / wp_remote_get for the two endpoints the plugin uses:
 *   POST /api/topics/extract-conversation  — extract topics for an article
 *   GET  /api/topics                       — fetch taxonomy listing
 *
 * Both methods return associative arrays with an 'error' key (null on success).
 * This is the only file that knows about the backend's JSON shapes or HTTP
 * mechanics; per spec § Architecture, the rest of the plugin trades in PHP arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_API_Client {

    const PATH_EXTRACT  = '/api/topics/extract-conversation';
    const PATH_TAXONOMY = '/api/topics';
    const TIMEOUT_EXTRACT  = 30; // spec D8: embedding extraction can run several seconds.
    const TIMEOUT_TAXONOMY = 2;  // spec D13: render-path budget; fall back to stale on slow backend.

    public function extract( int $post_id, string $article_text ): array {
        $base_url = AI4D_Topics_Settings::get( 'backend_url' );
        if ( ! $base_url ) {
            return array( 'topics' => array(), 'taxonomy_version' => null, 'error' => 'backend_url_not_configured' );
        }

        $body = array(
            'platform'   => 'wordpress-opengov',
            'article_id' => $post_id,
            'article'    => $article_text,
            'comments'   => array(),
            'language'   => 'el',
        );

        $response = wp_remote_post( $base_url . self::PATH_EXTRACT . '?include_labels=true', array(
            'method'  => 'POST',
            'timeout' => self::TIMEOUT_EXTRACT,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'topics' => array(), 'taxonomy_version' => null, 'error' => $response->get_error_code() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return array( 'topics' => array(), 'taxonomy_version' => null, 'error' => 'backend_' . $code );
        }
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return array( 'topics' => array(), 'taxonomy_version' => null, 'error' => 'malformed_response' );
        }

        // Backend returns {normalized: [{topic_id, score, raw_score, label?, parent?}], ...}.
        // Translate to the plugin's internal shape {topics: [{slug, score, kind, parent}]} so
        // the renderer/admin can keep using the simpler vocabulary.
        $normalized = is_array( $decoded['normalized'] ?? null ) ? $decoded['normalized'] : array();
        $topics = array();
        foreach ( $normalized as $item ) {
            if ( ! is_array( $item ) || empty( $item['topic_id'] ) ) {
                continue;
            }
            $topics[] = array(
                'slug'   => $item['topic_id'],
                'score'  => $item['score'] ?? 0,
                'kind'   => empty( $item['parent'] ) ? 'parent' : 'leaf',
                'parent' => $item['parent'] ?? null,
                // Backend returns localized labels when include_labels=true (we always
                // send it). Storing the label here means the metabox can render Greek
                // text immediately on first admin view, without waiting for the
                // taxonomy transient to be populated by the first public-page load.
                'label'  => ( isset( $item['label'] ) && is_string( $item['label'] ) ) ? $item['label'] : null,
            );
        }

        // The backend only emits leaf slugs (see taxonomy_data.py header comment) but the
        // renderer's hierarchy / parents_only views need explicit parent records. Synthesize
        // one parent record per unique parent slug referenced by the leaves. The renderer's
        // resolve_label() then joins each synthesized parent against the cached taxonomy
        // listing for its localized label. First-encounter order preserves "best parent first"
        // because the backend returns leaves score-sorted descending.
        $seen_parents = array();
        foreach ( $topics as $t ) {
            if ( ( $t['kind'] ?? '' ) === 'parent' ) {
                $seen_parents[ $t['slug'] ] = true;
            }
        }
        $parent_records = array();
        foreach ( $topics as $leaf ) {
            if ( ( $leaf['kind'] ?? '' ) !== 'leaf' ) {
                continue;
            }
            $parent_slug = $leaf['parent'];
            if ( ! $parent_slug || isset( $seen_parents[ $parent_slug ] ) ) {
                continue;
            }
            $seen_parents[ $parent_slug ] = true;
            $parent_records[] = array(
                'slug'   => $parent_slug,
                'score'  => $leaf['score'],
                'kind'   => 'parent',
                'parent' => null,
                // Synthesized parents have no label from the extract response; the
                // renderer resolves them via the taxonomy transient at render time.
                'label'  => null,
            );
        }
        $topics = array_merge( $parent_records, $topics );

        return array(
            'topics'           => $topics,
            'taxonomy_version' => $decoded['taxonomy_version'] ?? null,
            'error'            => null,
        );
    }

    public function get_taxonomy(): array {
        $base_url = AI4D_Topics_Settings::get( 'backend_url' );
        if ( ! $base_url ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'backend_url_not_configured' );
        }

        // The renderer owns both caches (the transient and the stale option). The
        // client reads only the stale option, only to attach If-None-Match: the
        // renderer calls us after the transient has expired, so the transient
        // never holds the ETag at this point.
        $stale = get_option( AI4D_Topics_Renderer::STALE_OPTION, null );
        $headers = array();
        if ( is_array( $stale ) && ! empty( $stale['etag'] ) ) {
            $headers['If-None-Match'] = $stale['etag'];
        }
        $headers['Accept'] = 'application/json';
        // The Runpod proxy turns the ETag weak (W/) when it compresses the answer,
        // and the toolkit compares If-None-Match exactly; ask for it uncompressed.
        $headers['Accept-Encoding'] = 'identity';

        $response = wp_remote_get( $base_url . self::PATH_TAXONOMY . '?language=el', array(
            'method'  => 'GET',
            'timeout' => self::TIMEOUT_TAXONOMY,
            'headers' => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => $response->get_error_code() );
        }
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 304 ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => true, 'error' => null );
        }
        if ( $code < 200 || $code >= 300 ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'backend_' . $code );
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'malformed_response' );
        }

        // Backend returns {version, language, parents: {slug: label}, leaves: {slug: label}, hierarchy: {leaf: parent}}.
        // Flatten parents + leaves into a single 'topics' array of {slug, label} so the renderer's
        // resolve_label() (which iterates $taxonomy['topics']) finds both.
        $topics = array();
        foreach ( ( $decoded['parents'] ?? array() ) as $slug => $label ) {
            $topics[] = array( 'slug' => $slug, 'label' => $label );
        }
        foreach ( ( $decoded['leaves'] ?? array() ) as $slug => $label ) {
            $topics[] = array( 'slug' => $slug, 'label' => $label );
        }
        $payload = array(
            'topics'    => $topics,
            'hierarchy' => $decoded['hierarchy'] ?? array(),
            'version'   => $decoded['version'] ?? null,
            'language'  => $decoded['language'] ?? null,
        );

        $etag = wp_remote_retrieve_header( $response, 'etag' );
        return array(
            'payload'      => $payload,
            'etag'         => $etag ?: null,
            'not_modified' => false,
            'error'        => null,
        );
    }
}
