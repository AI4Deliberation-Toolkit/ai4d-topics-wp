<?php
class Test_Extractor extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option( 'ai4d_topics_settings', array(
            'backend_url'      => 'https://api.example.com',
            'post_type'        => 'post',
            'include_tag'      => '',
            'exclude_tags'     => array( 'deliberation' ),
            'allowed_statuses' => array( 'publish' ),
            'allowed_authors'  => array(),
            'dry_run'          => false,
        ) );
    }

    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
        parent::tearDown();
    }

    public function test_matches_publish_post_without_excluded_tags() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        $this->assertNull( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_type_mismatch() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'post_type' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        $this->assertEquals( 'post_type_mismatch', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_status_not_allowed() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'draft', 'post_type' => 'post' ) );
        $this->assertEquals( 'post_status_not_allowed', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_has_excluded_tag() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        wp_set_post_tags( $post_id, array( 'deliberation' ) );
        $this->assertEquals( 'excluded_tag_matched', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_matches_any_of_multiple_exclude_tags() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'exclude_tags' => array( 'deliberation', 'draft-notice' ) )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        wp_set_post_tags( $post_id, array( 'draft-notice' ) );
        $this->assertEquals( 'excluded_tag_matched', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_include_tag_set_and_post_lacks_it() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'other' ) );
        $this->assertEquals( 'include_tag_missing', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_matches_when_include_tag_set_and_post_has_it() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'article' ) );
        $this->assertNull( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_exclude_tags_take_precedence_over_include_tag() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'article', 'deliberation' ) );
        $this->assertEquals( 'excluded_tag_matched', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_allowed_authors_set_and_post_author_not_in_list() {
        $author_a = $this->factory->user->create();
        $author_b = $this->factory->user->create();
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'allowed_authors' => array( $author_a ) )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author_b ) );
        $this->assertEquals( 'author_not_allowed', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_does_not_exist() {
        $this->assertEquals( 'post_not_found', ( new AI4D_Topics_Extractor() )->should_process( 999999 ) );
    }

    public function test_excludes_when_backend_url_not_configured() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'backend_url' => '' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $this->assertEquals( 'backend_url_not_configured', ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_run_writes_meta_on_success() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_content' => 'Some article body.' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(
                array( 'slug' => 'environment', 'score' => 0.87, 'kind' => 'parent' ),
            ),
            'taxonomy_version' => '2026-05-21-1',
            'error'            => null,
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $stored = json_decode( get_post_meta( $post_id, '_ai4d_topics', true ), true );
        $this->assertCount( 1, $stored );
        $this->assertEquals( 'environment', $stored[0]['slug'] );
        $this->assertEquals( '2026-05-21-1', get_post_meta( $post_id, '_ai4d_taxonomy_version', true ) );
        $this->assertIsNumeric( get_post_meta( $post_id, '_ai4d_extracted_at', true ) );
        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_extraction_error', true ) );
    }

    public function test_run_appends_success_log_event() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => 'v1', 'error' => null,
        ) );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'success', $events[0]['type'] );
        $this->assertEquals( $post_id, $events[0]['post_id'] );
    }

    public function test_run_writes_error_meta_on_api_failure() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => null, 'error' => 'backend_500',
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEquals( 'backend_500', get_post_meta( $post_id, '_ai4d_extraction_error', true ) );
        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
    }

    public function test_run_appends_failure_log_event_on_error() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => null, 'error' => 'http_request_failed',
        ) );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'failure', $events[0]['type'] );
    }

    public function test_run_clears_prior_error_on_success() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'backend_500' );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => 'v1', 'error' => null,
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_extraction_error', true ) );
    }

    public function test_run_strips_tags_and_shortcodes_before_sending() {
        add_shortcode( 'shortcode_thing', '__return_empty_string' );
        $post_id = $this->factory->post->create( array(
            'post_status'  => 'publish',
            'post_content' => '<p>Hello <strong>world</strong>.</p>[shortcode_thing]Some text.[/shortcode_thing]',
        ) );

        $captured = null;
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )
            ->method( 'extract' )
            ->with( $post_id, $this->callback( function ( $body ) use ( &$captured ) {
                $captured = $body;
                return true;
            } ) )
            ->willReturn( array( 'topics' => array(), 'taxonomy_version' => 'v1', 'error' => null ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertStringNotContainsString( '<p>', $captured );
        $this->assertStringNotContainsString( '[shortcode_thing]', $captured );
        $this->assertStringContainsString( 'Hello world', $captured );
    }

    public function test_run_skips_when_not_matching() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'post_type' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
    }

    public function test_dry_run_does_not_call_api_or_write_meta() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'dry_run' => true )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'dry_run', $events[0]['type'] );
    }

    public function test_run_does_not_write_taxonomy_version_when_null() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => null, 'error' => null,
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_taxonomy_version', true ) );
    }

    public function test_run_preserves_existing_topics_on_api_failure() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $existing = wp_json_encode( array(
            array( 'slug' => 'previous-topic', 'score' => 0.9, 'kind' => 'parent' ),
        ) );
        update_post_meta( $post_id, '_ai4d_topics', $existing );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(), 'taxonomy_version' => null, 'error' => 'backend_500',
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEquals( $existing, get_post_meta( $post_id, '_ai4d_topics', true ) );
        $this->assertEquals( 'backend_500', get_post_meta( $post_id, '_ai4d_extraction_error', true ) );
    }

    public function test_on_transition_does_nothing_for_non_publish_transition() {
        $post = $this->factory->post->create_and_get( array( 'post_status' => 'draft' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        ( new AI4D_Topics_Extractor( $client ) )->on_transition_post_status( 'draft', 'auto-draft', $post );
    }

    public function test_on_transition_does_nothing_for_non_wp_post() {
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        // Pass a non-WP_Post value — WP itself can pass null in some edge transitions.
        ( new AI4D_Topics_Extractor( $client ) )->on_transition_post_status( 'publish', 'draft', null );
    }

    public function test_run_preserves_non_ascii_labels_through_meta_storage() {
        // Regression: WP's update_post_meta() calls wp_unslash() on input, which
        // strips JSON escape backslashes. Without wp_slash() on the way in, a label
        // like "Διαφάνεια" (which wp_json_encode escapes to "Δι...") gets
        // stored as the literal text "u0394u03b9..." and renders as garbage. This
        // test asserts the full round-trip preserves the actual characters.
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'extract' )->willReturn( array(
            'topics' => array(
                array(
                    'slug'   => 'transparency-in-governance',
                    'score'  => 0.87,
                    'kind'   => 'leaf',
                    'parent' => 'governance',
                    'label'  => 'Διαφάνεια στη Διακυβέρνηση',
                ),
            ),
            'taxonomy_version' => 'v1',
            'error'            => null,
        ) );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $stored = json_decode( get_post_meta( $post_id, '_ai4d_topics', true ), true );
        $this->assertCount( 1, $stored );
        $this->assertEquals( 'Διαφάνεια στη Διακυβέρνηση', $stored[0]['label'] );
    }

    public function test_run_writes_skipped_meta_when_gate_rejects() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'backend_url' => '' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEquals(
            'skipped: backend_url_not_configured',
            get_post_meta( $post_id, '_ai4d_extraction_error', true )
        );
    }

    public function test_run_appends_skipped_log_event_with_reason() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'post_type' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'skipped', $events[0]['type'] );
        $this->assertEquals( 'post_type_mismatch', $events[0]['message'] );
        $this->assertEquals( $post_id, $events[0]['post_id'] );
    }

    public function test_run_preserves_existing_topics_on_skip() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $existing = wp_json_encode( array(
            array( 'slug' => 'previous-topic', 'score' => 0.9, 'kind' => 'parent' ),
        ) );
        update_post_meta( $post_id, '_ai4d_topics', $existing );

        // Reconfigure so this same post no longer matches (e.g. tag added later).
        wp_set_post_tags( $post_id, array( 'deliberation' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        ( new AI4D_Topics_Extractor( $client ) )->run( $post_id );

        $this->assertEquals( $existing, get_post_meta( $post_id, '_ai4d_topics', true ) );
        $this->assertEquals(
            'skipped: excluded_tag_matched',
            get_post_meta( $post_id, '_ai4d_extraction_error', true )
        );
    }

    public function test_on_transition_does_not_log_for_post_type_mismatch() {
        // The publish hook fires for every post type. Without the fast-path filter,
        // every page/CPT publish would burn a log slot with "post_type_mismatch".
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'post_type' => 'article' )
        ) );
        $post = $this->factory->post->create_and_get( array( 'post_status' => 'publish', 'post_type' => 'post' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->on_transition_post_status( 'publish', 'draft', $post );

        $this->assertEmpty( AI4D_Topics_Log::get_all() );
        $this->assertEmpty( get_post_meta( $post->ID, '_ai4d_extraction_error', true ) );
    }

    public function test_on_transition_does_log_for_other_skip_reasons() {
        // post_type matches but an excluded tag rejects — admin wants to see this.
        $post = $this->factory->post->create_and_get( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        wp_set_post_tags( $post->ID, array( 'deliberation' ) );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'extract' );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Extractor( $client ) )->on_transition_post_status( 'publish', 'draft', $post );

        $events = AI4D_Topics_Log::get_all();
        $this->assertCount( 1, $events );
        $this->assertEquals( 'skipped', $events[0]['type'] );
        $this->assertEquals( 'excluded_tag_matched', $events[0]['message'] );
    }

    public function test_on_transition_calls_run_for_publish_transition() {
        $post = $this->factory->post->create_and_get( array( 'post_status' => 'publish', 'post_content' => 'body' ) );
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )
            ->method( 'extract' )
            ->with( $post->ID, $this->anything() )
            ->willReturn( array( 'topics' => array(), 'taxonomy_version' => 'v1', 'error' => null ) );

        ( new AI4D_Topics_Extractor( $client ) )->on_transition_post_status( 'publish', 'draft', $post );

        // Confirm meta was written (run() did execute the success path).
        $this->assertNotEmpty( get_post_meta( $post->ID, '_ai4d_topics', true ) );
    }
}
