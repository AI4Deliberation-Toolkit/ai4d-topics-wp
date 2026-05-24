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
                        <th><label>Position</label></th>
                        <td>
                            <?php foreach ( AI4D_Topics_Settings::ALLOWED_POSITIONS as $pos ): ?>
                                <label>
                                    <input type="radio" name="ai4d_topics_settings[display_position]"
                                           value="<?php echo esc_attr( $pos ); ?>"
                                        <?php checked( $settings['display_position'], $pos ); ?> />
                                    <?php echo esc_html( $pos ); ?>
                                </label><br>
                            <?php endforeach; ?>
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
            echo '<p style="color:#a00"><strong>Last extraction failed:</strong> ' . esc_html( $error ) . '</p>';
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
                echo '<ul style="margin-left:1em">';
                foreach ( $topics as $t ) {
                    $label = $renderer->resolve_label( $t['slug'], $taxonomy );
                    echo '<li>' . esc_html( $label ) . ' <small>(' . esc_html( number_format( $t['score'] ?? 0, 2 ) ) . ')</small></li>';
                }
                echo '</ul>';
            }
        }

        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( self::NONCE_RE_EXTRACT ); ?>
            <input type="hidden" name="action" value="ai4d_topics_reextract" />
            <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>" />
            <button type="submit" class="button">Re-extract topics</button>
        </form>
        <?php
    }

    public function handle_reextract_action(): bool {
        if ( ! isset( $_POST['_wpnonce'] ) ) {
            return false;
        }
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], self::NONCE_RE_EXTRACT ) ) {
            return false;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return false;
        }
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id ) {
            return false;
        }
        $this->extractor->run( $post_id );
        return true;
    }
}
