<?php
class Test_Settings extends WP_UnitTestCase {

    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
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
}
