<?php
/**
 * Administrator email notifications for new interest-form submissions.
 *
 * Recipients (and a per-recipient on/off toggle) are configured at
 * Songfacts API CRM → Settings → "Notifications to Administrators".
 *
 * Deliberately does NOT set a From header: the site's own sender identity
 * applies (Post SMTP's configured sender when that plugin is active, otherwise
 * WordPress's `wordpress@<host>` default plus any `wp_mail_from` filters). The
 * Settings screen only *displays* that effective identity — it is not editable
 * here, and changing it means changing it where it actually lives.
 *
 * Reply-To *is* set, to the submitter's own address, so an admin can reply
 * straight from the notification.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_Notifications {

	/** Option: list of [ email, label, enabled ] recipient rows. */
	const OPTION_RECIPIENTS = 'sf_lp_notification_recipients';

	/** Option: rolling send log (non-autoloaded). */
	const OPTION_LOG = 'sf_lp_notification_log';

	/** Most recent entries kept in the log; older ones are discarded. */
	const LOG_MAX = 100;

	/** Hard cap on configured recipients, to keep one REST request bounded. */
	const MAX_RECIPIENTS = 25;

	/** Option: editable subject template — see apply_tokens() for merge tokens. */
	const OPTION_SUBJECT = 'sf_lp_notification_subject';

	/**
	 * Reproduces the exact wording build_subject() used to hardcode, so an admin
	 * who never touches this field sees no change in what gets sent.
	 */
	const DEFAULT_SUBJECT = '[{site_name}] New Songfacts API interest submission from {submitter_name}';

	public static function init() {
		add_action( 'sf_lp_submission_received', array( __CLASS__, 'on_submission_received' ), 10, 2 );
	}

	/* ──────────────────────────────────────────────────────────────
	   Recipients
	   ────────────────────────────────────────────────────────────── */

	/**
	 * @return array List of array{email: string, label: string, enabled: int}.
	 */
	public static function get_recipients() {
		$stored = get_option( self::OPTION_RECIPIENTS, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$recipients = array();

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['email'] ) ) {
				continue;
			}

			$recipients[] = array(
				'email'   => (string) $row['email'],
				'label'   => isset( $row['label'] ) ? (string) $row['label'] : '',
				'enabled' => empty( $row['enabled'] ) ? 0 : 1,
			);
		}

		return $recipients;
	}

	/**
	 * @return array Only the rows with their toggle switched on.
	 */
	public static function get_enabled_recipients() {
		return array_values(
			array_filter(
				self::get_recipients(),
				function ( $row ) {
					return ! empty( $row['enabled'] );
				}
			)
		);
	}

	/**
	 * `register_setting` sanitize callback for OPTION_RECIPIENTS.
	 *
	 * Drops blank rows silently (that's how a row is deleted), reports invalid
	 * addresses and duplicates back to the Settings screen rather than saving them.
	 *
	 * @param mixed $raw Raw $_POST value.
	 * @return array
	 */
	public static function sanitize_recipients( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean  = array();
		$seen   = array();
		$errors = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$email = isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';

			// A blank email is how the UI represents "removed" — not an error.
			if ( '' === $email ) {
				continue;
			}

			$sanitized = sanitize_email( $email );

			if ( '' === $sanitized || ! is_email( $sanitized ) ) {
				$errors[] = sprintf( '%s is not a valid email address and was not saved.', esc_html( $email ) );
				continue;
			}

			$key = strtolower( $sanitized );

			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( '%s was listed more than once; the duplicate was dropped.', esc_html( $sanitized ) );
				continue;
			}

			if ( count( $clean ) >= self::MAX_RECIPIENTS ) {
				$errors[] = sprintf( 'A maximum of %d recipients can be saved; the rest were dropped.', self::MAX_RECIPIENTS );
				break;
			}

			$seen[ $key ] = true;

			$clean[] = array(
				'email'   => $sanitized,
				'label'   => mb_substr( $label, 0, 100 ),
				'enabled' => empty( $row['enabled'] ) ? 0 : 1,
			);
		}

		foreach ( array_unique( $errors ) as $message ) {
			add_settings_error( self::OPTION_RECIPIENTS, 'sf_lp_recipient_invalid', $message, 'error' );
		}

		return $clean;
	}

	/* ──────────────────────────────────────────────────────────────
	   Sender identity (display only)
	   ────────────────────────────────────────────────────────────── */

	/**
	 * The From identity these notifications will actually go out with.
	 *
	 * Mirrors the resolution order mail actually takes: Post SMTP's configured
	 * sender when that plugin is active (it replaces `wp_mail` wholesale), else
	 * WordPress's own `wordpress@<host>` / "WordPress" default — then the
	 * `wp_mail_from` / `wp_mail_from_name` filters on top, unless Post SMTP is
	 * configured to enforce its sender and ignore overrides.
	 *
	 * @return array{email: string, name: string, source: string, enforced: bool}
	 */
	public static function get_from_identity() {
		$sitename = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		$email    = 'wordpress@' . $sitename;
		$name     = 'WordPress';
		$source   = 'WordPress default';
		$enforced = false;

		if ( class_exists( 'PostmanOptions' ) ) {
			$options = PostmanOptions::getInstance();

			$ps_email = method_exists( $options, 'getMessageSenderEmail' ) ? $options->getMessageSenderEmail() : '';
			$ps_name  = method_exists( $options, 'getMessageSenderName' ) ? $options->getMessageSenderName() : '';

			if ( ! empty( $ps_email ) ) {
				$email  = $ps_email;
				$source = 'Post SMTP';
			}
			if ( ! empty( $ps_name ) ) {
				$name   = $ps_name;
				$source = 'Post SMTP';
			}

			if ( method_exists( $options, 'isPluginSenderEmailEnforced' ) ) {
				$enforced = (bool) $options->isPluginSenderEmailEnforced();
			}
		}

		if ( ! $enforced ) {
			$email = apply_filters( 'wp_mail_from', $email );
			$name  = apply_filters( 'wp_mail_from_name', $name );
		}

		return array(
			'email'    => (string) $email,
			'name'     => (string) $name,
			'source'   => $source,
			'enforced' => $enforced,
		);
	}

	/**
	 * @return bool Whether Post SMTP is present and handling wp_mail.
	 */
	public static function post_smtp_active() {
		return class_exists( 'PostmanOptions' );
	}

	/**
	 * @return string URL of Post SMTP's own delivery log, or '' if unavailable.
	 */
	public static function post_smtp_log_url() {
		return self::post_smtp_active() ? admin_url( 'admin.php?page=postman_email_log' ) : '';
	}

	/* ──────────────────────────────────────────────────────────────
	   Message composition
	   ────────────────────────────────────────────────────────────── */

	public static function build_subject( $submission ) {
		$template = get_option( self::OPTION_SUBJECT, self::DEFAULT_SUBJECT );

		if ( '' === trim( (string) $template ) ) {
			// An admin can save the field blank; fall back rather than mailing with
			// no subject line at all.
			$template = self::DEFAULT_SUBJECT;
		}

		return self::apply_tokens( $template, $submission );
	}

	/**
	 * `register_setting` sanitize callback for OPTION_SUBJECT. A plain one-line
	 * field — no textarea, so no line breaks to preserve.
	 */
	public static function sanitize_subject( $value ) {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Merge tokens available in the admin-notification subject template.
	 * {submitter_name} carries the same "fall back to email if no name" logic the
	 * subject line always had, so a template built from these tokens can
	 * reproduce the original hardcoded wording exactly (see DEFAULT_SUBJECT).
	 */
	private static function apply_tokens( $text, $submission ) {
		$first = (string) ( $submission['first_name'] ?? '' );
		$last  = (string) ( $submission['last_name'] ?? '' );
		$name  = trim( $first . ' ' . $last );
		$name  = '' !== $name ? $name : (string) ( $submission['email'] ?? 'someone' );

		$tokens = array(
			'{first_name}'     => $first,
			'{last_name}'      => $last,
			'{submitter_name}' => $name,
			'{email}'          => (string) ( $submission['email'] ?? '' ),
			'{company}'        => (string) ( $submission['company'] ?? '' ),
			'{site_name}'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		return strtr( (string) $text, $tokens );
	}

	/**
	 * Every field the visitor submitted, in display order, as label => value.
	 *
	 * Known columns first, then anything extra that arrived in the payload but
	 * has no column of its own — so a new field added to the landing-page form
	 * still shows up in the notification without a code change here.
	 *
	 * @return array<string, string>
	 */
	public static function submitted_fields( $submission ) {
		$fields = array(
			'First Name' => (string) ( $submission['first_name'] ?? '' ),
			'Last Name'  => (string) ( $submission['last_name'] ?? '' ),
			'Email'      => (string) ( $submission['email'] ?? '' ),
			'Company'    => (string) ( $submission['company'] ?? '' ),
			'Message'    => (string) ( $submission['message'] ?? '' ),
		);

		$known   = array( 'firstName', 'lastName', 'email', 'company', 'message', 'submittedAt', 'turnstileToken' );
		$payload = array();

		if ( ! empty( $submission['raw_payload'] ) ) {
			$decoded = json_decode( (string) $submission['raw_payload'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		foreach ( $payload as $key => $value ) {
			if ( in_array( $key, $known, true ) || is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$label = ucwords( trim( preg_replace( '/(?<!^)[A-Z]/', ' $0', (string) $key ) ) );
			if ( ! isset( $fields[ $label ] ) ) {
				$fields[ $label ] = (string) $value;
			}
		}

		return $fields;
	}

	/**
	 * Format a stored UTC datetime in the site's timezone, matching the
	 * Submissions list. Falls back to "—" for a missing value.
	 */
	public static function format_date( $mysql_utc ) {
		if ( empty( $mysql_utc ) || '0000-00-00 00:00:00' === $mysql_utc ) {
			return '—';
		}

		$format = get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' );

		return get_date_from_gmt( $mysql_utc, $format );
	}

	/**
	 * The HTML body an administrator receives.
	 *
	 * Table-based, inline-styled, no external assets — the lowest common
	 * denominator that renders the same in webmail and desktop clients.
	 */
	public static function build_body( $submission ) {
		$fields       = self::submitted_fields( $submission );
		$submitted_at = self::format_date( $submission['submitted_at'] ?? '' );
		$received_at  = self::format_date( $submission['received_at'] ?? '' );
		$site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$admin_url    = admin_url( 'admin.php?page=sf-lp-submissions' );

		ob_start();
		?>
<div style="margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1d2327;">
	<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #dcdcde;border-radius:8px;">
		<tr>
			<td style="padding:24px 28px 8px;">
				<p style="margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#787c82;">Songfacts API Landing Page</p>
				<h1 style="margin:0 0 6px;font-size:20px;line-height:1.3;color:#1d2327;">New interest form submission</h1>
				<p style="margin:0;font-size:13px;color:#646970;">
					Submitted <strong style="color:#1d2327;"><?php echo esc_html( $submitted_at ); ?></strong>
					<?php if ( $received_at !== $submitted_at ) : ?>
						&middot; received <?php echo esc_html( $received_at ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<tr>
			<td style="padding:16px 28px 4px;">
				<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-size:14px;">
					<?php foreach ( $fields as $label => $value ) : ?>
						<tr>
							<th align="left" valign="top" style="padding:10px 16px 10px 0;border-bottom:1px solid #f0f0f1;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;white-space:nowrap;width:130px;">
								<?php echo esc_html( $label ); ?>
							</th>
							<td valign="top" style="padding:10px 0;border-bottom:1px solid #f0f0f1;color:#1d2327;">
								<?php
								if ( '' === trim( $value ) ) {
									echo '<span style="color:#a7aaad;">—</span>';
								} elseif ( is_email( $value ) ) {
									printf(
										'<a href="mailto:%1$s" style="color:#2271b1;">%1$s</a>',
										esc_attr( $value )
									);
								} else {
									echo nl2br( esc_html( $value ) );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th align="left" valign="top" style="padding:10px 16px 10px 0;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;white-space:nowrap;">
							Date Submitted
						</th>
						<td valign="top" style="padding:10px 0;color:#1d2327;">
							<?php echo esc_html( $submitted_at ); ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td style="padding:12px 28px 28px;">
				<a href="<?php echo esc_url( $admin_url ); ?>" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:4px;">
					View in <?php echo esc_html( $site_name ); ?>
				</a>
			</td>
		</tr>
		<tr>
			<td style="padding:14px 28px;border-top:1px solid #f0f0f1;background:#fafafa;border-radius:0 0 8px 8px;">
				<p style="margin:0;font-size:12px;line-height:1.6;color:#787c82;">
					You are receiving this because your address is switched on under
					<em>Songfacts API CRM &rarr; Settings &rarr; Notifications to Administrators</em>
					on <?php echo esc_html( $site_name ); ?>.
				</p>
			</td>
		</tr>
	</table>
</div>
		<?php
		return trim( ob_get_clean() );
	}

	/**
	 * A submission array to render the Settings-page preview from: the most
	 * recent real one if there is any, otherwise a fabricated placeholder so the
	 * preview is never blank on a fresh install.
	 *
	 * @return array{submission: array, is_placeholder: bool}
	 */
	public static function preview_submission() {
		$page = SF_LP_DB::get_page( 1, 1 );

		if ( ! empty( $page['items'][0] ) ) {
			return array(
				'submission'     => $page['items'][0],
				'is_placeholder' => false,
			);
		}

		$now = current_time( 'mysql', true );

		return array(
			'submission'     => array(
				'id'           => 0,
				'first_name'   => 'Jane',
				'last_name'    => 'Doe',
				'email'        => 'jane.doe@example.com',
				'company'      => 'Example Media Group',
				'message'      => "We're building a music trivia app and would like to discuss API access and pricing.",
				'submitted_at' => $now,
				'received_at'  => $now,
				'raw_payload'  => '',
			),
			'is_placeholder' => true,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	   Sending
	   ────────────────────────────────────────────────────────────── */

	/**
	 * Strip anything that would break (or let someone inject into) an RFC 5322
	 * display name before it goes into a To/Reply-To header.
	 */
	private static function header_safe_name( $name ) {
		$name = preg_replace( '/[\r\n]+/', ' ', (string) $name );
		$name = str_replace( array( '<', '>', '"', ',', ';', ':', '@' ), '', $name );

		return trim( $name );
	}

	/**
	 * Fired by the REST controller once a real submission has been stored.
	 * Never lets a mail problem affect the REST response.
	 *
	 * @param int   $submission_id
	 * @param array $submission
	 */
	public static function on_submission_received( $submission_id, $submission ) {
		try {
			self::send_for_submission( $submission, false );
		} catch ( Throwable $e ) {
			self::log_add(
				array(
					'to'            => '',
					'subject'       => '',
					'submission_id' => (int) $submission_id,
					'success'       => false,
					'error'         => 'Uncaught: ' . $e->getMessage(),
					'is_test'       => false,
				)
			);
		}
	}

	/**
	 * Mail every enabled recipient about one submission, logging each send.
	 *
	 * @param array $submission Submission row (as stored).
	 * @param bool  $is_test    Marks the log entries, and prefixes the subject.
	 * @return array{sent: int, failed: int, skipped: bool}
	 */
	public static function send_for_submission( $submission, $is_test = false ) {
		$recipients = self::get_enabled_recipients();

		if ( empty( $recipients ) ) {
			return array(
				'sent'    => 0,
				'failed'  => 0,
				'skipped' => true,
			);
		}

		$subject = self::build_subject( $submission );
		if ( $is_test ) {
			$subject = '[TEST] ' . $subject;
		}

		$body    = self::build_body( $submission );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Reply-To points at the submitter — but never on a test send, where the
		// preview is rendered from a real recent submission and an admin hitting
		// Reply would otherwise mail an actual customer about a test.
		$reply_to = ( ! $is_test && isset( $submission['email'] ) ) ? sanitize_email( $submission['email'] ) : '';
		if ( $reply_to && is_email( $reply_to ) ) {
			$reply_name = self::header_safe_name(
				trim( ( $submission['first_name'] ?? '' ) . ' ' . ( $submission['last_name'] ?? '' ) )
			);
			$headers[]  = '' !== $reply_name
				? sprintf( 'Reply-To: %s <%s>', $reply_name, $reply_to )
				: sprintf( 'Reply-To: %s', $reply_to );
		}

		$sent   = 0;
		$failed = 0;

		foreach ( $recipients as $recipient ) {
			$to    = $recipient['email'];
			$label = self::header_safe_name( $recipient['label'] );
			if ( '' !== $label ) {
				$to = sprintf( '%s <%s>', $label, $recipient['email'] );
			}

			// wp_mail() only returns a bool; the reason lives on wp_mail_failed
			// (Post SMTP fires it too, with the SMTP error text).
			$error   = '';
			$capture = function ( $wp_error ) use ( &$error ) {
				if ( is_wp_error( $wp_error ) ) {
					$error = $wp_error->get_error_message();
				}
			};

			add_action( 'wp_mail_failed', $capture );
			$ok = wp_mail( $to, $subject, $body, $headers );
			remove_action( 'wp_mail_failed', $capture );

			if ( $ok ) {
				++$sent;
			} else {
				++$failed;
			}

			self::log_add(
				array(
					'to'            => $recipient['email'],
					'subject'       => $subject,
					'submission_id' => (int) ( $submission['id'] ?? 0 ),
					'success'       => (bool) $ok,
					'error'         => $ok ? '' : ( $error ?: 'wp_mail() returned false with no error detail.' ),
					'is_test'       => (bool) $is_test,
				)
			);
		}

		return array(
			'sent'    => $sent,
			'failed'  => $failed,
			'skipped' => false,
		);
	}

	/* ──────────────────────────────────────────────────────────────
	   Send log
	   ────────────────────────────────────────────────────────────── */

	/**
	 * @return array Newest first.
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Prepend one entry, trimming the log to LOG_MAX.
	 */
	public static function log_add( $entry ) {
		$entry = wp_parse_args(
			$entry,
			array(
				'to'            => '',
				'subject'       => '',
				'submission_id' => 0,
				'success'       => false,
				'error'         => '',
				'is_test'       => false,
			)
		);

		$entry['time'] = current_time( 'mysql', true );
		$entry['via']  = self::post_smtp_active() ? 'Post SMTP' : 'wp_mail';

		$log = self::get_log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LOG_MAX );

		// Never autoload: this is only ever read on the Settings screen.
		if ( false === get_option( self::OPTION_LOG, false ) ) {
			add_option( self::OPTION_LOG, $log, '', 'no' );
		} else {
			update_option( self::OPTION_LOG, $log, false );
		}
	}

	public static function clear_log() {
		update_option( self::OPTION_LOG, array(), false );
	}
}
