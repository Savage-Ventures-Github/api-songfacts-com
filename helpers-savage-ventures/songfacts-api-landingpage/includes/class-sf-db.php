<?php
/**
 * Data access layer for the sflp_submissions table.
 */

defined( 'ABSPATH' ) || exit;

class SF_LP_DB {

	const STATUS_NEW       = 'new';
	const STATUS_COMPLETED = 'completed';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'sflp_submissions';
	}

	/**
	 * Create (or upgrade) the submissions table. Called on activation.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(191) NOT NULL DEFAULT '',
			last_name VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			company VARCHAR(191) NOT NULL DEFAULT '',
			message TEXT NULL,
			submitted_at DATETIME NULL,
			received_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			raw_payload LONGTEXT NULL,
			completed_at DATETIME NULL,
			is_sample TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY received_at (received_at),
			KEY is_sample (is_sample)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a new submission row.
	 *
	 * @param array $fields Sanitized fields: first_name, last_name, email, company, message, submitted_at, raw_payload.
	 * @return int|false Insert ID, or false on failure.
	 */
	public static function insert( $fields ) {
		global $wpdb;

		$status = in_array( $fields['status'] ?? '', array( self::STATUS_NEW, self::STATUS_COMPLETED ), true )
			? $fields['status']
			: self::STATUS_NEW;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'first_name'   => $fields['first_name'],
				'last_name'    => $fields['last_name'],
				'email'        => $fields['email'],
				'company'      => $fields['company'],
				'message'      => $fields['message'],
				'submitted_at' => $fields['submitted_at'],
				'received_at'  => $fields['received_at'] ?? current_time( 'mysql', true ),
				'status'       => $status,
				'completed_at' => self::STATUS_COMPLETED === $status ? current_time( 'mysql', true ) : null,
				'raw_payload'  => $fields['raw_payload'] ?? null,
				'is_sample'    => empty( $fields['is_sample'] ) ? 0 : 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Insert ten fake submissions flagged is_sample=1, for exercising the admin UI
	 * before real traffic exists. Safe to call repeatedly — always adds ten more.
	 *
	 * @return int Number of rows inserted.
	 */
	public static function insert_sample_batch() {
		$samples = array(
			array( 'Ada', 'Lovelace', 'ada@example.com', 'Analytical Engines', 'Interested in historical trivia endpoints for a museum kiosk.', self::STATUS_COMPLETED, -1 ),
			array( 'Grace', 'Hopper', 'grace@example.com', 'COBOL Consulting', 'Would like pricing for the songfacts + quotes bundle.', self::STATUS_NEW, -2 ),
			array( 'Alan', 'Turing', 'alan@example.com', 'Bletchley Labs', 'Building a trivia game and need bulk API access.', self::STATUS_NEW, -2 ),
			array( 'Katherine', 'Johnson', 'katherine@example.com', 'Orbital Media', 'Can the artistfacts endpoint be filtered by decade?', self::STATUS_COMPLETED, -3 ),
			array( 'Margaret', 'Hamilton', 'margaret@example.com', 'Guidance Systems Inc', '', self::STATUS_NEW, -4 ),
			array( 'Tim', 'Berners-Lee', 'tim@example.com', 'Web Foundation', 'Evaluating the music history calendar for a widget.', self::STATUS_NEW, -5 ),
			array( 'Hedy', 'Lamarr', 'hedy@example.com', 'Spread Spectrum Radio', 'Any rate limits on the blurbs endpoint?', self::STATUS_COMPLETED, -6 ),
			array( 'John', 'McCarthy', 'john@example.com', 'Lisp Machines', 'Interested in a quote-of-the-day integration.', self::STATUS_NEW, -7 ),
			array( 'Radia', 'Perlman', 'radia@example.com', 'Network Protocols LLC', '', self::STATUS_NEW, -8 ),
			array( 'Barbara', 'Liskov', 'barbara@example.com', 'Substitution Principle Co', 'Following up on our call last week.', self::STATUS_COMPLETED, -9 ),
		);

		$inserted = 0;

		foreach ( $samples as $sample ) {
			list( $first, $last, $email, $company, $message, $status, $days_ago ) = $sample;
			$timestamp = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $days_ago ) );

			$id = self::insert(
				array(
					'first_name'   => $first,
					'last_name'    => $last,
					'email'        => $email,
					'company'      => $company,
					'message'      => $message,
					'submitted_at' => $timestamp,
					'received_at'  => $timestamp,
					'status'       => $status,
					'raw_payload'  => wp_json_encode( array( 'sample' => true ) ),
					'is_sample'    => 1,
				)
			);

			if ( $id ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete every row flagged is_sample=1.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function delete_samples() {
		global $wpdb;
		return (int) $wpdb->delete( self::table_name(), array( 'is_sample' => 1 ), array( '%d' ) );
	}

	/**
	 * @return array{items: array, total: int}
	 */
	public static function get_page( $per_page = 20, $page = 1, $status_filter = '' ) {
		global $wpdb;

		$table  = self::table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		$where  = '';
		$params = array();
		if ( in_array( $status_filter, array( self::STATUS_NEW, self::STATUS_COMPLETED ), true ) ) {
			$where    = 'WHERE status = %s';
			$params[] = $status_filter;
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$items_sql = "SELECT * FROM {$table} {$where} ORDER BY received_at DESC LIMIT %d OFFSET %d";
		$items_params = array_merge( $params, array( $per_page, $offset ) );
		$items     = $wpdb->get_results( $wpdb->prepare( $items_sql, $items_params ), ARRAY_A );

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table_name() . " WHERE id = %d", $id ),
			ARRAY_A
		);
	}

	public static function set_status( $id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( self::STATUS_NEW, self::STATUS_COMPLETED ), true ) ) {
			return false;
		}

		return $wpdb->update(
			self::table_name(),
			array(
				'status'       => $status,
				'completed_at' => self::STATUS_COMPLETED === $status ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
