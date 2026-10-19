<?php
/**
 * Bulk re-extract — durable queue, set builder, preview, step processor,
 * admin page, and AJAX handlers. Admin-only; registered under is_admin().
 *
 * Per spec docs/superpowers/specs/2026-06-10-wp-plugin-bulk-reextract-design.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Bulk_Reextract {

    const OPTION_IDS      = 'ai4d_topics_bulk_ids';
    const OPTION_PROGRESS = 'ai4d_topics_bulk_progress';
    const NONCE           = 'ai4d_topics_bulk';
    const PAGE_SLUG       = 'ai4d-topics-bulk';
    const CHUNK_MAX       = 5;    // max posts processed per AJAX request
    const TIME_BUDGET     = 20;   // soft wall-clock seconds per AJAX request (direct mode)
    // Via the gateway one extraction may take up to 35 s, so a step stays
    // under 15 s + one call = 50 s, inside the common 60 s host limit.
    const TIME_BUDGET_GATEWAY = 15;

    private $extractor;

    public function __construct( ?AI4D_Topics_Extractor $extractor = null ) {
        $this->extractor = $extractor ?: new AI4D_Topics_Extractor();
    }

    /** Soft wall-clock seconds per AJAX request for the active connection mode. */
    public function time_budget(): int {
        return AI4D_Topics_Settings::connection()['mode'] === 'gateway' ? self::TIME_BUDGET_GATEWAY : self::TIME_BUDGET;
    }

    /**
     * Shared base WP_Query args for the gate-mirroring queries: post type, allowed
     * statuses, allowed authors, all rows, IDs only, deterministic order.
     */
    private function base_query_args(): array {
        $settings = AI4D_Topics_Settings::get_all();
        $args = array(
            'post_type'      => $settings['post_type'],
            'post_status'    => $settings['allowed_statuses'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        );
        if ( ! empty( $settings['allowed_authors'] ) ) {
            $args['author__in'] = array_map( 'intval', $settings['allowed_authors'] );
        }
        return $args;
    }

    /**
     * The include_tag tax_query clause (IN), or null when include_tag is unset.
     */
    private function include_tag_clause(): ?array {
        $settings = AI4D_Topics_Settings::get_all();
        if ( $settings['include_tag'] === '' ) {
            return null;
        }
        return array(
            'taxonomy' => 'post_tag',
            'field'    => 'slug',
            'terms'    => array( $settings['include_tag'] ),
            'operator' => 'IN',
        );
    }

    private function default_progress(): array {
        return array(
            'mode'       => '',
            'cursor'     => 0,
            'total'      => 0,
            'succeeded'  => 0,
            'skipped'    => 0,
            'failed'     => 0,
            'status'     => 'idle',  // idle | running | done | cancelled
            'started_by' => 0,
            'started_at' => 0,
            'updated_at' => 0,
        );
    }

    public function get_progress(): array {
        $p = get_option( self::OPTION_PROGRESS, null );
        return is_array( $p ) ? array_merge( $this->default_progress(), $p ) : $this->default_progress();
    }

    private function write_progress( array $p ): void {
        update_option( self::OPTION_PROGRESS, $p, false );
    }

    /**
     * Build the set of post IDs to process. The query mirrors should_process()'s
     * gate (post_type, allowed_statuses, allowed_authors, exclude/include tags) so
     * excluded posts are never enqueued or touched. should_process() inside run()
     * remains the authoritative run-time re-check.
     *
     * Note: should_process()'s configured check is intentionally NOT mirrored here.
     * With an unconfigured connection this still returns the full eligible set, which
     * run() then skips per post — so a count derived from this set would overstate
     * what actually gets processed until the active mode's URL (and key) is set.
     *
     * @param string $mode 'all' | 'extracted'
     * @return int[]
     */
    public function build_id_set( string $mode ): array {
        $settings = AI4D_Topics_Settings::get_all();
        $args     = $this->base_query_args();

        $tax_query = array();
        if ( ! empty( $settings['exclude_tags'] ) ) {
            $tax_query[] = array(
                'taxonomy' => 'post_tag',
                'field'    => 'slug',
                'terms'    => $settings['exclude_tags'],
                'operator' => 'NOT IN',
            );
        }
        $include = $this->include_tag_clause();
        if ( $include !== null ) {
            $tax_query[] = $include;
        }
        if ( ! empty( $tax_query ) ) {
            $args['tax_query'] = $tax_query;
        }

        if ( $mode === 'extracted' ) {
            $args['meta_query'] = array(
                array( 'key' => '_ai4d_topics', 'compare' => 'EXISTS' ),
            );
        }

        return array_map( 'intval', get_posts( $args ) );
    }

    /**
     * Count-only preview (no HTTP, no meta writes). Counts come from queries, not
     * a per-post should_process() loop, so the preview stays within one request at
     * scale.
     *
     * `excluded_by_tag` is measured against the SAME population as `eligible`
     * (post type, allowed statuses, allowed authors, include_tag) — it counts the
     * posts that would be eligible except that they carry an exclude tag. So
     * eligible + excluded_by_tag describe one coherent pool.
     *
     * @return array{mode:string, eligible:int, excluded_by_tag:int}
     */
    public function preview( string $mode ): array {
        $settings = AI4D_Topics_Settings::get_all();

        $eligible = count( $this->build_id_set( $mode ) );

        $excluded_by_tag = 0;
        if ( ! empty( $settings['exclude_tags'] ) ) {
            $args      = $this->base_query_args();
            $tax_query = array(
                array(
                    'taxonomy' => 'post_tag',
                    'field'    => 'slug',
                    'terms'    => $settings['exclude_tags'],
                    'operator' => 'IN',
                ),
            );
            $include = $this->include_tag_clause();
            if ( $include !== null ) {
                $tax_query[] = $include;
            }
            $args['tax_query'] = $tax_query;
            $excluded_by_tag   = count( get_posts( $args ) );
        }

        return array(
            'mode'            => $mode,
            'eligible'        => $eligible,
            'excluded_by_tag' => $excluded_by_tag,
        );
    }

    /**
     * Begin a run: build the ID set, write it once, and initialize progress.
     * Throws on an unknown mode. An empty set lands directly in 'done'.
     */
    public function start( string $mode ): array {
        if ( ! in_array( $mode, array( 'all', 'extracted' ), true ) ) {
            throw new InvalidArgumentException( 'Unknown bulk mode: ' . $mode );
        }

        $ids = $this->build_id_set( $mode );
        update_option( self::OPTION_IDS, array_values( $ids ), false );

        $p               = $this->default_progress();
        $p['mode']       = $mode;
        $p['total']      = count( $ids );
        $p['status']     = $p['total'] > 0 ? 'running' : 'done';
        $p['started_by'] = get_current_user_id();
        $p['started_at'] = time();
        $p['updated_at'] = time();
        $this->write_progress( $p );

        return $p;
    }

    /**
     * Force-stop a run and clear the queued IDs. Always available from the UI so a
     * 'running' status can never wedge the feature.
     */
    public function cancel(): void {
        $p               = $this->get_progress();
        $p['status']     = 'cancelled';
        $p['updated_at'] = time();
        $this->write_progress( $p );
        delete_option( self::OPTION_IDS );
    }

    /**
     * Process the next batch. Advances the cursor and rewrites progress AFTER EACH
     * POST (so a killed request still records completed posts), stopping when the
     * chunk count is hit OR the soft time budget elapses (so the request returns
     * before the host's request timeout can kill it mid-flight; time_budget():
     * 20 s direct, 15 s via the gateway, read once per step). No-op unless
     * status is 'running'.
     *
     * Classifies each post by the error meta run() leaves behind:
     *   empty            -> succeeded
     *   'skipped: ...'   -> skipped (defense-in-depth; the query already excludes these)
     *   anything else    -> failed
     */
    public function process_step(): array {
        $p = $this->get_progress();
        if ( $p['status'] !== 'running' ) {
            return $p;
        }

        $ids   = get_option( self::OPTION_IDS, array() );
        $total = count( $ids );
        $start = microtime( true );
        $budget = $this->time_budget();
        $done_this_step = 0;

        while ( $p['cursor'] < $total && $done_this_step < self::CHUNK_MAX ) {
            if ( ( microtime( true ) - $start ) > $budget ) {
                break;
            }

            $post_id = (int) $ids[ $p['cursor'] ];
            $this->extractor->run( $post_id );

            // Classify by the meta run() leaves behind. Authoritative for real
            // extraction paths (success deletes the error meta; skip/fail set it).
            // Exceptions: under the global dry_run setting, or if a post is deleted
            // mid-run, run() returns without touching the meta, so the count is
            // approximate — the Tools page warns when dry_run is enabled.
            $err = get_post_meta( $post_id, '_ai4d_extraction_error', true );
            if ( $err === '' || $err === false ) {
                $p['succeeded']++;
            } elseif ( strpos( (string) $err, 'skipped: ' ) === 0 ) {
                $p['skipped']++;
            } else {
                $p['failed']++;
            }

            $p['cursor']++;
            $p['updated_at'] = time();
            $done_this_step++;

            // A concurrent cancel() may have stopped the run while we were working.
            // Re-read the authoritative status and bail WITHOUT writing, so we never
            // resurrect a cancelled run. (Cancel has already deleted OPTION_IDS, so
            // processing stops regardless; this just preserves the recorded status.)
            $current = $this->get_progress();
            if ( $current['status'] !== 'running' ) {
                return $current;
            }
            $this->write_progress( $p );
        }

        if ( $p['cursor'] >= $total ) {
            $current = $this->get_progress();
            if ( $current['status'] !== 'running' ) {
                return $current;
            }
            $p['status'] = 'done';
            $this->write_progress( $p );
        }

        return $p;
    }

    /** Capability gate for every bulk action. */
    public function can_run(): bool {
        return current_user_can( 'manage_options' );
    }

    /** Shared guard for AJAX handlers: nonce + capability, or send an error and exit. */
    private function guard_ajax(): void {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
        }
        if ( ! $this->can_run() ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
        }
    }

    /** AJAX handler: return a preview count for the requested mode. */
    public function ajax_preview(): void {
        try {
            $this->guard_ajax();
            $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'all';
            if ( ! in_array( $mode, array( 'all', 'extracted' ), true ) ) {
                $mode = 'all';
            }
            wp_send_json_success( $this->preview( $mode ) );
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'bulk_error', 0, 'bulk preview error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'Server error' ) );
        }
    }

    /** AJAX handler: start a new bulk run (rejects if one is already in progress). */
    public function ajax_start(): void {
        try {
            $this->guard_ajax();
            $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'all';
            if ( ! in_array( $mode, array( 'all', 'extracted' ), true ) ) {
                wp_send_json_error( array( 'message' => 'Invalid mode' ), 400 );
            }
            // Refuse to clobber a run in progress; the UI offers Resume/Cancel instead.
            $current = $this->get_progress();
            if ( $current['status'] === 'running' ) {
                wp_send_json_error( array( 'message' => 'A run is already in progress' ), 409 );
            }
            wp_send_json_success( $this->start( $mode ) );
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'bulk_error', 0, 'bulk start error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'Server error' ) );
        }
    }

    /** AJAX handler: process the next batch and return updated progress. */
    public function ajax_step(): void {
        try {
            $this->guard_ajax();
            wp_send_json_success( $this->process_step() );
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'bulk_error', 0, 'bulk step error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'Server error' ) );
        }
    }

    /** AJAX handler: cancel the current run and return updated progress. */
    public function ajax_cancel(): void {
        try {
            $this->guard_ajax();
            $this->cancel();
            wp_send_json_success( $this->get_progress() );
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'bulk_error', 0, 'bulk cancel error: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'Server error' ) );
        }
    }

    /** Register the Bulk Re-extract page under the Tools menu. */
    public function register_page(): void {
        add_management_page(
            'AI4D Topics — Bulk Re-extract',
            'AI4D Topics Bulk',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /** Render the Bulk Re-extract admin page. */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        try {
            $settings = AI4D_Topics_Settings::get_all();
            $conn_error = AI4D_Topics_Settings::connection()['error'];
            ?>
            <div class="wrap">
                <h1>AI4D Topics — Bulk Re-extract</h1>

                <?php if ( $conn_error === 'backend_url_not_configured' ): ?>
                    <div class="notice notice-error"><p>No <strong>Toolkit URL</strong> configured — set it under Settings → AI4D Topics first.</p></div>
                <?php elseif ( $conn_error === 'gateway_not_configured' ): ?>
                    <div class="notice notice-error"><p><strong>Via the gateway</strong> is selected but the gateway URL or key is empty — set both under Settings → AI4D Topics, or select Direct.</p></div>
                <?php endif; ?>

                <?php if ( $settings['dry_run'] ): ?>
                    <div class="notice notice-warning"><p><strong>dry-run mode is enabled</strong> — a bulk run will log only and make no backend calls or meta writes. Disable dry-run in Settings to extract for real.</p></div>
                <?php endif; ?>

                <p>Re-extract topics across many articles at once. Excluded
                   (e.g. <code>deliberation</code>-tagged) articles are never touched.</p>

                <table class="form-table">
                    <tr>
                        <th>What to process</th>
                        <td>
                            <label><input type="radio" name="ai4d_bulk_mode" value="extracted" checked>
                                Already-extracted articles only</label><br>
                            <label><input type="radio" name="ai4d_bulk_mode" value="all">
                                All eligible articles (backfill)</label>
                        </td>
                    </tr>
                </table>

                <p>
                    <button class="button" id="ai4d-bulk-preview">Preview count</button>
                    <button class="button button-primary" id="ai4d-bulk-start">Start</button>
                    <button class="button" id="ai4d-bulk-resume">Resume</button>
                    <button class="button" id="ai4d-bulk-cancel">Cancel</button>
                </p>

                <div id="ai4d-bulk-preview-result"></div>

                <div id="ai4d-bulk-progress" style="max-width:640px">
                    <div style="background:#ddd;border-radius:4px;overflow:hidden;height:20px;margin:8px 0">
                        <div id="ai4d-bulk-bar" style="background:#2271b1;height:20px;width:0%"></div>
                    </div>
                    <p id="ai4d-bulk-status"></p>
                </div>

                <p><a href="<?php echo esc_url( admin_url( 'tools.php?page=ai4d-topics-log' ) ); ?>">View event log</a></p>
            </div>
            <?php
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'admin_error', 0, 'admin render error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
            echo '<p style="color:#a00"><strong>AI4D Topics:</strong> error rendering this section — see Tools → AI4D Topics Log.</p>';
        }
    }
}
