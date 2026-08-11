<?php
/**
 * Plugin Name:       Songfacts API Landing Page
 * Description:       Receives relayed Songfacts API interest-form submissions (Cloudflare Worker → n8n → this plugin) and manages them from wp-admin.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WingManWP
 * Text Domain:       songfacts-api-landingpage
 */

defined( 'ABSPATH' ) || exit;

define( 'SF_LP_VERSION', '1.1.2' );
define( 'SF_LP_PLUGIN_FILE', __FILE__ );
define( 'SF_LP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SF_LP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-jwt.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-db.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-access-control.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-rest-controller.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-list-table.php';
require_once SF_LP_PLUGIN_DIR . 'includes/class-sf-admin.php';

register_activation_hook( __FILE__, array( 'SF_LP_DB', 'install' ) );

// Boot access control (registers the user_has_cap filter). Must run at load time,
// not on `init`, so the synthesized caps exist for every request — including
// admin-ajax.php and REST — not just after `init` has fired.
SF_LP_Access_Control::init();

add_action( 'rest_api_init', array( 'SF_LP_REST_Controller', 'register_routes' ) );
add_action( 'init', array( 'SF_LP_Admin', 'init' ) );
