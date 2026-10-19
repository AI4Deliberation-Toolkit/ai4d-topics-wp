<?php
/**
 * HTTP client for the topic toolkit, called directly or through the AI4D gateway.
 *
 * Wraps wp_remote_post / wp_remote_get for the two calls the plugin makes:
 *   extraction  — extract topics for an article
 *   topic list  — fetch the taxonomy listing
 *
 * The connection mode (AI4D_Topics_Settings::connection(), read on every call) picks the route:
 *   direct:   POST {toolkit}/api/topics/extract-conversation, GET {toolkit}/api/topics;
 *             toolkit key, when set, as X-API-Key.
 *   gateway:  POST {gateway}/api/v1/tools/topic-toolkit/extract with the body wrapped as
 *             {input, options}, GET {gateway}/api/v1/tools/topic-toolkit/taxonomy;
 *             platform key as X-Gateway-Key. The answer's 'output' is the toolkit's body,
 *             so both modes share the same parsing. Errors are 'gateway_…' codes.
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
    const TOOL_ID       = 'topic-toolkit'; // the toolkit's ID in the gateway's registry
    const TIMEOUT_EXTRACT  = 30; // spec D8: embedding extraction can run several seconds.
    // Above the gateway's own tool timeout, so the plugin sees the gateway's 504 first.
    const TIMEOUT_EXTRACT_GATEWAY = 35;
    const TIMEOUT_TAXONOMY = 2;  // spec D13: render-path budget; fall back to stale on slow backend.

    public function extract( int $post_id, string $article_text ): array {
        $conn = AI4D_Topics_Settings::connection();
        if ( $conn['error'] !== null ) {
            return self::extract_failure( $conn['error'] );
        }
        $gateway = $conn['mode'] === 'gateway';

        $input = array(
            'platform'   => 'wordpress-opengov',
            'article_id' => $post_id,
            'article'    => $article_text,
            'comments'   => array(),
            'language'   => 'el',
        );
        if ( $gateway ) {
            $url     = self::gateway_url( $conn['base_url'], 'extract' );
            $body    = array( 'input' => $input, 'options' => array( 'include_labels' => true ) );
            $timeout = self::TIMEOUT_EXTRACT_GATEWAY;
        } else {
            $url     = $conn['base_url'] . self::PATH_EXTRACT . '?include_labels=true';
            $body    = $input;
            $timeout = self::TIMEOUT_EXTRACT;
        }

        $response = wp_remote_post( $url, array(
            'method'  => 'POST',
            'timeout' => $timeout,
            'headers' => array_merge( array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ), self::auth_headers( $conn ) ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            return self::extract_failure( ( $gateway ? 'gateway_' : '' ) . $response->get_error_code() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return self::extract_failure( self::status_error_code( $gateway, $code, $response ) );
        }
        $decoded = self::decode_body( $gateway, $response );
        if ( $decoded === null ) {
            return self::extract_failure( ( $gateway ? 'gateway_' : '' ) . 'malformed_response' );
        }

        return array(
            'topics'           => self::parse_extract_body( $decoded ),
            'taxonomy_version' => $decoded['taxonomy_version'] ?? null,
            'error'            => null,
        );
    }

    public function get_taxonomy(): array {
        $conn = AI4D_Topics_Settings::connection();
        if ( $conn['error'] !== null ) {
            return self::taxonomy_failure( $conn['error'] );
        }
        $gateway = $conn['mode'] === 'gateway';

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
        $headers = array_merge( $headers, self::auth_headers( $conn ) );

        $url = $gateway
            ? self::gateway_url( $conn['base_url'], 'taxonomy' ) . '?language=el'
            : $conn['base_url'] . self::PATH_TAXONOMY . '?language=el';

        $response = wp_remote_get( $url, array(
            'method'  => 'GET',
            'timeout' => self::TIMEOUT_TAXONOMY,
            'headers' => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            return self::taxonomy_failure( ( $gateway ? 'gateway_' : '' ) . $response->get_error_code() );
        }
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 304 ) {
            return array( 'payload' => null, 'etag' => null, 'not_modified' => true, 'error' => null );
        }
        if ( $code < 200 || $code >= 300 ) {
            return self::taxonomy_failure( self::status_error_code( $gateway, $code, $response ) );
        }

        $decoded = self::decode_body( $gateway, $response );
        if ( $decoded === null ) {
            return self::taxonomy_failure( ( $gateway ? 'gateway_' : '' ) . 'malformed_response' );
        }

        $etag = wp_remote_retrieve_header( $response, 'etag' );
        return array(
            'payload'      => self::parse_taxonomy_body( $decoded ),
            'etag'         => $etag ?: null,
            'not_modified' => false,
            'error'        => null,
        );
    }

    /**
     * Error code for a non-2xx answer from the gateway, from its error shape
     * {"error": {"source", "code", "message", "tool_status"?, …}}:
     *   gateway_<CODE>                  e.g. gateway_UNAUTHORIZED, gateway_TOOL_TIMEOUT
     *   gateway_TOOL_ERROR_<tool_status> the toolkit refused or failed, with its status
     *   gateway_<http status>           anything else: not the gateway's error shape (e.g.
     *                                   the proxy's HTML page when the pod is down), an
     *                                   unexpected code, or TOOL_ERROR without a tool_status
     * Never echoes body text beyond a code matching ^[A-Z_]{1,40}.
     */
    public static function gateway_error_code( int $status, string $body ): string {
        $decoded = json_decode( $body, true );
        $error   = ( is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ) ? $decoded['error'] : array();
        $code    = is_string( $error['code'] ?? null ) ? $error['code'] : '';

        if ( ! preg_match( '/^[A-Z_]{1,40}\z/', $code ) ) {
            return 'gateway_' . $status;
        }
        if ( $code === 'TOOL_ERROR' ) {
            return is_int( $error['tool_status'] ?? null )
                ? 'gateway_TOOL_ERROR_' . $error['tool_status']
                : 'gateway_' . $status;
        }
        return 'gateway_' . $code;
    }

    private static function gateway_url( string $base_url, string $operation ): string {
        return $base_url . '/api/v1/tools/' . self::TOOL_ID . '/' . $operation;
    }

    private static function auth_headers( array $conn ): array {
        if ( $conn['mode'] === 'gateway' ) {
            return array( 'X-Gateway-Key' => $conn['key'] );
        }
        // Direct: the toolkit has no key yet; send one only when it's set.
        return $conn['key'] !== '' ? array( 'X-API-Key' => $conn['key'] ) : array();
    }

    private static function status_error_code( bool $gateway, int $code, $response ): string {
        return $gateway
            ? self::gateway_error_code( $code, (string) wp_remote_retrieve_body( $response ) )
            : 'backend_' . $code;
    }

    /**
     * The toolkit's JSON body as an array, or null if malformed. Via the gateway
     * the body is the envelope and the toolkit's body is its array 'output'.
     */
    private static function decode_body( bool $gateway, $response ): ?array {
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $gateway ) {
            $decoded = ( is_array( $decoded ) && is_array( $decoded['output'] ?? null ) ) ? $decoded['output'] : null;
        }
        return is_array( $decoded ) ? $decoded : null;
    }

    private static function extract_failure( string $error ): array {
        return array( 'topics' => array(), 'taxonomy_version' => null, 'error' => $error );
    }

    private static function taxonomy_failure( string $error ): array {
        return array( 'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => $error );
    }

    private static function parse_extract_body( array $decoded ): array {
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
        return array_merge( $parent_records, $topics );
    }

    private static function parse_taxonomy_body( array $decoded ): array {
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
        return array(
            'topics'    => $topics,
            'hierarchy' => $decoded['hierarchy'] ?? array(),
            'version'   => $decoded['version'] ?? null,
            'language'  => $decoded['language'] ?? null,
        );
    }
}
