<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Connections {

    /**
     * Derive a 256-bit encryption key from AUTH_KEY (in wp-config.php, NOT in the database).
     * If the DB is exfiltrated without wp-config.php, encrypted passwords cannot be decrypted.
     */
    private static function get_encryption_key() {
        $base = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
        if ( $base === '' ) {
            // Fallback (still better than base64, but AUTH_KEY should be defined).
            $base = defined( 'NONCE_SALT' ) ? NONCE_SALT : ( 'smid-fallback-' . get_site_url() );
        }
        return hash( 'sha256', $base . '|smid_conn_v1', true ); // 32 raw bytes
    }

    /**
     * Encrypt a connection password.
     * Output: 'smidenc:v1:' . base64( iv[12] || tag[16] || ciphertext )
     * Uses AES-256-GCM (authenticated encryption — detects tampering).
     */
    public static function encrypt_password( $plaintext ) {
        if ( $plaintext === '' || $plaintext === null ) {
            return '';
        }
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            // Fall back to legacy base64 if OpenSSL not available (preserves old behavior).
            return base64_encode( $plaintext );
        }
        $key = self::get_encryption_key();
        $iv  = random_bytes( 12 );
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16
        );
        if ( $ciphertext === false ) {
            return base64_encode( $plaintext ); // graceful fallback
        }
        return 'smidenc:v1:' . base64_encode( $iv . $tag . $ciphertext );
    }

    /**
     * Decrypt a connection password.
     * Handles BOTH the new 'smidenc:v1:' format AND legacy base64.
     * Legacy entries auto-migrate the next time they are saved.
     */
    public static function decrypt_password( $stored ) {
        if ( $stored === '' || $stored === null ) {
            return '';
        }
        // New format
        if ( strpos( $stored, 'smidenc:v1:' ) === 0 ) {
            if ( ! function_exists( 'openssl_decrypt' ) ) {
                return '';
            }
            $blob = base64_decode( substr( $stored, 11 ), true );
            if ( $blob === false || strlen( $blob ) < 28 ) {
                return '';
            }
            $iv         = substr( $blob, 0, 12 );
            $tag        = substr( $blob, 12, 16 );
            $ciphertext = substr( $blob, 28 );
            $key        = self::get_encryption_key();
            $plaintext  = openssl_decrypt(
                $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag
            );
            return $plaintext === false ? '' : $plaintext;
        }
        // Legacy format: bare base64
        $maybe = base64_decode( $stored, true );
        return $maybe === false ? '' : $maybe;
    }

    public static function is_admin_user() {
        return current_user_can( 'administrator' );
    }

    public static function render_page() {
        if ( ! self::is_admin_user() ) {
            wp_die( __( 'You do not have permission to access this page.', 'smid' ) );
        }

        $connections     = get_option( 'smid_connections', array() );
        $brands_settings = get_option( 'smid_settings_brands', array() );
        $asset_settings  = get_option( 'smid_settings_asset_categories', array() );
        $oma_settings    = get_option( 'smid_settings_online_media_assets', array() );

        $brands_conn_id    = $brands_settings['connection_id'] ?? '';
        $brands_table      = $brands_settings['table'] ?? '';
        $asset_conn_id     = $asset_settings['connection_id'] ?? '';
        $asset_table       = $asset_settings['table'] ?? '';
        $oma_conn_id       = $oma_settings['connection_id'] ?? '';
        $oma_table         = $oma_settings['table'] ?? '';
        $sf_artists_settings = get_option( 'smid_settings_songfacts_artists', array() );
        $sf_songs_settings   = get_option( 'smid_settings_songfacts_songs', array() );
        $sf_albums_settings  = get_option( 'smid_settings_songfacts_albums', array() );
        $sf_artists_conn_id  = $sf_artists_settings['connection_id'] ?? '';
        $sf_artists_table    = $sf_artists_settings['table'] ?? '';
        $sf_songs_conn_id    = $sf_songs_settings['connection_id'] ?? '';
        $sf_songs_table      = $sf_songs_settings['table'] ?? '';
        $sf_albums_conn_id   = $sf_albums_settings['connection_id'] ?? '';
        $sf_albums_table     = $sf_albums_settings['table'] ?? '';

        $brands_label      = '';
        $brands_db         = '';
        $asset_label       = '';
        $asset_db          = '';
        $oma_label         = '';
        $oma_db            = '';
        $sf_artists_label  = '';
        $sf_artists_db     = '';
        $sf_songs_label    = '';
        $sf_songs_db       = '';
        $sf_albums_label   = '';
        $sf_albums_db      = '';

        if ( $brands_conn_id && isset( $connections[ $brands_conn_id ] ) ) {
            $brands_label = $connections[ $brands_conn_id ]['label'];
            $brands_db    = $connections[ $brands_conn_id ]['db_name'];
        }
        if ( $asset_conn_id && isset( $connections[ $asset_conn_id ] ) ) {
            $asset_label = $connections[ $asset_conn_id ]['label'];
            $asset_db    = $connections[ $asset_conn_id ]['db_name'];
        }
        if ( $oma_conn_id && isset( $connections[ $oma_conn_id ] ) ) {
            $oma_label = $connections[ $oma_conn_id ]['label'];
            $oma_db    = $connections[ $oma_conn_id ]['db_name'];
        }
        if ( $sf_artists_conn_id && isset( $connections[ $sf_artists_conn_id ] ) ) {
            $sf_artists_label = $connections[ $sf_artists_conn_id ]['label'];
            $sf_artists_db    = $connections[ $sf_artists_conn_id ]['db_name'];
        }
        if ( $sf_songs_conn_id && isset( $connections[ $sf_songs_conn_id ] ) ) {
            $sf_songs_label = $connections[ $sf_songs_conn_id ]['label'];
            $sf_songs_db    = $connections[ $sf_songs_conn_id ]['db_name'];
        }
        if ( $sf_albums_conn_id && isset( $connections[ $sf_albums_conn_id ] ) ) {
            $sf_albums_label = $connections[ $sf_albums_conn_id ]['label'];
            $sf_albums_db    = $connections[ $sf_albums_conn_id ]['db_name'];
        }
        ?>
        <div class="wrap smid-wrap">

            <div class="smid-tech-alert">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong>For Technical Team Only</strong>
                    <p>This section manages live database connections and form settings. Incorrect configuration may break data saving across the plugin. Only modify these settings if you know what you are doing.</p>
                </div>
            </div>

            <h1 class="smid-page-title">
                <span class="dashicons dashicons-database"></span>
                <?php _e( 'Database Connections', 'smid' ); ?>
                <button class="button button-primary smid-btn-add" id="smid-add-connection">
                    + <?php _e( 'Add New Connection', 'smid' ); ?>
                </button>
            </h1>

            <!-- ── Connections Table ── -->
            <div class="smid-connections-list">
                <?php if ( empty( $connections ) ) : ?>
                    <div class="smid-empty-state">
                        <span class="dashicons dashicons-database-add"></span>
                        <p><?php _e( 'No database connections yet. Click "Add New Connection" to get started.', 'smid' ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="widefat smid-table">
                        <thead>
                            <tr>
                                <th><?php _e( 'Label', 'smid' ); ?></th>
                                <th><?php _e( 'Host', 'smid' ); ?></th>
                                <th><?php _e( 'Database', 'smid' ); ?></th>
                                <th><?php _e( 'Username', 'smid' ); ?></th>
                                <th><?php _e( 'Status', 'smid' ); ?></th>
                                <th><?php _e( 'Actions', 'smid' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $connections as $id => $conn ) : ?>
                                <tr data-id="<?php echo esc_attr( $id ); ?>">
                                    <td><strong><?php echo esc_html( $conn['label'] ); ?></strong></td>
                                    <td><?php echo esc_html( $conn['host'] ); ?></td>
                                    <td><?php echo esc_html( $conn['db_name'] ); ?></td>
                                    <td><?php echo esc_html( $conn['username'] ); ?></td>
                                    <td>
                                        <span class="smid-status smid-status-unknown" data-id="<?php echo esc_attr( $id ); ?>">
                                            &bull; <?php _e( 'Unknown', 'smid' ); ?>
                                        </span>
                                    </td>
                                    <td class="smid-actions">
                                        <button class="button smid-btn-test" data-id="<?php echo esc_attr( $id ); ?>">
                                            <?php _e( 'Test', 'smid' ); ?>
                                        </button>
                                        <a class="button button-primary smid-btn-tables"
                                            href="<?php echo esc_url( admin_url( 'admin.php?page=smid-tables&connection_id=' . $id ) ); ?>">
                                            <span class="dashicons dashicons-list-view" style="margin-top:3px;font-size:15px;width:15px;height:15px;"></span>
                                            <?php _e( 'View Tables', 'smid' ); ?>
                                        </a>
                                        <button class="button smid-btn-edit"
                                            data-id="<?php echo esc_attr( $id ); ?>"
                                            data-label="<?php echo esc_attr( $conn['label'] ); ?>"
                                            data-host="<?php echo esc_attr( $conn['host'] ); ?>"
                                            data-db="<?php echo esc_attr( $conn['db_name'] ); ?>"
                                            data-user="<?php echo esc_attr( $conn['username'] ); ?>">
                                            <?php _e( 'Edit', 'smid' ); ?>
                                        </button>
                                        <button class="button smid-btn-delete" data-id="<?php echo esc_attr( $id ); ?>">
                                            <?php _e( 'Delete', 'smid' ); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ── Form Settings Section ── -->
            <div class="smid-section-title" style="margin-top:32px;"><?php _e( 'Form Settings', 'smid' ); ?></div>
            <p class="description" style="margin:-4px 0 16px;"><?php _e( 'Assign a database connection and table for each form.', 'smid' ); ?></p>

            <div class="smid-form-settings-grid">

                <!-- Brands Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-tag" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Brands', 'smid' ); ?></strong>
                        <?php if ( $brands_conn_id && $brands_table ) : ?>
                            <span class="smid-badge-configured">
                                <span class="dashicons dashicons-yes-alt"></span> <?php _e( 'Configured', 'smid' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="smid-badge-missing">
                                <span class="dashicons dashicons-warning"></span> <?php _e( 'Not configured', 'smid' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="smid-fs-card-body">
                        <?php if ( $brands_conn_id && $brands_table ) : ?>
                            <!-- Configured: info row -->
                            <div id="smid-brands-info-row">
                                <table class="widefat smid-info-table">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Connection', 'smid' ); ?></th>
                                            <th><?php _e( 'Database', 'smid' ); ?></th>
                                            <th><?php _e( 'Table', 'smid' ); ?></th>
                                            <th><?php _e( 'Actions', 'smid' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php echo esc_html( $brands_label ); ?></strong></td>
                                            <td><span class="smid-db-badge"><?php echo esc_html( $brands_db ); ?></span></td>
                                            <td><code><?php echo esc_html( $brands_table ); ?></code></td>
                                            <td class="smid-actions">
                                                <button class="button smid-btn-edit-settings" data-target="#smid-brands-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                                <button class="button smid-btn-delete-settings" data-form="brands" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Edit form (hidden) -->
                            <div id="smid-brands-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <!-- Not configured: show form directly -->
                            <div id="smid-brands-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="brands" data-table-target="#smid-brands-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $brands_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-brands-table-sel" class="smid-select smid-table-selector" data-form="brands">
                                            <?php if ( $brands_table ) : ?>
                                                <option value="<?php echo esc_attr( $brands_table ); ?>" selected><?php echo esc_html( $brands_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <span class="smid-tbl-spinner spinner" style="float:none;margin:0;"></span>
                                    </div>
                                </div>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="brands" data-conn-sel=".smid-conn-selector[data-form='brands']" data-table-sel="#smid-brands-table-sel">
                                        <?php echo ( $brands_conn_id && $brands_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $brands_conn_id && $brands_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-brands-edit-form" data-info="#smid-brands-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div><!-- .smid-fs-card-body -->
                </div><!-- Brands card -->

                <!-- Asset Categories Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-category" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Asset Categories', 'smid' ); ?></strong>
                        <?php if ( $asset_conn_id && $asset_table ) : ?>
                            <span class="smid-badge-configured">
                                <span class="dashicons dashicons-yes-alt"></span> <?php _e( 'Configured', 'smid' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="smid-badge-missing">
                                <span class="dashicons dashicons-warning"></span> <?php _e( 'Not configured', 'smid' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="smid-fs-card-body">
                        <?php if ( $asset_conn_id && $asset_table ) : ?>
                            <!-- Configured: info row -->
                            <div id="smid-asset-info-row">
                                <table class="widefat smid-info-table">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Connection', 'smid' ); ?></th>
                                            <th><?php _e( 'Database', 'smid' ); ?></th>
                                            <th><?php _e( 'Table', 'smid' ); ?></th>
                                            <th><?php _e( 'Actions', 'smid' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php echo esc_html( $asset_label ); ?></strong></td>
                                            <td><span class="smid-db-badge"><?php echo esc_html( $asset_db ); ?></span></td>
                                            <td><code><?php echo esc_html( $asset_table ); ?></code></td>
                                            <td class="smid-actions">
                                                <button class="button smid-btn-edit-settings" data-target="#smid-asset-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                                <button class="button smid-btn-delete-settings" data-form="asset_categories" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Edit form (hidden) -->
                            <div id="smid-asset-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <div id="smid-asset-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="asset_categories" data-table-target="#smid-asset-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $asset_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-asset-table-sel" class="smid-select smid-table-selector" data-form="asset_categories">
                                            <?php if ( $asset_table ) : ?>
                                                <option value="<?php echo esc_attr( $asset_table ); ?>" selected><?php echo esc_html( $asset_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <span class="smid-tbl-spinner spinner" style="float:none;margin:0;"></span>
                                    </div>
                                </div>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="asset_categories" data-conn-sel=".smid-conn-selector[data-form='asset_categories']" data-table-sel="#smid-asset-table-sel">
                                        <?php echo ( $asset_conn_id && $asset_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $asset_conn_id && $asset_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-asset-edit-form" data-info="#smid-asset-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div><!-- .smid-fs-card-body -->
                </div><!-- Asset Categories card -->

                <!-- Online Media Assets Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-video-alt3" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Online Media Assets', 'smid' ); ?></strong>
                        <?php if ( $oma_conn_id && $oma_table ) : ?>
                            <span class="smid-badge-configured">
                                <span class="dashicons dashicons-yes-alt"></span> <?php _e( 'Configured', 'smid' ); ?>
                            </span>
                        <?php else : ?>
                            <span class="smid-badge-missing">
                                <span class="dashicons dashicons-warning"></span> <?php _e( 'Not configured', 'smid' ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="smid-fs-card-body">
                        <?php if ( $oma_conn_id && $oma_table ) : ?>
                            <div id="smid-oma-info-row">
                                <table class="widefat smid-info-table">
                                    <thead>
                                        <tr>
                                            <th><?php _e( 'Connection', 'smid' ); ?></th>
                                            <th><?php _e( 'Database', 'smid' ); ?></th>
                                            <th><?php _e( 'Table', 'smid' ); ?></th>
                                            <th><?php _e( 'Actions', 'smid' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php echo esc_html( $oma_label ); ?></strong></td>
                                            <td><span class="smid-db-badge"><?php echo esc_html( $oma_db ); ?></span></td>
                                            <td><code><?php echo esc_html( $oma_table ); ?></code></td>
                                            <td class="smid-actions">
                                                <button class="button smid-btn-edit-settings" data-target="#smid-oma-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                                <button class="button smid-btn-delete-settings" data-form="online_media_assets" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div id="smid-oma-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <div id="smid-oma-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="online_media_assets" data-table-target="#smid-oma-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $oma_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-oma-table-sel" class="smid-select smid-table-selector" data-form="online_media_assets">
                                            <?php if ( $oma_table ) : ?>
                                                <option value="<?php echo esc_attr( $oma_table ); ?>" selected><?php echo esc_html( $oma_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <span class="smid-tbl-spinner spinner" style="float:none;margin:0;"></span>
                                    </div>
                                </div>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="online_media_assets" data-conn-sel=".smid-conn-selector[data-form='online_media_assets']" data-table-sel="#smid-oma-table-sel">
                                        <?php echo ( $oma_conn_id && $oma_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $oma_conn_id && $oma_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-oma-edit-form" data-info="#smid-oma-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div><!-- .smid-fs-card-body -->
                </div><!-- Online Media Assets card -->

                <!-- Songfacts Artists Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-admin-users" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Songfacts — Artists', 'smid' ); ?></strong>
                        <?php if ( $sf_artists_conn_id && $sf_artists_table ) : ?>
                            <span class="smid-badge-configured"><span class="dashicons dashicons-yes-alt"></span> <?php _e( 'Configured', 'smid' ); ?></span>
                        <?php else : ?>
                            <span class="smid-badge-missing"><span class="dashicons dashicons-warning"></span> <?php _e( 'Not configured', 'smid' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="smid-fs-card-body">
                        <?php if ( $sf_artists_conn_id && $sf_artists_table ) : ?>
                            <div id="smid-sf-artists-info-row">
                                <table class="widefat smid-info-table"><thead><tr>
                                    <th><?php _e( 'Connection', 'smid' ); ?></th>
                                    <th><?php _e( 'Database', 'smid' ); ?></th>
                                    <th><?php _e( 'Table', 'smid' ); ?></th>
                                    <th><?php _e( 'Actions', 'smid' ); ?></th>
                                </tr></thead><tbody><tr>
                                    <td><strong><?php echo esc_html( $sf_artists_label ); ?></strong></td>
                                    <td><span class="smid-db-badge"><?php echo esc_html( $sf_artists_db ); ?></span></td>
                                    <td><code><?php echo esc_html( $sf_artists_table ); ?></code></td>
                                    <td class="smid-actions">
                                        <button class="button smid-btn-edit-settings" data-target="#smid-sf-artists-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                        <button class="button smid-btn-delete-settings" data-form="songfacts_artists" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                    </td>
                                </tr></tbody></table>
                            </div>
                            <div id="smid-sf-artists-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <div id="smid-sf-artists-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="songfacts_artists" data-table-target="#smid-sf-artists-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $sf_artists_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Artists Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-sf-artists-table-sel" class="smid-select smid-table-selector" data-form="songfacts_artists">
                                            <?php if ( $sf_artists_table ) : ?>
                                                <option value="<?php echo esc_attr( $sf_artists_table ); ?>" selected><?php echo esc_html( $sf_artists_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <span class="smid-tbl-spinner spinner" style="float:none;margin:0;"></span>
                                    </div>
                                </div>
                                <p class="description" style="margin:0 0 10px;font-size:12px;">
                                    <?php _e( 'Table must have <code>id</code> and <code>name</code> columns.', 'smid' ); ?>
                                </p>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="songfacts_artists" data-conn-sel=".smid-conn-selector[data-form='songfacts_artists']" data-table-sel="#smid-sf-artists-table-sel">
                                        <?php echo ( $sf_artists_conn_id && $sf_artists_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $sf_artists_conn_id && $sf_artists_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-sf-artists-edit-form" data-info="#smid-sf-artists-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div>
                </div><!-- Songfacts Artists card -->

                <!-- Songfacts Songs Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-media-audio" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Songfacts — Songs', 'smid' ); ?></strong>
                        <?php if ( $sf_songs_conn_id && $sf_songs_table ) : ?>
                            <span class="smid-badge-configured"><span class="dashicons dashicons-yes-alt"></span> <?php _e( 'Configured', 'smid' ); ?></span>
                        <?php else : ?>
                            <span class="smid-badge-missing"><span class="dashicons dashicons-warning"></span> <?php _e( 'Not configured', 'smid' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="smid-fs-card-body">
                        <?php if ( $sf_songs_conn_id && $sf_songs_table ) : ?>
                            <div id="smid-sf-songs-info-row">
                                <table class="widefat smid-info-table"><thead><tr>
                                    <th><?php _e( 'Connection', 'smid' ); ?></th>
                                    <th><?php _e( 'Database', 'smid' ); ?></th>
                                    <th><?php _e( 'Table', 'smid' ); ?></th>
                                    <th><?php _e( 'Actions', 'smid' ); ?></th>
                                </tr></thead><tbody><tr>
                                    <td><strong><?php echo esc_html( $sf_songs_label ); ?></strong></td>
                                    <td><span class="smid-db-badge"><?php echo esc_html( $sf_songs_db ); ?></span></td>
                                    <td><code><?php echo esc_html( $sf_songs_table ); ?></code></td>
                                    <td class="smid-actions">
                                        <button class="button smid-btn-edit-settings" data-target="#smid-sf-songs-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                        <button class="button smid-btn-delete-settings" data-form="songfacts_songs" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                    </td>
                                </tr></tbody></table>
                            </div>
                            <div id="smid-sf-songs-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <div id="smid-sf-songs-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="songfacts_songs" data-table-target="#smid-sf-songs-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $sf_songs_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Songs Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-sf-songs-table-sel" class="smid-select smid-table-selector" data-form="songfacts_songs">
                                            <?php if ( $sf_songs_table ) : ?>
                                                <option value="<?php echo esc_attr( $sf_songs_table ); ?>" selected><?php echo esc_html( $sf_songs_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <span class="smid-tbl-spinner spinner" style="float:none;margin:0;"></span>
                                    </div>
                                </div>
                                <p class="description" style="margin:0 0 10px;font-size:12px;">
                                    <?php _e( 'Table must have <code>id</code>, <code>artist_id</code>, and <code>title</code> columns.', 'smid' ); ?>
                                </p>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="songfacts_songs" data-conn-sel=".smid-conn-selector[data-form='songfacts_songs']" data-table-sel="#smid-sf-songs-table-sel">
                                        <?php echo ( $sf_songs_conn_id && $sf_songs_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $sf_songs_conn_id && $sf_songs_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-sf-songs-edit-form" data-info="#smid-sf-songs-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div>
                </div><!-- Songfacts Songs card -->

                <!-- Songfacts Albums Settings Card -->
                <div class="smid-card smid-no-pad smid-fs-card">
                    <div class="smid-fs-card-header">
                        <span class="dashicons dashicons-album" style="color:#2271b1;"></span>
                        <strong><?php _e( 'Songfacts — Albums', 'smid' ); ?></strong>
                        <?php if ( $sf_albums_conn_id && $sf_albums_table ) : ?>
                            <span class="smid-badge-configured"><?php _e( 'Configured', 'smid' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="padding:16px;">
                        <?php if ( $sf_albums_conn_id && $sf_albums_table ) : ?>
                            <div id="smid-sf-albums-info-row">
                                <table class="wp-list-table widefat fixed" style="border:none;margin-bottom:10px;"><thead><tr>
                                    <th><?php _e( 'Connection', 'smid' ); ?></th>
                                    <th><?php _e( 'Database', 'smid' ); ?></th>
                                    <th><?php _e( 'Table', 'smid' ); ?></th>
                                    <th><?php _e( 'Actions', 'smid' ); ?></th>
                                </tr></thead><tbody><tr>
                                    <td><strong><?php echo esc_html( $sf_albums_label ); ?></strong></td>
                                    <td><span class="smid-db-badge"><?php echo esc_html( $sf_albums_db ); ?></span></td>
                                    <td><code><?php echo esc_html( $sf_albums_table ); ?></code></td>
                                    <td class="smid-actions">
                                        <button class="button smid-btn-edit-settings" data-target="#smid-sf-albums-edit-form"><?php _e( 'Edit', 'smid' ); ?></button>
                                        <button class="button smid-btn-delete-settings" data-form="songfacts_albums" style="color:#d63638;border-color:#d63638;"><?php _e( 'Disconnect', 'smid' ); ?></button>
                                    </td>
                                </tr></tbody></table>
                            </div>
                            <div id="smid-sf-albums-edit-form" class="smid-fs-edit-form" style="display:none;">
                        <?php else : ?>
                            <div id="smid-sf-albums-edit-form" class="smid-fs-edit-form">
                        <?php endif; ?>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Connection', 'smid' ); ?> <span class="required">*</span></label>
                                    <select class="smid-select smid-conn-selector" data-form="songfacts_albums" data-table-target="#smid-sf-albums-table-sel">
                                        <option value=""><?php _e( '— Select Connection —', 'smid' ); ?></option>
                                        <?php foreach ( $connections as $id => $conn ) : ?>
                                            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $sf_albums_conn_id, $id ); ?>>
                                                <?php echo esc_html( $conn['label'] ); ?> (<?php echo esc_html( $conn['db_name'] ); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="smid-form-row" style="margin-bottom:14px;">
                                    <label><?php _e( 'Albums Table', 'smid' ); ?> <span class="required">*</span></label>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <select id="smid-sf-albums-table-sel" class="smid-select smid-table-selector" data-form="songfacts_albums">
                                            <?php if ( $sf_albums_table ) : ?>
                                                <option value="<?php echo esc_attr( $sf_albums_table ); ?>" selected><?php echo esc_html( $sf_albums_table ); ?></option>
                                            <?php else : ?>
                                                <option value=""><?php _e( '— Select Connection First —', 'smid' ); ?></option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <p class="description" style="margin:0 0 10px;font-size:12px;">
                                    <?php _e( 'Table must have <code>id</code>, <code>artistid</code>, and <code>title</code> columns.', 'smid' ); ?>
                                </p>
                                <div class="smid-form-actions" style="border-top:none;padding-top:0;margin-top:8px;">
                                    <button class="button button-primary smid-save-settings" data-form="songfacts_albums" data-conn-sel=".smid-conn-selector[data-form='songfacts_albums']" data-table-sel="#smid-sf-albums-table-sel">
                                        <?php echo ( $sf_albums_conn_id && $sf_albums_table ) ? __( 'Update', 'smid' ) : __( 'Save Settings', 'smid' ); ?>
                                    </button>
                                    <?php if ( $sf_albums_conn_id && $sf_albums_table ) : ?>
                                        <button class="button smid-cancel-edit-settings" data-target="#smid-sf-albums-edit-form" data-info="#smid-sf-albums-info-row"><?php _e( 'Cancel', 'smid' ); ?></button>
                                    <?php endif; ?>
                                    <span class="smid-settings-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
                                </div>
                            </div>
                    </div>
                </div><!-- Songfacts Albums card -->

            </div><!-- .smid-form-settings-grid -->

        </div><!-- .smid-wrap -->

        <!-- ── Add/Edit Connection Modal ── -->
        <div class="smid-modal-overlay" id="smid-modal-overlay" style="display:none;">
            <div class="smid-modal">
                <div class="smid-modal-header">
                    <h2 id="smid-modal-title"><?php _e( 'Add New Connection', 'smid' ); ?></h2>
                    <button class="smid-modal-close" id="smid-modal-close">&times;</button>
                </div>
                <div class="smid-modal-body">
                    <form id="smid-connection-form">
                        <input type="hidden" id="smid-connection-id" name="connection_id" value="">
                        <div class="smid-form-row">
                            <label for="smid-label"><?php _e( 'Connection Label', 'smid' ); ?> <span class="required">*</span></label>
                            <input type="text" id="smid-label" name="label" placeholder="<?php _e( 'e.g. Production Server', 'smid' ); ?>" required>
                            <p class="description"><?php _e( 'A friendly name to identify this connection.', 'smid' ); ?></p>
                        </div>
                        <div class="smid-form-row">
                            <label for="smid-host"><?php _e( 'Host', 'smid' ); ?> <span class="required">*</span></label>
                            <input type="text" id="smid-host" name="host" placeholder="<?php _e( 'e.g. 127.0.0.1 or db.example.com', 'smid' ); ?>" required>
                        </div>
                        <div class="smid-form-row">
                            <label for="smid-db-name"><?php _e( 'Database Name', 'smid' ); ?> <span class="required">*</span></label>
                            <input type="text" id="smid-db-name" name="db_name" placeholder="<?php _e( 'e.g. my_database', 'smid' ); ?>" required>
                        </div>
                        <div class="smid-form-row">
                            <label for="smid-username"><?php _e( 'Username', 'smid' ); ?> <span class="required">*</span></label>
                            <input type="text" id="smid-username" name="username" placeholder="<?php _e( 'Database username', 'smid' ); ?>" required>
                        </div>
                        <div class="smid-form-row">
                            <label for="smid-password"><?php _e( 'Password', 'smid' ); ?></label>
                            <div class="smid-password-wrap">
                                <input type="password" id="smid-password" name="password" placeholder="<?php _e( 'Database password', 'smid' ); ?>">
                                <button type="button" class="smid-toggle-password" tabindex="-1">
                                    <span class="dashicons dashicons-visibility"></span>
                                </button>
                            </div>
                            <p class="description smid-edit-note" style="display:none;"><?php _e( 'Leave blank to keep the existing password.', 'smid' ); ?></p>
                        </div>
                        <div class="smid-form-actions">
                            <button type="submit" class="button button-primary" id="smid-save-btn"><?php _e( 'Save Connection', 'smid' ); ?></button>
                            <button type="button" class="button" id="smid-cancel-btn"><?php _e( 'Cancel', 'smid' ); ?></button>
                            <span class="smid-saving-spinner" style="display:none;">
                                <span class="spinner is-active"></span> <?php _e( 'Saving...', 'smid' ); ?>
                            </span>
                        </div>
                        <div class="smid-form-notice" id="smid-form-notice" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajax_save_connection() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'smid' ) ) );
        }

        $id       = sanitize_key( $_POST['connection_id'] ?? '' );
        $label    = sanitize_text_field( $_POST['label'] ?? '' );
        $host     = sanitize_text_field( $_POST['host'] ?? '' );
        $db_name  = sanitize_text_field( $_POST['db_name'] ?? '' );
        $username = sanitize_text_field( $_POST['username'] ?? '' );
        $password = $_POST['password'] ?? '';

        if ( ! $label || ! $host || ! $db_name || ! $username ) {
            wp_send_json_error( array( 'message' => __( 'Please fill all required fields.', 'smid' ) ) );
        }

        $connections   = get_option( 'smid_connections', array() );
        if ( ! $id ) { $id = 'conn_' . uniqid(); }
        $existing_pass = $connections[ $id ]['password'] ?? '';

        $connections[ $id ] = array(
            'label'    => $label,
            'host'     => $host,
            'db_name'  => $db_name,
            'username' => $username,
            'password' => $password !== '' ? self::encrypt_password( $password ) : $existing_pass,
        );

        update_option( 'smid_connections', $connections );
        wp_send_json_success( array(
            'message' => __( 'Connection saved successfully.', 'smid' ),
            'id'      => $id,
            'conn'    => array( 'label' => $label, 'host' => $host, 'db_name' => $db_name, 'username' => $username ),
        ) );
    }

    public static function ajax_delete_connection() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'smid' ) ) );
        }

        $id = sanitize_key( $_POST['connection_id'] ?? '' );
        if ( ! $id ) { wp_send_json_error( array( 'message' => __( 'Invalid connection ID.', 'smid' ) ) ); }

        $connections = get_option( 'smid_connections', array() );
        unset( $connections[ $id ] );
        update_option( 'smid_connections', $connections );
        wp_send_json_success( array( 'message' => __( 'Connection deleted.', 'smid' ) ) );
    }

    public static function ajax_test_connection() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'smid' ) ) );
        }

        $id          = sanitize_key( $_POST['connection_id'] ?? '' );
        $connections = get_option( 'smid_connections', array() );

        if ( ! $id || ! isset( $connections[ $id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Connection not found.', 'smid' ) ) );
        }

        $conn = $connections[ $id ];

        // Build a diagnostic dump so the failure response shows what was attempted
        // and from which source IP (helps when remote MySQL needs an IP whitelisted).
        // Password is intentionally omitted.
        $diagnostics = array(
            'attempt_time_utc' => gmdate( 'c' ),
            'remote_host'      => isset( $conn['host'] ) ? $conn['host'] : null,
            'remote_port'      => isset( $conn['port'] ) ? $conn['port'] : 3306,
            'remote_db'        => isset( $conn['db_name'] ) ? $conn['db_name'] : null,
            'remote_user'      => isset( $conn['username'] ) ? $conn['username'] : null,
            'server_hostname'  => function_exists( 'gethostname' ) ? gethostname() : null,
            'server_addr'      => isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : null,
            'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null,
            'outbound_ip'      => null,
        );

        // Detect outbound public IP (what the remote MySQL host sees in its access logs).
        $ip_response = wp_remote_get(
            'https://api.ipify.org',
            array( 'timeout' => 5, 'sslverify' => true )
        );
        if ( ! is_wp_error( $ip_response ) && 200 === (int) wp_remote_retrieve_response_code( $ip_response ) ) {
            $diagnostics['outbound_ip'] = trim( wp_remote_retrieve_body( $ip_response ) );
        } elseif ( is_wp_error( $ip_response ) ) {
            $diagnostics['outbound_ip_lookup_error'] = $ip_response->get_error_message();
        }

        mysqli_report( MYSQLI_REPORT_OFF );
        $mysqli = @new mysqli( $conn['host'], $conn['username'], self::decrypt_password( $conn['password'] ), $conn['db_name'] );

        if ( $mysqli->connect_error ) {
            $diagnostics['mysql_error'] = $mysqli->connect_error;
            $diagnostics['mysql_errno'] = $mysqli->connect_errno;
            wp_send_json_error( array(
                'message'     => $mysqli->connect_error,
                'diagnostics' => $diagnostics,
            ) );
        }

        $mysqli->close();
        wp_send_json_success( array( 'message' => __( 'Connection successful!', 'smid' ) ) );
    }
}
