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
        delete_option( 'ai4d_topics_taxonomy_stale' );
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
            'body'     => wp_json_encode( array( 'normalized' => array(), 'taxonomy_version' => '2026-05-21-1' ) ),
            'headers'  => array(),
        ) );

        $client = new AI4D_Topics_API_Client();
        $client->extract( 42, 'Article body text here.' );

        $this->assertEquals( 'https://api.example.com/api/topics/extract-conversation?include_labels=true', $this->captured_request['url'] );
        $this->assertEquals( 'POST', $this->captured_request['args']['method'] );
        $this->assertEquals( 'application/json', $this->captured_request['args']['headers']['Content-Type'] );
        $body = json_decode( $this->captured_request['args']['body'], true );
        $this->assertEquals( 'wordpress-opengov', $body['platform'] );
        $this->assertEquals( 42, $body['article_id'] );
        $this->assertEquals( 'Article body text here.', $body['article'] );
        $this->assertEquals( array(), $body['comments'] );
        $this->assertEquals( 'el', $body['language'] );
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
        // Backend response: leaves only (per taxonomy_data.py header — the extractor never
        // returns parent slugs as topic_id). Parents are synthesized client-side from the
        // 'parent' field on each leaf so the renderer's hierarchy view works.
        $backend_response = array(
            'normalized' => array(
                array( 'topic_id' => 'waste-management', 'score' => 0.87, 'raw_score' => 0.45, 'parent' => 'environment' ),
                array( 'topic_id' => 'air-quality',      'score' => 0.81, 'raw_score' => 0.40, 'parent' => 'environment' ),
                array( 'topic_id' => 'judicial_reform',  'score' => 0.65, 'raw_score' => 0.35, 'parent' => 'justice' ),
            ),
            'taxonomy_version' => '2026-05-21-1',
        );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $backend_response ),
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );

        // Synthesized parents prepended in first-encounter order (deduped), then leaves.
        // Backend response had no `label` field, so all labels are null.
        $this->assertEquals(
            array(
                array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent', 'parent' => null, 'label' => null ),
                array( 'slug' => 'justice',          'score' => 0.65, 'kind' => 'parent', 'parent' => null, 'label' => null ),
                array( 'slug' => 'waste-management', 'score' => 0.87, 'kind' => 'leaf',   'parent' => 'environment', 'label' => null ),
                array( 'slug' => 'air-quality',      'score' => 0.81, 'kind' => 'leaf',   'parent' => 'environment', 'label' => null ),
                array( 'slug' => 'judicial_reform',  'score' => 0.65, 'kind' => 'leaf',   'parent' => 'justice',     'label' => null ),
            ),
            $result['topics']
        );
        $this->assertEquals( '2026-05-21-1', $result['taxonomy_version'] );
        $this->assertNull( $result['error'] );
    }

    public function test_extract_stores_localized_label_from_backend() {
        $backend_response = array(
            'normalized' => array(
                array( 'topic_id' => 'waste-management', 'score' => 0.87, 'parent' => 'environment', 'label' => 'Διαχείριση αποβλήτων' ),
                array( 'topic_id' => 'air-quality',      'score' => 0.81, 'parent' => 'environment', 'label' => 'Ποιότητα αέρα' ),
            ),
            'taxonomy_version' => '2026-05-21-1',
        );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $backend_response ),
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );

        // Leaves keep the backend's localized label. Synthesized parents have null
        // label (backend doesn't denormalize parent labels into the extract response).
        $leaves = array_values( array_filter( $result['topics'], function ( $t ) {
            return $t['kind'] === 'leaf';
        } ) );
        $this->assertEquals( 'Διαχείριση αποβλήτων', $leaves[0]['label'] );
        $this->assertEquals( 'Ποιότητα αέρα', $leaves[1]['label'] );

        $parents = array_values( array_filter( $result['topics'], function ( $t ) {
            return $t['kind'] === 'parent';
        } ) );
        $this->assertNull( $parents[0]['label'] );
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

    /* ---------- Connection modes ---------- */

    private function use_gateway_mode() {
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-test', 'backend_url' => 'https://api.example.com' ) );
    }

    private function envelope( array $output ) {
        return wp_json_encode( array( 'output' => $output, 'metadata' => array( 'request_id' => 'r-1', 'tool_id' => 'topic-toolkit' ) ) );
    }

    public function test_gateway_extract_sends_envelope_to_gateway() {
        $this->use_gateway_mode();
        $this->stub_http( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => $this->envelope( array( 'normalized' => array() ) ) ) );
        ( new AI4D_Topics_API_Client() )->extract( 42, 'Article body text here.' );
        $this->assertSame( 'https://gw.example.com/api/v1/tools/topic-toolkit/extract', $this->captured_request['url'] );
        $args = $this->captured_request['args'];
        $this->assertSame( 'POST', $args['method'] );
        $this->assertSame( 35, $args['timeout'] );
        $this->assertSame( 'gk-test', $args['headers']['X-Gateway-Key'] );
        $this->assertSame( 'application/json', $args['headers']['Content-Type'] );
        $this->assertArrayNotHasKey( 'X-API-Key', $args['headers'] );
        $this->assertSame( array(
            'input'   => array( 'platform' => 'wordpress-opengov', 'article_id' => 42, 'article' => 'Article body text here.', 'comments' => array(), 'language' => 'el' ),
            'options' => array( 'include_labels' => true ),
        ), json_decode( $args['body'], true ) );
    }

    public function test_gateway_extract_reads_output() {
        $this->use_gateway_mode();
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'headers'  => array(),
            'body'     => $this->envelope( array(
                'normalized' => array(
                    array( 'topic_id' => 'waste-management', 'score' => 0.87, 'raw_score' => 0.45, 'parent' => 'environment' ),
                    array( 'topic_id' => 'air-quality',      'score' => 0.81, 'raw_score' => 0.40, 'parent' => 'environment' ),
                    array( 'topic_id' => 'judicial_reform',  'score' => 0.65, 'raw_score' => 0.35, 'parent' => 'justice' ),
                ),
                'taxonomy_version' => '2026-05-21-1',
            ) ),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->extract( 1, 'x' );

        $this->assertEquals(
            array(
                array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent', 'parent' => null, 'label' => null ),
                array( 'slug' => 'justice',          'score' => 0.65, 'kind' => 'parent', 'parent' => null, 'label' => null ),
                array( 'slug' => 'waste-management', 'score' => 0.87, 'kind' => 'leaf',   'parent' => 'environment', 'label' => null ),
                array( 'slug' => 'air-quality',      'score' => 0.81, 'kind' => 'leaf',   'parent' => 'environment', 'label' => null ),
                array( 'slug' => 'judicial_reform',  'score' => 0.65, 'kind' => 'leaf',   'parent' => 'justice',     'label' => null ),
            ),
            $result['topics']
        );
        $this->assertEquals( '2026-05-21-1', $result['taxonomy_version'] );
        $this->assertNull( $result['error'] );
    }

    /** @dataProvider gateway_error_cases */
    public function test_gateway_extract_error_codes( $response, string $expected ) {
        $this->use_gateway_mode();
        $this->stub_http( $response );
        $this->assertSame( $expected, ( new AI4D_Topics_API_Client() )->extract( 1, 'x' )['error'] );
    }

    public function gateway_error_cases(): array {
        $err = function ( int $status, array $error ) {
            return array( 'response' => array( 'code' => $status ), 'headers' => array(), 'body' => wp_json_encode( array( 'error' => $error ) ) );
        };
        return array(
            'timeout'        => array( $err( 504, array( 'source' => 'gateway', 'code' => 'TOOL_TIMEOUT', 'message' => 'm' ) ), 'gateway_TOOL_TIMEOUT' ),
            'unauthorized'   => array( $err( 401, array( 'source' => 'gateway', 'code' => 'UNAUTHORIZED', 'message' => 'm' ) ), 'gateway_UNAUTHORIZED' ),
            'tool_400'       => array( $err( 400, array( 'source' => 'tool', 'code' => 'TOOL_ERROR', 'tool_status' => 400, 'tool_body' => array() ) ), 'gateway_TOOL_ERROR_400' ),
            'tool_500'       => array( $err( 502, array( 'source' => 'tool', 'code' => 'TOOL_ERROR', 'tool_status' => 500, 'tool_body' => null ) ), 'gateway_TOOL_ERROR_500' ),
            'tool_no_status' => array( $err( 502, array( 'source' => 'tool', 'code' => 'TOOL_ERROR' ) ), 'gateway_502' ),
            'html_502'       => array( array( 'response' => array( 'code' => 502 ), 'headers' => array(), 'body' => '<html>Bad gateway</html>' ), 'gateway_502' ),
            'unsafe_code'    => array( $err( 500, array( 'code' => '<script>x</script>' ) ), 'gateway_500' ),
            'transport'      => array( new WP_Error( 'http_request_failed', 'cURL error 28' ), 'gateway_http_request_failed' ),
            'no_output'      => array( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'metadata' => array() ) ) ), 'gateway_malformed_response' ),
        );
    }

    public function test_direct_mode_sends_toolkit_key_when_set() {
        update_option( 'ai4d_topics_settings', array( 'backend_url' => 'https://api.example.com', 'toolkit_key' => 'tk-1' ) );
        $this->stub_http( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'normalized' => array() ) ) ) );
        $client = new AI4D_Topics_API_Client();
        $client->extract( 1, 'x' );
        $this->assertSame( 'tk-1', $this->captured_request['args']['headers']['X-API-Key'] );
        $client->get_taxonomy();
        $this->assertSame( 'tk-1', $this->captured_request['args']['headers']['X-API-Key'] );
        $this->assertArrayNotHasKey( 'X-Gateway-Key', $this->captured_request['args']['headers'] );
    }

    public function test_direct_mode_sends_no_key_header_when_unset() {
        $this->stub_http( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'normalized' => array() ) ) ) );
        $client = new AI4D_Topics_API_Client();
        $client->extract( 1, 'x' );
        $this->assertArrayNotHasKey( 'X-API-Key', $this->captured_request['args']['headers'] );
        $client->get_taxonomy();
        $this->assertArrayNotHasKey( 'X-API-Key', $this->captured_request['args']['headers'] );
    }

    public function test_gateway_taxonomy_request() {
        $this->use_gateway_mode();
        update_option( 'ai4d_topics_taxonomy_stale', array( 'payload' => array( 'topics' => array() ), 'etag' => '"v-el"' ) );
        $this->stub_http( array( 'response' => array( 'code' => 304 ), 'headers' => array(), 'body' => '' ) );
        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertSame( 'https://gw.example.com/api/v1/tools/topic-toolkit/taxonomy?language=el', $this->captured_request['url'] );
        $h = $this->captured_request['args']['headers'];
        $this->assertSame( 'gk-test', $h['X-Gateway-Key'] );
        $this->assertSame( '"v-el"', $h['If-None-Match'] );
        $this->assertSame( 'identity', $h['Accept-Encoding'] );
        $this->assertSame( 2, $this->captured_request['args']['timeout'] );
        $this->assertTrue( $result['not_modified'] );
    }

    public function test_gateway_taxonomy_reads_output_and_etag() {
        $this->use_gateway_mode();
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'headers'  => array( 'etag' => '"2026-05-21-1-el"' ),
            'body'     => $this->envelope( array(
                'version'   => '2026-05-21-1',
                'language'  => 'el',
                'parents'   => array( 'environment' => 'Environment' ),
                'leaves'    => array( 'waste-management' => 'Waste management' ),
                'hierarchy' => array( 'waste-management' => 'environment' ),
            ) ),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();

        $this->assertEquals(
            array(
                'topics'    => array(
                    array( 'slug' => 'environment',      'label' => 'Environment' ),
                    array( 'slug' => 'waste-management', 'label' => 'Waste management' ),
                ),
                'hierarchy' => array( 'waste-management' => 'environment' ),
                'version'   => '2026-05-21-1',
                'language'  => 'el',
            ),
            $result['payload']
        );
        $this->assertSame( '"2026-05-21-1-el"', $result['etag'] );
        $this->assertFalse( $result['not_modified'] );
        $this->assertNull( $result['error'] );
    }

    public function test_client_reads_mode_on_every_call() {
        $this->stub_http( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'normalized' => array() ) ) ) );
        $client = new AI4D_Topics_API_Client();
        $client->extract( 1, 'x' );
        $this->assertStringStartsWith( 'https://api.example.com/', $this->captured_request['url'] );
        $this->use_gateway_mode();
        $client->extract( 1, 'x' );
        $this->assertStringStartsWith( 'https://gw.example.com/', $this->captured_request['url'] );
    }

    public function test_gateway_not_configured_makes_no_request() {
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'backend_url' => 'https://api.example.com', 'gateway_url' => 'https://gw.example.com' ) );
        $this->stub_http( array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '{}' ) );
        $client = new AI4D_Topics_API_Client();
        $this->assertSame( 'gateway_not_configured', $client->extract( 1, 'x' )['error'] );
        $this->assertSame( 'gateway_not_configured', $client->get_taxonomy()['error'] );
        $this->assertNull( $this->captured_request );
    }

    /* ---------- Taxonomy listing ---------- */

    public function test_get_taxonomy_sends_get_with_no_etag_initially() {
        delete_transient( 'ai4d_topics_taxonomy' );
        delete_option( 'ai4d_topics_taxonomy_stale' );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array() ) ),
            'headers'  => array( 'etag' => 'W/"abc123"' ),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertEquals( 'GET', $this->captured_request['args']['method'] );
        $this->assertArrayNotHasKey( 'If-None-Match', $this->captured_request['args']['headers'] );
    }

    public function test_get_taxonomy_sends_if_none_match_from_stale_option_after_cache_expiry() {
        // The renderer calls the client only once the transient has expired, so
        // the ETag must come from the stale option, not the (empty) transient.
        update_option( 'ai4d_topics_taxonomy_stale', array( 'payload' => array( 'topics' => array() ), 'etag' => '"2026-05-21-1-el"' ) );

        $this->stub_http( array(
            'response' => array( 'code' => 304 ),
            'body'     => '',
            'headers'  => array(),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertSame( '"2026-05-21-1-el"', $this->captured_request['args']['headers']['If-None-Match'] );
    }

    public function test_get_taxonomy_asks_for_uncompressed_answer() {
        // The Runpod proxy weakens the ETag (W/) when it compresses the answer.
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( array( 'topics' => array() ) ),
            'headers'  => array(),
        ) );

        ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertSame( 'identity', $this->captured_request['args']['headers']['Accept-Encoding'] );
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
        update_option( 'ai4d_topics_taxonomy_stale', array( 'payload' => array( 'topics' => array() ), 'etag' => '"2026-05-21-1-el"' ) );

        $this->stub_http( array(
            'response' => array( 'code' => 304 ),
            'body'     => '',
            'headers'  => array(),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();
        $this->assertTrue( $result['not_modified'] );
        $this->assertNull( $result['payload'] );
        $this->assertNull( $result['error'] );
    }

    public function test_get_taxonomy_returns_payload_and_etag_on_success() {
        delete_transient( 'ai4d_topics_taxonomy' );
        // Backend's actual response shape (parents + leaves dicts, plus hierarchy).
        $backend_response = array(
            'version'   => '2026-05-21-1',
            'language'  => 'el',
            'parents'   => array( 'environment' => 'Environment' ),
            'leaves'    => array( 'waste-management' => 'Waste management' ),
            'hierarchy' => array( 'waste-management' => 'environment' ),
        );
        $this->stub_http( array(
            'response' => array( 'code' => 200 ),
            'body'     => wp_json_encode( $backend_response ),
            'headers'  => array( 'etag' => 'W/"abc123"' ),
        ) );

        $result = ( new AI4D_Topics_API_Client() )->get_taxonomy();

        // Plugin's internal shape — parents + leaves flattened into a single topics array
        // so the renderer's resolve_label() finds both.
        $this->assertEquals(
            array(
                'topics'    => array(
                    array( 'slug' => 'environment',      'label' => 'Environment' ),
                    array( 'slug' => 'waste-management', 'label' => 'Waste management' ),
                ),
                'hierarchy' => array( 'waste-management' => 'environment' ),
                'version'   => '2026-05-21-1',
                'language'  => 'el',
            ),
            $result['payload']
        );
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
