<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Logs {

    public static function render_page() {
        if ( ! current_user_can( 'smid_access_logs' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'smid' ) );
        }

        // Handle clear action
        if ( isset( $_POST['smid_clear_log'], $_POST['smid_log_file'], $_POST['_wpnonce'] ) ) {
            if ( wp_verify_nonce( $_POST['_wpnonce'], 'smid_clear_log' ) ) {
                SMID_Logger::clear_log( sanitize_text_field( $_POST['smid_log_file'] ) );
                echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
            }
        }

        $log_files  = SMID_Logger::get_log_files();
        $selected   = isset( $_GET['log_file'] ) ? sanitize_text_field( $_GET['log_file'] ) : ( $log_files[0] ?? '' );
        $filter     = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'all';
        $entries    = SMID_Logger::get_log_entries( $selected, 300 );

        if ( $filter !== 'all' ) {
            $level   = strtoupper( $filter );
            $entries = array_values( array_filter( $entries, function( $e ) use ( $level ) {
                return $e['level'] === $level;
            } ) );
        }
        ?>
        <div class="wrap smid-wrap">
            <h1 class="smid-page-title">
                <span class="dashicons dashicons-list-view"></span>
                <?php _e( 'Plugin Logs', 'smid' ); ?>
            </h1>

            <!-- Toolbar -->
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
                <form method="get" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="page" value="smid-logs">

                    <label style="font-weight:600;"><?php _e( 'Log file:', 'smid' ); ?></label>
                    <select name="log_file" onchange="this.form.submit()" style="max-width:220px;">
                        <?php if ( empty( $log_files ) ) : ?>
                            <option value=""><?php _e( '— no logs yet —', 'smid' ); ?></option>
                        <?php else : ?>
                            <?php foreach ( $log_files as $f ) : ?>
                                <option value="<?php echo esc_attr( $f ); ?>" <?php selected( $f, $selected ); ?>>
                                    <?php echo esc_html( basename( $f ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>

                    <label style="font-weight:600;"><?php _e( 'Filter:', 'smid' ); ?></label>
                    <select name="filter" onchange="this.form.submit()">
                        <option value="all"     <?php selected( $filter, 'all' ); ?>><?php _e( 'All', 'smid' ); ?></option>
                        <option value="success" <?php selected( $filter, 'success' ); ?>><?php _e( 'Success', 'smid' ); ?></option>
                        <option value="error"   <?php selected( $filter, 'error' ); ?>><?php _e( 'Errors', 'smid' ); ?></option>
                    </select>
                </form>

                <?php if ( $selected ) : ?>
                    <form method="post" style="margin-left:auto;" onsubmit="return confirm('Clear this log file?');">
                        <?php wp_nonce_field( 'smid_clear_log' ); ?>
                        <input type="hidden" name="smid_log_file" value="<?php echo esc_attr( $selected ); ?>">
                        <button type="submit" name="smid_clear_log" class="button button-secondary" style="color:#d63638;border-color:#d63638;">
                            <span class="dashicons dashicons-trash" style="margin-top:3px;"></span>
                            <?php _e( 'Clear Log', 'smid' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Log Table -->
            <div class="smid-card smid-no-pad">
                <?php if ( empty( $entries ) ) : ?>
                    <div style="padding:40px;text-align:center;color:#646970;">
                        <span class="dashicons dashicons-info-outline" style="font-size:32px;width:32px;height:32px;margin-bottom:8px;display:block;margin:0 auto 8px;"></span>
                        <?php echo $log_files ? __( 'No log entries match the current filter.', 'smid' ) : __( 'No log files found yet. Logs are created automatically on plugin activity.', 'smid' ); ?>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped" style="border:none;">
                        <thead>
                            <tr>
                                <th style="width:160px;"><?php _e( 'Time', 'smid' ); ?></th>
                                <th style="width:90px;"><?php _e( 'Level', 'smid' ); ?></th>
                                <th><?php _e( 'Message', 'smid' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $entries as $e ) : ?>
                                <?php
                                $badge_color = $e['level'] === 'ERROR'
                                    ? '#d63638'
                                    : ( $e['level'] === 'SUCCESS' ? '#00a32a' : '#2271b1' );
                                ?>
                                <tr>
                                    <td style="color:#646970;font-size:12px;font-family:monospace;"><?php echo esc_html( $e['time'] ); ?></td>
                                    <td>
                                        <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo $badge_color; ?>;">
                                            <?php echo esc_html( $e['level'] ); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:13px;word-break:break-all;"><?php echo esc_html( $e['message'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="padding:8px 16px;color:#646970;font-size:12px;">
                        <?php printf( __( 'Showing %d entries (newest first).', 'smid' ), count( $entries ) ); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
