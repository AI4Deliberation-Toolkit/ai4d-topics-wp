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
        $this->assertTrue( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_type_mismatch() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'post_type' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_status_not_allowed() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'draft', 'post_type' => 'post' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_has_excluded_tag() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        wp_set_post_tags( $post_id, array( 'deliberation' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_matches_any_of_multiple_exclude_tags() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'exclude_tags' => array( 'deliberation', 'draft-notice' ) )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        wp_set_post_tags( $post_id, array( 'draft-notice' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_include_tag_set_and_post_lacks_it() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'other' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_matches_when_include_tag_set_and_post_has_it() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'article' ) );
        $this->assertTrue( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_exclude_tags_take_precedence_over_include_tag() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'include_tag' => 'article' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $post_id, array( 'article', 'deliberation' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_allowed_authors_set_and_post_author_not_in_list() {
        $author_a = $this->factory->user->create();
        $author_b = $this->factory->user->create();
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'allowed_authors' => array( $author_a ) )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_author' => $author_b ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }

    public function test_excludes_when_post_does_not_exist() {
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( 999999 ) );
    }

    public function test_excludes_when_backend_url_not_configured() {
        update_option( 'ai4d_topics_settings', array_merge(
            get_option( 'ai4d_topics_settings' ),
            array( 'backend_url' => '' )
        ) );
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $this->assertFalse( ( new AI4D_Topics_Extractor() )->should_process( $post_id ) );
    }
}
