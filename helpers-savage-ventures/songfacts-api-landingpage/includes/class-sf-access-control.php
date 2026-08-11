<?php
/**
 * SF_LP Access Control — role-based page access management.
 *
 * Same schema as the Savage Media Indexing Database plugin's SMID_Access_Control:
 * grants are stored per-role in a single option, and the matching
 * `sf_lp_access_*` capabilities are synthesized at runtime via `user_has_cap`
 * (never written into the roles table). Settings stays admin-only always.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_Access_Control {

	const OPTION     = 'sf_lp_access_control';
	const NONCE      = 'sf_lp_access_control_nonce';
	const NONCE_DIAG = 'sf_lp_access_diag_nonce';
	const CAP_PREFIX = 'sf_lp_access_';
	const CAP_ANY    = 'sf_lp_any_access';

	/**
	 * Run the user_has_cap filter very late, so a role-manager / membership plugin
	 * that rebuilds $allcaps at a lower priority can't silently drop our grants.
	 */
	const FILTER_PRIORITY = 9999;

	/**
	 * Grants that can be handed to non-admin roles.
	 * Settings (JWT secret + sample data) is always admin-only.
	 *
	 * 'menu'    => true means holding this grant reveals the top-level menu.
	 * 'implies' => other grant keys this one automatically carries with it.
	 */
	public static function get_configurable_pages() {
		return array(
			'submissions'      => array(
				'label'   => 'View Submissions',
				'icon'    => 'dashicons-email-alt',
				'desc'    => 'See the Songfacts API CRM menu, the submissions list, and the expandable row details.',
				'menu'    => true,
				'implies' => array(),
			),
			'submissions_edit' => array(
				'label'   => 'Edit Submissions',
				'icon'    => 'dashicons-edit',
				'desc'    => 'Mark submissions as completed. Automatically includes view access.',
				'menu'    => false,
				'implies' => array( 'submissions' ),
			),
		);
	}

	/**
	 * Registers the user_has_cap filter (and the admin nag). Called at plugin
	 * load time, not on `init`.
	 */
	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), self::FILTER_PRIORITY, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_no_grants_notice' ) );
	}

	/**
	 * Dynamically grant sf_lp_access_* capabilities based on saved role settings.
	 */
	public static function filter_caps( $allcaps, $caps, $args, $user ) {
		$is_admin = ! empty( $allcaps['manage_options'] );
		$roles    = ( $user instanceof WP_User ) ? $user->roles : array();
		$grants   = null; // resolved lazily, only when one of our caps is asked for

		foreach ( (array) $caps as $cap ) {
			if ( self::CAP_ANY !== $cap && 0 !== strpos( $cap, self::CAP_PREFIX ) ) {
				continue;
			}

			// Admin always gets every sf_lp cap.
			if ( $is_admin ) {
				$allcaps[ $cap ] = true;
				continue;
			}

			if ( null === $grants ) {
				$grants = self::resolve_grants( $roles );
			}

			// sf_lp_any_access — controls top-level menu visibility.
			if ( self::CAP_ANY === $cap ) {
				$allcaps[ $cap ] = self::grants_reveal_menu( $grants );
				continue;
			}

			// Explicitly grant OR deny — the false is critical because it overrides any
			// sf_lp_access_* cap that may have been stored directly in the role/user
			// capabilities in the WP database from previous testing or manual assignment.
			$page_key        = substr( $cap, strlen( self::CAP_PREFIX ) );
			$allcaps[ $cap ] = ! empty( $grants[ $page_key ] );
		}

		return $allcaps;
	}

	/**
	 * Resolve the effective grant set for a set of roles, applying the 'implies'
	 * relationships.
	 *
	 * The implication matters: without it, a role toggled to "Edit Submissions"
	 * only would hold an edit cap while the menu and the list screen stayed
	 * invisible — a switched-on grant that appears to do nothing. Every
	 * combination of toggles must produce a reachable screen.
	 *
	 * @return array grant_key => bool
	 */
	public static function resolve_grants( $roles, $settings = null ) {
		if ( null === $settings ) {
			$settings = get_option( self::OPTION, array() );
		}

		$pages  = self::get_configurable_pages();
		$grants = array_fill_keys( array_keys( $pages ), false );

		foreach ( $pages as $page_key => $page_info ) {
			foreach ( (array) $roles as $role ) {
				if ( ! empty( $settings[ $role ][ $page_key ] ) ) {
					$grants[ $page_key ] = true;
					break;
				}
			}
		}

		// Apply implications (single pass is enough for the current one-level graph).
		foreach ( $pages as $page_key => $page_info ) {
			if ( empty( $grants[ $page_key ] ) ) {
				continue;
			}
			foreach ( (array) $page_info['implies'] as $implied ) {
				if ( array_key_exists( $implied, $grants ) ) {
					$grants[ $implied ] = true;
				}
			}
		}

		return $grants;
	}

	/**
	 * Does this resolved grant set reveal the top-level menu?
	 */
	public static function grants_reveal_menu( $grants ) {
		foreach ( self::get_configurable_pages() as $page_key => $page_info ) {
			if ( ! empty( $page_info['menu'] ) && ! empty( $grants[ $page_key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if any of the given roles have a specific grant (implications applied).
	 */
	public static function role_has_access( $roles, $page_key, $settings = null ) {
		$grants = self::resolve_grants( $roles, $settings );
		return ! empty( $grants[ $page_key ] );
	}

	/**
	 * Check if any of the given roles hold a grant that should reveal the menu.
	 */
	public static function any_role_access( $roles, $settings = null ) {
		return self::grants_reveal_menu( self::resolve_grants( $roles, $settings ) );
	}

	/**
	 * Convenience wrappers used by the admin screens and AJAX handlers.
	 * Administrators pass both by virtue of filter_caps().
	 */
	public static function can_view() {
		return current_user_can( self::CAP_PREFIX . 'submissions' );
	}

	public static function can_edit() {
		return current_user_can( self::CAP_PREFIX . 'submissions_edit' );
	}

	/**
	 * True if at least one non-admin role has at least one grant switched on.
	 */
	public static function has_any_grants() {
		$settings = get_option( self::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		foreach ( $settings as $role_grants ) {
			foreach ( (array) $role_grants as $value ) {
				if ( ! empty( $value ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get all WP roles except Administrator.
	 */
	public static function get_non_admin_roles() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new WP_Roles();
		}
		$result = array();
		foreach ( $wp_roles->get_names() as $key => $name ) {
			if ( 'administrator' === $key ) {
				continue;
			}
			$result[ $key ] = translate_user_role( $name );
		}
		return $result;
	}

	/**
	 * Admin nag: the plugin is active but nobody has been granted anything yet,
	 * so every non-admin still sees nothing. Easy to miss otherwise — grants
	 * default to off and activation does not seed them.
	 */
	public static function maybe_no_grants_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'sf-lp-' ) || 'sf-lp-access-control' === $page ) {
			return;
		}
		if ( self::has_any_grants() ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>Songfacts API CRM:</strong> no roles have been granted access yet, so only administrators can see this menu. <a href="%s">Open Access Control</a> to grant a role.</p></div>',
			esc_url( admin_url( 'admin.php?page=sf-lp-access-control' ) )
		);
	}

	/**
	 * Render the Access Control settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		// ── Handle form save ──────────────────────────────────────────
		$saved = false;
		if ( isset( $_POST['sf_lp_access_save'] ) && check_admin_referer( self::NONCE ) ) {
			$new_settings = array();
			$roles        = self::get_non_admin_roles();
			$pages        = self::get_configurable_pages();

			foreach ( $roles as $role_key => $role_name ) {
				$new_settings[ $role_key ] = array();
				foreach ( $pages as $page_key => $page_info ) {
					$new_settings[ $role_key ][ $page_key ] =
						isset( $_POST['sf_lp_access'][ $role_key ][ $page_key ] ) ? 1 : 0;
				}
			}
			update_option( self::OPTION, $new_settings );
			$saved = true;
		}

		$settings = get_option( self::OPTION, array() );
		$roles    = self::get_non_admin_roles();
		$pages    = self::get_configurable_pages();
		?>
		<div class="wrap sf-lp-access-wrap">

			<h1 class="sf-lp-page-title">
				<span class="dashicons dashicons-shield-alt"></span>
				Access Control
			</h1>

			<p class="sf-lp-access-desc">
				Choose which WordPress roles can see and edit Songfacts API CRM submissions.
				Administrators always have full access to everything. Granting <strong>Edit
				Submissions</strong> automatically includes view access, so a role never ends up
				with a switched-on grant it cannot reach.
			</p>

			<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><strong>Access settings saved successfully.</strong></p>
			</div>
			<?php endif; ?>

			<?php if ( ! self::has_any_grants() ) : ?>
			<div class="notice notice-warning">
				<p>
					No roles are granted access right now — every non-administrator sees no
					Songfacts API CRM menu at all. Switch on a toggle below and save.
				</p>
			</div>
			<?php endif; ?>

			<form method="post" id="sf-lp-access-form">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="sf-lp-access-card">

					<table class="sf-lp-access-table">
						<thead>
							<tr>
								<th class="col-page">Page / Feature</th>
								<th class="col-admin">
									<div class="sf-lp-role-head">
										<span class="dashicons dashicons-admin-users"></span>
										Administrator
									</div>
								</th>
								<?php foreach ( $roles as $role_key => $role_name ) : ?>
								<th>
									<div class="sf-lp-role-head">
										<span class="dashicons dashicons-businessman"></span>
										<?php echo esc_html( $role_name ); ?>
										<code class="sf-lp-role-key"><?php echo esc_html( $role_key ); ?></code>
									</div>
								</th>
								<?php endforeach; ?>
							</tr>
						</thead>

						<tbody>

							<?php foreach ( $pages as $page_key => $page_info ) : ?>
							<tr>
								<td class="col-page">
									<span class="dashicons <?php echo esc_attr( $page_info['icon'] ); ?>"></span>
									<span>
										<strong><?php echo esc_html( $page_info['label'] ); ?></strong>
										<span class="sf-lp-access-hint"><?php echo esc_html( $page_info['desc'] ); ?></span>
									</span>
								</td>
								<td class="col-admin">
									<span class="sf-lp-full-access">
										<span class="dashicons dashicons-yes-alt"></span>
										Full Access
									</span>
								</td>
								<?php foreach ( $roles as $role_key => $role_name ) : ?>
								<td class="col-toggle">
									<label class="sf-lp-toggle">
										<input
											type="checkbox"
											name="sf_lp_access[<?php echo esc_attr( $role_key ); ?>][<?php echo esc_attr( $page_key ); ?>]"
											value="1"
											<?php checked( ! empty( $settings[ $role_key ][ $page_key ] ) ); ?>
										>
										<span class="sf-lp-toggle-track">
											<span class="sf-lp-toggle-thumb"></span>
										</span>
									</label>
								</td>
								<?php endforeach; ?>
							</tr>
							<?php endforeach; ?>

							<!-- Admin-only locked rows -->
							<?php
							$locked_pages = array(
								'Settings' => 'dashicons-admin-generic',
							);
							foreach ( $locked_pages as $label => $icon ) :
							?>
							<tr class="sf-lp-row-locked">
								<td class="col-page">
									<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
									<span>
										<strong><?php echo esc_html( $label ); ?></strong>
										<span class="sf-lp-admin-only-tag">Admin only</span>
									</span>
								</td>
								<td class="col-admin">
									<span class="sf-lp-full-access">
										<span class="dashicons dashicons-yes-alt"></span>
										Full Access
									</span>
								</td>
								<?php foreach ( $roles as $role_key => $role_name ) : ?>
								<td class="col-toggle">
									<span class="sf-lp-locked-icon">
										<span class="dashicons dashicons-lock"></span>
									</span>
								</td>
								<?php endforeach; ?>
							</tr>
							<?php endforeach; ?>

						</tbody>
					</table>

				</div><!-- .sf-lp-access-card -->

				<div class="sf-lp-access-footer">
					<button type="submit" name="sf_lp_access_save" class="button button-primary sf-lp-save-btn">
						<span class="dashicons dashicons-saved"></span>
						Save Access Settings
					</button>
				</div>

			</form>

			<?php self::render_diagnostics(); ?>

		</div>
		<?php
	}

	/**
	 * Collapsed diagnostics block. Exists because "the menu isn't showing for
	 * role X" is otherwise unfalsifiable from the outside: it shows what is
	 * actually stored, what the filter actually resolves, and who else is
	 * filtering capabilities on this site.
	 */
	private static function render_diagnostics() {
		$settings = get_option( self::OPTION, false );
		$roles    = self::get_non_admin_roles();
		$pages    = self::get_configurable_pages();

		// Optional single-user lookup.
		$checked_user = null;
		if ( isset( $_POST['sf_lp_check_user'] ) && check_admin_referer( self::NONCE_DIAG ) ) {
			$needle = sanitize_text_field( wp_unslash( $_POST['sf_lp_check_user'] ) );
			if ( '' !== $needle ) {
				$checked_user = is_email( $needle )
					? get_user_by( 'email', $needle )
					: get_user_by( 'login', $needle );
				if ( ! $checked_user ) {
					$checked_user = get_user_by( 'slug', $needle );
				}
			}
		}
		?>
		<details class="sf-lp-diag">
			<summary>Diagnostics — why can't a role see the menu?</summary>

			<div class="sf-lp-diag-body">

				<h3>Stored option <code><?php echo esc_html( self::OPTION ); ?></code></h3>
				<?php if ( false === $settings ) : ?>
					<p class="sf-lp-diag-bad">
						The option does not exist yet — the Access Control form has never been
						saved on this site. Nothing is granted.
					</p>
				<?php elseif ( ! self::has_any_grants() ) : ?>
					<p class="sf-lp-diag-bad">
						The option exists but every grant is off. Nothing is granted.
					</p>
				<?php else : ?>
					<table class="widefat striped sf-lp-diag-table">
						<thead>
							<tr>
								<th>Role key</th>
								<?php foreach ( $pages as $page_key => $page_info ) : ?>
									<th>Stored <code><?php echo esc_html( $page_key ); ?></code></th>
								<?php endforeach; ?>
								<?php foreach ( $pages as $page_key => $page_info ) : ?>
									<th>Effective <code><?php echo esc_html( $page_key ); ?></code></th>
								<?php endforeach; ?>
								<th>Menu visible</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $roles as $role_key => $role_name ) : ?>
							<?php $grants = self::resolve_grants( array( $role_key ) ); ?>
							<tr>
								<td><code><?php echo esc_html( $role_key ); ?></code></td>
								<?php foreach ( $pages as $page_key => $page_info ) : ?>
									<td><?php echo empty( $settings[ $role_key ][ $page_key ] ) ? '&mdash;' : 'on'; ?></td>
								<?php endforeach; ?>
								<?php foreach ( $pages as $page_key => $page_info ) : ?>
									<td><?php echo empty( $grants[ $page_key ] ) ? '&mdash;' : '<strong>yes</strong>'; ?></td>
								<?php endforeach; ?>
								<td><?php echo self::grants_reveal_menu( $grants ) ? '<strong>yes</strong>' : '&mdash;'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">
						If the role you granted is not listed above, it was added by another plugin
						after this page rendered, or its role key differs from the one you toggled.
					</p>
				<?php endif; ?>

				<h3>Check a specific user</h3>
				<form method="post">
					<?php wp_nonce_field( self::NONCE_DIAG ); ?>
					<p>
						<input type="text" name="sf_lp_check_user" class="regular-text"
							placeholder="username or email"
							value="<?php echo $checked_user ? esc_attr( $checked_user->user_login ) : ''; ?>">
						<button type="submit" class="button">Check user</button>
					</p>
				</form>

				<?php if ( isset( $_POST['sf_lp_check_user'] ) && ! $checked_user ) : ?>
					<p class="sf-lp-diag-bad">No user found with that username or email.</p>
				<?php elseif ( $checked_user ) : ?>
					<?php $user_grants = self::resolve_grants( $checked_user->roles ); ?>
					<table class="widefat striped sf-lp-diag-table">
						<tbody>
							<tr>
								<th>User</th>
								<td><?php echo esc_html( $checked_user->user_login ); ?> (ID <?php echo (int) $checked_user->ID; ?>)</td>
							</tr>
							<tr>
								<th>Role key(s) actually assigned</th>
								<td>
									<?php
									echo $checked_user->roles
										? '<code>' . esc_html( implode( '</code>, <code>', $checked_user->roles ) ) . '</code>'
										: '<em>none</em>';
									?>
								</td>
							</tr>
							<tr>
								<th>Resolved from settings</th>
								<td>
									<?php
									$parts = array();
									foreach ( $user_grants as $page_key => $on ) {
										$parts[] = esc_html( $page_key ) . ': ' . ( $on ? '<strong>yes</strong>' : 'no' );
									}
									echo implode( ' &nbsp;|&nbsp; ', $parts );
									?>
								</td>
							</tr>
							<tr>
								<th>Live <code>user_can()</code> result</th>
								<td>
									<?php
									$live = array(
										self::CAP_ANY                        => user_can( $checked_user, self::CAP_ANY ),
										self::CAP_PREFIX . 'submissions'     => user_can( $checked_user, self::CAP_PREFIX . 'submissions' ),
										self::CAP_PREFIX . 'submissions_edit' => user_can( $checked_user, self::CAP_PREFIX . 'submissions_edit' ),
									);
									$parts = array();
									foreach ( $live as $cap => $on ) {
										$parts[] = '<code>' . esc_html( $cap ) . '</code>: ' . ( $on ? '<strong>yes</strong>' : 'no' );
									}
									echo implode( '<br>', $parts );
									?>
									<p class="description">
										This runs the full capability chain, every plugin included. If it
										disagrees with "Resolved from settings" above, another plugin is
										overriding these capabilities — see the filter list below.
									</p>
								</td>
							</tr>
						</tbody>
					</table>
				<?php endif; ?>

				<h3>Callbacks on the <code>user_has_cap</code> filter</h3>
				<?php
				global $wp_filter;
				$rows = array();
				if ( isset( $wp_filter['user_has_cap'] ) && isset( $wp_filter['user_has_cap']->callbacks ) ) {
					foreach ( $wp_filter['user_has_cap']->callbacks as $priority => $callbacks ) {
						foreach ( $callbacks as $callback ) {
							$rows[] = array( $priority, self::describe_callback( $callback['function'] ) );
						}
					}
				}
				?>
				<?php if ( $rows ) : ?>
					<table class="widefat striped sf-lp-diag-table">
						<thead><tr><th>Priority</th><th>Callback</th></tr></thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row[0] ); ?></td>
								<td><code><?php echo esc_html( $row[1] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">
						This plugin runs at priority <?php echo (int) self::FILTER_PRIORITY; ?>. Anything
						listed at a <em>higher</em> number runs after it and could overwrite these grants.
					</p>
				<?php else : ?>
					<p><em>No callbacks registered.</em></p>
				<?php endif; ?>

				<h3>Environment</h3>
				<ul class="sf-lp-diag-list">
					<li>Plugin version: <code><?php echo esc_html( SF_LP_VERSION ); ?></code></li>
					<li>Access control class file loaded: <code><?php echo esc_html( basename( __FILE__ ) ); ?></code></li>
					<li>Multisite: <code><?php echo is_multisite() ? 'yes' : 'no'; ?></code></li>
				</ul>

			</div>
		</details>
		<?php
	}

	/**
	 * Human-readable name for a filter callback, for the diagnostics table.
	 */
	private static function describe_callback( $fn ) {
		if ( is_string( $fn ) ) {
			return $fn . '()';
		}
		if ( is_array( $fn ) && 2 === count( $fn ) ) {
			$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
			return $class . '::' . (string) $fn[1] . '()';
		}
		if ( $fn instanceof Closure ) {
			return 'Closure';
		}
		if ( is_object( $fn ) ) {
			return get_class( $fn ) . '::__invoke()';
		}
		return 'unknown';
	}
}
