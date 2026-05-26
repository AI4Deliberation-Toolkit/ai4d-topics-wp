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
        unset( $GLOBALS['post'] );  // tests set this manually; clear to avoid leakage
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

    public function test_resolve_label_returns_localized_label_when_known() {
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ),
        ) );
        $renderer = new AI4D_Topics_Renderer();
        $this->assertEquals( 'Περιβάλλον', $renderer->resolve_label( 'environment', $taxonomy ) );
    }

    public function test_resolve_label_falls_back_to_slug_when_unknown() {
        $renderer = new AI4D_Topics_Renderer();
        $this->assertEquals( 'unknown-slug', $renderer->resolve_label( 'unknown-slug', array( 'topics' => array() ) ) );
    }

    public function test_resolve_label_prefers_stored_label_over_taxonomy() {
        // Stored labels come from the extract response and are already localized;
        // they should win over the taxonomy lookup (which is for parent slugs not
        // present in the extract response, or as a slower fallback).
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment', 'label' => 'Different label from taxonomy' ),
        ) );
        $renderer = new AI4D_Topics_Renderer();
        $this->assertEquals(
            'Stored label wins',
            $renderer->resolve_label( 'environment', $taxonomy, 'Stored label wins' )
        );
    }

    public function test_resolve_label_falls_through_to_taxonomy_when_stored_label_null() {
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ),
        ) );
        $renderer = new AI4D_Topics_Renderer();
        $this->assertEquals( 'Περιβάλλον', $renderer->resolve_label( 'environment', $taxonomy, null ) );
    }

    public function test_resolve_label_falls_through_to_taxonomy_when_stored_label_empty_string() {
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ),
        ) );
        $renderer = new AI4D_Topics_Renderer();
        $this->assertEquals( 'Περιβάλλον', $renderer->resolve_label( 'environment', $taxonomy, '' ) );
    }

    public function test_build_html_compact_shape_lists_leaves_inline_after_parent() {
        $topics = array(
            array( 'slug' => 'environment',      'score' => 3.0, 'kind' => 'parent' ),
            array( 'slug' => 'waste-management', 'score' => 2.5, 'kind' => 'leaf', 'parent' => 'environment' ),
            array( 'slug' => 'recycling',        'score' => 2.4, 'kind' => 'leaf', 'parent' => 'environment' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment',      'label' => 'Περιβάλλον' ),
            array( 'slug' => 'waste-management', 'label' => 'Διαχείριση αποβλήτων' ),
            array( 'slug' => 'recycling',        'label' => 'Ανακύκλωση' ),
        ) );
        $settings = array(
            'display_shape'       => 'compact',
            'display_show_header' => true,
            'display_header_text' => 'Θεματικές',
            'display_top_n'       => 5,
            'display_min_score'   => 0,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );

        $this->assertStringContainsString( 'class="ai4d-topics-block ai4d-topics-compact"', $html );
        $this->assertStringContainsString( '<strong>Περιβάλλον</strong>', $html );
        // Leaves rendered inline, comma-separated, after the parent's colon.
        $this->assertMatchesRegularExpression(
            '#<strong>Περιβάλλον</strong>:\s*<span class="ai4d-topics-leaves">Διαχείριση αποβλήτων, Ανακύκλωση</span>#u',
            $html
        );
        // No nested <ul> children — the inline format replaces the sub-list.
        $this->assertStringNotContainsString( '<ul><li>', str_replace( "\n", '', $html ) );
    }

    public function test_build_html_compact_shape_handles_parent_with_no_leaves() {
        $topics = array(
            array( 'slug' => 'environment', 'score' => 3.0, 'kind' => 'parent' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment', 'label' => 'Περιβάλλον' ),
        ) );
        $settings = array(
            'display_shape'       => 'compact',
            'display_show_header' => false,
            'display_header_text' => '',
            'display_top_n'       => 5,
            'display_min_score'   => 0,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );

        $this->assertStringContainsString( '<strong>Περιβάλλον</strong>', $html );
        // No colon or leaves span when the parent has no leaves.
        $this->assertStringNotContainsString( 'ai4d-topics-leaves', $html );
    }

    public function test_build_html_compact_shape_escapes_attacker_controlled_labels() {
        $topics = array(
            array( 'slug' => 'p', 'score' => 3.0, 'kind' => 'parent' ),
            array( 'slug' => 'l', 'score' => 2.5, 'kind' => 'leaf', 'parent' => 'p', 'label' => '<script>alert(1)</script>' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'p', 'label' => '<img src=x onerror=y>' ),
        ) );
        $settings = array(
            'display_shape' => 'compact', 'display_show_header' => false,
            'display_header_text' => '', 'display_top_n' => 5, 'display_min_score' => 0,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
        $this->assertStringContainsString( '&lt;img', $html );
    }

    public function test_build_html_hierarchy_groups_leaves_under_parents() {
        $topics = array(
            array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent' ),
            array( 'slug' => 'waste-management', 'score' => 0.81, 'kind' => 'leaf', 'parent' => 'environment' ),
            array( 'slug' => 'recycling',        'score' => 0.74, 'kind' => 'leaf', 'parent' => 'environment' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment',      'label' => 'Περιβάλλον' ),
            array( 'slug' => 'waste-management', 'label' => 'Διαχείριση αποβλήτων' ),
            array( 'slug' => 'recycling',        'label' => 'Ανακύκλωση' ),
        ) );
        $settings = array(
            'display_shape'       => 'hierarchy',
            'display_show_header' => true,
            'display_header_text' => 'Θεματικές',
            'display_top_n'       => 5,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        $this->assertStringContainsString( 'Θεματικές', $html );
        $this->assertStringContainsString( 'Περιβάλλον', $html );
        $this->assertStringContainsString( 'Διαχείριση αποβλήτων', $html );
        $this->assertStringContainsString( 'Ανακύκλωση', $html );
        $this->assertStringContainsString( 'class="ai4d-topics-block ai4d-topics-hierarchy"', $html );
    }

    public function test_build_html_chips_shape_emits_flat_leaf_list_top_n() {
        $topics = array(
            array( 'slug' => 'a', 'score' => 0.9, 'kind' => 'leaf', 'parent' => 'x' ),
            array( 'slug' => 'b', 'score' => 0.8, 'kind' => 'leaf', 'parent' => 'x' ),
            array( 'slug' => 'c', 'score' => 0.7, 'kind' => 'leaf', 'parent' => 'x' ),
            array( 'slug' => 'd', 'score' => 0.6, 'kind' => 'leaf', 'parent' => 'x' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'a', 'label' => 'A-label' ),
            array( 'slug' => 'b', 'label' => 'B-label' ),
            array( 'slug' => 'c', 'label' => 'C-label' ),
            array( 'slug' => 'd', 'label' => 'D-label' ),
        ) );
        $settings = array(
            'display_shape'       => 'chips',
            'display_show_header' => false,
            'display_header_text' => '',
            'display_top_n'       => 2,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        $this->assertStringContainsString( 'A-label', $html );
        $this->assertStringContainsString( 'B-label', $html );
        $this->assertStringNotContainsString( 'C-label', $html );
        $this->assertStringContainsString( 'class="ai4d-topics-block ai4d-topics-chips"', $html );
        $this->assertStringNotContainsString( '<h3', $html );  // header suppressed when display_show_header=false
    }

    public function test_build_html_parents_only_shape_emits_parents() {
        $topics = array(
            array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent' ),
            array( 'slug' => 'waste-management', 'score' => 0.81, 'kind' => 'leaf', 'parent' => 'environment' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment',      'label' => 'Περιβάλλον' ),
            array( 'slug' => 'waste-management', 'label' => 'Διαχείριση αποβλήτων' ),
        ) );
        $settings = array(
            'display_shape'       => 'parents_only',
            'display_show_header' => false,
            'display_header_text' => '',
            'display_top_n'       => 5,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        $this->assertStringContainsString( 'Περιβάλλον', $html );
        $this->assertStringNotContainsString( 'Διαχείριση αποβλήτων', $html );
    }

    public function test_build_html_renders_ai_disclosure_next_to_header() {
        // AI disclosure is shown inline with the header so visitors know the topics
        // weren't editorially curated. Customizable per partner site language.
        $topics = array(
            array( 'slug' => 'env', 'score' => 3.0, 'kind' => 'parent' ),
            array( 'slug' => 'waste', 'score' => 3.0, 'kind' => 'leaf', 'parent' => 'env', 'label' => 'Waste' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => true,
            'display_header_text' => 'Header', 'display_ai_disclosure_text' => 'AI-generated',
            'display_top_n' => 5, 'display_min_score' => 0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );

        $this->assertStringContainsString( 'ai4d-topics-ai-disclosure', $output );
        $this->assertStringContainsString( 'AI-generated', $output );
        // Disclosure should be inside the same <h3> as the header.
        $this->assertMatchesRegularExpression(
            '#<h3[^>]*>Header\s*<span class="ai4d-topics-ai-disclosure">AI-generated</span></h3>#',
            $output
        );
    }

    public function test_build_html_omits_ai_disclosure_when_empty() {
        $topics = array(
            array( 'slug' => 'env', 'score' => 3.0, 'kind' => 'parent' ),
            array( 'slug' => 'waste', 'score' => 3.0, 'kind' => 'leaf', 'parent' => 'env', 'label' => 'Waste' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => true,
            'display_header_text' => 'Header', 'display_ai_disclosure_text' => '',
            'display_top_n' => 5, 'display_min_score' => 0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );

        $this->assertStringNotContainsString( 'ai4d-topics-ai-disclosure', $output );
        $this->assertStringContainsString( 'Header', $output );
    }

    public function test_build_html_escapes_ai_disclosure_text() {
        $topics = array(
            array( 'slug' => 'env', 'score' => 3.0, 'kind' => 'parent' ),
            array( 'slug' => 'waste', 'score' => 3.0, 'kind' => 'leaf', 'parent' => 'env', 'label' => 'Waste' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => true,
            'display_header_text' => 'Header', 'display_ai_disclosure_text' => '<script>x</script>',
            'display_top_n' => 5, 'display_min_score' => 0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );

        $this->assertStringNotContainsString( '<script>x</script>', $output );
        $this->assertStringContainsString( '&lt;script&gt;', $output );
    }

    public function test_build_html_filters_topics_below_min_score() {
        // Backend often returns long tails of low-score topics; the partner site
        // tunes display_min_score to hide irrelevant matches without changing
        // stored data (so the cutoff can be re-tuned anytime).
        $topics = array(
            array( 'slug' => 'environment',      'score' => 3.5, 'kind' => 'parent' ),
            array( 'slug' => 'noise-parent',     'score' => 1.2, 'kind' => 'parent' ),
            array( 'slug' => 'waste-management', 'score' => 3.5, 'kind' => 'leaf', 'parent' => 'environment', 'label' => 'Waste' ),
            array( 'slug' => 'noise-leaf',       'score' => 1.0, 'kind' => 'leaf', 'parent' => 'noise-parent', 'label' => 'Noise' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => false, 'display_header_text' => '',
            'display_top_n' => 5, 'display_min_score' => 2.0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );

        $this->assertStringContainsString( 'Waste', $output );
        $this->assertStringNotContainsString( 'Noise', $output );
        // Whole parent subtree dropped when its score is below threshold.
        $this->assertStringNotContainsString( 'noise-parent', $output );
    }

    public function test_build_html_returns_empty_when_all_topics_below_threshold() {
        $topics = array(
            array( 'slug' => 'x', 'score' => 0.5, 'kind' => 'parent' ),
            array( 'slug' => 'y', 'score' => 0.3, 'kind' => 'leaf', 'parent' => 'x', 'label' => 'Y' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => true, 'display_header_text' => 'Header',
            'display_top_n' => 5, 'display_min_score' => 2.0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );
        $this->assertEquals( '', $output );
    }

    public function test_build_html_min_score_zero_disables_filter() {
        $topics = array(
            array( 'slug' => 'x', 'score' => 0.5, 'kind' => 'parent' ),
            array( 'slug' => 'y', 'score' => 0.3, 'kind' => 'leaf', 'parent' => 'x', 'label' => 'Y-label' ),
        );
        $settings = array(
            'display_shape' => 'hierarchy', 'display_show_header' => false, 'display_header_text' => '',
            'display_top_n' => 5, 'display_min_score' => 0.0,
        );

        $output = ( new AI4D_Topics_Renderer() )->build_html( $topics, array( 'topics' => array() ), $settings );
        $this->assertStringContainsString( 'Y-label', $output );
    }

    public function test_build_html_empty_topics_returns_empty_string() {
        $html = ( new AI4D_Topics_Renderer() )->build_html( array(), array( 'topics' => array() ), array(
            'display_shape' => 'hierarchy', 'display_show_header' => true, 'display_header_text' => 'X', 'display_top_n' => 5,
        ) );
        $this->assertEquals( '', $html );
    }

    public function test_build_html_escapes_attacker_controlled_labels() {
        $topics = array( array( 'slug' => 'evil', 'score' => 0.9, 'kind' => 'leaf', 'parent' => 'x' ) );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'evil', 'label' => '<script>alert(1)</script>' ),
        ) );
        $settings = array(
            'display_shape' => 'chips', 'display_show_header' => false, 'display_header_text' => '', 'display_top_n' => 5,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
    }

    public function test_build_html_unknown_shape_falls_back_to_compact() {
        $topics = array(
            array( 'slug' => 'environment',      'score' => 0.87, 'kind' => 'parent' ),
            array( 'slug' => 'waste-management', 'score' => 0.81, 'kind' => 'leaf', 'parent' => 'environment' ),
        );
        $taxonomy = array( 'topics' => array(
            array( 'slug' => 'environment',      'label' => 'Περιβάλλον' ),
            array( 'slug' => 'waste-management', 'label' => 'Διαχείριση αποβλήτων' ),
        ) );
        $settings = array(
            'display_shape'       => 'pie-chart',  // unknown shape
            'display_show_header' => false,
            'display_header_text' => '',
            'display_top_n'       => 5,
        );

        $html = ( new AI4D_Topics_Renderer() )->build_html( $topics, $taxonomy, $settings );
        // Falls back to compact (the new default): wrapper indicates compact, both parent + leaf rendered.
        $this->assertStringContainsString( 'class="ai4d-topics-block ai4d-topics-compact"', $html );
        $this->assertStringContainsString( 'Περιβάλλον', $html );
        $this->assertStringContainsString( 'Διαχείριση αποβλήτων', $html );
    }

    public function test_filter_returns_original_content_when_no_meta() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        $renderer = new AI4D_Topics_Renderer();
        $original = '<p>Original content.</p>';
        $GLOBALS['post'] = get_post( $post_id );
        setup_postdata( $GLOBALS['post'] );

        $result = $renderer->filter_content( $original );
        $this->assertEquals( $original, $result );
    }

    public function test_filter_returns_original_content_when_meta_malformed() {
        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', 'not-json' );
        $GLOBALS['post'] = get_post( $post_id );
        setup_postdata( $GLOBALS['post'] );

        $renderer = new AI4D_Topics_Renderer();
        $result = $renderer->filter_content( '<p>x</p>' );
        $this->assertEquals( '<p>x</p>', $result );
    }

    public function test_filter_always_prepends_topics_above_article_body() {
        // 0.1.10: topics always render before the article body so they sit just
        // under the title/byline (which most themes render outside the_content).
        set_transient( 'ai4d_topics_taxonomy', array(
            'payload' => array( 'topics' => array( array( 'slug' => 'env', 'label' => 'Περιβάλλον' ) ) ),
            'etag'    => 'x',
        ), HOUR_IN_SECONDS );

        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'env', 'score' => 3.0, 'kind' => 'parent' ),
        ) ) );
        $this->go_to( get_permalink( $post_id ) );  // make is_singular() return true
        $GLOBALS['post'] = get_post( $post_id );
        setup_postdata( $GLOBALS['post'] );

        $renderer = new AI4D_Topics_Renderer();
        $result = $renderer->filter_content( '<p>Article body.</p>' );
        $this->assertStringEndsWith( '<p>Article body.</p>', $result );
        $this->assertStringStartsWith( '<div class="ai4d-topics-block', ltrim( $result ) );
    }

    public function test_filter_skips_when_post_type_does_not_match() {
        update_option( 'ai4d_topics_settings', array_merge(
            AI4D_Topics_Settings::get_all(),
            array( 'post_type' => 'article' )
        ) );

        $post_id = $this->factory->post->create( array( 'post_status' => 'publish', 'post_type' => 'post' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array( array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ) ) ) );
        $GLOBALS['post'] = get_post( $post_id );
        setup_postdata( $GLOBALS['post'] );

        $renderer = new AI4D_Topics_Renderer();
        $result = $renderer->filter_content( '<p>x</p>' );
        $this->assertEquals( '<p>x</p>', $result );
    }

    public function test_filter_swallows_inner_exceptions_safely() {
        AI4D_Topics_Log::clear();

        $post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
        update_post_meta( $post_id, '_ai4d_topics', wp_json_encode( array(
            array( 'slug' => 'env', 'score' => 0.9, 'kind' => 'parent' ),
        ) ) );
        $this->go_to( get_permalink( $post_id ) );  // make is_singular() return true
        $GLOBALS['post'] = get_post( $post_id );
        setup_postdata( $GLOBALS['post'] );

        $client = $this->createMock( AI4D_Topics_API_Client::class );
        $client->method( 'get_taxonomy' )->willThrowException( new Exception( 'boom' ) );
        $renderer = new AI4D_Topics_Renderer( $client );

        $result = $renderer->filter_content( '<p>Article body.</p>' );
        $this->assertEquals( '<p>Article body.</p>', $result );

        $events = AI4D_Topics_Log::get_all();
        $this->assertNotEmpty( $events );
        $this->assertEquals( 'render_error', $events[0]['type'] );
        $this->assertEquals( $post_id, $events[0]['post_id'] );
    }
}
