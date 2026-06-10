<?php
class Test_Bulk_Reextract extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option( 'ai4d_topics_settings', AI4D_Topics_Settings::get_all() );
    }

    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
        delete_option( 'ai4d_topics_bulk_ids' );
        delete_option( 'ai4d_topics_bulk_progress' );
        parent::tearDown();
    }

    public function test_default_progress_is_idle() {
        $bulk = new AI4D_Topics_Bulk_Reextract();
        $p = $bulk->get_progress();
        $this->assertSame( 'idle', $p['status'] );
        $this->assertSame( 0, $p['cursor'] );
        $this->assertSame( 0, $p['total'] );
    }

    public function test_build_id_set_all_returns_eligible_posts() {
        $a = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $b = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $draft = $this->factory->post->create( array( 'post_status' => 'draft' ) );

        $bulk = new AI4D_Topics_Bulk_Reextract();
        $ids = $bulk->build_id_set( 'all' );

        $this->assertContains( $a, $ids );
        $this->assertContains( $b, $ids );
        $this->assertNotContains( $draft, $ids ); // allowed_statuses defaults to ['publish']
    }

    public function test_build_id_set_excludes_deliberation_tagged() {
        $kept = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $excluded = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $excluded, array( 'deliberation' ), true );

        $bulk = new AI4D_Topics_Bulk_Reextract();
        $ids = $bulk->build_id_set( 'all' );

        $this->assertContains( $kept, $ids );
        $this->assertNotContains( $excluded, $ids );
    }

    public function test_build_id_set_extracted_only_includes_posts_with_topics_meta() {
        $with = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $without = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $with, '_ai4d_topics', wp_json_encode( array() ) ); // even '[]' counts

        $bulk = new AI4D_Topics_Bulk_Reextract();
        $ids = $bulk->build_id_set( 'extracted' );

        $this->assertContains( $with, $ids );
        $this->assertNotContains( $without, $ids );
    }
}
