<?php
/**
 * WP-Admin UI: top-level menu, Submissions list, Settings page, and the
 * "Mark as Completed" AJAX handler.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_Admin {

	const OPTION_JWT_SECRET = 'sf_lp_jwt_secret';
	const NONCE_ACTION      = 'sf_lp_admin_nonce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sf_lp_mark_completed', array( __CLASS__, 'ajax_mark_completed' ) );
		add_action( 'wp_ajax_sf_lp_populate_samples', array( __CLASS__, 'ajax_populate_samples' ) );
		add_action( 'wp_ajax_sf_lp_delete_samples', array( __CLASS__, 'ajax_delete_samples' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function register_menu() {
		// Top-level menu — visible to admins, or any role granted Submissions access
		// via Songfacts API CRM → Access Control.
		add_menu_page(
			'Songfacts API CRM',
			'Songfacts API CRM',
			SF_LP_Access_Control::CAP_ANY,
			'sf-lp-submissions',
			array( __CLASS__, 'render_submissions_page' ),
			'dashicons-media-audio',
			26
		);

		// Submissions — controllable via Access Control.
		add_submenu_page(
			'sf-lp-submissions',
			'Submissions',
			'Submissions',
			SF_LP_Access_Control::CAP_PREFIX . 'submissions',
			'sf-lp-submissions',
			array( __CLASS__, 'render_submissions_page' )
		);

		// Settings — admin only always (holds the JWT signing secret).
		add_submenu_page(
			'sf-lp-submissions',
			'Settings',
			'Settings',
			'manage_options',
			'sf-lp-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		// Access Control — admin only always.
		add_submenu_page(
			'sf-lp-submissions',
			'Access Control',
			'Access Control',
			'manage_options',
			'sf-lp-access-control',
			array( 'SF_LP_Access_Control', 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'sf_lp_settings',
			self::OPTION_JWT_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	public static function enqueue_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 0 !== strpos( $page, 'sf-lp-' ) ) {
			return;
		}

		wp_enqueue_style(
			'sf-lp-admin',
			SF_LP_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			SF_LP_VERSION
		);

		wp_enqueue_script(
			'sf-lp-admin',
			SF_LP_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			SF_LP_VERSION,
			true
		);

		wp_localize_script(
			'sf-lp-admin',
			'sfLpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	public static function render_submissions_page() {
		if ( ! SF_LP_Access_Control::can_view() ) {
			return;
		}

		$table = new SF_LP_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Submissions</h1>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="sf-lp-submissions">
				<?php $table->views(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Songfacts API CRM Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'sf_lp_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sf_lp_jwt_secret">JWT Signing Secret</label></th>
						<td>
							<input type="password" id="sf_lp_jwt_secret" name="<?php echo esc_attr( self::OPTION_JWT_SECRET ); ?>"
								value="<?php echo esc_attr( get_option( self::OPTION_JWT_SECRET, '' ) ); ?>"
								class="regular-text" autocomplete="off">
							<p class="description">
								Must exactly match <code>JWT_SIGNING_SECRET</code> configured on the Cloudflare Worker
								(<code>songfacts-api-interest-submission</code>). Requests to
								<code>/wp-json/songfacts-crm/v1/submissions</code> are rejected unless their bearer
								token is signed with this same secret.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>

			<h2>Sample Data</h2>
			<p class="description">
				Populate the Submissions list with ten fake, clearly-marked (<code>is_sample</code>)
				rows for testing the admin UI, or remove all of them again. Never affects real
				submissions.
			</p>
			<p>
				<button type="button" class="button button-secondary" id="sf-lp-populate-samples">Populate Sample Submissions</button>
				<button type="button" class="button" id="sf-lp-delete-samples">Delete Sample Submissions</button>
				<span id="sf-lp-sample-status" style="margin-left: 8px;"></span>
			</p>
		</div>
		<?php
	}

	public static function ajax_mark_completed() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! SF_LP_Access_Control::can_view() || ! SF_LP_Access_Control::can_edit() ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Missing submission id.' ), 400 );
		}

		$updated = SF_LP_DB::set_status( $id, SF_LP_DB::STATUS_COMPLETED );

		if ( false === $updated ) {
			wp_send_json_error( array( 'message' => 'Could not update submission.' ), 500 );
		}

		wp_send_json_success( array( 'id' => $id, 'status' => SF_LP_DB::STATUS_COMPLETED ) );
	}

	public static function ajax_populate_samples() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$inserted = SF_LP_DB::insert_sample_batch();

		wp_send_json_success( array( 'inserted' => $inserted ) );
	}

	public static function ajax_delete_samples() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$deleted = SF_LP_DB::delete_samples();

		wp_send_json_success( array( 'deleted' => $deleted ) );
	}
}
