<?php
/**
 * Runs only when the plugin is deleted via wp-admin (not on deactivate).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sflp_submissions" );

delete_option( 'sf_lp_jwt_secret' );
delete_option( 'sf_lp_access_control' );
delete_option( 'sf_lp_notification_recipients' );
delete_option( 'sf_lp_notification_log' );
delete_option( 'sf_lp_visitor_reply_enabled' );
delete_option( 'sf_lp_visitor_reply_message' );
