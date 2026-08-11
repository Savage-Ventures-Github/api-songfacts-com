<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SMID_Online_Media_Assets {

    private static function is_admin_user() {
        return current_user_can( 'smid_access_oma' );
    }

    public static function render_page() {
        if ( ! self::is_admin_user() ) {
            wp_die( __( 'You do not have permission to access this page.', 'smid' ) );
        }

        $connections = get_option( 'smid_connections', array() );
        $settings    = get_option( 'smid_settings_online_media_assets', array() );
        $saved_conn  = $settings['connection_id'] ?? '';
        $saved_table = $settings['table'] ?? '';
        $configured  = $saved_conn && $saved_table && isset( $connections[ $saved_conn ] );
        $conn_label  = '';
        $conn_db     = '';

        if ( $configured ) {
            $conn_label = $connections[ $saved_conn ]['label'];
            $conn_db    = $connections[ $saved_conn ]['db_name'];
        }
        ?>
        <div class="wrap smid-wrap">
            <h1 class="smid-page-title">
                <span class="dashicons dashicons-video-alt3"></span>
                <?php _e( 'Online Media Assets', 'smid' ); ?>
                <?php if ( $configured ) : ?>
                    <button class="button smid-page-title-btn" id="smid-open-oma-modal">
                        + <?php _e( 'Add New Asset', 'smid' ); ?>
                    </button>
                <?php endif; ?>
            </h1>

            <?php if ( $configured ) : ?>

                <div class="smid-card smid-no-pad" id="smid-oma-list-wrap">
                    <div class="smid-loading" style="padding:40px 0;justify-content:center;">
                        <span class="spinner is-active"></span>
                        <span style="color:#646970;"><?php _e( 'Loading assets…', 'smid' ); ?></span>
                    </div>
                </div>
            <?php else : ?>
                <div class="smid-notice-card">
                    <span class="dashicons dashicons-warning" style="color:#996800;font-size:24px;width:24px;height:24px;flex-shrink:0;"></span>
                    <div>
                        <strong><?php _e( 'No database connection assigned to Online Media Assets.', 'smid' ); ?></strong>
                        <p style="margin:4px 0 0;">
                            <?php _e( 'Go to', 'smid' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=smid-connections' ) ); ?>">
                                <?php _e( 'DB Connections', 'smid' ); ?>
                            </a>
                            <?php _e( 'and configure the Online Media Assets form settings.', 'smid' ); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── View Details Modal ── -->
        <div class="smid-modal-overlay smid-view-modal-overlay" id="smid-view-modal" style="display:none;">
            <div class="smid-modal smid-view-modal">
                <div class="smid-modal-header">
                    <h2 id="smid-view-modal-title"><?php _e( 'Asset Details', 'smid' ); ?></h2>
                    <button class="smid-modal-close" id="smid-view-modal-close">&times;</button>
                </div>
                <div class="smid-modal-body" id="smid-view-modal-body"></div>
            </div>
        </div>

        <!-- ── Add/Edit Asset Modal ── -->
        <div class="smid-modal-overlay smid-oma-modal-overlay" id="smid-oma-modal" style="display:none;">
            <div class="smid-modal smid-oma-modal">
                <div class="smid-modal-header">
                    <h2 id="smid-oma-modal-title"><?php _e( 'Add New Asset', 'smid' ); ?></h2>
                    <button class="smid-modal-close" id="smid-oma-modal-close">&times;</button>
                </div>
                <div class="smid-modal-body">
                    <form id="smid-oma-form">
                        <input type="hidden" id="smid-oma-id" value="">

                        <div class="smid-oma-grid">

                            <!-- Asset Title -->
                            <div class="smid-form-row smid-oma-full">
                                <label for="smid-oma-title">
                                    <?php _e( 'Asset Title', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <input type="text" id="smid-oma-title" class="regular-text"
                                    placeholder="<?php _e( 'Enter a title for this asset…', 'smid' ); ?>" style="width:100%;">
                            </div>

                            <!-- Public URL -->
                            <div class="smid-form-row smid-oma-full">
                                <label for="smid-oma-url">
                                    <span class="dashicons dashicons-admin-links"></span>
                                    <?php _e( 'Public URL', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <input type="text" id="smid-oma-url" class="regular-text"
                                    placeholder="https://youtube.com/watch?v=… or www.example.com" required style="width:100%;">
                                <p class="description"><?php _e( 'YouTube, Podcast, or other public media link.', 'smid' ); ?></p>
                            </div>

                            <!-- Brand (3 cols) -->
                            <div class="smid-form-row smid-oma-span-3">
                                <label for="smid-oma-brand">
                                    <span class="dashicons dashicons-tag"></span>
                                    <?php _e( 'Brand', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <select id="smid-oma-brand" class="smid-select2" style="width:100%;">
                                    <option value=""><?php _e( 'Search brand…', 'smid' ); ?></option>
                                </select>
                            </div>

                            <!-- Asset Category (3 cols) -->
                            <div class="smid-form-row smid-oma-span-3">
                                <label for="smid-oma-category">
                                    <span class="dashicons dashicons-category"></span>
                                    <?php _e( 'Asset Category', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <select id="smid-oma-category" class="smid-select2" style="width:100%;">
                                    <option value=""><?php _e( 'Search category…', 'smid' ); ?></option>
                                </select>
                            </div>

                            <!-- Source (2 cols) -->
                            <div class="smid-form-row smid-oma-span-2">
                                <label for="smid-oma-source">
                                    <span class="dashicons dashicons-microphone"></span>
                                    <?php _e( 'Source', 'smid' ); ?>
                                </label>
                                <select id="smid-oma-source" class="smid-select2" style="width:100%;">
                                    <option value="">— <?php _e( 'Select source', 'smid' ); ?> —</option>
                                    <option value="Studio Interview"><?php _e( 'Studio Interview', 'smid' ); ?></option>
                                    <option value="Field Interview"><?php _e( 'Field Interview', 'smid' ); ?></option>
                                    <option value="Video Podcast"><?php _e( 'Video Podcast', 'smid' ); ?></option>
                                    <option value="Audio Podcast"><?php _e( 'Audio Podcast', 'smid' ); ?></option>
                                    <option value="Phone Interview"><?php _e( 'Phone Interview', 'smid' ); ?></option>
                                    <option value="Email Interview"><?php _e( 'Email Interview', 'smid' ); ?></option>
                                </select>
                            </div>

                            <!-- Useable Media (2 cols) -->
                            <div class="smid-form-row smid-oma-span-2">
                                <label for="smid-oma-useable-media">
                                    <span class="dashicons dashicons-controls-play"></span>
                                    <?php _e( 'Useable Media', 'smid' ); ?>
                                </label>
                                <select id="smid-oma-useable-media" class="smid-select2" style="width:100%;">
                                    <option value="">— <?php _e( 'Select media type', 'smid' ); ?> —</option>
                                    <option value="Text"><?php _e( 'Text', 'smid' ); ?></option>
                                    <option value="Audio"><?php _e( 'Audio', 'smid' ); ?></option>
                                    <option value="Video"><?php _e( 'Video', 'smid' ); ?></option>
                                </select>
                            </div>

                            <!-- Published Date (2 cols) -->
                            <div class="smid-form-row smid-oma-span-2">
                                <label for="smid-oma-pubdate">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <?php _e( 'Published Date', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <input type="date" id="smid-oma-pubdate" class="regular-text" style="width:100%;">
                            </div>

                            <!-- Artist(s) + Song(s) dynamic pairs — full width -->
                            <div class="smid-form-row smid-oma-full smid-pairs-section">
                                <label class="smid-pairs-section-label">
                                    <span class="dashicons dashicons-format-audio"></span>
                                    <?php _e( 'Artist / Song / Album', 'smid' ); ?>
                                </label>
                                <div id="smid-oma-pairs"></div>
                                <button type="button" id="smid-add-pair-btn">
                                    <span class="dashicons dashicons-plus-alt2"></span>
                                    <?php _e( 'Add Another Row', 'smid' ); ?>
                                </button>
                            </div>

                            <!-- Full Text Transcript -->
                            <div class="smid-form-row smid-oma-full">
                                <label for="smid-oma-full-text-transcript">
                                    <span class="dashicons dashicons-editor-alignleft"></span>
                                    <?php _e( 'Full Text Transcript', 'smid' ); ?>
                                </label>
                                <textarea id="smid-oma-full-text-transcript" class="large-text" rows="6"
                                    placeholder="<?php _e( 'Paste the full cleaned-up transcript of the clip here…', 'smid' ); ?>"
                                    style="width:100%;resize:vertical;"></textarea>
                            </div>

                            <!-- Select Quote -->
                            <div class="smid-form-row smid-oma-full">
                                <label for="smid-oma-select-quote-selection">
                                    <span class="dashicons dashicons-format-quote"></span>
                                    <?php _e( 'Select Quote', 'smid' ); ?>
                                </label>
                                <textarea id="smid-oma-select-quote-selection" class="large-text smid-quote-textarea" rows="3"
                                    maxlength="280"
                                    placeholder="<?php _e( 'A short quote from the transcript (max 280 characters)…', 'smid' ); ?>"
                                    style="width:100%;resize:vertical;"></textarea>
                                <div class="smid-char-counter">
                                    <span id="smid-quote-char-count">0</span> / 280
                                </div>
                                <p class="description"><?php _e( 'Keep under 280 characters for social media use.', 'smid' ); ?></p>
                            </div>

                            <!-- Keywords with autosuggest -->
                            <div class="smid-form-row smid-oma-full">
                                <label>
                                    <span class="dashicons dashicons-tag"></span>
                                    <?php _e( 'Keywords', 'smid' ); ?> <span class="smid-required">*</span>
                                </label>
                                <div class="smid-tag-input-wrap" id="smid-tag-wrap">
                                    <div class="smid-tags-container" id="smid-tags-container"></div>
                                    <input type="text" id="smid-tag-input" class="smid-tag-text"
                                        placeholder="<?php _e( 'Type a keyword and press Enter…', 'smid' ); ?>"
                                        autocomplete="off">
                                    <div class="smid-kw-suggestions" id="smid-kw-suggestions"></div>
                                </div>
                                <input type="hidden" id="smid-oma-keywords" value="">
                                <p class="description"><?php _e( 'Type to see suggestions or press Enter to add a new keyword.', 'smid' ); ?></p>
                            </div>

                        </div><!-- .smid-oma-grid -->

                        <div class="smid-form-actions">
                            <button type="submit" class="button button-primary" id="smid-oma-submit">
                                <?php _e( 'Add Asset', 'smid' ); ?>
                            </button>
                            <button type="button" class="button" id="smid-oma-modal-cancel">
                                <?php _e( 'Cancel', 'smid' ); ?>
                            </button>
                            <span class="smid-saving-spinner" style="display:none;">
                                <span class="spinner is-active"></span>
                            </span>
                        </div>
                        <div class="smid-form-notice" id="smid-oma-notice" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX: Get records ──────────────────────────────────────────
    public static function ajax_get_records() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $mysqli = self::get_form_mysqli();
        if ( ! $mysqli['conn'] ) {
            wp_send_json_error( array( 'message' => $mysqli['error'] ) );
        }

        $db          = $mysqli['conn'];
        $table       = $mysqli['table'];
        $pairs_table = 'sv_artist_name_song_title_index';
        $rows        = array();

        // JOIN with pairs table — fetch each pair row individually to preserve album
        $result = $db->query(
            "SELECT m.*,
                p.artist_id, p.song_id, p.album_id AS pair_album_id, p.album_name AS pair_album_name
             FROM `{$table}` m
             LEFT JOIN `{$pairs_table}` p ON p.sv_media_index_id = m.id
             ORDER BY m.id DESC, p.artist_id ASC, p.song_id ASC"
        );
        $records_map = array();
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $mid = $row['id'];
                if ( ! isset( $records_map[ $mid ] ) ) {
                    $records_map[ $mid ] = $row;
                    $records_map[ $mid ]['pairs']      = array();
                    $records_map[ $mid ]['artist_ids'] = array();
                    $records_map[ $mid ]['song_ids']   = array();
                    unset( $records_map[ $mid ]['artist_id'], $records_map[ $mid ]['song_id'],
                           $records_map[ $mid ]['pair_album_id'], $records_map[ $mid ]['pair_album_name'] );
                }
                if ( ! empty( $row['artist_id'] ) ) {
                    $records_map[ $mid ]['pairs'][] = array(
                        'aId'       => intval( $row['artist_id'] ),
                        'sId'       => intval( $row['song_id'] ),
                        'sName'     => '',
                        'albumId'   => intval( $row['pair_album_id'] ?? 0 ),
                        'albumName' => $row['pair_album_name'] ?? '',
                    );
                    $records_map[ $mid ]['artist_ids'][] = intval( $row['artist_id'] );
                    $records_map[ $mid ]['song_ids'][]   = intval( $row['song_id'] );
                }
            }
        }
        $rows = array_values( $records_map );
        $db->close();

        // Enrich pairs with song titles from songfacts songs table
        if ( ! empty( $rows ) ) {
            $all_song_ids = array();
            foreach ( $rows as $row ) {
                foreach ( $row['pairs'] as $pair ) {
                    if ( $pair['sId'] ) $all_song_ids[] = $pair['sId'];
                }
            }
            $all_song_ids = array_values( array_unique( array_filter( $all_song_ids ) ) );
            if ( ! empty( $all_song_ids ) ) {
                $song_titles = self::fetch_song_titles( $all_song_ids );
                foreach ( $rows as &$row ) {
                    foreach ( $row['pairs'] as &$pair ) {
                        $pair['sName'] = isset( $song_titles[ $pair['sId'] ] ) ? $song_titles[ $pair['sId'] ] : '';
                    }
                    unset( $pair );
                }
                unset( $row );
            }
        }

        // Include brands + categories + artists so the list table always has names available
        $brands     = self::fetch_options( 'brands' );
        $categories = self::fetch_options( 'asset_categories' );
        $artists    = self::fetch_songfacts_artists();

        wp_send_json_success( array(
            'records'    => $rows,
            'brands'     => $brands,
            'categories' => $categories,
            'artists'    => $artists,
        ) );
    }

    // ── Helper: fetch song titles by IDs from songfacts songs table ──
    private static function fetch_song_titles( array $song_ids ) {
        $settings    = get_option( 'smid_settings_songfacts_songs', array() );
        $connections = get_option( 'smid_connections', array() );
        $conn_id     = $settings['connection_id'] ?? '';
        $table       = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            return array();
        }

        $c        = $connections[ $conn_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );
        if ( $db->connect_error ) {
            return array();
        }

        $ids_escaped = implode( ',', array_map( 'intval', $song_ids ) );
        $result      = $db->query( "SELECT sfid, title FROM `{$table}` WHERE sfid IN ({$ids_escaped})" );
        $map         = array();
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $map[ $row['sfid'] ] = $row['title'];
            }
        }
        $db->close();
        return $map;
    }

    // ── AJAX: Get form data (brands + categories + artists) ────────
    public static function ajax_get_form_data() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $brands     = self::fetch_options( 'brands' );
        $categories = self::fetch_options( 'asset_categories' );
        $artists    = self::fetch_songfacts_artists();

        $sf_artists_settings = get_option( 'smid_settings_songfacts_artists', array() );
        $sf_songs_settings   = get_option( 'smid_settings_songfacts_songs',   array() );

        wp_send_json_success( array(
            'brands'           => $brands,
            'categories'       => $categories,
            'artists'          => $artists,
            'sf_artists_conn'  => $sf_artists_settings['connection_id'] ?? '',
            'sf_artists_table' => $sf_artists_settings['table']         ?? '',
            'sf_songs_conn'    => $sf_songs_settings['connection_id']   ?? '',
            'sf_songs_table'   => $sf_songs_settings['table']           ?? '',
        ) );
    }

    // ── AJAX: Get songs by artist_id ───────────────────────────────
    public static function ajax_get_songs_by_artist() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $artist_id = intval( $_POST['artist_id'] ?? 0 );
        if ( ! $artist_id ) {
            wp_send_json_error( array( 'message' => 'Invalid artist ID.' ) );
        }

        $settings    = get_option( 'smid_settings_songfacts_songs', array() );
        $connections = get_option( 'smid_connections', array() );
        $conn_id     = $settings['connection_id'] ?? '';
        $table       = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            wp_send_json_error( array( 'message' => 'Songfacts songs not configured.' ) );
        }

        $c        = $connections[ $conn_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $db->connect_error ) {
            wp_send_json_error( array( 'message' => 'DB error: ' . $db->connect_error ) );
        }

        $rows = array();
        $stmt = $db->prepare( "SELECT sfid AS id, title, album FROM `{$table}` WHERE `artistid` = ? ORDER BY title ASC" );
        if ( $stmt ) {
            $stmt->bind_param( 'i', $artist_id );
            $stmt->execute();
            $result = $stmt->get_result();
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = array(
                    'id'    => $row['id'],
                    'name'  => $row['title'],
                    'album' => $row['album'] ?? '',
                );
            }
            $stmt->close();
        }
        $db->close();

        wp_send_json_success( array( 'songs' => $rows ) );
    }

    // ── AJAX: Get albums by artist IDs ─────────────────────────────
    public static function ajax_get_albums_by_artist() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $artist_ids_raw = wp_unslash( $_POST['artist_ids'] ?? '' );
        $artist_ids     = array_filter( array_map( 'intval', explode( ',', $artist_ids_raw ) ) );
        if ( empty( $artist_ids ) ) {
            wp_send_json_success( array( 'albums' => array() ) );
        }

        $settings    = get_option( 'smid_settings_songfacts_albums', array() );
        $connections = get_option( 'smid_connections', array() );
        $conn_id     = $settings['connection_id'] ?? '';
        $table       = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            wp_send_json_error( array( 'message' => 'Songfacts albums not configured.' ) );
        }

        $c        = $connections[ $conn_id ];
        $password = base64_decode( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );
        if ( $db->connect_error ) {
            wp_send_json_error( array( 'message' => 'DB error: ' . $db->connect_error ) );
        }

        $ids_str = implode( ',', $artist_ids );
        $result  = $db->query(
            "SELECT MIN(`sfid`) AS id, `album` AS name
             FROM `{$table}`
             WHERE `artistid` IN ({$ids_str}) AND `album` IS NOT NULL AND `album` != ''
             GROUP BY `album`
             ORDER BY `album` ASC"
        );
        $rows    = array();
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = array( 'id' => intval( $row['id'] ), 'name' => $row['name'] );
            }
        }
        $db->close();
        wp_send_json_success( array( 'albums' => $rows ) );
    }

    // ── AJAX: Get existing keywords from OMA table ─────────────────
    public static function ajax_get_existing_keywords() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $mysqli = self::get_form_mysqli();
        if ( ! $mysqli['conn'] ) {
            wp_send_json_success( array( 'keywords' => array() ) );
        }

        $db    = $mysqli['conn'];
        $table = $mysqli['table'];

        $result  = $db->query( "SELECT keywords FROM `{$table}` WHERE keywords IS NOT NULL AND keywords != '[]'" );
        $all_kws = array();
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $arr = json_decode( $row['keywords'], true );
                if ( is_array( $arr ) ) {
                    foreach ( $arr as $kw ) {
                        $kw = trim( $kw );
                        if ( $kw ) $all_kws[] = strtolower( $kw );
                    }
                }
            }
        }
        $db->close();

        $unique = array_values( array_unique( $all_kws ) );
        sort( $unique );
        wp_send_json_success( array( 'keywords' => $unique ) );
    }

    // ── AJAX: Save record ──────────────────────────────────────────
    public static function ajax_save_record() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $id            = intval( $_POST['oma_id'] ?? 0 );
        $asset_title   = sanitize_text_field( wp_unslash( $_POST['asset_title'] ?? '' ) );
        $public_url    = trim( wp_unslash( $_POST['public_url'] ?? '' ) );
        $brand_id      = intval( $_POST['brand_id'] ?? 0 );
        $cat_id        = intval( $_POST['cat_id'] ?? 0 );
        $pub_date      = sanitize_text_field( $_POST['pub_date'] ?? '' );
        $source                 = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
        $useable_media          = sanitize_text_field( wp_unslash( $_POST['useable_media'] ?? '' ) );
        $full_text_transcript   = wp_kses_post( wp_unslash( $_POST['full_text_transcript'] ?? '' ) );
        $select_quote_selection = sanitize_text_field( wp_unslash( $_POST['select_quote_selection'] ?? '' ) );
        // Enforce 280-char limit on quote
        if ( mb_strlen( $select_quote_selection ) > 280 ) {
            $select_quote_selection = mb_substr( $select_quote_selection, 0, 280 );
        }

        // pairs: JSON array of {artistId, artistName, songId, songName, albumName}
        $pairs_raw = wp_unslash( $_POST['pairs'] ?? '[]' );
        $pairs_arr = json_decode( $pairs_raw, true );
        if ( ! is_array( $pairs_arr ) ) $pairs_arr = array();

        // Backward compat flat arrays
        $artist_ids_raw = wp_unslash( $_POST['artist_ids'] ?? '[]' );
        $artist_ids_arr = json_decode( $artist_ids_raw, true );
        if ( ! is_array( $artist_ids_arr ) ) $artist_ids_arr = array();

        $song_ids_raw = wp_unslash( $_POST['song_ids'] ?? '[]' );
        $song_ids_arr = json_decode( $song_ids_raw, true );
        if ( ! is_array( $song_ids_arr ) ) $song_ids_arr = array();

        $keywords_raw = wp_unslash( $_POST['keywords'] ?? '[]' );
        $keywords_arr = json_decode( $keywords_raw, true );
        if ( ! is_array( $keywords_arr ) ) $keywords_arr = array();
        $keywords_arr = array_map( 'sanitize_text_field', $keywords_arr );
        $keywords     = wp_json_encode( $keywords_arr );

        if ( ! $public_url ) {
            wp_send_json_error( array( 'message' => 'Public URL is required.' ) );
        }
        if ( ! $brand_id ) {
            wp_send_json_error( array( 'message' => 'Brand is required.' ) );
        }
        if ( ! $cat_id ) {
            wp_send_json_error( array( 'message' => 'Asset Category is required.' ) );
        }

        $mysqli = self::get_form_mysqli();
        if ( ! $mysqli['conn'] ) {
            wp_send_json_error( array( 'message' => $mysqli['error'] ) );
        }

        $db    = $mysqli['conn'];
        $table = $mysqli['table'];
        $pairs_table = 'sv_artist_name_song_title_index';

        $pub_date_val = $pub_date ?: date( 'Y-m-d' );

        if ( $id > 0 ) {
            // UPDATE
            $stmt = $db->prepare(
                "UPDATE `{$table}` SET
                    `asset_title`            = ?,
                    `public_url`             = ?,
                    `brand_id`               = ?,
                    `asset_category_id`      = ?,
                    `pub_date`               = ?,
                    `keywords`               = ?,
                    `source`                 = ?,
                    `useable_media`          = ?,
                    `full_text_transcript`   = ?,
                    `select_quote_selection` = ?
                WHERE `id` = ?"
            );
            if ( $stmt === false ) {
                $err = $db->error;
                $db->close();
                SMID_Logger::error( 'OMA UPDATE prepare() failed', array( 'error' => $err, 'table' => $table ) );
                wp_send_json_error( array( 'message' => 'DB error: ' . $err ) );
            }
            $stmt->bind_param( 'ssiissssssi',
                $asset_title, $public_url, $brand_id, $cat_id, $pub_date_val, $keywords, $source, $useable_media, $full_text_transcript, $select_quote_selection, $id
            );
        } else {
            // INSERT
            $stmt = $db->prepare(
                "INSERT INTO `{$table}`
                    (`asset_title`, `public_url`, `brand_id`, `asset_category_id`, `pub_date`, `keywords`, `source`, `useable_media`, `full_text_transcript`, `select_quote_selection`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ( $stmt === false ) {
                $err = $db->error;
                $db->close();
                SMID_Logger::error( 'OMA INSERT prepare() failed', array( 'error' => $err, 'table' => $table ) );
                wp_send_json_error( array( 'message' => 'DB error: ' . $err ) );
            }
            $stmt->bind_param( 'ssiissssss',
                $asset_title, $public_url, $brand_id, $cat_id, $pub_date_val, $keywords, $source, $useable_media, $full_text_transcript, $select_quote_selection
            );
        }

        if ( ! $stmt->execute() ) {
            $err = $db->error;
            $stmt->close();
            $db->close();
            SMID_Logger::error( 'OMA execute() failed', array( 'error' => $err, 'table' => $table ) );
            wp_send_json_error( array( 'message' => $err ) );
        }

        $new_id = $id > 0 ? $id : $db->insert_id;
        $stmt->close();

        // ── Sync artist-song pairs ─────────────────────────────────
        // Delete existing pairs for this record (on update)
        if ( $id > 0 ) {
            $stmt_del = $db->prepare( "DELETE FROM `{$pairs_table}` WHERE `sv_media_index_id` = ?" );
            if ( $stmt_del ) {
                $stmt_del->bind_param( 'i', $new_id );
                $stmt_del->execute();
                $stmt_del->close();
            }
        }

        // Insert each artist-song-album pair
        // Use $pairs_arr if available (new format), else fall back to flat arrays
        $pairs_to_insert = array();
        if ( ! empty( $pairs_arr ) ) {
            foreach ( $pairs_arr as $p ) {
                $a_id     = intval( $p['artistId'] ?? 0 );
                $s_id     = intval( $p['songId'] ?? 0 );
                $al_id    = intval( $p['albumId'] ?? 0 );
                $al_name  = sanitize_text_field( $p['albumName'] ?? '' );
                if ( $a_id ) $pairs_to_insert[] = array( 'a' => $a_id, 's' => $s_id, 'album_id' => $al_id, 'album_name' => $al_name );
            }
        } else {
            foreach ( $artist_ids_arr as $idx => $artist ) {
                $a_id = intval( $artist['id'] ?? 0 );
                if ( ! $a_id ) continue;
                $s_id = intval( $song_ids_arr[ $idx ]['id'] ?? 0 );
                $pairs_to_insert[] = array( 'a' => $a_id, 's' => $s_id, 'album_id' => 0, 'album_name' => '' );
            }
        }

        foreach ( $pairs_to_insert as $pair ) {
            $a_id    = $pair['a'];
            $s_id    = intval( $pair['s'] );
            $al_id   = $pair['album_id'];
            $al_name = $pair['album_name'];

            if ( $s_id ) {
                // Artist + Song: use numeric song_id
                $stmt_pair = $db->prepare(
                    "INSERT IGNORE INTO `{$pairs_table}` (`sv_media_index_id`, `artist_id`, `song_id`, `album_id`, `album_name`) VALUES (?, ?, ?, ?, ?)"
                );
                if ( $stmt_pair ) {
                    $stmt_pair->bind_param( 'iiiis', $new_id, $a_id, $s_id, $al_id, $al_name );
                    $stmt_pair->execute();
                    $stmt_pair->close();
                }
            } else {
                // Artist only (no song) — use literal NULL to avoid FK/unique-key issues with 0
                $stmt_pair = $db->prepare(
                    "INSERT IGNORE INTO `{$pairs_table}` (`sv_media_index_id`, `artist_id`, `song_id`, `album_id`, `album_name`) VALUES (?, ?, NULL, ?, ?)"
                );
                if ( $stmt_pair ) {
                    $stmt_pair->bind_param( 'iiis', $new_id, $a_id, $al_id, $al_name );
                    $stmt_pair->execute();
                    $stmt_pair->close();
                }
            }
        }

        $db->close();

        SMID_Logger::success( $id > 0 ? 'OMA record updated' : 'OMA record inserted', array(
            'id'         => $new_id,
            'public_url' => $public_url,
            'pairs'      => count( $artist_ids_arr ),
        ) );

        wp_send_json_success( array(
            'message' => $id > 0 ? 'Asset updated.' : 'Asset added.',
            'id'      => $new_id,
            'is_new'  => $id === 0,
        ) );
    }

    // ── AJAX: Delete record ────────────────────────────────────────
    public static function ajax_delete_record() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! self::is_admin_user() ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $id = intval( $_POST['oma_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
        }

        $mysqli = self::get_form_mysqli();
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
            SMID_Logger::success( 'OMA record deleted', array( 'id' => $id ) );
            wp_send_json_success( array( 'message' => 'Asset deleted.' ) );
        } else {
            $err = $db->error;
            $stmt->close();
            $db->close();
            SMID_Logger::error( 'OMA DELETE failed', array( 'id' => $id, 'error' => $err ) );
            wp_send_json_error( array( 'message' => $err ) );
        }
    }

    // ── Helper: fetch artists from songfacts artists table ────────
    private static function fetch_songfacts_artists() {
        $settings    = get_option( 'smid_settings_songfacts_artists', array() );
        $connections = get_option( 'smid_connections', array() );
        $conn_id     = $settings['connection_id'] ?? '';
        $table       = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            return array();
        }

        $c        = $connections[ $conn_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $db->connect_error ) {
            return array();
        }

        $rows   = array();
        $result = $db->query( "SELECT id, name FROM `{$table}` ORDER BY name ASC" );
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = array( 'id' => $row['id'], 'name' => $row['name'] );
            }
        }
        $db->close();
        return $rows;
    }

    // ── Helper: fetch brands or categories from their configured connection ──
    private static function fetch_options( $form_key ) {
        $settings    = get_option( 'smid_settings_' . $form_key, array() );
        $connections = get_option( 'smid_connections', array() );

        $conn_id = $settings['connection_id'] ?? '';
        $table   = $settings['table'] ?? '';

        if ( ! $conn_id || ! $table || ! isset( $connections[ $conn_id ] ) ) {
            return array();
        }

        $c        = $connections[ $conn_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $db = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $db->connect_error ) {
            return array();
        }

        $rows   = array();
        $result = $db->query( "SELECT id, name FROM `{$table}` ORDER BY name ASC" );
        if ( $result ) {
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = array( 'id' => $row['id'], 'name' => $row['name'] );
            }
        }
        $db->close();
        return $rows;
    }

    // ── Helper: get mysqli for OMA table ──────────────────────────
    private static function get_form_mysqli() {
        $settings    = get_option( 'smid_settings_online_media_assets', array() );
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
