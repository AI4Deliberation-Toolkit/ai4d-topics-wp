<?php
class Test_Admin extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );
        update_option( 'ai4d_topics_settings', AI4D_Topics_Settings::get_all() );
    }

    public function tearDown(): void {
        $_REQUEST = array();
        $_REQUEST = array();
        delete_option( 'ai4d_topics_settings' );
        wp_set_current_user( 0 );  // belt-and-braces; tests in this file switch users
        parent::tearDown();
    }

    public function test_settings_page_registered_under_settings_menu() {
        ( new AI4D_Topics_Admin() )->register_settings_page();
        global $submenu;
        $found = false;
        foreach ( $submenu['options-general.php'] ?? array() as $item ) {
            if ( $item[2] === 'ai4d-topics-settings' ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'Settings submenu not registered' );
    }

    public function test_settings_form_field_renders_current_value() {
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'backend_url' => 'https://example.com' )
        ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_settings_page();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'https://example.com', $output );
    }

    public function test_only_administrators_see_settings_link() {
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );
        $this->assertTrue( current_user_can( 'manage_options' ) );

        $editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor_id );
        $this->assertFalse( current_user_can( 'manage_options' ) );
    }

    public function test_render_settings_page_dies_for_non_admin() {
        $editor = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        $this->expectException( WPDieException::class );
        ( new AI4D_Topics_Admin() )->render_settings_page();
    }

    public function test_settings_form_renders_all_expected_fields() {
        ob_start();
        ( new AI4D_Topics_Admin() )->render_settings_page();
        $output = ob_get_clean();

        $expected_fields = array(
            'connection_mode',
            'backend_url',
            'toolkit_key',
            'toolkit_key_clear',
            'gateway_url',
            'gateway_key',
            'gateway_key_clear',
            'post_type',
            'include_tag',
            'exclude_tags',
            'dry_run',
            'display_shape',
            'display_top_n',
            'display_show_header',
            'display_header_text',
            'uninstall_behavior',
        );
        foreach ( $expected_fields as $field ) {
            $this->assertStringContainsString(
                "ai4d_topics_settings[{$field}]",
                $output,
                "Form field missing: {$field}"
            );
        }
    }

    public function test_register_setting_wires_sanitize_callback() {
        ( new AI4D_Topics_Admin() )->register_settings_page();

        global $wp_registered_settings;
        $this->assertArrayHasKey( 'ai4d_topics_settings', $wp_registered_settings );
        $this->assertEquals(
            array( 'AI4D_Topics_Settings', 'sanitize' ),
            $wp_registered_settings['ai4d_topics_settings']['sanitize_callback']
        );
    }

    public function test_metabox_visually_marks_below_threshold_topics() {
        // The metabox shows all topics (admin debugging) but greys out below-threshold
        // ones with a "below threshold" suffix so admins can tune display_min_score.
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'display_min_score' => 2.0 )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_slash( wp_json_encode( array(
            array( 'slug' => 'above', 'score' => 3.0, 'kind' => 'leaf', 'parent' => 'p', 'label' => 'Above-Label' ),
            array( 'slug' => 'below', 'score' => 1.0, 'kind' => 'leaf', 'parent' => 'p', 'label' => 'Below-Label' ),
        ) ) ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Above-Label', $output );
        $this->assertStringContainsString( 'Below-Label', $output );
        $this->assertStringContainsString( 'below threshold', $output );
    }

    public function test_metabox_renders_topics_when_meta_present() {
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array( array( 'slug' => 'env', 'label' => 'Περιβάλλον' ) ) ),
            'etag'    => 'x',
        ), HOUR_IN_SECONDS );

        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ),
        ) ) );
        update_post_meta( $post_id, '_ai4d_extracted_at', time() );
        update_post_meta( $post_id, '_ai4d_taxonomy_version', '2026-05-21-1' );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Περιβάλλον', $output );
        $this->assertStringContainsString( '2026-05-21-1', $output );
        $this->assertStringContainsString( 'Re-extract topics', $output );
    }

    public function test_metabox_shows_no_extraction_yet_message_when_meta_absent() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'No extraction yet', $output );
    }

    public function test_metabox_shows_error_when_extraction_failed() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'backend_500' );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'backend_500', $output );
    }

    public function test_reextract_handler_rejects_request_without_nonce() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $_REQUEST = array( 'post_id' => $post_id );
        $admin = new AI4D_Topics_Admin();
        $this->assertFalse( $admin->handle_reextract_action() );
    }

    public function test_reextract_handler_rejects_unauthorized_user() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber_id );

        $_REQUEST = array(
            'post_id' => $post_id,
            '_wpnonce' => wp_create_nonce( AI4D_Topics_Admin::NONCE_RE_EXTRACT ),
        );

        $this->assertFalse( ( new AI4D_Topics_Admin() )->handle_reextract_action() );
    }

    public function test_reextract_handler_runs_extractor_for_authorized_request() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        $_REQUEST = array(
            'post_id' => $post_id,
            '_wpnonce' => wp_create_nonce( AI4D_Topics_Admin::NONCE_RE_EXTRACT ),
        );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'extract' )->willReturn( array(
            'topics' => array( array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ) ),
            'taxonomy_version' => '2026-05-21-1',
            'error' => null,
        ) );

        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'backend_url' => 'https://api.example.com' )
        ) );

        $admin = new AI4D_Topics_Admin( new AI4D_Topics_Extractor( $client ) );
        $this->assertTrue( $admin->handle_reextract_action() );
        $this->assertNotEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
    }

    public function test_clear_handler_rejects_request_without_nonce() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $_REQUEST = array( 'post_id' => $post_id );
        $this->assertFalse( ( new AI4D_Topics_Admin() )->handle_clear_action() );
    }

    public function test_clear_handler_rejects_unauthorized_user() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );

        $_REQUEST = array(
            'post_id'  => $post_id,
            '_wpnonce' => wp_create_nonce( AI4D_Topics_Admin::NONCE_CLEAR ),
        );

        $this->assertFalse( ( new AI4D_Topics_Admin() )->handle_clear_action() );
    }

    public function test_clear_handler_deletes_all_topic_meta_for_authorized_request() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ),
        ) ) );
        update_post_meta( $post_id, '_ai4d_extracted_at', time() );
        update_post_meta( $post_id, '_ai4d_taxonomy_version', '2026-05-21-1' );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'backend_500' );

        $_REQUEST = array(
            'post_id'  => $post_id,
            '_wpnonce' => wp_create_nonce( AI4D_Topics_Admin::NONCE_CLEAR ),
        );

        $this->assertTrue( ( new AI4D_Topics_Admin() )->handle_clear_action() );

        foreach ( AI4D_Topics_Extractor::POST_META_KEYS as $key ) {
            $this->assertEmpty(
                get_post_meta( $post_id, $key, true ),
                "Expected $key to be cleared"
            );
        }
    }

    public function test_clear_handler_appends_cleared_log_event() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $_REQUEST = array(
            'post_id'  => $post_id,
            '_wpnonce' => wp_create_nonce( AI4D_Topics_Admin::NONCE_CLEAR ),
        );

        AI4D_Topics_Log::clear();
        ( new AI4D_Topics_Admin() )->handle_clear_action();

        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'cleared', $events[0]['type'] );
        $this->assertEquals( $post_id, $events[0]['post_id'] );
    }

    public function test_metabox_renders_clear_button_when_topics_present() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ),
        ) ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Clear topics', $output );
        $this->assertStringContainsString( 'ai4d_topics_clear', $output );
    }

    public function test_metabox_does_not_render_clear_button_when_no_topics() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'Clear topics', $output );
    }

    public function test_metabox_shows_skipped_label_for_gate_rejection() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'skipped: excluded_tag_matched' );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_metabox( get_post( $post_id ) );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Last extraction skipped', $output );
        $this->assertStringContainsString( 'excluded_tag_matched', $output );
        // The 'skipped: ' prefix should be stripped from the displayed reason.
        $this->assertStringNotContainsString( 'skipped: excluded_tag_matched', $output );
    }

    public function test_register_metabox_registers_for_configured_post_type() {
        global $wp_meta_boxes;
        $wp_meta_boxes = array();
        set_current_screen( 'post' );

        ( new AI4D_Topics_Admin() )->register_metabox();

        $this->assertArrayHasKey( 'ai4d-topics-metabox', $wp_meta_boxes['post']['side']['default'] ?? array() );
    }

    public function test_column_renders_success_when_topics_meta_present() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'a', 'score' => 0.9, 'kind' => 'leaf', 'parent' => 'x' ),
            array( 'slug' => 'b', 'score' => 0.8, 'kind' => 'leaf', 'parent' => 'x' ),
        ) ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_column( 'ai4d_topics', $post_id );
        $output = ob_get_clean();

        $this->assertStringContainsString( '#28a745', $output ); // green dot
        $this->assertStringContainsString( '2', $output );
    }

    public function test_column_renders_failure_when_error_meta_present() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'backend_500' );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_column( 'ai4d_topics', $post_id );
        $output = ob_get_clean();

        $this->assertStringContainsString( '#dc3545', $output ); // red dot
    }

    public function test_column_renders_blank_when_no_meta() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_column( 'ai4d_topics', $post_id );
        $output = ob_get_clean();

        $this->assertEquals( '—', trim( $output ) );
    }

    public function test_log_viewer_displays_events() {
        AI4D_Topics_Log::clear();
        AI4D_Topics_Log::append( 'success', 42, 'extracted 3 topics' );

        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_log_viewer();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'extracted 3 topics', $output );
        $this->assertStringContainsString( 'success', $output );
    }

    public function test_column_renders_blank_when_topics_array_is_empty() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array() ) );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_column( 'ai4d_topics', $post_id );
        $output = ob_get_clean();

        $this->assertEquals( '—', trim( $output ) );
    }

    public function test_render_log_viewer_dies_for_non_admin() {
        $editor = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        $this->expectException( WPDieException::class );
        ( new AI4D_Topics_Admin() )->render_log_viewer();
    }

    public function test_log_viewer_shows_empty_state_when_no_events() {
        AI4D_Topics_Log::clear();

        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_log_viewer();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'No events yet', $output );
    }

    public function test_log_viewer_escapes_message_content() {
        AI4D_Topics_Log::clear();
        AI4D_Topics_Log::append( 'failure', 1, '<script>alert(1)</script>' );

        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );

        ob_start();
        ( new AI4D_Topics_Admin() )->render_log_viewer();
        $output = ob_get_clean();

        $this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
        $this->assertStringContainsString( '&lt;script&gt;', $output );
    }

    private function render_settings( array $opts ): string {
        update_option( 'ai4d_topics_settings', $opts );
        ob_start();
        ( new AI4D_Topics_Admin() )->render_settings_page();
        return ob_get_clean();
    }

    public function test_mode_switch_marks_the_active_mode() {
        $out = $this->render_settings( array( 'connection_mode' => 'gateway' ) );
        $this->assertMatchesRegularExpression( '/name="ai4d_topics_settings\[connection_mode\]"[^>]*value="gateway"[^>]*checked/', $out );
        $this->assertDoesNotMatchRegularExpression( '/name="ai4d_topics_settings\[connection_mode\]"[^>]*value="direct"[^>]*checked/', $out );
    }

    public function test_settings_page_never_prints_stored_keys() {
        $out = $this->render_settings( array( 'toolkit_key' => 'tk-SECRET-1', 'gateway_key' => 'gk-SECRET-2' ) );
        $this->assertStringNotContainsString( 'tk-SECRET-1', $out );
        $this->assertStringNotContainsString( 'gk-SECRET-2', $out );
        $this->assertSame( 2, substr_count( $out, '<span class="ai4d-key-status">set — leave empty to keep</span>' ) );
    }

    public function test_key_fields_are_empty_password_fields() {
        $out = $this->render_settings( array( 'gateway_key' => 'gk-1' ) );
        foreach ( array( 'toolkit_key', 'gateway_key' ) as $f ) {
            $this->assertMatchesRegularExpression( '/<input[^>]*name="ai4d_topics_settings\[' . $f . '\]"[^>]*>/', $out );
            preg_match( '/<input[^>]*name="ai4d_topics_settings\[' . $f . '\]"[^>]*>/', $out, $m );
            $this->assertStringContainsString( 'type="password"', $m[0] );
            $this->assertStringContainsString( 'value=""', $m[0] );
            $this->assertStringContainsString( 'autocomplete="new-password"', $m[0] );
        }
    }

    public function test_empty_key_shows_not_set() {
        $this->assertSame( 2, substr_count( $this->render_settings( array() ), '<span class="ai4d-key-status">not set</span>' ) );
    }

    public function test_warns_when_gateway_mode_is_incomplete() {
        $out = $this->render_settings( array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com' ) );
        $this->assertStringContainsString( 'gateway_not_configured', $out );
    }

    public function test_no_warning_for_complete_gateway_mode() {
        $out = $this->render_settings( array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-1' ) );
        $this->assertStringNotContainsString( 'gateway_not_configured', $out );
    }
}
