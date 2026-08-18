<?php
/**
 * Plugin Name:       Songfacts API Landing Page
 * Description:       Receives relayed Songfacts API interest-form submissions (Cloudflare Worker → n8n → this plugin) and manages them from wp-admin.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WingManWP
 * Text Domain:       songfacts-api-landingpage
 */

defined( 'ABSPATH' ) || exit;

define( 'SF_LP_VERSION', '1.3.0' );
define( 'SF_LP_PLUGIN_FILE', __FILE__ );
define( 'SF_LP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SF_LP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-jwt.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-db.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-access-control.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-rest-controller.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-list-table.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-notifications.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-admin.php';

register_activation_hook( __FILE__, array( 'SF_LP_DB', 'install' ) );

// Boot access control (registers the user_has_cap filter). Must run at load time,
// not on `init`, so the synthesized caps exist for every request — including
// admin-ajax.php and REST — not just after `init` has fired.
SF_LP_Access_Control::init();

// Admin notifications listen on `sf_lp_submission_received`, which fires during
// the REST request — wired here at load time rather than from SF_LP_Admin::init()
// so the listener exists on every request regardless of admin bootstrapping.
SF_LP_Notifications::init();

// Visitor auto-reply listens on the same hook, for the same reason.
add_action( 'sf_lp_submission_received', array( 'SF_LP_Admin', 'maybe_send_visitor_acknowledgement' ), 10, 2 );

add_action( 'rest_api_init', array( 'SF_LP_REST_Controller', 'register_routes' ) );
add_action( 'init', array( 'SF_LP_Admin', 'init' ) );
