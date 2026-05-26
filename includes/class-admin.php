<?php
/**
 * Admin UX — settings page, metabox + re-extract button, posts-list column, log viewer.
 *
 * Per spec § Admin UX.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI4D_Topics_Admin {

    const SETTINGS_SLUG = 'ai4d-topics-settings';
    const LOG_VIEWER_SLUG = 'ai4d-topics-log';
    const NONCE_RE_EXTRACT = 'ai4d_topics_reextract';
    const NONCE_CLEAR = 'ai4d_topics_clear';

    private $extractor;

    public function __construct( ?AI4D_Topics_Extractor $extractor = null ) {
        $this->extractor = $extractor ?: new AI4D_Topics_Extractor();
    }

    public function register_settings_page(): void {
        add_options_page(
            'AI4D Topics',
            'AI4D Topics',
            'manage_options',
            self::SETTINGS_SLUG,
            array( $this, 'render_settings_page' )
        );

        register_setting( 'ai4d_topics_settings_group', 'ai4d_topics_settings', array(
            'sanitize_callback' => array( 'AI4D_Topics_Settings', 'sanitize' ),
        ) );
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        try {
            $settings = AI4D_Topics_Settings::get_all();
            ?>
            <div class="wrap">
                <h1>AI4D Topics — Settings</h1>
                <form method="post" action="options.php">
                    <?php settings_fields( 'ai4d_topics_settings_group' ); ?>

                    <h2>Backend</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="backend_url">Backend URL</label></th>
                            <td>
                                <input name="ai4d_topics_settings[backend_url]" id="backend_url" type="url"
                                       value="<?php echo esc_attr( $settings['backend_url'] ); ?>" class="regular-text" />
                                <p class="description">Full base URL of the AI4D backend, e.g. https://api.example.com</p>
                            </td>
                        </tr>
                    </table>

                    <h2>What to process</h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="post_type">Post type</label></th>
                            <td><input name="ai4d_topics_settings[post_type]" id="post_type"
                                       value="<?php echo esc_attr( $settings['post_type'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="include_tag">Include tag</label></th>
                            <td>
                                <input name="ai4d_topics_settings[include_tag]" id="include_tag"
                                       value="<?php echo esc_attr( $settings['include_tag'] ); ?>" />
                                <p class="description">Empty = process all non-excluded posts.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="exclude_tags">Exclude tags</label></th>
                            <td>
                                <input name="ai4d_topics_settings[exclude_tags]" id="exclude_tags" class="regular-text"
                                       value="<?php echo esc_attr( implode( ', ', $settings['exclude_tags'] ) ); ?>" />
                                <p class="description">Comma-separated list (e.g. "deliberation, draft-only").</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="dry_run">Dry-run mode</label></th>
                            <td>
                                <input type="checkbox" name="ai4d_topics_settings[dry_run]" id="dry_run" value="1"
                                    <?php checked( $settings['dry_run'] ); ?> />
                                <span class="description">Log what would happen but skip backend calls and meta writes.</span>
                            </td>
                        </tr>
                    </table>

                    <h2>Display</h2>
                    <table class="form-table">
                        <tr>
                            <th><label>Shape</label></th>
                            <td>
                                <?php foreach ( AI4D_Topics_Settings::ALLOWED_SHAPES as $shape ): ?>
                                    <label>
                                        <input type="radio" name="ai4d_topics_settings[display_shape]"
                                               value="<?php echo esc_attr( $shape ); ?>"
                                            <?php checked( $settings['display_shape'], $shape ); ?> />
                                        <?php echo esc_html( $shape ); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="display_top_n">Top N (chips only)</label></th>
                            <td><input type="number" min="1" max="20" name="ai4d_topics_settings[display_top_n]" id="display_top_n"
                                       value="<?php echo esc_attr( $settings['display_top_n'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="display_min_score">Minimum score</label></th>
                            <td>
                                <input type="number" step="0.1" min="0" max="10" name="ai4d_topics_settings[display_min_score]"
                                       id="display_min_score" value="<?php echo esc_attr( $settings['display_min_score'] ); ?>" />
                                <p class="description">Topics scoring below this value are hidden on the public page.
                                The admin metabox shows all topics with below-threshold ones greyed out so you can tune the cutoff.
                                Default <code>2.0</code>; typical relevant topics score 2.0–4.0.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Show header</label></th>
                            <td>
                                <input type="checkbox" name="ai4d_topics_settings[display_show_header]" value="1"
                                    <?php checked( $settings['display_show_header'] ); ?> />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="display_header_text">Header text</label></th>
                            <td><input name="ai4d_topics_settings[display_header_text]" id="display_header_text" class="regular-text"
                                       value="<?php echo esc_attr( $settings['display_header_text'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="display_ai_disclosure_text">AI disclosure</label></th>
                            <td>
                                <input name="ai4d_topics_settings[display_ai_disclosure_text]" id="display_ai_disclosure_text" class="regular-text"
                                       value="<?php echo esc_attr( $settings['display_ai_disclosure_text'] ); ?>" />
                                <p class="description">Shown as a small note next to the header, e.g. "AI-generated".
                                Leave empty to hide. Customize for your audience's language.</p>
                            </td>
                        </tr>
                    </table>

                    <h2>Lifecycle</h2>
                    <table class="form-table">
                        <tr>
                            <th><label>On uninstall</label></th>
                            <td>
                                <?php foreach ( AI4D_Topics_Settings::ALLOWED_UNINSTALL as $opt ): ?>
                                    <label>
                                        <input type="radio" name="ai4d_topics_settings[uninstall_behavior]"
                                               value="<?php echo esc_attr( $opt ); ?>"
                                            <?php checked( $settings['uninstall_behavior'], $opt ); ?> />
                                        <?php echo esc_html( $opt ); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'admin_error', 0, 'admin render error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
            echo '<p style="color:#a00"><strong>AI4D Topics:</strong> error rendering this section — see Tools → AI4D Topics Log.</p>';
        }
    }

    public function register_metabox(): void {
        $post_type = AI4D_Topics_Settings::get( 'post_type' );
        add_meta_box(
            'ai4d-topics-metabox',
            'AI4D Topics',
            array( $this, 'render_metabox' ),
            $post_type,
            'side',
            'default'
        );
    }

    public function render_metabox( WP_Post $post ): void {
        try {
            $raw_topics   = get_post_meta( $post->ID, '_ai4d_topics', true );
            $extracted_at = get_post_meta( $post->ID, '_ai4d_extracted_at', true );
            $tax_version  = get_post_meta( $post->ID, '_ai4d_taxonomy_version', true );
            $error        = get_post_meta( $post->ID, '_ai4d_extraction_error', true );

            // Read taxonomy from cache only — don't trigger a network call inline with
            // the edit-screen render. Falls back to slug-as-label if no cache (acceptable
            // for admin-only context).
            $renderer = new AI4D_Topics_Renderer();
            $cached   = get_transient( AI4D_Topics_Renderer::TRANSIENT_KEY );
            $taxonomy = ( is_array( $cached ) && isset( $cached['payload'] ) )
                ? $cached['payload']
                : array( 'topics' => array() );

            if ( $error ) {
                // 'skipped: <reason>' indicates the eligibility gate rejected the post
                // (e.g., backend URL not set, wrong post type, excluded tag). Distinct
                // from a real failure where the API was called and returned non-2xx.
                if ( strpos( $error, 'skipped: ' ) === 0 ) {
                    $reason = substr( $error, strlen( 'skipped: ' ) );
                    echo '<p style="color:#a00"><strong>Last extraction skipped:</strong> ' . esc_html( $reason ) . '</p>';
                } else {
                    echo '<p style="color:#a00"><strong>Last extraction failed:</strong> ' . esc_html( $error ) . '</p>';
                }
            }

            if ( ! $raw_topics ) {
                echo '<p>No extraction yet.</p>';
            } else {
                $topics = json_decode( $raw_topics, true );
                if ( is_array( $topics ) ) {
                    echo '<p><strong>Last extracted:</strong> ' . esc_html( date_i18n( 'Y-m-d H:i', (int) $extracted_at ) ) . '</p>';
                    if ( $tax_version ) {
                        echo '<p><strong>Taxonomy version:</strong> ' . esc_html( $tax_version ) . '</p>';
                    }
                    $settings_all = AI4D_Topics_Settings::get_all();
                    $min_score    = (float) $settings_all['display_min_score'];
                    echo '<ul style="margin-left:1em">';
                    foreach ( $topics as $t ) {
                        $label  = $renderer->resolve_label( $t['slug'], $taxonomy, $t['label'] ?? null );
                        $score  = (float) ( $t['score'] ?? 0 );
                        $hidden = $score < $min_score;
                        // Below-threshold topics: greyed out + suffix tag. Admin still
                        // sees what was extracted (useful for tuning the cutoff).
                        $style  = $hidden ? ' style="color:#888"' : '';
                        $suffix = $hidden ? ' <em style="color:#888">(below threshold)</em>' : '';
                        echo '<li' . $style . '>' . esc_html( $label )
                             . ' <small>(' . esc_html( number_format( $score, 2 ) ) . ')</small>'
                             . $suffix . '</li>';
                    }
                    echo '</ul>';
                }
            }

            // Metaboxes render inside WP's post-edit <form id="post">. HTML disallows
            // nested forms; in Gutenberg this resulted in the click being swallowed
            // (no submission at all). Use anchor links to admin-post.php instead —
            // nonce-protected GETs are the standard WP pattern for per-row actions.
            $reextract_url = wp_nonce_url(
                add_query_arg(
                    array( 'action' => 'ai4d_topics_reextract', 'post_id' => $post->ID ),
                    admin_url( 'admin-post.php' )
                ),
                self::NONCE_RE_EXTRACT
            );
            ?>
            <a href="<?php echo esc_url( $reextract_url ); ?>" class="button">Re-extract topics</a>
            <?php if ( $raw_topics ):
                $clear_url = wp_nonce_url(
                    add_query_arg(
                        array( 'action' => 'ai4d_topics_clear', 'post_id' => $post->ID ),
                        admin_url( 'admin-post.php' )
                    ),
                    self::NONCE_CLEAR
                );
                ?>
                <a href="<?php echo esc_url( $clear_url ); ?>" class="button" style="margin-left:6px"
                   onclick="return confirm('Remove all stored topics for this post?');">Clear topics</a>
            <?php endif; ?>
            <?php
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'admin_error', 0, 'admin render error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
            echo '<p style="color:#a00"><strong>AI4D Topics:</strong> error rendering this section — see Tools → AI4D Topics Log.</p>';
        }
    }

    public function handle_reextract_action(): bool {
        // Read from $_REQUEST: link-based invocation passes nonce + post_id in the
        // query string ($_GET); $_REQUEST covers both link and any legacy POST callers.
        if ( ! isset( $_REQUEST['_wpnonce'] ) ) {
            return false;
        }
        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], self::NONCE_RE_EXTRACT ) ) {
            return false;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        $post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
        if ( ! $post_id ) {
            return false;
        }
        $this->extractor->run( $post_id );
        return true;
    }

    public function register_column( array $columns ): array {
        $columns['ai4d_topics'] = 'Topics';
        return $columns;
    }

    public function render_column( string $column, int $post_id ): void {
        if ( $column !== 'ai4d_topics' ) {
            return;
        }
        try {
            // Display priority: topics > real failure > skipped/none.
            // - Topics present → green dot + count (last-known-good wins over later skips)
            // - No topics + real backend failure → red dot
            // - No topics + skipped (eligibility gate rejected) → dash, since "skipped"
            //   is a config state rather than an alert: when the backend isn't configured
            //   yet, every published post is "skipped" and a screen of red dots is noise.
            $raw = get_post_meta( $post_id, '_ai4d_topics', true );
            if ( $raw ) {
                $topics = json_decode( $raw, true );
                $count  = is_array( $topics ) ? count( $topics ) : 0;
                if ( $count === 0 ) {
                    echo '—';  // successful extraction with zero matches reads visually like "no extraction" — by design
                    return;
                }
                echo '<span style="color:#28a745">●</span> ' . (int) $count;
                return;
            }
            $error = get_post_meta( $post_id, '_ai4d_extraction_error', true );
            if ( $error && strpos( $error, 'skipped: ' ) !== 0 ) {
                echo '<span style="color:#dc3545" title="' . esc_attr( $error ) . '">●</span>';
                return;
            }
            echo '—';
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'admin_error', 0, 'admin render error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
            echo '<p style="color:#a00"><strong>AI4D Topics:</strong> error rendering this section — see Tools → AI4D Topics Log.</p>';
        }
    }

    public function register_log_viewer_page(): void {
        add_management_page(
            'AI4D Topics Log',
            'AI4D Topics Log',
            'manage_options',
            self::LOG_VIEWER_SLUG,
            array( $this, 'render_log_viewer' )
        );
    }

    public function register_metabox_for_current_screen(): void {
        $post_type = AI4D_Topics_Settings::get( 'post_type' );
        add_action( 'add_meta_boxes_' . $post_type, array( $this, 'register_metabox' ) );
    }

    public function handle_reextract_action_and_redirect(): void {
        $ok = $this->handle_reextract_action();
        $post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
        $redirect = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url();
        if ( $redirect ) {
            $redirect = add_query_arg( 'ai4d_topics_reextracted', $ok ? '1' : '0', $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }
        wp_safe_redirect( admin_url() );
        exit;
    }

    public function handle_clear_action(): bool {
        if ( ! isset( $_REQUEST['_wpnonce'] ) ) {
            return false;
        }
        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], self::NONCE_CLEAR ) ) {
            return false;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        $post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
        if ( ! $post_id ) {
            return false;
        }
        foreach ( AI4D_Topics_Extractor::POST_META_KEYS as $key ) {
            delete_post_meta( $post_id, $key );
        }
        AI4D_Topics_Log::append( 'cleared', $post_id, 'topics cleared by admin' );
        return true;
    }

    public function handle_clear_action_and_redirect(): void {
        $ok = $this->handle_clear_action();
        $post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
        $redirect = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url();
        if ( $redirect ) {
            $redirect = add_query_arg( 'ai4d_topics_cleared', $ok ? '1' : '0', $redirect );
            wp_safe_redirect( $redirect );
            exit;
        }
        wp_safe_redirect( admin_url() );
        exit;
    }

    public function render_log_viewer(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        try {
            $events = AI4D_Topics_Log::get_all();
            ?>
            <div class="wrap">
                <h1>AI4D Topics — Event Log</h1>
                <p>Most recent <?php echo (int) AI4D_Topics_Log::MAX_ENTRIES; ?> events. Failures are recoverable via the per-article "Re-extract topics" button on the edit screen.</p>
                <table class="widefat striped">
                    <thead><tr><th>Time</th><th>Type</th><th>Post</th><th>Message</th></tr></thead>
                    <tbody>
                        <?php if ( empty( $events ) ): ?>
                            <tr><td colspan="4">No events yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ( $events as $e ): ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) $e['timestamp'] ) ); ?></td>
                                <td><?php echo esc_html( $e['type'] ); ?></td>
                                <td>
                                    <?php
                                    $link = get_edit_post_link( (int) $e['post_id'] );
                                    if ( $link ) {
                                        echo '<a href="' . esc_url( $link ) . '">#' . (int) $e['post_id'] . '</a>';
                                    } else {
                                        echo '#' . (int) $e['post_id'];
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html( $e['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        } catch ( \Throwable $e ) {
            AI4D_Topics_Log::append( 'admin_error', 0, 'admin render error in ' . __FUNCTION__ . ': ' . $e->getMessage() );
            echo '<p style="color:#a00"><strong>AI4D Topics:</strong> error rendering this section — see Tools → AI4D Topics Log.</p>';
        }
    }
}
