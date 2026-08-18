<?php
/**
 * Submissions list table — extends core WP_List_Table.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SF_LP_List_Table extends WP_List_Table {

	private $status_filter = '';

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'         => 'Name',
			'email'        => 'Email',
			'company'      => 'Company',
			'submitted_at' => 'Submitted',
			'status'       => 'Status',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'submitted_at' => array( 'submitted_at', true ),
		);
	}

	public function get_views() {
		$base = admin_url( 'admin.php?page=sf-lp-submissions' );

		$views = array(
			'all'       => sprintf( '<a href="%s"%s>All</a>', esc_url( $base ), '' === $this->status_filter ? ' class="current"' : '' ),
			'new'       => sprintf( '<a href="%s"%s>New</a>', esc_url( add_query_arg( 'status', 'new', $base ) ), 'new' === $this->status_filter ? ' class="current"' : '' ),
			'completed' => sprintf( '<a href="%s"%s>Completed</a>', esc_url( add_query_arg( 'status', 'completed', $base ) ), 'completed' === $this->status_filter ? ' class="current"' : '' ),
		);

		return $views;
	}

	public function no_items() {
		echo 'No submissions received yet.';
	}

	public function prepare_items() {
		$per_page = 20;
		$current_page = $this->get_pagenum();

		$this->status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		$result = SF_LP_DB::get_page( $per_page, $current_page, $this->status_filter );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	public function single_row( $item ) {
		$row_id = 'sf-lp-row-' . (int) $item['id'];
		echo '<tr id="' . esc_attr( $row_id ) . '" class="sf-lp-row" data-id="' . (int) $item['id'] . '">';
		$this->single_row_columns( $item );
		echo '</tr>';

		$colspan = count( $this->get_columns() );
		echo '<tr id="' . esc_attr( $row_id ) . '-detail" class="sf-lp-detail-row" style="display:none;">';
		echo '<td colspan="' . (int) $colspan . '">';
		echo '<div class="sf-lp-detail">';
		echo '<p><strong>Message:</strong><br>' . ( $item['message'] ? nl2br( esc_html( $item['message'] ) ) : '<em>(none)</em>' ) . '</p>';
		echo '<p><strong>Received:</strong> ' . esc_html( $item['received_at'] ) . ' UTC</p>';
		if ( ! empty( $item['completed_at'] ) ) {
			echo '<p><strong>Completed:</strong> ' . esc_html( $item['completed_at'] ) . ' UTC</p>';
		}
		echo '<p><strong>Visitor Acknowledgement:</strong> ' . self::render_acknowledgement_status( $item ) . '</p>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Renders the "did the visitor acknowledgement email actually go out"
	 * status for one submission's detail row.
	 *
	 * This plugin's own send path (SF_LP_Admin::maybe_send_visitor_acknowledgement())
	 * keeps no record of its own — Post SMTP's Email Log is the only record,
	 * per the task: "leverage Post SMTP records that will already exist."
	 */
	private static function render_acknowledgement_status( $item ) {
		$log = self::find_acknowledgement_log( $item );

		if ( null === $log ) {
			return '<em>Not sent</em>';
		}

		return sprintf(
			'<span class="sf-lp-badge %1$s">%2$s</span> %3$s',
			esc_attr( $log['class'] ),
			esc_html( $log['label'] ),
			esc_html( $log['time'] )
		);
	}

	/**
	 * Looks up the Post SMTP log entry (if any) for the acknowledgement email
	 * this submission's own address would have received, correlated purely by
	 * recipient address + a time window starting at received_at — there's no
	 * shared ID between the two plugins to join on directly.
	 *
	 * Deliberately reads the raw {$wpdb->prefix}post_smtp_logs table rather
	 * than calling into any Post SMTP class: Post SMTP is already treated as
	 * optional elsewhere in this plugin (see
	 * SF_LP_Notifications::post_smtp_active()), and this table's shape
	 * (PostmanEmailLogs::install_table(), gated by the postman_db_version
	 * option) has been Post SMTP's storage since its 2.5.0 logging rewrite.
	 *
	 * @param array $item Submission row (needs 'email' and 'received_at').
	 * @return array{time: string, label: string, class: string}|null Null if
	 *         Post SMTP isn't active/on the modern table, or no log row matched.
	 */
	private static function find_acknowledgement_log( $item ) {
		global $wpdb;

		if ( empty( $item['email'] ) || empty( $item['received_at'] ) || ! get_option( 'postman_db_version' ) ) {
			return null;
		}

		// Post SMTP's `time` column is current_time( 'timestamp' ) — the site's
		// local wall-clock time expressed as a Unix timestamp, NOT a true UTC
		// epoch (a well-known WordPress quirk: current_time('timestamp') omits
		// the $gmt argument). received_at is stored as a real UTC MySQL
		// datetime (see SF_LP_DB::insert()), so it has to be converted to that
		// same "local time labeled as UTC" basis before comparing — comparing
		// raw epoch seconds would silently mismatch by the site's UTC offset
		// on any site not actually set to UTC.
		$local_received = get_date_from_gmt( $item['received_at'], 'Y-m-d H:i:s' );
		$window_start   = strtotime( $local_received . ' UTC' );

		if ( false === $window_start ) {
			return null;
		}

		// The send is synchronous, in the same request that inserts the
		// submission, so the real log entry's time is normally within a
		// second or two of received_at — this window is generous padding
		// against clock/request-timing drift, not a real estimate.
		$window_end = $window_start + 10 * MINUTE_IN_SECONDS;

		$table = $wpdb->prefix . 'post_smtp_logs';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT time, success FROM {$table} WHERE to_header = %s AND time >= %d AND time <= %d ORDER BY time ASC LIMIT 1",
				$item['email'],
				$window_start,
				$window_end
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$status = self::interpret_post_smtp_success( $row['success'] );

		return array(
			// Matches Post SMTP's own Email Log screen exactly: plain date(),
			// no WordPress timezone conversion — `time` is already the site's
			// local wall-clock value, so running it through get_date_from_gmt()
			// or date_i18n() here would apply the UTC offset a second time.
			'time'  => date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ),
			'label' => $status['label'],
			'class' => $status['class'],
		);
	}

	/**
	 * Mirrors the exact success/failure interpretation Post SMTP's own Email
	 * Log screen uses for its `success` column (see
	 * PostmanEmailLogs::get_logs_ajax()) — the column isn't a clean boolean,
	 * it's '1', one of two special strings, or an arbitrary error message, so
	 * this has to match that quirk rather than "simplify" it, or a legitimate
	 * fallback/queued send would show as Failed here while Post SMTP's own
	 * log shows it as Success.
	 */
	private static function interpret_post_smtp_success( $value ) {
		if ( '1' === (string) $value ) {
			return array( 'label' => 'Sent', 'class' => 'sf-lp-badge-completed' );
		}

		if ( 'Sent ( ** Fallback ** )' === $value ) {
			return array( 'label' => 'Sent (fallback)', 'class' => 'sf-lp-badge-completed' );
		}

		if ( 'In Queue' === $value ) {
			return array( 'label' => 'In Queue', 'class' => 'sf-lp-badge-new' );
		}

		return array( 'label' => 'Failed: ' . $value, 'class' => 'sf-lp-badge-failed' );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return esc_html( trim( $item['first_name'] . ' ' . $item['last_name'] ) );
			case 'email':
				return esc_html( $item['email'] );
			case 'company':
				return $item['company'] ? esc_html( $item['company'] ) : '&#8212;';
			case 'submitted_at':
				return esc_html( $item['submitted_at'] ? $item['submitted_at'] : $item['received_at'] );
			case 'status':
				return $this->render_status_cell( $item );
			default:
				return '';
		}
	}

	private function render_status_cell( $item ) {
		$is_completed = SF_LP_DB::STATUS_COMPLETED === $item['status'];

		$badge = $is_completed
			? '<span class="sf-lp-badge sf-lp-badge-completed">Completed</span>'
			: '<span class="sf-lp-badge sf-lp-badge-new">New</span>';

		// Read-only roles (Submissions granted, Edit Submissions not) see the badge only.
		$button = ( $is_completed || ! SF_LP_Access_Control::can_edit() )
			? ''
			: sprintf(
				'<button type="button" class="button button-small sf-lp-mark-completed" data-id="%d">Mark as Completed</button>',
				(int) $item['id']
			);

		return $badge . ' ' . $button;
	}
}
