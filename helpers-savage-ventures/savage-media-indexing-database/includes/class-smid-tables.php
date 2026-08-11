<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Tables {

    // ── Get a mysqli connection from saved options ──────────────────
    private static function get_mysqli( $connection_id ) {
        $connections = get_option( 'smid_connections', array() );

        if ( ! isset( $connections[ $connection_id ] ) ) {
            return false;
        }

        $c        = $connections[ $connection_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );
        $mysqli   = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $mysqli->connect_error ) {
            return false;
        }

        return $mysqli;
    }

    // ── Admin page ─────────────────────────────────────────────────
    public static function render_page() {
        $connection_id = sanitize_key( $_GET['connection_id'] ?? '' );
        $connections   = get_option( 'smid_connections', array() );

        if ( ! $connection_id || ! isset( $connections[ $connection_id ] ) ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . __( 'Invalid connection.', 'smid' ) . '</p></div></div>';
            return;
        }

        $conn = $connections[ $connection_id ];
        ?>
        <div class="wrap smid-wrap">
            <h1 class="smid-page-title">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=smid-connections' ) ); ?>" class="smid-back-link">
                    <span class="dashicons dashicons-arrow-left-alt"></span>
                </a>
                <span class="dashicons dashicons-list-view"></span>
                <?php echo esc_html( $conn['label'] ); ?> &mdash; <?php _e( 'Tables', 'smid' ); ?>
                <span class="smid-db-badge"><?php echo esc_html( $conn['db_name'] ); ?></span>
            </h1>

            <div id="smid-tables-container" data-connection="<?php echo esc_attr( $connection_id ); ?>">
                <div class="smid-loading">
                    <span class="spinner is-active"></span> <?php _e( 'Loading tables…', 'smid' ); ?>
                </div>
            </div>

            <!-- View Data Modal -->
            <div class="smid-modal-overlay" id="smid-data-modal" style="display:none;">
                <div class="smid-modal smid-modal-xl">
                    <div class="smid-modal-header">
                        <h2 id="smid-data-modal-title"><?php _e( 'Table Data', 'smid' ); ?></h2>
                        <button class="smid-modal-close" id="smid-data-modal-close">&times;</button>
                    </div>
                    <div class="smid-modal-body smid-modal-scroll" id="smid-data-modal-body">
                        <div class="smid-loading">
                            <span class="spinner is-active"></span> <?php _e( 'Loading…', 'smid' ); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rename Modal -->
            <div class="smid-modal-overlay" id="smid-rename-modal" style="display:none;">
                <div class="smid-modal">
                    <div class="smid-modal-header">
                        <h2><?php _e( 'Rename Table', 'smid' ); ?></h2>
                        <button class="smid-modal-close smid-rename-close">&times;</button>
                    </div>
                    <div class="smid-modal-body">
                        <div class="smid-form-row">
                            <label><?php _e( 'New Table Name', 'smid' ); ?> <span class="required">*</span></label>
                            <input type="text" id="smid-rename-input" class="regular-text">
                            <input type="hidden" id="smid-rename-old">
                        </div>
                        <div class="smid-form-actions">
                            <button class="button button-primary" id="smid-rename-confirm"><?php _e( 'Rename', 'smid' ); ?></button>
                            <button class="button smid-rename-close"><?php _e( 'Cancel', 'smid' ); ?></button>
                        </div>
                        <div class="smid-form-notice" id="smid-rename-notice" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX: Get all tables ───────────────────────────────────────
    public static function ajax_get_tables() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $mysqli        = self::get_mysqli( $connection_id );

        if ( ! $mysqli ) {
            wp_send_json_error( array( 'message' => 'Could not connect to database.' ) );
        }

        $tables = array();
        $result = $mysqli->query( 'SHOW TABLE STATUS' );

        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $tables[] = array(
                    'name'    => $row['Name'],
                    'rows'    => $row['Rows'],
                    'engine'  => $row['Engine'],
                    'size'    => self::format_size( ( $row['Data_length'] + $row['Index_length'] ) ),
                    'created' => $row['Create_time'] ?? '',
                );
            }
        }

        $mysqli->close();

        wp_send_json_success( array( 'tables' => $tables ) );
    }

    // ── AJAX: View table data ──────────────────────────────────────
    public static function ajax_get_table_data() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $table         = $_POST['table_name'] ?? '';
        $page          = max( 1, intval( $_POST['page'] ?? 1 ) );
        $search_id     = intval( $_POST['search_id'] ?? 0 );   // optional: filter to single record
        $per_page      = 50;
        $offset        = ( $page - 1 ) * $per_page;

        $mysqli = self::get_mysqli( $connection_id );
        if ( ! $mysqli ) {
            wp_send_json_error( array( 'message' => 'Could not connect.' ) );
        }

        // Sanitize table name — only allow alphanumeric, underscore, dash
        $safe_table = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $table );
        if ( ! $safe_table ) {
            $mysqli->close();
            wp_send_json_error( array( 'message' => 'Invalid table name.' ) );
        }

        // Optional single-record filter (for clickable ID links from OMA list)
        $where = '';
        if ( $search_id > 0 ) {
            // Try both `id` and `sfid` column names (songfacts uses sfid)
            $cols_res = $mysqli->query( "SHOW COLUMNS FROM `{$safe_table}` LIKE 'sfid'" );
            $id_col   = ( $cols_res && $cols_res->num_rows > 0 ) ? 'sfid' : 'id';
            $where    = " WHERE `{$id_col}` = {$search_id}";
        }

        // Total rows
        $count_res  = $mysqli->query( "SELECT COUNT(*) AS total FROM `{$safe_table}`{$where}" );
        $total_rows = 0;
        if ( $count_res ) {
            $total_rows = (int) $count_res->fetch_assoc()['total'];
        }

        // Fetch rows
        $result  = $mysqli->query( "SELECT * FROM `{$safe_table}`{$where} LIMIT {$per_page} OFFSET {$offset}" );
        $rows    = array();
        $columns = array();

        if ( $result && $result->num_rows > 0 ) {
            $field_info = $result->fetch_fields();
            foreach ( $field_info as $f ) {
                $columns[] = $f->name;
            }
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = $row;
            }
        }

        $mysqli->close();

        wp_send_json_success( array(
            'table'       => $safe_table,
            'columns'     => $columns,
            'rows'        => $rows,
            'total'       => $total_rows,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_rows / $per_page ),
        ) );
    }

    // ── AJAX: Drop (delete) table ──────────────────────────────────
    public static function ajax_drop_table() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $table         = $_POST['table_name'] ?? '';
        $safe_table    = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $table );

        if ( ! $safe_table ) {
            wp_send_json_error( array( 'message' => 'Invalid table name.' ) );
        }

        $mysqli = self::get_mysqli( $connection_id );
        if ( ! $mysqli ) {
            wp_send_json_error( array( 'message' => 'Could not connect.' ) );
        }

        if ( $mysqli->query( "DROP TABLE `{$safe_table}`" ) ) {
            $mysqli->close();
        } else {
            $err = $mysqli->error;
            $mysqli->close();
            wp_send_json_error( array( 'message' => $err ) );
        }
    }

    // ── AJAX: Rename table ─────────────────────────────────────────
    public static function ajax_rename_table() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $old_name      = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $_POST['old_name'] ?? '' );
        $new_name      = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $_POST['new_name'] ?? '' );

        if ( ! $old_name || ! $new_name ) {
            wp_send_json_error( array( 'message' => 'Invalid table name.' ) );
        }

        $mysqli = self::get_mysqli( $connection_id );
        if ( ! $mysqli ) {
            wp_send_json_error( array( 'message' => 'Could not connect.' ) );
        }

        if ( $mysqli->query( "RENAME TABLE `{$old_name}` TO `{$new_name}`" ) ) {
            $mysqli->close();
            wp_send_json_success( array( 'message' => "Renamed to `{$new_name}`.", 'new_name' => $new_name ) );
        } else {
            $err = $mysqli->error;
            $mysqli->close();
            wp_send_json_error( array( 'message' => $err ) );
        }
    }

    // ── Helper: format bytes ───────────────────────────────────────
    private static function format_size( $bytes ) {
        if ( $bytes < 1024 ) return $bytes . ' B';
        if ( $bytes < 1048576 ) return round( $bytes / 1024, 1 ) . ' KB';
        return round( $bytes / 1048576, 1 ) . ' MB';
    }
}
