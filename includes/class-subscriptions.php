<?php
/**
 * DINA Subscriptions – Subscription management for GoBookMe SaaS.
 *
 * @package GoBookMeSaaS
 * @subpackage Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DINA_Subscriptions
 *
 * Handles subscription lifecycle: activation, creation, cancellation,
 * and overdue suspension/auto-cancellation.
 *
 * @since 1.0.0
 */
class DINA_Subscriptions {

	/**
	 * Database table name (with prefix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'dinia_subscriptions';
	}

	/**
	 * Retrieve active subscriptions for a given customer.
	 *
	 * @param int $customer_id Customer (user) ID.
	 *
	 * @return array|null Array of subscription rows, or null on failure.
	 *
	 * @since 1.0.0
	 */
	public function get_active( int $customer_id ): ?array {
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->table}
			 WHERE customer_id = %d
			   AND status = 'active'
			 ORDER BY created_at DESC",
			$customer_id
		);

		$results = $this->db->get_results( $sql, ARRAY_A );

		return false === $results ? null : $results;
	}

	/**
	 * Create a new subscription record.
	 *
	 * @param int         $customer_id Customer (user) ID.
	 * @param int         $plan_id     Subscription plan ID.
	 * @param string      $interval    Billing interval (e.g. 'monthly', 'yearly').
	 *
	 * @return int|false The new subscription ID on success, false on failure.
	 *
	 * @since 1.0.0
	 */
	public function create( int $customer_id, int $plan_id, string $interval ) {
		$data = array(
			'customer_id'       => $customer_id,
			'plan_id'           => $plan_id,
			'interval'          => $interval,
			'status'            => 'active',
			'current_period_start' => current_time( 'mysql' ),
			'current_period_end'   => $this->calculate_period_end( $interval ),
			'created_at'        => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		$format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

		$inserted = $this->db->insert( $this->table, $data, $format );

		if ( false === $inserted ) {
			return false;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Cancel all active subscriptions for a given customer.
	 *
	 * Sets status to 'cancelled' and records the cancellation timestamp.
	 *
	 * @param int $customer_id Customer (user) ID.
	 *
	 * @return int|false Number of rows updated, or false on failure.
	 *
	 * @since 1.0.0
	 */
	public function cancel( int $customer_id ) {
		$result = $this->db->update(
			$this->table,
			array(
				'status'      => 'cancelled',
				'cancelled_at' => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
			),
			array(
				'customer_id' => $customer_id,
				'status'      => 'active',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		return $result;
	}

	/**
	 * Suspend overdue subscriptions and auto-cancel those overdue by 30+ days.
	 *
	 * Checks for active subscriptions whose current_period_end is in the past.
	 * - Sets status = 'suspended' for overdue subscriptions.
	 * - Sets status = 'cancelled' for subscriptions overdue by 30 days or more.
	 *
	 * @return array{ suspended: int, cancelled: int } Count of affected rows.
	 *
	 * @since 1.0.0
	 */
	public function suspend_overdue(): array {
		$now = current_time( 'mysql' );

		// 1) Suspend overdue subscriptions (past period end, still active).
		$suspended = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				 SET status     = 'suspended',
				     updated_at = %s
				 WHERE status   = 'active'
				   AND current_period_end < %s
				   AND current_period_end >= DATE_SUB(%s, INTERVAL 30 DAY)",
				$now,
				$now,
				$now
			)
		);

		// 2) Auto-cancel subscriptions overdue by 30+ days.
		$cancelled = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table}
				 SET status       = 'cancelled',
				     cancelled_at = %s,
				     updated_at   = %s
				 WHERE status     = 'active'
				   AND current_period_end < DATE_SUB(%s, INTERVAL 30 DAY)",
				$now,
				$now,
				$now
			)
		);

		return array(
			'suspended' => false === $suspended ? 0 : $suspended,
			'cancelled' => false === $cancelled ? 0 : $cancelled,
		);
	}

	/**
	 * Calculate the period end datetime based on the billing interval.
	 *
	 * @param string $interval Billing interval ('monthly', 'yearly', etc.).
	 *
	 * @return string MySQL datetime string of the period end.
	 *
	 * @since 1.0.0
	 */
	private function calculate_period_end( string $interval ): string {
		$map = array(
			'daily'     => '+1 day',
			'weekly'    => '+1 week',
			'monthly'   => '+1 month',
			'quarterly' => '+3 months',
			'yearly'    => '+1 year',
		);

		$modifier = $map[ $interval ] ?? '+1 month';

		return gmdate( 'Y-m-d H:i:s', strtotime( $modifier ) );
	}
}
