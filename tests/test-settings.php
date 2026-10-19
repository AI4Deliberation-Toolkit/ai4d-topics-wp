<?php
class Test_Settings extends WP_UnitTestCase {

    const STORED = array( 'toolkit_key' => 'tk-1', 'gateway_key' => 'gk-1' );

    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
        $GLOBALS['wp_settings_errors'] = array();
        parent::tearDown();
    }

    public function test_defaults_when_no_option_saved() {
        $settings = AI4D_Topics_Settings::get_all();
        $this->assertEquals( '', $settings['backend_url'] );
        $this->assertEquals( 'post', $settings['post_type'] );
        $this->assertEquals( '', $settings['include_tag'] );
        $this->assertEquals( array( 'deliberation' ), $settings['exclude_tags'] );
        $this->assertEquals( array( 'publish' ), $settings['allowed_statuses'] );
        $this->assertEquals( array(), $settings['allowed_authors'] );
        $this->assertFalse( $settings['dry_run'] );
        $this->assertEquals( 'compact', $settings['display_shape'] );
        $this->assertEquals( 5, $settings['display_top_n'] );
        $this->assertEquals( 2.0, $settings['display_min_score'] );
        $this->assertArrayNotHasKey( 'display_position', $settings );
        $this->assertTrue( $settings['display_show_header'] );
        $this->assertEquals( 'Θεματικές ενότητες', $settings['display_header_text'] );
        $this->assertEquals( 'AI-generated', $settings['display_ai_disclosure_text'] );
        $this->assertEquals( 'keep_meta', $settings['uninstall_behavior'] );
    }

    public function test_get_single_returns_default_when_unset() {
        $this->assertEquals( 'post', AI4D_Topics_Settings::get( 'post_type' ) );
    }

    public function test_saved_values_override_defaults() {
        update_option( 'ai4d_topics_settings', array( 'post_type' => 'article' ) );
        $this->assertEquals( 'article', AI4D_Topics_Settings::get( 'post_type' ) );
        $this->assertEquals( 'post', (new AI4D_Topics_Settings())->defaults()['post_type'] );
    }

    public function test_sanitize_trims_and_lowercases_include_tag() {
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'include_tag' => '  Article  ' ) );
        $this->assertEquals( 'article', $sanitized['include_tag'] );
    }

    public function test_sanitize_normalizes_exclude_tags_string_to_array() {
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'exclude_tags' => 'deliberation, DRAFT , deliberation' ) );
        $this->assertEquals( array( 'deliberation', 'draft' ), $sanitized['exclude_tags'] );
    }

    public function test_sanitize_rejects_invalid_backend_url() {
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'backend_url' => 'ftp://example.com' ) );
        $this->assertEquals( '', $sanitized['backend_url'] );
    }

    public function test_sanitize_accepts_http_and_https_backend_url() {
        $http = AI4D_Topics_Settings::sanitize( array( 'backend_url' => 'http://localhost:8000' ) );
        $this->assertEquals( 'http://localhost:8000', $http['backend_url'] );
        $https = AI4D_Topics_Settings::sanitize( array( 'backend_url' => 'https://api.example.com' ) );
        $this->assertEquals( 'https://api.example.com', $https['backend_url'] );
    }

    public function test_sanitize_strips_trailing_slash_from_backend_url() {
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'backend_url' => 'https://api.example.com/' ) );
        $this->assertEquals( 'https://api.example.com', $sanitized['backend_url'] );
    }

    public function test_sanitize_clamps_display_top_n() {
        $low  = AI4D_Topics_Settings::sanitize( array( 'display_top_n' => 0 ) );
        $high = AI4D_Topics_Settings::sanitize( array( 'display_top_n' => 999 ) );
        $this->assertEquals( 1, $low['display_top_n'] );
        $this->assertEquals( 20, $high['display_top_n'] );
    }

    public function test_sanitize_clamps_display_min_score() {
        $negative = AI4D_Topics_Settings::sanitize( array( 'display_min_score' => -1.5 ) );
        $huge     = AI4D_Topics_Settings::sanitize( array( 'display_min_score' => 999 ) );
        $float    = AI4D_Topics_Settings::sanitize( array( 'display_min_score' => '2.5' ) );
        $this->assertEquals( 0.0, $negative['display_min_score'] );
        $this->assertEquals( 10.0, $huge['display_min_score'] );
        $this->assertEquals( 2.5, $float['display_min_score'] );
    }

    public function test_sanitize_rejects_unknown_display_shape() {
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'display_shape' => 'pie-chart' ) );
        $this->assertEquals( 'compact', $sanitized['display_shape'] );
    }

    public function test_sanitize_drops_legacy_display_position_key() {
        // display_position was removed in 0.1.10 (topics always render before
        // the article body). The sanitize whitelist must drop it.
        $sanitized = AI4D_Topics_Settings::sanitize( array( 'display_position' => 'before' ) );
        $this->assertArrayNotHasKey( 'display_position', $sanitized );
    }

    public function test_defaults_include_connection_settings() {
        $s = AI4D_Topics_Settings::get_all();
        $this->assertSame( 'direct', $s['connection_mode'] );
        $this->assertSame( '', $s['toolkit_key'] );
        $this->assertSame( '', $s['gateway_url'] );
        $this->assertSame( '', $s['gateway_key'] );
    }

    public function test_sanitize_rejects_unknown_connection_mode() {
        $this->assertSame( 'direct', AI4D_Topics_Settings::sanitize( array( 'connection_mode' => 'proxy' ) )['connection_mode'] );
        $this->assertSame( 'gateway', AI4D_Topics_Settings::sanitize( array( 'connection_mode' => 'gateway' ) )['connection_mode'] );
    }

    public function test_sanitize_normalizes_gateway_url() {
        $this->assertSame( 'https://gw.example.com', AI4D_Topics_Settings::sanitize( array( 'gateway_url' => ' https://gw.example.com/ ' ) )['gateway_url'] );
        $this->assertSame( '', AI4D_Topics_Settings::sanitize( array( 'gateway_url' => 'ftp://gw.example.com' ) )['gateway_url'] );
    }

    public function test_sanitize_keeps_stored_keys_when_posted_empty() {
        update_option( 'ai4d_topics_settings', self::STORED );
        $out = AI4D_Topics_Settings::sanitize( array( 'toolkit_key' => '', 'gateway_key' => '' ) );
        $this->assertSame( 'tk-1', $out['toolkit_key'] );
        $this->assertSame( 'gk-1', $out['gateway_key'] );
    }

    public function test_sanitize_keeps_stored_keys_on_unrelated_save() {
        update_option( 'ai4d_topics_settings', self::STORED );
        $out = AI4D_Topics_Settings::sanitize( array( 'display_top_n' => 7 ) );
        $this->assertSame( 7, $out['display_top_n'] );
        $this->assertSame( 'tk-1', $out['toolkit_key'] );
        $this->assertSame( 'gk-1', $out['gateway_key'] );
    }

    public function test_sanitize_trims_pasted_key() {
        $this->assertSame( 'gk-2', AI4D_Topics_Settings::sanitize( array( 'gateway_key' => "  gk-2\n" ) )['gateway_key'] );
    }

    public function test_sanitize_rejects_key_with_line_break_and_keeps_stored() {
        update_option( 'ai4d_topics_settings', self::STORED );
        $out = AI4D_Topics_Settings::sanitize( array( 'gateway_key' => "gk-evil\r\nX-Injected: 1" ) );
        $this->assertSame( 'gk-1', $out['gateway_key'] );
        $this->assertNotEmpty( get_settings_errors( 'ai4d_topics_settings' ) );
        // An invalid typed key ignores the clear box too (decision 5).
        $out = AI4D_Topics_Settings::sanitize( array( 'gateway_key' => "gk evil", 'gateway_key_clear' => '1' ) );
        $this->assertSame( 'gk-1', $out['gateway_key'] );
    }

    public function test_sanitize_clear_box_removes_only_that_key() {
        update_option( 'ai4d_topics_settings', self::STORED );
        $out = AI4D_Topics_Settings::sanitize( array( 'gateway_key' => '', 'gateway_key_clear' => '1' ) );
        $this->assertSame( '', $out['gateway_key'] );
        $this->assertSame( 'tk-1', $out['toolkit_key'] );
        $this->assertArrayNotHasKey( 'gateway_key_clear', $out );
    }

    public function test_sanitize_typed_key_wins_over_clear_box() {
        update_option( 'ai4d_topics_settings', self::STORED );
        $out = AI4D_Topics_Settings::sanitize( array( 'gateway_key' => 'gk-3', 'gateway_key_clear' => '1' ) );
        $this->assertSame( 'gk-3', $out['gateway_key'] );
    }

    public function test_sanitize_is_idempotent_on_first_save() {
        // WP sanitizes twice when the option doesn't exist yet (update_option → add_option).
        $once = AI4D_Topics_Settings::sanitize( array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-1', 'toolkit_key_clear' => '1' ) );
        $this->assertSame( $once, AI4D_Topics_Settings::sanitize( $once ) );
    }

    public function test_save_through_wordpress_keeps_keys_on_later_save() {
        register_setting( 'ai4d_topics_settings_group', 'ai4d_topics_settings', array( 'sanitize_callback' => array( 'AI4D_Topics_Settings', 'sanitize' ) ) );
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-1' ) );
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => '', 'display_top_n' => 9 ) );
        $this->assertSame( 'gk-1', get_option( 'ai4d_topics_settings' )['gateway_key'] );
        $this->assertSame( 9, get_option( 'ai4d_topics_settings' )['display_top_n'] );
    }

    public function test_connection_direct() {
        update_option( 'ai4d_topics_settings', array( 'backend_url' => 'https://tk.example.com', 'toolkit_key' => 'tk-1', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-1' ) );
        $this->assertSame( array( 'mode' => 'direct', 'base_url' => 'https://tk.example.com', 'key' => 'tk-1', 'error' => null ), AI4D_Topics_Settings::connection() );
    }

    public function test_connection_direct_without_url() {
        $this->assertSame( 'backend_url_not_configured', AI4D_Topics_Settings::connection()['error'] );
    }

    public function test_connection_gateway() {
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'gateway_url' => 'https://gw.example.com', 'gateway_key' => 'gk-1' ) );
        $this->assertSame( array( 'mode' => 'gateway', 'base_url' => 'https://gw.example.com', 'key' => 'gk-1', 'error' => null ), AI4D_Topics_Settings::connection() );
    }

    public function test_connection_gateway_needs_url_and_key_and_never_falls_back() {
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'backend_url' => 'https://tk.example.com', 'gateway_url' => 'https://gw.example.com' ) );
        $c = AI4D_Topics_Settings::connection();
        $this->assertSame( 'gateway_not_configured', $c['error'] );
        $this->assertSame( 'https://gw.example.com', $c['base_url'] );
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'gateway', 'backend_url' => 'https://tk.example.com', 'gateway_key' => 'gk-1' ) );
        $this->assertSame( 'gateway_not_configured', AI4D_Topics_Settings::connection()['error'] );
    }

    public function test_connection_treats_unknown_stored_mode_as_direct() {
        update_option( 'ai4d_topics_settings', array( 'connection_mode' => 'bogus', 'backend_url' => 'https://tk.example.com' ) );
        $this->assertSame( 'direct', AI4D_Topics_Settings::connection()['mode'] );
    }
}
