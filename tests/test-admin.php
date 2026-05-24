<?php
class Test_Admin extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        $admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );
        update_option( 'ai4d_topics_settings', AI4D_Topics_Settings::get_all() );
    }

    public function tearDown(): void {
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
            'backend_url',
            'post_type',
            'include_tag',
            'exclude_tags',
            'dry_run',
            'display_shape',
            'display_top_n',
            'display_position',
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
}
