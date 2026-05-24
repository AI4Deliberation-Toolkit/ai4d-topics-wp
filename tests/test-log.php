<?php
class Test_Log extends WP_UnitTestCase {

    public function tearDown(): void {
        delete_option( 'ai4d_topics_log' );
        parent::tearDown();
    }

    public function test_append_writes_event_to_empty_log() {
        AI4D_Topics_Log::append( 'success', 42, 'extracted 3 topics' );
        $events = AI4D_Topics_Log::get_all();
        $this->assertCount( 1, $events );
        $this->assertEquals( 'success', $events[0]['type'] );
        $this->assertEquals( 42, $events[0]['post_id'] );
        $this->assertEquals( 'extracted 3 topics', $events[0]['message'] );
        $this->assertIsInt( $events[0]['timestamp'] );
    }

    public function test_events_are_ordered_newest_first() {
        AI4D_Topics_Log::append( 'success', 1, 'first' );
        AI4D_Topics_Log::append( 'failure', 2, 'second' );
        $events = AI4D_Topics_Log::get_all();
        $this->assertEquals( 'second', $events[0]['message'] );
        $this->assertEquals( 'first',  $events[1]['message'] );
    }

    public function test_buffer_caps_at_200_events() {
        for ( $i = 0; $i < 250; $i++ ) {
            AI4D_Topics_Log::append( 'success', $i, "msg-$i" );
        }
        $events = AI4D_Topics_Log::get_all();
        $this->assertCount( 200, $events );
        // Newest should be msg-249
        $this->assertEquals( 'msg-249', $events[0]['message'] );
        // Oldest in buffer should be msg-50
        $this->assertEquals( 'msg-50', $events[199]['message'] );
    }

    public function test_clear_empties_log() {
        AI4D_Topics_Log::append( 'success', 1, 'x' );
        AI4D_Topics_Log::clear();
        $this->assertEquals( array(), AI4D_Topics_Log::get_all() );
    }
}
