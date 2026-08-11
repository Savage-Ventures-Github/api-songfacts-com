<?php
/**
 * Plugin Name: Savage Media Indexing Database
 * Description: Connect and manage multiple live server database connections with Brands and Asset Categories management.
 * Version:     1.0.6
 * Author:      Savage Ventures
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SMID_VERSION', '1.1.0' );
define( 'SMID_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMID_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include files
require_once SMID_PLUGIN_DIR . 'includes/class-smid-logger.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-access-control.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-connections.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-brands.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-asset-categories.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-online-media-assets.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-tables.php';
require_once SMID_PLUGIN_DIR . 'includes/class-smid-logs.php';

// Boot access control (registers user_has_cap filter)
SMID_Access_Control::init();

class Savage_Media_Indexing_Database {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_main_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Connections AJAX
        add_action( 'wp_ajax_smid_save_connection',  array( 'SMID_Connections', 'ajax_save_connection' ) );
        add_action( 'wp_ajax_smid_delete_connection', array( 'SMID_Connections', 'ajax_delete_connection' ) );
        add_action( 'wp_ajax_smid_test_connection',  array( 'SMID_Connections', 'ajax_test_connection' ) );

        // Tables AJAX
        add_action( 'wp_ajax_smid_get_tables',       array( 'SMID_Tables', 'ajax_get_tables' ) );
        add_action( 'wp_ajax_smid_get_table_data',   array( 'SMID_Tables', 'ajax_get_table_data' ) );
        add_action( 'wp_ajax_smid_drop_table',       array( 'SMID_Tables', 'ajax_drop_table' ) );
        add_action( 'wp_ajax_smid_rename_table',     array( 'SMID_Tables', 'ajax_rename_table' ) );

        // Form settings AJAX
        add_action( 'wp_ajax_smid_get_tables_for_settings', array( $this, 'ajax_get_tables_for_settings' ) );
        add_action( 'wp_ajax_smid_save_form_settings',      array( $this, 'ajax_save_form_settings' ) );
        add_action( 'wp_ajax_smid_delete_form_settings',    array( $this, 'ajax_delete_form_settings' ) );

        // Brands CRUD AJAX
        add_action( 'wp_ajax_smid_get_brands',    array( 'SMID_Brands', 'ajax_get_brands' ) );
        add_action( 'wp_ajax_smid_save_brand',    array( 'SMID_Brands', 'ajax_save_brand' ) );
        add_action( 'wp_ajax_smid_delete_brand',  array( 'SMID_Brands', 'ajax_delete_brand' ) );

        // Asset Categories CRUD AJAX
        add_action( 'wp_ajax_smid_get_categories',  array( 'SMID_Artist_Categories', 'ajax_get_categories' ) );
        add_action( 'wp_ajax_smid_save_category',   array( 'SMID_Artist_Categories', 'ajax_save_category' ) );
        add_action( 'wp_ajax_smid_delete_category', array( 'SMID_Artist_Categories', 'ajax_delete_category' ) );

        // Online Media Assets CRUD AJAX
        add_action( 'wp_ajax_smid_get_oma_records',       array( 'SMID_Online_Media_Assets', 'ajax_get_records' ) );
        add_action( 'wp_ajax_smid_get_oma_form_data',     array( 'SMID_Online_Media_Assets', 'ajax_get_form_data' ) );
        add_action( 'wp_ajax_smid_save_oma_record',       array( 'SMID_Online_Media_Assets', 'ajax_save_record' ) );
        add_action( 'wp_ajax_smid_delete_oma_record',     array( 'SMID_Online_Media_Assets', 'ajax_delete_record' ) );
        add_action( 'wp_ajax_smid_get_oma_songs_by_artist',   array( 'SMID_Online_Media_Assets', 'ajax_get_songs_by_artist' ) );
        add_action( 'wp_ajax_smid_get_oma_albums_by_artist',  array( 'SMID_Online_Media_Assets', 'ajax_get_albums_by_artist' ) );
        add_action( 'wp_ajax_smid_get_oma_existing_keywords', array( 'SMID_Online_Media_Assets', 'ajax_get_existing_keywords' ) );
    }

    /**
     * admin_init hook — redirects non-admins away from the Connections page
     * to their first accessible SMID page. Runs early, before any output.
     */
    public function maybe_redirect_main_menu() {
        $page = isset( $_GET['page'] ) ? $_GET['page'] : '';
        if ( $page !== 'smid-connections' ) {
            return;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return; // admins stay on Connections
        }
        $redirects = array(
            'smid_access_oma'        => 'smid-online-media-assets',
            'smid_access_brands'     => 'smid-brands',
            'smid_access_categories' => 'smid-asset-categories',
            'smid_access_logs'       => 'smid-logs',
        );
        foreach ( $redirects as $cap => $slug ) {
            if ( current_user_can( $cap ) ) {
                wp_redirect( admin_url( 'admin.php?page=' . $slug ) );
                exit;
            }
        }
    }

    /**
     * Main menu page callback (fallback — used by admins only in practice).
     */
    public function render_main_page() {
        SMID_Connections::render_page();
    }

    public function register_menus() {
        // Main menu — visible to admin OR any user with at least one smid page grant
        add_menu_page(
            __( 'Savage Media indexing', 'smid' ),
            __( 'Savage Media indexing', 'smid' ),
            'smid_any_access',
            'smid-connections',
            array( $this, 'render_main_page' ),
            'dashicons-database',
            30
        );

        // Connections — admin only always
        add_submenu_page(
            'smid-connections',
            __( 'Connections', 'smid' ),
            __( 'Connections', 'smid' ),
            'manage_options',
            'smid-connections',
            array( 'SMID_Connections', 'render_page' )
        );

        // Brands — controllable via Access Control
        add_submenu_page(
            'smid-connections',
            __( 'Brands', 'smid' ),
            __( 'Brands', 'smid' ),
            'smid_access_brands',
            'smid-brands',
            array( 'SMID_Brands', 'render_page' )
        );

        // Asset Categories — controllable via Access Control
        add_submenu_page(
            'smid-connections',
            __( 'Asset Categories', 'smid' ),
            __( 'Asset Categories', 'smid' ),
            'smid_access_categories',
            'smid-asset-categories',
            array( 'SMID_Artist_Categories', 'render_page' )
        );

        // Online Media Assets — controllable via Access Control
        add_submenu_page(
            'smid-connections',
            __( 'Online Media Assets', 'smid' ),
            __( 'Online Media Assets', 'smid' ),
            'smid_access_oma',
            'smid-online-media-assets',
            array( 'SMID_Online_Media_Assets', 'render_page' )
        );

        // Logs — controllable via Access Control
        add_submenu_page(
            'smid-connections',
            __( 'Logs', 'smid' ),
            __( 'Logs', 'smid' ),
            'smid_access_logs',
            'smid-logs',
            array( 'SMID_Logs', 'render_page' )
        );

        // Access Control — admin only always
        add_submenu_page(
            'smid-connections',
            __( 'Access Control', 'smid' ),
            __( 'Access Control', 'smid' ),
            'manage_options',
            'smid-access-control',
            array( 'SMID_Access_Control', 'render_page' )
        );

        // Tables — admin only, hidden from menu
        add_submenu_page(
            null,
            __( 'Tables', 'smid' ),
            __( 'Tables', 'smid' ),
            'manage_options',
            'smid-tables',
            array( 'SMID_Tables', 'render_page' )
        );

        // Remove the auto-generated "Savage Media indexing" duplicate submenu entry for non-admins.
        // WordPress auto-adds a same-slug submenu (using the parent label) when the explicit
        // same-slug submenu (Connections, manage_options) is hidden from the current user.
        if ( ! current_user_can( 'manage_options' ) ) {
            remove_submenu_page( 'smid-connections', 'smid-connections' );
        }
    }

    public function enqueue_assets( $hook ) {
        // Match any SMID admin page regardless of parent menu title
        if ( strpos( $hook, 'smid' ) === false ) {
            return;
        }

        // Select2
        wp_enqueue_style(
            'smid-select2',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css',
            array(),
            '4.1.0'
        );
        wp_enqueue_script(
            'smid-select2',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js',
            array( 'jquery' ),
            '4.1.0',
            true
        );

        wp_enqueue_style(
            'smid-admin',
            SMID_PLUGIN_URL . 'assets/admin.css',
            array( 'smid-select2' ),
            SMID_VERSION
        );

        wp_enqueue_script(
            'smid-admin',
            SMID_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery', 'smid-select2' ),
            SMID_VERSION,
            true
        );

        wp_localize_script( 'smid-admin', 'smidAjax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'smid_nonce' ),
        ) );
    }

    // ── AJAX: Get tables list for a connection (settings dropdown) ──
    public function ajax_get_tables_for_settings() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $connections   = get_option( 'smid_connections', array() );

        if ( ! isset( $connections[ $connection_id ] ) ) {
            wp_send_json_error( array( 'message' => 'Connection not found.' ) );
        }

        $c        = $connections[ $connection_id ];
        $password = SMID_Connections::decrypt_password( $c['password'] );

        mysqli_report( MYSQLI_REPORT_OFF );
        $mysqli = @new mysqli( $c['host'], $c['username'], $password, $c['db_name'] );

        if ( $mysqli->connect_error ) {
            wp_send_json_error( array( 'message' => 'Could not connect: ' . $mysqli->connect_error ) );
        }

        $tables = array();
        $result = $mysqli->query( 'SHOW TABLES' );
        if ( $result ) {
            while ( $row = $result->fetch_array() ) {
                $tables[] = $row[0];
            }
        }
        $mysqli->close();

        wp_send_json_success( array( 'tables' => $tables ) );
    }

    // ── AJAX: Save form settings ────────────────────────────────────
    public function ajax_save_form_settings() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $form_key      = sanitize_key( $_POST['form_key'] ?? '' );       // 'brands' or 'asset_categories'
        $connection_id = sanitize_key( $_POST['connection_id'] ?? '' );
        $table         = sanitize_text_field( $_POST['table'] ?? '' );

        if ( ! $form_key || ! $connection_id || ! $table ) {
            wp_send_json_error( array( 'message' => 'All fields required.' ) );
        }

        $allowed_keys = array( 'brands', 'asset_categories', 'online_media_assets', 'songfacts_artists', 'songfacts_songs', 'songfacts_albums' );
        if ( ! in_array( $form_key, $allowed_keys ) ) {
            wp_send_json_error( array( 'message' => 'Invalid form key.' ) );
        }

        update_option( 'smid_settings_' . $form_key, array(
            'connection_id' => $connection_id,
            'table'         => $table,
        ) );

        wp_send_json_success( array( 'message' => 'Settings saved.' ) );
    }

    // ── AJAX: Delete form settings (disconnect) ─────────────────────
    public function ajax_delete_form_settings() {
        check_ajax_referer( 'smid_nonce', 'nonce' );
        if ( ! current_user_can( 'administrator' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        $form_key     = sanitize_key( $_POST['form_key'] ?? '' );
        $allowed_keys = array( 'brands', 'asset_categories', 'online_media_assets', 'songfacts_artists', 'songfacts_songs', 'songfacts_albums' );

        if ( ! in_array( $form_key, $allowed_keys ) ) {
            wp_send_json_error( array( 'message' => 'Invalid form key.' ) );
        }

        delete_option( 'smid_settings_' . $form_key );
        wp_send_json_success( array( 'message' => 'Settings removed.' ) );
    }

    public static function activate() {
        if ( ! get_option( 'smid_connections' ) ) {
            add_option( 'smid_connections', array() );
        }
    }
}

register_activation_hook( __FILE__, array( 'Savage_Media_Indexing_Database', 'activate' ) );

new Savage_Media_Indexing_Database();
