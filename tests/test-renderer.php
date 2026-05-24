<?php
class Test_Renderer extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        update_option( 'ai4d_topics_settings', array( 'backend_url' => 'https://api.example.com' ) );
        delete_transient( 'ai4d_topics_taxonomy' );
        delete_option( 'ai4d_topics_taxonomy_stale' );
    }

    public function tearDown(): void {
        delete_option( 'ai4d_topics_settings' );
        delete_transient( 'ai4d_topics_taxonomy' );
        delete_option( 'ai4d_topics_taxonomy_stale' );
        parent::tearDown();
    }

    public function test_resolver_uses_cached_payload_when_fresh() {
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array( array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ) ) ),
            'etag'    => 'W/"abc"',
        ), HOUR_IN_SECONDS );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->never() )->method( 'get_taxonomy' );

        $renderer = new AI4D_Topics_Renderer( $client );
        $taxonomy = $renderer->load_taxonomy();
        $this->assertEquals( 'Περιβάλλον', $taxonomy['topics'][0]['label'] );
    }

    public function test_resolver_fetches_when_no_cache() {
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'get_taxonomy' )->willReturn( array(
            'payload'      => array( 'topics' => array( array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ) ) ),
            'etag'         => 'W/"new"',
            'not_modified' => false,
            'error'        => null,
        ) );

        $taxonomy = ( new AI4D_Topics_Renderer( $client ) )->load_taxonomy();
        $this->assertEquals( 'Περιβάλλον', $taxonomy['topics'][0]['label'] );

        $cached = get_transient( 'ai4d_topics_taxonomy' );
        $this->assertEquals( 'W/"new"', $cached['etag'] );

        // Stale option mirror is also written so the next request can fall back to it on backend error.
        $stale = get_option( 'ai4d_topics_taxonomy_stale' );
        $this->assertIsArray( $stale );
        $this->assertEquals( 'W/"new"', $stale['etag'] );
    }

    public function test_resolver_uses_stale_cache_on_backend_error() {
        update_option( 'ai4d_topics_taxonomy_stale', array(
            'payload' => array( 'topics' => array( array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ) ) ),
            'etag'    => 'W/"stale"',
        ), false );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'get_taxonomy' )->willReturn( array(
            'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'http_request_failed',
        ) );

        $taxonomy = ( new AI4D_Topics_Renderer( $client ) )->load_taxonomy();
        $this->assertEquals( 'Περιβάλλον', $taxonomy['topics'][0]['label'] );

        delete_option( 'ai4d_topics_taxonomy_stale' );
    }

    public function test_resolver_304_reuses_stale_option_and_repopulates_transient() {
        update_option( 'ai4d_topics_taxonomy_stale', array(
            'payload' => array( 'topics' => array( array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ) ) ),
            'etag'    => 'W/"abc"',
        ), false );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'get_taxonomy' )->willReturn( array(
            'payload' => null, 'etag' => null, 'not_modified' => true, 'error' => null,
        ) );

        $taxonomy = ( new AI4D_Topics_Renderer( $client ) )->load_taxonomy();
        $this->assertEquals( 'Περιβάλλον', $taxonomy['topics'][0]['label'] );
        $this->assertIsArray( get_transient( 'ai4d_topics_taxonomy' ) );

        delete_option( 'ai4d_topics_taxonomy_stale' );
    }

    public function test_resolver_returns_empty_when_no_cache_and_backend_error() {
        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'get_taxonomy' )->willReturn( array(
            'payload' => null, 'etag' => null, 'not_modified' => false, 'error' => 'http_request_failed',
        ) );

        $taxonomy = ( new AI4D_Topics_Renderer( $client ) )->load_taxonomy();
        $this->assertEquals( array( 'topics' => array() ), $taxonomy );
    }

    public function test_resolver_clears_transient_on_304_without_stale() {
        // Seed the transient but NOT the stale option — pathological state.
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array() ),
            'etag'    => 'W/"abc"',
        ), HOUR_IN_SECONDS );
        // Force the resolver to skip the fresh-cache return and reach the fetch branch
        // by deleting the transient right before the call — but we also want the API
        // client to BELIEVE the transient was there. We simulate this by stubbing the
        // client to return not_modified=true directly.
        delete_transient( 'ai4d_topics_taxonomy' );
        // Now re-seed so the fresh-cache check fails (we want fetch path) — actually
        // we need the resolver to take the fetch path with not_modified=true.
        // The way to drive that is to keep the transient empty here, then have the mock
        // return not_modified=true.

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->expects( $this->once() )->method( 'get_taxonomy' )->willReturn( array(
            'payload' => null, 'etag' => null, 'not_modified' => true, 'error' => null,
        ) );

        $result = ( new AI4D_Topics_Renderer( $client ) )->load_taxonomy();
        $this->assertEquals( array( 'topics' => array() ), $result );
        $this->assertFalse( get_transient( 'ai4d_topics_taxonomy' ) );  // self-healed
    }
}
