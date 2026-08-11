<?php
/**
 * SMID Access Control — role-based page access management
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SMID_Access_Control {

    /**
     * Pages that can be granted to non-admin roles.
     * Connections + Tables are always admin-only.
     */
    public static function get_configurable_pages() {
        return array(
            'oma'        => array( 'label' => 'Online Media Assets', 'icon' => 'dashicons-video-alt3' ),
            'brands'     => array( 'label' => 'Brands',              'icon' => 'dashicons-tag' ),
            'categories' => array( 'label' => 'Asset Categories',    'icon' => 'dashicons-category' ),
            'logs'       => array( 'label' => 'Error Logs',          'icon' => 'dashicons-warning' ),
        );
    }

    /**
     * Hook into user_has_cap to dynamically grant smid_access_* caps.
     * Called once during plugin init.
     */
    public static function init() {
        add_filter( 'user_has_cap', array( __CLASS__, 'filter_caps' ), 10, 4 );
    }

    /**
     * Dynamically grant smid_access_* capabilities based on saved role settings.
     */
    public static function filter_caps( $allcaps, $caps, $args, $user ) {
        $is_admin = ! empty( $allcaps['manage_options'] );
        $settings = get_option( 'smid_access_control', array() );

        foreach ( $caps as $cap ) {
            // Handle smid_any_access — controls main menu visibility
            if ( $cap === 'smid_any_access' ) {
                if ( $is_admin || self::any_role_access( $user->roles, $settings ) ) {
                    $allcaps['smid_any_access'] = true;
                } else {
                    $allcaps['smid_any_access'] = false; // explicit deny
                }
                continue;
            }

            if ( strpos( $cap, 'smid_access_' ) !== 0 ) continue;

            // Admin always gets all smid caps
            if ( $is_admin ) {
                $allcaps[ $cap ] = true;
                continue;
            }

            // Explicitly grant OR deny — the false is critical because it overrides any
            // smid_access_* cap that may have been stored directly in the role/user
            // capabilities in the WP database from previous testing or manual assignment.
            $page_key = str_replace( 'smid_access_', '', $cap );
            if ( self::role_has_access( $user->roles, $page_key, $settings ) ) {
                $allcaps[ $cap ] = true;
            } else {
                $allcaps[ $cap ] = false; // explicit deny — overrides DB-stored caps
            }
        }

        return $allcaps;
    }

    /**
     * Check if any of the given roles have access to a specific page.
     */
    public static function role_has_access( $roles, $page_key, $settings = null ) {
        if ( $settings === null ) {
            $settings = get_option( 'smid_access_control', array() );
        }
        foreach ( (array) $roles as $role ) {
            if ( ! empty( $settings[ $role ][ $page_key ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if any of the given roles have access to ANY configurable page.
     * Used to determine main-menu visibility.
     */
    public static function any_role_access( $roles, $settings = null ) {
        if ( $settings === null ) {
            $settings = get_option( 'smid_access_control', array() );
        }
        $pages = array_keys( self::get_configurable_pages() );
        foreach ( (array) $roles as $role ) {
            if ( empty( $settings[ $role ] ) ) continue;
            foreach ( $pages as $page_key ) {
                if ( ! empty( $settings[ $role ][ $page_key ] ) ) {
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
            if ( $key === 'administrator' ) continue;
            $result[ $key ] = translate_user_role( $name );
        }
        return $result;
    }

    /**
     * Render the Access Control settings page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have permission to access this page.', 'smid' ) );
        }

        // ── Handle form save ──────────────────────────────────────────
        $saved = false;
        if ( isset( $_POST['smid_access_save'] ) && check_admin_referer( 'smid_access_control_nonce' ) ) {
            $new_settings = array();
            $roles = self::get_non_admin_roles();
            $pages = self::get_configurable_pages();

            foreach ( $roles as $role_key => $role_name ) {
                $new_settings[ $role_key ] = array();
                foreach ( $pages as $page_key => $page_info ) {
                    $new_settings[ $role_key ][ $page_key ] =
                        isset( $_POST['smid_access'][ $role_key ][ $page_key ] ) ? 1 : 0;
                }
            }
            update_option( 'smid_access_control', $new_settings );
            $saved = true;
        }

        $settings = get_option( 'smid_access_control', array() );
        $roles    = self::get_non_admin_roles();
        $pages    = self::get_configurable_pages();
        ?>
        <div class="wrap smid-wrap smid-access-wrap">

            <h1 class="smid-page-title">
                <span class="dashicons dashicons-shield-alt"></span>
                <?php _e( 'Access Control', 'smid' ); ?>
            </h1>

            <p class="smid-access-desc">
                <?php _e( 'Choose which WordPress roles can access each plugin page. Administrators always have full access to everything.', 'smid' ); ?>
            </p>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php _e( 'Access settings saved successfully.', 'smid' ); ?></strong></p>
            </div>
            <?php endif; ?>

            <form method="post" id="smid-access-form">
                <?php wp_nonce_field( 'smid_access_control_nonce' ); ?>

                <div class="smid-access-card">

                    <table class="smid-access-table">
                        <thead>
                            <tr>
                                <th class="col-page"><?php _e( 'Page / Feature', 'smid' ); ?></th>
                                <th class="col-admin">
                                    <div class="smid-role-head">
                                        <span class="dashicons dashicons-admin-users"></span>
                                        <?php _e( 'Administrator', 'smid' ); ?>
                                    </div>
                                </th>
                                <?php foreach ( $roles as $role_key => $role_name ) : ?>
                                <th>
                                    <div class="smid-role-head">
                                        <span class="dashicons dashicons-businessman"></span>
                                        <?php echo esc_html( $role_name ); ?>
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
                                    <strong><?php echo esc_html( $page_info['label'] ); ?></strong>
                                </td>
                                <td class="col-admin">
                                    <span class="smid-full-access">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php _e( 'Full Access', 'smid' ); ?>
                                    </span>
                                </td>
                                <?php foreach ( $roles as $role_key => $role_name ) : ?>
                                <td class="col-toggle">
                                    <label class="smid-toggle">
                                        <input
                                            type="checkbox"
                                            name="smid_access[<?php echo esc_attr( $role_key ); ?>][<?php echo esc_attr( $page_key ); ?>]"
                                            value="1"
                                            <?php checked( ! empty( $settings[ $role_key ][ $page_key ] ) ); ?>
                                        >
                                        <span class="smid-toggle-track">
                                            <span class="smid-toggle-thumb"></span>
                                        </span>
                                    </label>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>

                            <!-- Admin-only locked rows -->
                            <?php
                            $locked_pages = array(
                                'DB Connections' => 'dashicons-database',
                                'Tables'         => 'dashicons-editor-table',
                            );
                            foreach ( $locked_pages as $label => $icon ) :
                            ?>
                            <tr class="smid-row-locked">
                                <td class="col-page">
                                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                                    <strong><?php echo esc_html( $label ); ?></strong>
                                    <span class="smid-admin-only-tag"><?php _e( 'Admin only', 'smid' ); ?></span>
                                </td>
                                <td class="col-admin">
                                    <span class="smid-full-access">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php _e( 'Full Access', 'smid' ); ?>
                                    </span>
                                </td>
                                <?php foreach ( $roles as $role_key => $role_name ) : ?>
                                <td class="col-toggle">
                                    <span class="smid-locked-icon">
                                        <span class="dashicons dashicons-lock"></span>
                                    </span>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>

                        </tbody>
                    </table>

                </div><!-- .smid-access-card -->

                <div class="smid-access-footer">
                    <button type="submit" name="smid_access_save" class="button button-primary smid-save-btn">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e( 'Save Access Settings', 'smid' ); ?>
                    </button>
                </div>

            </form>
        </div>
        <?php
    }
}
