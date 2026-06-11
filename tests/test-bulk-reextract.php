<?php
class Mock_Bulk_Extractor extends AI4D_Topics_Extractor {
    public $processed = array();
    /** Simulate a successful extraction: record the call, write success meta. */
    public function run( int $post_id ): void {
        $this->processed[] = $post_id;
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array( array( 'slug' => 'x', 'score' => 3 ) ) ) );
        delete_post_meta( $post_id, '_ai4d_extraction_error' );
    }
}

class Mock_Bulk_Skip_Extractor extends AI4D_Topics_Extractor {
    /** Simulate run() deciding to skip (gate reason written to error meta). */
    public function run( int $post_id ): void {
        update_post_meta( $post_id, '_ai4d_extraction_error', 'skipped: excluded_tag_matched' );
    }
}

class Mock_Bulk_Fail_Extractor extends AI4D_Topics_Extractor {
    /** Simulate a backend failure (raw error in error meta, no 'skipped:' prefix). */
    public function run( int $post_id ): void {
        update_post_meta( $post_id, '_ai4d_extraction_error', 'backend error 500' );
    }
}

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

    public function test_process_step_advances_cursor_and_counts_success() {
        $ids = array(
            $this->factory->post->create( array( 'post_status' => 'publish' ) ),
            $this->factory->post->create( array( 'post_status' => 'publish' ) ),
            $this->factory->post->create( array( 'post_status' => 'publish' ) ),
        );
        $mock = new Mock_Bulk_Extractor();
        $bulk = new AI4D_Topics_Bulk_Reextract( $mock );
        $bulk->start( 'all' );

        $p = $bulk->process_step();

        // CHUNK_MAX is 5, so all 3 process in one step here.
        $this->assertSame( 3, $p['cursor'] );
        $this->assertSame( 3, $p['succeeded'] );
        $this->assertSame( 'done', $p['status'] );
        $this->assertCount( 3, $mock->processed );
    }

    public function test_process_step_is_idempotent_when_done() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $mock = new Mock_Bulk_Extractor();
        $bulk = new AI4D_Topics_Bulk_Reextract( $mock );
        $bulk->start( 'all' );
        $bulk->process_step();           // -> done
        $p = $bulk->process_step();      // second call must be a no-op

        $this->assertSame( 'done', $p['status'] );
        $this->assertCount( 1, $mock->processed ); // not re-processed
    }

    public function test_process_step_does_nothing_when_cancelled() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $mock = new Mock_Bulk_Extractor();
        $bulk = new AI4D_Topics_Bulk_Reextract( $mock );
        $bulk->start( 'all' );
        $bulk->cancel();
        $bulk->process_step();
        $this->assertCount( 0, $mock->processed );
    }

    public function test_process_step_counts_skipped() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $bulk = new AI4D_Topics_Bulk_Reextract( new Mock_Bulk_Skip_Extractor() );
        $bulk->start( 'all' );
        $p = $bulk->process_step();
        $this->assertSame( 1, $p['skipped'] );
        $this->assertSame( 0, $p['succeeded'] );
        $this->assertSame( 0, $p['failed'] );
    }

    public function test_process_step_counts_failed() {
        $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $bulk = new AI4D_Topics_Bulk_Reextract( new Mock_Bulk_Fail_Extractor() );
        $bulk->start( 'all' );
        $p = $bulk->process_step();
        $this->assertSame( 1, $p['failed'] );
        $this->assertSame( 0, $p['succeeded'] );
        $this->assertSame( 0, $p['skipped'] );
    }

    public function test_process_step_spans_multiple_chunks() {
        for ( $i = 0; $i < 6; $i++ ) {
            $this->factory->post->create( array( 'post_status' => 'publish' ) );
        }
        $mock = new Mock_Bulk_Extractor();
        $bulk = new AI4D_Topics_Bulk_Reextract( $mock );
        $bulk->start( 'all' );

        $p1 = $bulk->process_step(); // CHUNK_MAX=5 -> 5 done, still running
        $this->assertSame( 'running', $p1['status'] );
        $this->assertSame( 5, $p1['cursor'] );
        $this->assertCount( 5, $mock->processed );

        $p2 = $bulk->process_step(); // remaining 1 -> done; counters persist across steps
        $this->assertSame( 'done', $p2['status'] );
        $this->assertSame( 6, $p2['cursor'] );
        $this->assertSame( 6, $p2['succeeded'] );
        $this->assertCount( 6, $mock->processed );
    }

    public function test_can_run_true_for_admin_false_for_editor() {
        $bulk = new AI4D_Topics_Bulk_Reextract();

        $admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
        $this->assertTrue( $bulk->can_run() );

        $editor = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );
        $this->assertFalse( $bulk->can_run() );

        wp_set_current_user( 0 );
    }

    public function test_register_page_adds_tools_submenu() {
        $admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );

        ( new AI4D_Topics_Bulk_Reextract() )->register_page();

        global $submenu;
        $found = false;
        foreach ( $submenu['tools.php'] ?? array() as $item ) {
            if ( $item[2] === 'ai4d-topics-bulk' ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue( $found, 'Bulk submenu not registered under Tools' );
    }

    public function test_render_page_dies_for_non_admin() {
        $editor = $this->factory->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $editor );

        $this->expectException( WPDieException::class );
        ( new AI4D_Topics_Bulk_Reextract() )->render_page();
    }

    public function test_render_page_warns_when_dry_run_enabled() {
        $admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin );
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'dry_run' => true )
        ) );

        ob_start();
        ( new AI4D_Topics_Bulk_Reextract() )->render_page();
        $out = ob_get_clean();

        $this->assertStringContainsString( 'dry-run', $out );
    }
}
