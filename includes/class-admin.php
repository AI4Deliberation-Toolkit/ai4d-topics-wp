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
}
