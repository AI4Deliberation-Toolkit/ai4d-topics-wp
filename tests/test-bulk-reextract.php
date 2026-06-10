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
}
