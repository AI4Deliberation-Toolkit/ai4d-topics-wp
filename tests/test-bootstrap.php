<?php
class Test_Bootstrap extends WP_UnitTestCase {
    public function test_plugin_constants_defined() {
        $this->assertTrue( defined( 'AI4D_TOPICS_VERSION' ) );
        $plugin_data = get_file_data( AI4D_TOPICS_PLUGIN_FILE, array( 'Version' => 'Version' ) );
        $this->assertEquals( $plugin_data['Version'], AI4D_TOPICS_VERSION, 'AI4D_TOPICS_VERSION must match the Version: header in the plugin file.' );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_FILE' ) );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_DIR' ) );
    }
}

class Test_Migrations extends WP_UnitTestCase {
    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
        delete_option( 'ai4d_topics_db_version' );
        parent::tearDown();
    }

    public function test_migration_bumps_legacy_hierarchy_default_to_compact() {
        update_option( 'ai4d_topics_settings', array( 'display_shape' => 'hierarchy', 'backend_url' => 'https://x' ) );
        delete_option( 'ai4d_topics_db_version' );

        ai4d_topics_maybe_migrate();

        $opts = get_option( 'ai4d_topics_settings' );
        $this->assertEquals( 'compact', $opts['display_shape'] );
        $this->assertEquals( 'https://x', $opts['backend_url'] );  // unrelated keys preserved
        $this->assertEquals( AI4D_TOPICS_VERSION, get_option( 'ai4d_topics_db_version' ) );
    }

    public function test_migration_preserves_explicit_chips_pick() {
        update_option( 'ai4d_topics_settings', array( 'display_shape' => 'chips' ) );
        delete_option( 'ai4d_topics_db_version' );

        ai4d_topics_maybe_migrate();

        $this->assertEquals( 'chips', get_option( 'ai4d_topics_settings' )['display_shape'] );
    }

    public function test_migration_preserves_explicit_parents_only_pick() {
        update_option( 'ai4d_topics_settings', array( 'display_shape' => 'parents_only' ) );
        delete_option( 'ai4d_topics_db_version' );

        ai4d_topics_maybe_migrate();

        $this->assertEquals( 'parents_only', get_option( 'ai4d_topics_settings' )['display_shape'] );
    }

    public function test_migration_is_idempotent_once_db_version_matches() {
        update_option( 'ai4d_topics_settings', array( 'display_shape' => 'hierarchy' ) );
        update_option( 'ai4d_topics_db_version', AI4D_TOPICS_VERSION );

        ai4d_topics_maybe_migrate();

        // Already-current site: stored hierarchy stays put (user explicitly picked it
        // after the migration already ran). Avoids re-stomping their choice.
        $this->assertEquals( 'hierarchy', get_option( 'ai4d_topics_settings' )['display_shape'] );
    }

    public function test_migration_handles_fresh_install_with_no_saved_settings() {
        delete_option( 'ai4d_topics_settings' );
        delete_option( 'ai4d_topics_db_version' );

        ai4d_topics_maybe_migrate();

        // No settings to migrate, but db_version should be recorded so future
        // upgrade migrations have a baseline.
        $this->assertEquals( AI4D_TOPICS_VERSION, get_option( 'ai4d_topics_db_version' ) );
    }

    public function test_migration_0_1_12_puts_upgraded_site_in_direct_mode() {
        update_option( 'ai4d_topics_settings', array( 'backend_url' => 'https://tk.example.com', 'display_shape' => 'chips' ) );
        update_option( 'ai4d_topics_db_version', '0.1.11' );
        ai4d_topics_maybe_migrate();
        $opts = get_option( 'ai4d_topics_settings' );
        $this->assertSame( 'direct', $opts['connection_mode'] );
        $this->assertSame( 'https://tk.example.com', $opts['backend_url'] );
        $this->assertSame( 'chips', $opts['display_shape'] );
        $this->assertSame( 'https://tk.example.com', AI4D_Topics_Settings::connection()['base_url'] );
    }

    public function test_migration_0_1_12_writes_nothing_on_fresh_install() {
        delete_option( 'ai4d_topics_settings' );
        delete_option( 'ai4d_topics_db_version' );
        ai4d_topics_maybe_migrate();
        $this->assertNull( get_option( 'ai4d_topics_settings', null ) );
    }
}
