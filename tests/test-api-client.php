<?php
/**
 * Tests for AI4D_Topics_API_Client.
 *
 * We mock wp_remote_post / wp_remote_get by overriding the 'pre_http_request'
 * filter, which is WP's official short-circuit for HTTP calls in tests.
 */
class Test_API_Client extends WP_UnitTestCase {

    private $captured_request;

    public function setUp(): void {
        parent::setUp();
        $this->captured_request = null;
        update_option( 'ai4d_topics_settings', array( 'backend_url' => 'https://api.example.com' ) );
    }

    public function tearDown(): void {
        remove_all_filters( 'pre_http_request' );
        delete_option( 'ai4d_topics_settings' );
        parent::tearDown();
    }

    /** Helper: register a canned response and capture the outgoing request. */
    private function stub_http( $response ) {
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $response ) {
            $this->captured_request = array( 'url' => $url, 'args' => $args );
            return $response;
        }, 10, 3 );
    }

    public function test_extract_sends_expected_request_body() {
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array(), 'taxonomy_version' => '2026-05-21-1' ) ),
            'headers'  => array(),
        ) );

        $client = new AI4D_Topics_API_Client();
        $client->extract( 42, 'Article body text here.' );

        $this->assertEquals( 'https://api.example.com/api/topics/extract-conversation', $this->captured_request['url'] );
        $this->assertEquals( 'POST', $this->captured_request['args']['method'] );
        $this->assertEquals( 'application/json', $this->captured_request['args']['headers']['Content-Type'] );
        $body = json_decode( $this->captured_request['args']['body'], true );
        $this->assertEquals( 'wordpress-opengov', $body['platform'] );
        $this->assertEquals( 42, $body['article_id'] );
        $this->assertEquals( 'Article body text here.', $body['article'] );
        $this->assertEquals( array(), $body['comments'] );
        $this->assertEquals( 'el', $body['explicit_language'] );
    }

    public function test_extract_uses_30s_timeout() {
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array(), 'taxonomy_version' => 'v1' ) ),
            'headers'  => array(),
        ) );

        ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( 30, $this->captured_request['args']['timeout'] );
    }

    public function test_extract_returns_parsed_topics_on_success() {
        $payload = array(
            'topics' => array(
                array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent' ),
                array( 'slug' => 'waste-management', 'score' => 0.81, 'kind' => 'leaf', 'parent' => 'environment' ),
            ),
            'taxonomy_version' => '2026-05-21-1',
        );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $payload ),
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( $payload['topics'], $result['topics'] );
        $this->assertEquals( '2026-05-21-1', $result['taxonomy_version'] );
        $this->assertNull( $result['error'] );
    }

    public function test_extract_returns_error_on_non_2xx() {
        $this->stub_http( array(
            'response' => array( 'code' => 500 ),
            'body'     => 'Internal Server Error',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( 'backend_500', $result['error'] );
    }

    public function test_extract_returns_error_on_network_failure() {
        $this->stub_http( new WP_Error( 'http_request_failed', 'connection timed out' ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( 'http_request_failed', $result['error'] );
    }

    public function test_extract_returns_error_when_backend_url_not_configured() {
        delete_option( 'ai4d_topics_settings' );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( 'backend_url_not_configured', $result['error'] );
    }

    /* ---------- Taxonomy listing ---------- */

    public function test_get_taxonomy_sends_get_with_no_etag_initially() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array() ) ),
            'headers'  => array( 'etag' => 'W/"abc123"' ),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 'GET', $this->captured_request['args']['method'] );
        $this->assertArrayNotHasKey( 'If-None-Match', $this->captured_request['args']['headers'] );
    }

    public function test_get_taxonomy_sends_if_none_match_when_etag_cached() {
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array() ),
            'etag'    => 'W/"abc123"',
        ), HOUR_IN_SECONDS );

        $this->stub_http( array(
            'response' => array( 'code' => 304 ),
            'body'     => '',
            'headers'  => array(),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 'W/"abc123"', $this->captured_request['args']['headers']['If-None-Match'] );

        delete_transient( 'ai4d_topics_taxonomy' );
    }

    public function test_get_taxonomy_uses_2s_timeout() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array() ) ),
            'headers'  => array(),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 2, $this->captured_request['args']['timeout'] );
    }

    public function test_extract_returns_malformed_response_when_body_is_not_json_object() {
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => 'not json at all',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );
        $this->assertEquals( 'malformed_response', $result['error'] );
        $this->assertEquals( array(), $result['topics'] );
    }

    public function test_get_taxonomy_returns_malformed_response_when_body_is_not_json_object() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => 'null',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 'malformed_response', $result['error'] );
    }

    public function test_get_taxonomy_returns_not_modified_on_304() {
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array() ),
            'etag'    => 'W/"abc123"',
        ), HOUR_IN_SECONDS );

        $this->stub_http( array(
            'response' => array( 'code' => 304 ),
            'body'     => '',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertTrue( $result['not_modified'] );
        $this->assertNull( $result['payload'] );
        $this->assertNull( $result['error'] );

        delete_transient( 'ai4d_topics_taxonomy' );
    }

    public function test_get_taxonomy_returns_payload_and_etag_on_success() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $payload = array( 'topics' => array( array( 'slug' => 'environment' ) ), 'parents' => array() );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $payload ),
            'headers'  => array( 'etag' => 'W/"abc123"' ),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( $payload, $result['payload'] );
        $this->assertEquals( 'W/"abc123"', $result['etag'] );
        $this->assertFalse( $result['not_modified'] );
        $this->assertNull( $result['error'] );
    }

    public function test_get_taxonomy_returns_null_etag_when_header_missing() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array() ) ),
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertNull( $result['etag'] );
    }

    public function test_get_taxonomy_returns_error_on_non_2xx() {
        delete_transient( 'ai4d_topics_taxonomy' );
        $this->stub_http( array(
            'response' => array( 'code' => 503 ),
            'body'     => 'Service Unavailable',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 'backend_503', $result['error'] );
        $this->assertNull( $result['payload'] );
        $this->assertFalse( $result['not_modified'] );
    }
}
