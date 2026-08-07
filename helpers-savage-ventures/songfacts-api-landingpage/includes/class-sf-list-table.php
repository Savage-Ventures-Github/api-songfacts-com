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
		echo '</div>';
		echo '</td>';
		echo '</tr>';
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

		$button = $is_completed
			? ''
			: sprintf(
				'<button type="button" class="button button-small sf-lp-mark-completed" data-id="%d">Mark as Completed</button>',
				(int) $item['id']
			);

		return $badge . ' ' . $button;
	}
}
