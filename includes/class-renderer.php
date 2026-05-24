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
}
