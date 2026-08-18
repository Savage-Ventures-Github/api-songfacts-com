<?php
/**
 * WP-Admin UI: top-level menu, Submissions list, Settings page, and the
 * "Mark as Completed" AJAX handler.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_Admin {

	const OPTION_JWT_SECRET             = 'sf_lp_jwt_secret';
	const OPTION_VISITOR_REPLY_ENABLED  = 'sf_lp_visitor_reply_enabled';
	const OPTION_VISITOR_REPLY_SUBJECT  = 'sf_lp_visitor_reply_subject';
	const OPTION_VISITOR_REPLY_MESSAGE  = 'sf_lp_visitor_reply_message';
	const NONCE_ACTION                  = 'sf_lp_admin_nonce';

	// {first_name} and {site_name} are replaced in both fields below — see
	// apply_visitor_tokens().
	const DEFAULT_VISITOR_REPLY_SUBJECT = 'Thanks for reaching out to {site_name}';
	const DEFAULT_VISITOR_REPLY_MESSAGE = "Hi {first_name},\n\nThank you for your interest in the Songfacts API. We've received your message and someone from our team will be in touch soon.";

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_sf_lp_mark_completed', array( __CLASS__, 'ajax_mark_completed' ) );
		add_action( 'wp_ajax_sf_lp_populate_samples', array( __CLASS__, 'ajax_populate_samples' ) );
		add_action( 'wp_ajax_sf_lp_delete_samples', array( __CLASS__, 'ajax_delete_samples' ) );
		add_action( 'wp_ajax_sf_lp_send_test_notification', array( __CLASS__, 'ajax_send_test_notification' ) );
		add_action( 'wp_ajax_sf_lp_clear_email_log', array( __CLASS__, 'ajax_clear_email_log' ) );
		add_action( 'wp_ajax_sf_lp_send_test_visitor_notification', array( __CLASS__, 'ajax_send_test_visitor_notification' ) );
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

		register_setting(
			'sf_lp_settings',
			SF_LP_Notifications::OPTION_RECIPIENTS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'SF_LP_Notifications', 'sanitize_recipients' ),
				'default'           => array(),
			)
		);

		register_setting(
			'sf_lp_settings',
			SF_LP_Notifications::OPTION_SUBJECT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( 'SF_LP_Notifications', 'sanitize_subject' ),
				'default'           => SF_LP_Notifications::DEFAULT_SUBJECT,
			)
		);

		register_setting(
			'sf_lp_settings',
			self::OPTION_VISITOR_REPLY_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_visitor_reply_enabled' ),
				'default'           => '',
			)
		);

		register_setting(
			'sf_lp_settings',
			self::OPTION_VISITOR_REPLY_SUBJECT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => self::DEFAULT_VISITOR_REPLY_SUBJECT,
			)
		);

		register_setting(
			'sf_lp_settings',
			self::OPTION_VISITOR_REPLY_MESSAGE,
			array(
				'type'              => 'string',
				// Plain text only, matching what's actually sent — sanitize_textarea_field
				// strips HTML/scripts while preserving the line breaks admins type in.
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => self::DEFAULT_VISITOR_REPLY_MESSAGE,
			)
		);
	}

	/**
	 * Settings API sanitize callback for the visitor auto-reply toggle. A checkbox
	 * only appears in $_POST when checked, so the settings form also submits a
	 * same-named hidden "0" input just before it — the checked value (if any)
	 * wins since it renders later in the DOM. This callback just normalizes
	 * whatever survived that to a strict '1' or ''.
	 */
	public static function sanitize_visitor_reply_enabled( $value ) {
		return '1' === (string) $value ? '1' : '';
	}

	/**
	 * Replaces the merge tokens available in the visitor auto-reply subject and
	 * message: {first_name} (the visitor's own submitted first name) and
	 * {site_name} (this site's title). Plain strtr() — no regex, so a stray
	 * '{' or '}' elsewhere in the text is never touched.
	 */
	private static function apply_visitor_tokens( $text, $first_name ) {
		$tokens = array(
			'{first_name}' => $first_name,
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		return strtr( $text, $tokens );
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
			<?php settings_errors(); ?>

			<h2 class="nav-tab-wrapper" id="sf-lp-settings-tabs">
				<a href="#" class="nav-tab nav-tab-active" data-sf-lp-tab="visitors">Notifications to Visitors</a>
				<a href="#" class="nav-tab" data-sf-lp-tab="administrators">Notifications to Administrators</a>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'sf_lp_settings' ); ?>

				<div class="sf-lp-tab-panel" data-sf-lp-panel="visitors">
					<p class="description sf-lp-tab-note">
						Auto respond to visitors with an acknowledgement of their submission. The subject
						and message below both support fill-in fields: <code>{first_name}</code> and
						<code>{site_name}</code> — replaced with the visitor's own submitted first name and
						this site's title.
					</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Auto-Reply</th>
							<td>
								<label for="sf_lp_visitor_reply_enabled">
									<!-- Unchecked checkboxes are omitted from $_POST entirely, so this hidden
									     "0" (rendered first, overridden by the checkbox's "1" when checked)
									     is what lets an admin actually turn the toggle back off. -->
									<input type="hidden" name="<?php echo esc_attr( self::OPTION_VISITOR_REPLY_ENABLED ); ?>" value="0">
									<input type="checkbox" id="sf_lp_visitor_reply_enabled"
										name="<?php echo esc_attr( self::OPTION_VISITOR_REPLY_ENABLED ); ?>" value="1"
										<?php checked( '1', get_option( self::OPTION_VISITOR_REPLY_ENABLED, '' ) ); ?>>
									Automatically email visitors an acknowledgement when they submit the interest form.
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sf_lp_visitor_reply_subject">Subject</label></th>
							<td>
								<input type="text" id="sf_lp_visitor_reply_subject" class="large-text"
									name="<?php echo esc_attr( self::OPTION_VISITOR_REPLY_SUBJECT ); ?>"
									value="<?php echo esc_attr( get_option( self::OPTION_VISITOR_REPLY_SUBJECT, self::DEFAULT_VISITOR_REPLY_SUBJECT ) ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sf_lp_visitor_reply_message">Acknowledgement Message</label></th>
							<td>
								<textarea id="sf_lp_visitor_reply_message"
									name="<?php echo esc_attr( self::OPTION_VISITOR_REPLY_MESSAGE ); ?>"
									rows="6" class="large-text"><?php echo esc_textarea( get_option( self::OPTION_VISITOR_REPLY_MESSAGE, self::DEFAULT_VISITOR_REPLY_MESSAGE ) ); ?></textarea>
								<p class="description">Plain text only (no HTML) — sent verbatim as the body of the acknowledgement email to the address the visitor submitted.</p>
							</td>
						</tr>
					</table>

					<?php self::render_sender_and_preview( 'visitor' ); ?>
				</div>

				<div class="sf-lp-tab-panel" data-sf-lp-panel="administrators" hidden>
					<p class="description sf-lp-section-desc">
						Email one or more administrators whenever a new interest-form submission arrives from
						the Songfacts API landing page. Each address has its own on/off switch, so an address
						can stay in the list without receiving mail. Addresses are saved with the
						<strong>Save Changes</strong> button below.
					</p>

					<?php self::render_recipients_table(); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sf_lp_notification_subject">Subject</label></th>
							<td>
								<input type="text" id="sf_lp_notification_subject" class="large-text"
									name="<?php echo esc_attr( SF_LP_Notifications::OPTION_SUBJECT ); ?>"
									value="<?php echo esc_attr( get_option( SF_LP_Notifications::OPTION_SUBJECT, SF_LP_Notifications::DEFAULT_SUBJECT ) ); ?>">
								<p class="description">
									Supports <code>{first_name}</code>, <code>{last_name}</code>,
									<code>{submitter_name}</code> (falls back to their email if no name was given),
									<code>{email}</code>, <code>{company}</code>, and <code>{site_name}</code>.
									The message body itself isn't editable — it's always the full list of
									submitted fields, shown in the preview below, so a field added to the
									landing page later still shows up here with no code change.
								</p>
							</td>
						</tr>
					</table>

					<?php self::render_sender_and_preview( 'admin' ); ?>
					<?php self::render_email_log(); ?>
				</div>

				<?php submit_button(); ?>
			</form>

			<hr>

			<details class="sf-lp-diag">
				<summary>Technical</summary>
				<div class="sf-lp-diag-body">
					<h3>Sample Data</h3>
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

					<hr>

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
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Repeatable recipient rows: address, optional label, on/off toggle, remove.
	 *
	 * Rows are plain form fields inside the main settings form — adding and
	 * removing rows is pure DOM work in admin.js, and nothing is persisted until
	 * Save Changes posts the whole array to options.php.
	 */
	private static function render_recipients_table() {
		$recipients = SF_LP_Notifications::get_recipients();
		?>
		<div class="sf-lp-notify-card">
			<table class="sf-lp-notify-table" id="sf-lp-recipients">
				<thead>
					<tr>
						<th class="col-email">Email Address</th>
						<th class="col-label">Name / Note <span class="sf-lp-optional">optional</span></th>
						<th class="col-enabled">Send</th>
						<th class="col-remove"><span class="screen-reader-text">Remove</span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recipients as $index => $recipient ) : ?>
						<?php self::render_recipient_row( (string) $index, $recipient ); ?>
					<?php endforeach; ?>
				</tbody>
				<tbody class="sf-lp-notify-empty" <?php echo $recipients ? 'style="display:none;"' : ''; ?>>
					<tr>
						<td colspan="4" class="sf-lp-notify-none">
							No notification recipients yet — nobody is emailed when a submission arrives.
						</td>
					</tr>
				</tbody>
			</table>
			<div class="sf-lp-notify-actions">
				<button type="button" class="button button-secondary" id="sf-lp-add-recipient">
					<span class="dashicons dashicons-plus-alt2"></span> Add Recipient
				</button>
			</div>
		</div>

		<template id="sf-lp-recipient-template">
			<?php
			self::render_recipient_row(
				'__INDEX__',
				array(
					'email'   => '',
					'label'   => '',
					'enabled' => 1,
				)
			);
			?>
		</template>
		<?php
	}

	/**
	 * One recipient row. `$index` is `__INDEX__` for the <template> copy, which
	 * admin.js swaps for the next real array index when a row is added.
	 */
	private static function render_recipient_row( $index, $recipient ) {
		$base = SF_LP_Notifications::OPTION_RECIPIENTS . '[' . $index . ']';
		?>
		<tr class="sf-lp-notify-row">
			<td class="col-email">
				<input type="email" class="regular-text" name="<?php echo esc_attr( $base . '[email]' ); ?>"
					value="<?php echo esc_attr( $recipient['email'] ); ?>"
					placeholder="admin@example.com" autocomplete="off">
			</td>
			<td class="col-label">
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base . '[label]' ); ?>"
					value="<?php echo esc_attr( $recipient['label'] ); ?>"
					placeholder="Sales team" autocomplete="off">
			</td>
			<td class="col-enabled">
				<label class="sf-lp-toggle">
					<?php // Hidden 0 first: an unchecked checkbox posts nothing at all. ?>
					<input type="hidden" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="0">
					<input type="checkbox" name="<?php echo esc_attr( $base . '[enabled]' ); ?>" value="1"
						<?php checked( ! empty( $recipient['enabled'] ) ); ?>>
					<span class="sf-lp-toggle-track">
						<span class="sf-lp-toggle-thumb"></span>
					</span>
				</label>
			</td>
			<td class="col-remove">
				<button type="button" class="button-link sf-lp-remove-recipient" aria-label="Remove this recipient">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * The "Sender & Message Preview" expandable, shared by both tabs. The sender
	 * identity really is identical either way — neither maybe_send_visitor_
	 * acknowledgement() nor SF_LP_Notifications::send_for_submission() sets a
	 * custom From header, so both go out with whatever WordPress/Post SMTP is
	 * configured to use. Only the preview renderer differs per tab.
	 *
	 * @param string $context 'visitor' or 'admin'.
	 */
	private static function render_sender_and_preview( $context ) {
		?>
		<details class="sf-lp-diag">
			<summary>Sender &amp; Message Preview</summary>
			<div class="sf-lp-diag-body">
				<?php
				self::render_from_identity();
				if ( 'admin' === $context ) {
					self::render_message_preview();
				} else {
					self::render_visitor_message_preview();
				}
				?>
			</div>
		</details>
		<?php
	}

	/**
	 * Read-only display of the sender identity these notifications go out with.
	 * Deliberately not editable here — it belongs to WordPress / Post SMTP.
	 */
	private static function render_from_identity() {
		$from     = SF_LP_Notifications::get_from_identity();
		$smtp_url = SF_LP_Notifications::post_smtp_log_url();
		?>
		<h3 class="sf-lp-notify-subhead">Sender</h3>
		<table class="form-table sf-lp-from-table" role="presentation">
			<tr>
				<th scope="row">From Email Address</th>
				<td>
					<code class="sf-lp-readonly"><?php echo esc_html( $from['email'] ); ?></code>
					<span class="sf-lp-source-tag"><?php echo esc_html( $from['source'] ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row">From Name</th>
				<td>
					<code class="sf-lp-readonly"><?php echo esc_html( $from['name'] ); ?></code>
					<span class="sf-lp-source-tag"><?php echo esc_html( $from['source'] ); ?></span>
				</td>
			</tr>
		</table>
		<p class="description sf-lp-from-note">
			Shown for reference only — this plugin sends no <code>From</code> header of its own, so mail
			goes out with whatever the site is configured to use.
			<?php if ( SF_LP_Notifications::post_smtp_active() ) : ?>
				Change it under <a href="<?php echo esc_url( admin_url( 'admin.php?page=postman' ) ); ?>">Post SMTP &rarr; Settings &rarr; Message</a>.
				<?php if ( $from['enforced'] ) : ?>
					Post SMTP is set to enforce this sender, so <code>wp_mail_from</code> filters are ignored.
				<?php endif; ?>
			<?php else : ?>
				Post SMTP is not active; this is WordPress's default sender plus any
				<code>wp_mail_from</code> / <code>wp_mail_from_name</code> filters.
			<?php endif; ?>
			Replies go to the person who submitted the form, via a <code>Reply-To</code> header.
			<?php if ( $smtp_url ) : ?>
				&mdash; <a href="<?php echo esc_url( $smtp_url ); ?>">Post SMTP delivery log</a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Live render of the exact HTML body administrators receive, in an iframe so
	 * the email's own styles can't leak into wp-admin (or vice versa).
	 */
	private static function render_message_preview() {
		$preview    = SF_LP_Notifications::preview_submission();
		$submission = $preview['submission'];
		$body       = SF_LP_Notifications::build_body( $submission );
		$subject    = SF_LP_Notifications::build_subject( $submission );
		$enabled    = SF_LP_Notifications::get_enabled_recipients();
		?>
		<h3 class="sf-lp-notify-subhead">Message Preview</h3>
		<p class="description sf-lp-section-desc">
			<?php if ( $preview['is_placeholder'] ) : ?>
				No submissions have been received yet, so this preview uses placeholder data.
			<?php else : ?>
				Rendered from the most recent real submission.
			<?php endif; ?>
			Every field the visitor submitted is included, along with the date of submission.
		</p>
		<div class="sf-lp-preview">
			<div class="sf-lp-preview-head">
				<div><span class="sf-lp-preview-label">Subject</span> <?php echo esc_html( $subject ); ?></div>
				<div>
					<span class="sf-lp-preview-label">To</span>
					<?php if ( $enabled ) : ?>
						<?php
						$to_list = array_map(
							function ( $row ) {
								return $row['email'];
							},
							$enabled
						);
						echo esc_html( implode( ', ', $to_list ) );
						?>
					<?php else : ?>
						<em>nobody — no recipient is switched on</em>
					<?php endif; ?>
				</div>
			</div>
			<iframe class="sf-lp-preview-frame" title="Notification email preview"
				srcdoc="<?php echo esc_attr( $body ); ?>"></iframe>
		</div>
		<p class="sf-lp-preview-actions">
			<button type="button" class="button button-secondary" id="sf-lp-send-test"
				<?php disabled( empty( $enabled ) ); ?>>Send Test Notification</button>
			<span class="description">
				Sends this exact message (subject prefixed <code>[TEST]</code>) to every switched-on
				recipient, and records it in the log below. Save your changes first.
			</span>
			<span id="sf-lp-test-status" class="sf-lp-inline-status"></span>
		</p>
		<?php
	}

	/**
	 * Plain-text preview of the visitor acknowledgement — subject and body with
	 * {first_name}/{site_name} tokens applied — plus the "send a test copy"
	 * fields (first name + email address), hidden until the button is clicked.
	 *
	 * Rendered from the most recent real submission (or placeholder data on a
	 * fresh install) via the same SF_LP_Notifications::preview_submission()
	 * the admin preview uses, so both tabs demo consistently.
	 */
	private static function render_visitor_message_preview() {
		$preview    = SF_LP_Notifications::preview_submission();
		$submission = $preview['submission'];
		$first_name = (string) ( $submission['first_name'] ?? '' );

		$subject_tpl = get_option( self::OPTION_VISITOR_REPLY_SUBJECT, self::DEFAULT_VISITOR_REPLY_SUBJECT );
		if ( '' === trim( $subject_tpl ) ) {
			$subject_tpl = self::DEFAULT_VISITOR_REPLY_SUBJECT;
		}
		$message_tpl = get_option( self::OPTION_VISITOR_REPLY_MESSAGE, self::DEFAULT_VISITOR_REPLY_MESSAGE );

		$subject = self::apply_visitor_tokens( $subject_tpl, $first_name );
		$body    = self::apply_visitor_tokens( $message_tpl, $first_name );
		$enabled = '1' === get_option( self::OPTION_VISITOR_REPLY_ENABLED, '' );
		?>
		<h3 class="sf-lp-notify-subhead">Message Preview</h3>
		<p class="description sf-lp-section-desc">
			<?php if ( $preview['is_placeholder'] ) : ?>
				No submissions have been received yet, so this preview uses placeholder data.
			<?php else : ?>
				Rendered from the most recent real submission.
			<?php endif; ?>
			<?php if ( ! $enabled ) : ?>
				Auto-Reply is currently switched off, so this message is not being sent to anyone.
			<?php endif; ?>
		</p>
		<div class="sf-lp-preview">
			<div class="sf-lp-preview-head">
				<div><span class="sf-lp-preview-label">Subject</span> <?php echo esc_html( $subject ); ?></div>
				<div><span class="sf-lp-preview-label">To</span> <?php echo esc_html( $submission['email'] ?? '' ); ?></div>
			</div>
			<pre class="sf-lp-preview-text"><?php echo esc_html( $body ); ?></pre>
		</div>
		<p class="sf-lp-preview-actions">
			<button type="button" class="button button-secondary" id="sf-lp-send-test-visitor">Send Test Notification</button>
			<span class="description">
				Sends this exact subject and message to an address you choose below, using whatever
				first name you type in place of <code>{first_name}</code> — doesn't touch real
				submissions or require Auto-Reply to be switched on.
			</span>
		</p>
		<div class="sf-lp-test-visitor-fields" id="sf-lp-test-visitor-fields" hidden>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sf-lp-test-visitor-first-name">First Name</label></th>
					<td><input type="text" id="sf-lp-test-visitor-first-name" class="regular-text" placeholder="Optional"></td>
				</tr>
				<tr>
					<th scope="row"><label for="sf-lp-test-visitor-email">Email Address</label></th>
					<td><input type="email" id="sf-lp-test-visitor-email" class="regular-text" placeholder="you@example.com"></td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="sf-lp-test-visitor-confirm">Send</button>
				<button type="button" class="button" id="sf-lp-test-visitor-cancel">Cancel</button>
				<span id="sf-lp-test-visitor-status" class="sf-lp-inline-status"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Collapsed-by-default log of every notification this plugin has attempted.
	 *
	 * Records the outcome wp_mail() reported — which, with Post SMTP bound to
	 * wp_mail, is Post SMTP's actual send result, including its SMTP error text
	 * on failure. Post SMTP's own log has the full transcript per message.
	 */
	private static function render_email_log() {
		$log      = SF_LP_Notifications::get_log();
		$smtp_url = SF_LP_Notifications::post_smtp_log_url();
		?>
		<h2 id="sf-lp-email-log">Notification Email Log</h2>
		<details class="sf-lp-log">
			<summary>
				Sent notifications
				<span class="sf-lp-log-count"><?php echo esc_html( count( $log ) ); ?></span>
			</summary>
			<div class="sf-lp-log-body">
				<p class="description">
					The last <?php echo esc_html( SF_LP_Notifications::LOG_MAX ); ?> notification emails this
					plugin tried to send.
					<?php if ( $smtp_url ) : ?>
						Full delivery transcripts (and resend) live in
						<a href="<?php echo esc_url( $smtp_url ); ?>">Post SMTP &rarr; Email Log</a>.
					<?php endif; ?>
				</p>

				<?php if ( empty( $log ) ) : ?>
					<p class="sf-lp-log-empty">Nothing sent yet.</p>
				<?php else : ?>
					<table class="widefat striped sf-lp-log-table">
						<thead>
							<tr>
								<th>When</th>
								<th>Recipient</th>
								<th>Subject</th>
								<th>Submission</th>
								<th>Result</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( SF_LP_Notifications::format_date( $entry['time'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( $entry['to'] ?? '' ); ?></td>
									<td>
										<?php echo esc_html( $entry['subject'] ?? '' ); ?>
										<?php if ( ! empty( $entry['is_test'] ) ) : ?>
											<span class="sf-lp-admin-only-tag">test</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $entry['submission_id'] ) ) : ?>
											#<?php echo esc_html( $entry['submission_id'] ); ?>
										<?php else : ?>
											&mdash;
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $entry['success'] ) ) : ?>
											<span class="sf-lp-badge sf-lp-badge-completed">Sent</span>
										<?php else : ?>
											<span class="sf-lp-badge sf-lp-badge-failed">Failed</span>
											<?php if ( ! empty( $entry['error'] ) ) : ?>
												<span class="sf-lp-log-error"><?php echo esc_html( $entry['error'] ); ?></span>
											<?php endif; ?>
										<?php endif; ?>
										<span class="sf-lp-log-via"><?php echo esc_html( $entry['via'] ?? '' ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<button type="button" class="button" id="sf-lp-clear-log">Clear Log</button>
						<span id="sf-lp-log-status" class="sf-lp-inline-status"></span>
					</p>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	public static function ajax_send_test_notification() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$preview = SF_LP_Notifications::preview_submission();
		$result  = SF_LP_Notifications::send_for_submission( $preview['submission'], true );

		if ( ! empty( $result['skipped'] ) ) {
			wp_send_json_error( array( 'message' => 'No recipient is switched on. Add one and save before testing.' ), 400 );
		}

		wp_send_json_success( $result );
	}

	public static function ajax_clear_email_log() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		SF_LP_Notifications::clear_log();

		wp_send_json_success( array( 'cleared' => true ) );
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

	/**
	 * Sends the wp-admin-configured plain-text acknowledgement to a visitor's own
	 * submitted address, if the Settings → Notifications to Visitors toggle is on.
	 *
	 * Hung off the same `sf_lp_submission_received` action Notifications uses (see
	 * the bootstrap file) rather than called directly from the REST controller, so
	 * it only ever fires for a real submission — the sample-data actions above
	 * call SF_LP_DB::insert() directly and never reach this hook.
	 *
	 * Best-effort: a mail failure here must never surface as a failed submission,
	 * and an uncaught exception would otherwise propagate out of do_action() and
	 * back into the REST response — so this is wrapped the same defensive way
	 * SF_LP_Notifications::on_submission_received() is.
	 *
	 * @param int   $submission_id
	 * @param array $submission Row as stored by SF_LP_DB — must have 'email'.
	 */
	public static function maybe_send_visitor_acknowledgement( $submission_id, $submission ) {
		try {
			if ( '1' !== get_option( self::OPTION_VISITOR_REPLY_ENABLED, '' ) ) {
				return;
			}

			$email = is_array( $submission ) ? ( $submission['email'] ?? '' ) : '';

			if ( empty( $email ) || ! is_email( $email ) ) {
				return;
			}

			$message = get_option( self::OPTION_VISITOR_REPLY_MESSAGE, self::DEFAULT_VISITOR_REPLY_MESSAGE );

			if ( '' === trim( $message ) ) {
				return;
			}

			$subject = get_option( self::OPTION_VISITOR_REPLY_SUBJECT, self::DEFAULT_VISITOR_REPLY_SUBJECT );
			if ( '' === trim( $subject ) ) {
				// An admin can save the field blank; unlike a blank message (which
				// cancels the send above), a blank subject just falls back rather
				// than mailing with no subject line at all.
				$subject = self::DEFAULT_VISITOR_REPLY_SUBJECT;
			}

			$first_name = is_array( $submission ) ? (string) ( $submission['first_name'] ?? '' ) : '';

			$subject = self::apply_visitor_tokens( $subject, $first_name );
			$message = self::apply_visitor_tokens( $message, $first_name );

			// Explicit plain-text content type: the message is edited as plain text in
			// Settings and must stay plain text in the sent email regardless of any
			// wp_mail_content_type filter another plugin (e.g. Post SMTP) on this install
			// might set.
			wp_mail( $email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		} catch ( Throwable $e ) {
			// Swallow — see the doc comment above. Nothing to log to; this plugin's
			// only send log belongs to SF_LP_Notifications, not this simpler feature.
		}
	}

	/**
	 * Sends a test copy of the visitor acknowledgement to an admin-chosen
	 * address, substituting an admin-chosen first name. Bypasses the Auto-Reply
	 * toggle entirely — a test send has to work even while the feature itself
	 * is switched off, the same way SF_LP_Notifications' admin test-send
	 * ignores nothing but does require at least one enabled recipient (there's
	 * no equivalent gate here since the address is typed in per-send, not
	 * drawn from a stored list).
	 *
	 * @param string $email      Address to send to (validated by the caller).
	 * @param string $first_name Value to substitute for {first_name}.
	 * @return bool True if wp_mail() reported success.
	 */
	public static function send_test_visitor_acknowledgement( $email, $first_name ) {
		$subject = get_option( self::OPTION_VISITOR_REPLY_SUBJECT, self::DEFAULT_VISITOR_REPLY_SUBJECT );
		if ( '' === trim( $subject ) ) {
			$subject = self::DEFAULT_VISITOR_REPLY_SUBJECT;
		}

		$message = get_option( self::OPTION_VISITOR_REPLY_MESSAGE, self::DEFAULT_VISITOR_REPLY_MESSAGE );

		$subject = '[TEST] ' . self::apply_visitor_tokens( $subject, $first_name );
		$message = self::apply_visitor_tokens( $message, $first_name );

		return (bool) wp_mail( $email, $subject, $message, array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	public static function ajax_send_test_visitor_notification() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Enter a valid email address to send the test to.' ), 400 );
		}

		$sent = self::send_test_visitor_acknowledgement( $email, $first_name );

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => 'wp_mail() reported failure — check your mail configuration.' ), 500 );
		}

		wp_send_json_success( array( 'email' => $email ) );
	}
}
