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

    public function test_preview_reports_eligible_and_excluded_counts() {
        $e1 = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $e2 = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $x  = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $x, array( 'deliberation' ), true );

        $bulk = new AI4D_Topics_Bulk_Reextract();
        $preview = $bulk->preview( 'all' );

        $this->assertSame( 2, $preview['eligible'] );
        $this->assertSame( 1, $preview['excluded_by_tag'] );
        $this->assertSame( 'all', $preview['mode'] );
    }

    public function test_preview_excluded_count_zero_when_no_exclude_tags() {
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'exclude_tags' => array() )
        ) );
        $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $preview = ( new AI4D_Topics_Bulk_Reextract() )->preview( 'all' );
        $this->assertSame( 0, $preview['excluded_by_tag'] );
    }

    public function test_preview_excluded_by_tag_respects_include_tag_population() {
        // include_tag set: only posts carrying 'gov' are in the eligible pool.
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'include_tag' => 'gov' ) // exclude_tags stays default ['deliberation']
        ) );

        // Has exclude tag but NOT the include tag -> outside the pool, must NOT count.
        $outside = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $outside, array( 'deliberation' ), true );

        // Would be eligible (has include tag) except it also carries the exclude tag -> counts.
        $inside = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        wp_set_post_tags( $inside, array( 'deliberation', 'gov' ), true );

        $preview = ( new AI4D_Topics_Bulk_Reextract() )->preview( 'all' );
        $this->assertSame( 1, $preview['excluded_by_tag'] );
    }

    public function test_progress_options_are_not_autoloaded() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $bulk = new AI4D_Topics_Bulk_Reextract();
        $bulk->start( 'all' );
        $autoload = wp_load_alloptions();
        $this->assertArrayNotHasKey( 'ai4d_topics_bulk_progress', $autoload );
        $this->assertArrayNotHasKey( 'ai4d_topics_bulk_ids', $autoload );
    }

    public function test_start_enqueues_ids_and_sets_running() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $this->factory->post->create( array( 'post_status' => 'publish' ) );

        $bulk = new AI4D_Topics_Bulk_Reextract();
        $p = $bulk->start( 'all' );

        $this->assertSame( 'running', $p['status'] );
        $this->assertSame( 2, $p['total'] );
        $this->assertSame( 0, $p['cursor'] );
        $this->assertCount( 2, get_option( 'ai4d_topics_bulk_ids' ) );
    }

    public function test_start_with_empty_set_is_done_immediately() {
        $bulk = new AI4D_Topics_Bulk_Reextract();
        $p = $bulk->start( 'all' );
        $this->assertSame( 'done', $p['status'] );
        $this->assertSame( 0, $p['total'] );
    }

    public function test_start_rejects_invalid_mode() {
        $bulk = new AI4D_Topics_Bulk_Reextract();
        $this->expectException( InvalidArgumentException::class );
        $bulk->start( 'bogus' );
    }

    public function test_cancel_resets_to_cancelled_and_clears_ids() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $bulk = new AI4D_Topics_Bulk_Reextract();
        $bulk->start( 'all' );
        $bulk->cancel();

        $p = $bulk->get_progress();
        $this->assertSame( 'cancelled', $p['status'] );
        $this->assertFalse( get_option( 'ai4d_topics_bulk_ids', false ) );
    }
}
