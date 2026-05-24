<?php
class Test_Uninstall extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option( 'ai4d_topics_settings', AI4D_Topics_Settings::get_all() );
        update_option( 'ai4d_topics_log', array( array( 'timestamp' => time(), 'type' => 'success', 'post_id' => 1, 'message' => 'x' ) ) );
        update_option( 'ai4d_topics_taxonomy_stale', array( 'payload' => array( 'topics' => array() ), 'etag' => 'x' ) );
        update_option( 'ai4d_topics_retry_queue', array( 1 => array( 'attempts' => 1 ) ) );
        set_transient( 'ai4d_topics_taxonomy', array( 'payload' => array( 'topics' => array() ), 'etag' => 'x' ), HOUR_IN_SECONDS );
    }

    public function test_uninstall_removes_options_when_keep_meta_mode() {
        $post_id = $this->factory->post->create();
        update_post_meta( $post_id, '_ai4d_topics', '[]' );
        update_post_meta( $post_id, '_ai4d_extracted_at', time() );

        ai4d_topics_uninstall_cleanup( 'keep_meta' );

        $this->assertFalse( get_option( 'ai4d_topics_settings', false ) );
        $this->assertFalse( get_option( 'ai4d_topics_log', false ) );
        $this->assertFalse( get_option( 'ai4d_topics_taxonomy_stale', false ) );
        $this->assertFalse( get_transient( 'ai4d_topics_taxonomy' ) );
        $this->assertFalse( get_option( 'ai4d_topics_retry_queue', false ) );

        // Post meta preserved
        $this->assertNotEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
    }

    public function test_uninstall_removes_post_meta_when_remove_everything_mode() {
        $post_id = $this->factory->post->create();
        update_post_meta( $post_id, '_ai4d_topics', '[]' );
        update_post_meta( $post_id, '_ai4d_extracted_at', time() );
        update_post_meta( $post_id, '_ai4d_taxonomy_version', 'v1' );
        update_post_meta( $post_id, '_ai4d_extraction_error', 'x' );

        ai4d_topics_uninstall_cleanup( 'remove_everything' );

        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_topics', true ) );
        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_extracted_at', true ) );
        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_taxonomy_version', true ) );
        $this->assertEmpty( get_post_meta( $post_id, '_ai4d_extraction_error', true ) );
    }

    public function test_uninstall_behavior_defaults_to_keep_meta_when_setting_missing() {
        delete_option( 'ai4d_topics_settings' );

        // Mirror uninstall.php's resolution logic.
        $mode = AI4D_Topics_Settings::get( 'uninstall_behavior' ) ?? 'keep_meta';

        $this->assertSame( 'keep_meta', $mode );
    }
}
