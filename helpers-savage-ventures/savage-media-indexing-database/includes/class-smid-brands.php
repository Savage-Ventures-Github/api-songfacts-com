<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Brands {

    // ── Admin-only check ───────────────────────────────────────────
    private static function is_admin_user() {
        return current_user_can( 'smid_access_brands' );
    }

    public static function render_page() {
        if ( ! self::is_admin_user() ) {
            wp_die( __( 'You do not have permission to access this page.', 'smid' ) );
        }

        $connections = get_option( 'smid_connections', array() );
        $settings    = get_option( 'smid_settings_brands', array() );
        $saved_conn  = $settings['connection_id'] ?? '';
        $saved_table = $settings['table'] ?? '';
        $conn_label  = '';
        $conn_db     = '';
        $configured  = $saved_conn && $saved_table && isset( $connections[ $saved_conn ] );

        if ( $configured ) {
            $conn_label = $connections[ $saved_conn ]['label'];
            $conn_db    = $connections[ $saved_conn ]['db_name'];
        }
        ?>
        <div class="wrap smid-wrap">
            <h1 class="smid-page-title">
                <span class="dashicons dashicons-tag"></span>
                <?php _e( 'Brands', 'smid' ); ?>
                <?php if ( $configured ) : ?>
                    <button class="button smid-page-title-btn" id="smid-open-brand-modal">
                        + <?php _e( 'Add New Brand', 'smid' ); ?>
                    </button>
                <?php endif; ?>
            </h1>

            <?php if ( $configured ) : ?>


                <!-- Brands List -->
                <div class="smid-card smid-no-pad" id="smid-brands-list-wrap">
                    <div class="smid-loading" style="padding:40px 0;justify-content:center;">
                        <span class="spinner is-active"></span>
                        <span style="color:#646970;"><?php _e( 'Loading brands…', 'smid' ); ?></span>
                    </div>
                </div>

            <?php else : ?>
                <!-- Not configured notice -->
                <div class="smid-notice-card">
                    <span class="dashicons dashicons-warning" style="color:#996800;font-size:24px;width:24px;height:24px;flex-shrink:0;"></span>
                    <div>
                        <strong><?php _e( 'No database connection assigned to Brands.', 'smid' ); ?></strong>
                        <p style="margin:4px 0 0;">
                            <?php _e( 'Go to', 'smid' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=smid-connections' ) ); ?>">
                                <?php _e( 'DB Connections', 'smid' ); ?>
                            </a>
                            <?php _e( 'and use the "Assign" button to link a connection to Brands.', 'smid' ); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Brand Modal ── -->
        <div class="smid-modal-overlay" id="smid-brand-modal" style="display:none;">
            <div class="smid-modal">
                <div class="smid-modal-header">
                    <h2 id="smid-brand-modal-title"><?php _e( 'Add New Brand', 'smid' ); ?></h2>
                    <button class="smid-modal-close" id="smid-brand-modal-close">&times;</button>
                </div>
                <div class="smid-modal-body">
                    <form id="smid-brand-form">
                        <input type="hidden" id="smid-brand-id" value="">
                        <div class="smid-form-row">
                            <label for="smid-brand-name-input">
                                <?php _e( 'Brand Name', 'smid' ); ?> <span class="required">*</span>
                            </label>
                            <input type="text" id="smid-brand-name-input" class="regular-text"
                                placeholder="<?php _e( 'Enter brand name', 'smid' ); ?>" required>
                        </div>
                        <div class="smid-form-actions">
                            <button type="submit" class="button button-primary" id="smid-brand-submit">
                                <?php _e( 'Add Brand', 'smid' ); ?>
                            </button>
                            <button type="button" class="button" id="smid-brand-modal-cancel">
                                <?php _e( 'Cancel', 'smid' ); ?>
                            </button>
                            <span class="smid-saving-spinner" style="display:none;">
                                <span class="spinner is-active"></span>
                            </span>
                        </div>
                        <div class="smid-form-notice" id="smid-brand-notice" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX: Get brands ───────────────────────────────────────────
    public static function ajax_get_brands() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $mysqli = self::get_form_mysqli( 'brands' );
        if ( ! $mysqli['conn'] ) {
            wp_send_json_error( array( 'message' => $mysqli['error'] ) );
        }

        $db    = $mysqli['conn'];
        $table = $mysqli['table'];
        $rows  = array();

        $result = $db->query( "SELECT * FROM `{$table}` ORDER BY id DESC" );
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = $row;
            }
        }
        $db->close();

        wp_send_json_success( array( 'brands' => $rows, 'table' => $table ) );
    }

    // ── AJAX: Save brand (create or update) ───────────────────────
    public static function ajax_save_brand() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $id   = intval( $_POST['brand_id'] ?? 0 );
        $name = trim( sanitize_text_field( $_POST['brand_name'] ?? '' ) );

        if ( ! $name ) {
            wp_send_json_error( array( 'message' => 'Brand name is required.' ) );
        }

        $mysqli = self::get_form_mysqli( 'brands' );
        if ( ! $mysqli['conn'] ) {
            wp_send_json_error( array( 'message' => $mysqli['error'] ) );
        }

        $db    = $mysqli['conn'];
        $table = $mysqli['table'];

        if ( $id > 0 ) {
            $stmt = $db->prepare( "UPDATE `{$table}` SET `name` = ? WHERE `id` = ?" );
            $stmt->bind_param( 'si', $name, $id );
        } else {
            $stmt = $db->prepare( "INSERT INTO `{$table}` (`name`) VALUES (?)" );
            $stmt->bind_param( 's', $name );
        }

        if ( $stmt->execute() ) {
            $new_id = $id > 0 ? $id : $db->insert_id;
            $stmt->close();
            $db->close();
            wp_send_json_success( array(
                'message' => $id > 0 ? 'Brand updated.' : 'Brand added.',
                'id'      => $new_id,
                'name'    => $name,
                'is_new'  => $id === 0,
            ) );
        } else {
            $err = $db->error;
            $stmt->close();
            $db->close();
            wp_send_json_error( array( 'message' => $err ) );
        }
    }

    // ── AJAX: Delete brand ─────────────────────────────────────────
    public static function ajax_delete_brand() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $id = intval( $_POST['brand_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $mysqli = self::get_form_mysqli( 'brands' );
        if ( ! $mysqli['conn'] ) {
            wp_send_json_error( array( 'message' => $mysqli['error'] ) );
        }

        $db    = $mysqli['conn'];
        $table = $mysqli['table'];

        $stmt = $db->prepare( "DELETE FROM `{$table}` WHERE `id` = ?" );
        $stmt->bind_param( 'i', $id );

        if ( $stmt->execute() ) {
            $stmt->close();
            $db->close();
            wp_send_json_success( array( 'message' => 'Brand deleted.' ) );
        } else {
            $err = $db->error;
            $stmt->close();
            $db->close();
            wp_send_json_error( array( 'message' => $err ) );
        }
    }

    // ── Helper: get mysqli from saved brand settings ───────────────
    private static function get_form_mysqli( $form_key ) {
        $settings    = get_option( 'smid_settings_' . $form_key, array() );
        $connections = get_option( 'smid_connections', array() );

        $conn_id = $settings['connection_id'] ?? '';
        $table   = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            return array( 'conn' => false, 'table' => '', 'error' => 'Settings not configured.' );
        }

        $c        = $connections[ $conn_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $db->connect_error ) {
            return array( 'conn' => false, 'table' => '', 'error' => 'DB connection failed: ' . $db->connect_error );
        }

        return array( 'conn' => $db, 'table' => $table, 'error' => '' );
    }
}
