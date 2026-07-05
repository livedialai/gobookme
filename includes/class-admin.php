<?php
/**
 * DINA_Admin – WordPress Admin-Oberfläche für GoBookMe SaaS
 *
 * Stellt das Backend-Menü und alle Admin-Seiten bereit:
 * Kundenverwaltung, Pläne, Rechnungen, Backups, Einstellungen.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Admin
 */
class DINA_Admin {

	/**
	 * WordPress-Datenbank-Objekt.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Tabellen-Präfix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Instanz von DINA_Customers.
	 *
	 * @var DINA_Customers
	 */
	private $customers;

	/**
	 * Instanz von DINA_Plans.
	 *
	 * @var DINA_Plans
	 */
	private $plans;

	/**
	 * Instanz von DINA_Invoices.
	 *
	 * @var DINA_Invoices
	 */
	private $invoices;

	/**
	 * Instanz von DINA_Backup.
	 *
	 * @var DINA_Backup
	 */
	private $backup;

	/**
	 * Konstruktor.
	 *
	 * Initialisiert DB-Verbindung, Hilfsklassen und WordPress-Hooks.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb   = $wpdb;
		$this->prefix = $wpdb->prefix;

		$this->customers = new DINA_Customers();
		$this->plans     = new DINA_Plans();
		$this->invoices  = new DINA_Invoices();
		$this->backup    = new DINA_Backup();

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_dashboard_setup', function() {
			if ( ! current_user_can( 'manage_options' ) ) return;
			wp_add_dashboard_widget( 'dinia_dashboard_widget', 'Dinia – Heutige Reservierungen', function() {
				global $wpdb;
				$today = current_time( 'Y-m-d' );
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT r.*, t.name as table_name FROM {$wpdb->prefix}dinia_reservations r
					 LEFT JOIN {$wpdb->prefix}dinia_tables t ON r.table_id = t.id
					 WHERE r.date = %s AND r.status = 'confirmed'
					 ORDER BY r.time_start ASC",
					$today
				) );
				if ( empty( $rows ) ) { echo '<p>Keine Reservierungen für heute.</p>'; return; }
				echo '<table style="width:100%;border-collapse:collapse;">';
				echo '<tr style="background:#f5f5f5;"><th style="padding:6px;text-align:left;border-bottom:1px solid #ddd;">Zeit</th><th style="padding:6px;text-align:left;border-bottom:1px solid #ddd;">Gast</th><th style="padding:6px;text-align:left;border-bottom:1px solid #ddd;">Pers.</th><th style="padding:6px;text-align:left;border-bottom:1px solid #ddd;">Tisch</th></tr>';
				foreach ( $rows as $r ) {
					echo '<tr><td style="padding:4px 6px;border-bottom:1px solid #eee;">' . esc_html( $r->time_start ) . '</td>';
					echo '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . esc_html( $r->guest_name ) . '</td>';
					echo '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . (int) $r->guest_count . '</td>';
					echo '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . esc_html( $r->table_name ?: '—' ) . '</td></tr>';
				}
				echo '</table>';
			} );
		} );
	}

	/**
	 * Admin-Styles einbinden.
	 */
	public function enqueue_styles() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'dinia' ) === false ) {
			return;
		}

		wp_enqueue_script( 'dinia-admin', DINIA_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), DINIA_VERSION, true );
		wp_localize_script( 'dinia-admin', 'wpApiSettings', array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
		?>
		<style>
			.dinia-wrap {
				max-width: 1200px;
				margin: 20px auto;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			}
			.dinia-wrap h1 {
				font-size: 24px;
				font-weight: 600;
				margin-bottom: 20px;
				color: #1d2327;
			}
			.dinia-wrap h2 {
				font-size: 18px;
				font-weight: 500;
				margin: 24px 0 12px;
				color: #2c3338;
			}
			.dinia-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.04);
			}
			.dinia-table-wrap {
				overflow-x: auto;
			}
			.dinia-table {
				width: 100%;
				border-collapse: collapse;
				font-size: 14px;
			}
			.dinia-table th {
				background: #f0f0f1;
				font-weight: 600;
				text-align: left;
				padding: 10px 12px;
				border-bottom: 2px solid #c3c4c7;
				white-space: nowrap;
			}
			.dinia-table td {
				padding: 10px 12px;
				border-bottom: 1px solid #f0f0f1;
				vertical-align: middle;
			}
			.dinia-table tr:hover td {
				background: #f6f7f7;
			}
			.dinia-form label {
				display: block;
				font-weight: 600;
				margin-bottom: 4px;
				color: #2c3338;
			}
			.dinia-form .form-row {
				margin-bottom: 16px;
			}
			.dinia-form input[type="text"],
			.dinia-form input[type="email"],
			.dinia-form input[type="password"],
			.dinia-form input[type="number"],
			.dinia-form select,
			.dinia-form textarea {
				width: 100%;
				max-width: 480px;
				padding: 8px 12px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				font-size: 14px;
				line-height: 1.4;
			}
			.dinia-form textarea {
				min-height: 80px;
			}
			.dinia-form .inline-group {
				display: flex;
				gap: 8px;
				align-items: center;
			}
			.dinia-btn {
				display: inline-block;
				padding: 8px 16px;
				font-size: 14px;
				font-weight: 500;
				text-decoration: none;
				border-radius: 4px;
				cursor: pointer;
				border: 1px solid transparent;
				line-height: 1.4;
				transition: background 0.15s ease;
			}
			.dinia-btn-primary {
				background: #2271b1;
				color: #fff;
				border-color: #2271b1;
			}
			.dinia-btn-primary:hover {
				background: #135e96;
				color: #fff;
			}
			.dinia-btn-secondary {
				background: #f6f7f7;
				color: #2271b1;
				border-color: #2271b1;
			}
			.dinia-btn-secondary:hover {
				background: #f0f0f1;
			}
			.dinia-btn-danger {
				background: #d63638;
				color: #fff;
				border-color: #d63638;
			}
			.dinia-btn-danger:hover {
				background: #b32d2e;
			}
			.dinia-btn-success {
				background: #00a32a;
				color: #fff;
				border-color: #00a32a;
			}
			.dinia-btn-success:hover {
				background: #008a20;
			}
			.dinia-btn-sm {
				padding: 4px 10px;
				font-size: 12px;
			}
			.dinia-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: 500;
			}
			.dinia-badge-active {
				background: #d4f4d4;
				color: #005c1f;
			}
			.dinia-badge-inactive,
			.dinia-badge-pending {
				background: #fef5d4;
				color: #996b00;
			}
			.dinia-badge-suspended {
				background: #ffe0d4;
				color: #a62c00;
			}
			.dinia-badge-cancelled {
				background: #f1f1f1;
				color: #666;
			}
			.dinia-badge-paid {
				background: #d4f4d4;
				color: #005c1f;
			}
			.dinia-alert {
				padding: 12px 16px;
				border-radius: 4px;
				margin-bottom: 16px;
				font-size: 14px;
			}
			.dinia-alert-success {
				background: #d4f4d4;
				border: 1px solid #00a32a;
				color: #005c1f;
			}
			.dinia-alert-error {
				background: #fce5e5;
				border: 1px solid #d63638;
				color: #8a1f1f;
			}
			.dinia-alert-info {
				background: #e5f0fa;
				border: 1px solid #2271b1;
				color: #1d4e7a;
			}
			.dinia-api-key-box {
				background: #f0f6fc;
				border: 1px dashed #2271b1;
				border-radius: 4px;
				padding: 12px 16px;
				font-family: "Courier New", monospace;
				font-size: 14px;
				word-break: break-all;
				margin-top: 8px;
			}
			.dinia-nav-tabs {
				margin-bottom: 0;
				border-bottom: 1px solid #c3c4c7;
				padding-left: 0;
				list-style: none;
				display: flex;
				gap: 0;
			}
			.dinia-nav-tabs li {
				margin-bottom: -1px;
			}
			.dinia-nav-tabs a {
				display: block;
				padding: 10px 18px;
				text-decoration: none;
				color: #646970;
				border: 1px solid transparent;
				border-bottom: none;
				border-radius: 4px 4px 0 0;
				font-weight: 500;
				font-size: 14px;
			}
			.dinia-nav-tabs a:hover {
				color: #135e96;
				background: #f0f0f1;
			}
			.dinia-nav-tabs .active a {
				color: #1d2327;
				background: #fff;
				border-color: #c3c4c7;
			}
			.dinia-meta {
				color: #646970;
				font-size: 12px;
			}
			.dinia-flex {
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.dinia-mb-20 {
				margin-bottom: 20px;
			}

						/* ─── Restaurant-Konfiguration: Farbschema #f5f5f5 / #ff6b00 ─── */
			.dinia-rest-wrap {
				max-width: 1200px;
				margin: 20px auto;
				background: #f5f5f5;
				padding: 20px;
				border-radius: 8px;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			}
			.dinia-rest-wrap h1 {
				font-size: 24px;
				font-weight: 600;
				color: #1d2327;
				margin-bottom: 20px;
			}
			.dinia-rest-wrap h2 {
				font-size: 18px;
				font-weight: 500;
				color: #2c3338;
				margin: 24px 0 12px;
				border-bottom: 2px solid #ff6b00;
				padding-bottom: 6px;
			}
			.dinia-rest-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.04);
			}
			.dinia-rest-btn-primary {
				background: #ff6b00;
				color: #fff;
				border-color: #ff6b00;
			}
			.dinia-rest-btn-primary:hover {
				background: #e05e00;
				color: #fff;
			}
			.dinia-rest-btn-secondary {
				background: #f6f7f7;
				color: #ff6b00;
				border-color: #ff6b00;
			}
			.dinia-rest-btn-secondary:hover {
				background: #f0f0f1;
			}
			.dinia-rest-btn-danger {
				background: #d63638;
				color: #fff;
				border-color: #d63638;
			}
			.dinia-rest-btn-danger:hover {
				background: #b32d2e;
			}
			.dinia-rest-table {
				width: 100%;
				border-collapse: collapse;
				font-size: 14px;
			}
			.dinia-rest-table th {
				background: #ff6b00;
				color: #fff;
				font-weight: 600;
				text-align: left;
				padding: 10px 12px;
				border-bottom: 2px solid #e05e00;
				white-space: nowrap;
			}
			.dinia-rest-table td {
				padding: 10px 12px;
				border-bottom: 1px solid #f0f0f1;
				vertical-align: middle;
			}
			.dinia-rest-table tr:nth-child(even) td {
				background: #fafafa;
			}
			.dinia-rest-table tr:hover td {
				background: #fff3e6;
			}
			.dinia-rest-form label {
				display: block;
				font-weight: 600;
				margin-bottom: 4px;
				color: #2c3338;
			}
			.dinia-rest-form .form-row {
				margin-bottom: 16px;
			}
			.dinia-rest-form input[type="text"],
			.dinia-rest-form input[type="email"],
			.dinia-rest-form input[type="password"],
			.dinia-rest-form input[type="number"],
			.dinia-rest-form input[type="date"],
			.dinia-rest-form input[type="time"],
			.dinia-rest-form select,
			.dinia-rest-form textarea {
				width: 100%;
				max-width: 480px;
				padding: 8px 12px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				font-size: 14px;
				line-height: 1.4;
			}
			.dinia-rest-form input[type="color"] {
				width: 60px;
				height: 36px;
				cursor: pointer;
				vertical-align: middle;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				padding: 2px;
			}
			.dinia-rest-customer-selector {
				background: #fff;
				border: 1px solid #ff6b00;
				border-radius: 6px;
				padding: 16px 20px;
				margin-bottom: 20px;
				display: flex;
				align-items: center;
				gap: 12px;
			}
			.dinia-rest-customer-selector label {
				font-weight: 600;
				color: #2c3338;
				white-space: nowrap;
			}
			.dinia-rest-customer-selector select {
				min-width: 250px;
				padding: 6px 10px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
				font-size: 14px;
			}
			.dinia-rest-modal {
				display: none;
				position: fixed;
				z-index: 9999;
				left: 0;
				top: 0;
				width: 100%;
				height: 100%;
				overflow: auto;
				background: rgba(0,0,0,0.5);
			}
			.dinia-rest-modal-content {
				background: #fff;
				margin: 10% auto;
				padding: 30px;
				border-radius: 8px;
				max-width: 520px;
				box-shadow: 0 4px 20px rgba(0,0,0,0.15);
			}
			.dinia-rest-modal-content h2 {
				border-bottom: 2px solid #ff6b00;
				padding-bottom: 10px;
				margin-top: 0;
			}
			.dinia-rest-status {
				padding: 6px 12px;
				border-radius: 4px;
				font-size: 13px;
				font-weight: 500;
			}
			.dinia-rest-status-ok {
				background: #d4f4d4;
				color: #005c1f;
			}
			.dinia-rest-status-error {
				background: #fce5e5;
				color: #8a1f1f;
			}
			.dinia-rest-code-box {
				background: #f5f5f5;
				border: 1px dashed #ff6b00;
				border-radius: 4px;
				padding: 16px;
				font-family: "Courier New", monospace;
				font-size: 13px;
				word-break: break-all;
				margin: 8px 0;
				position: relative;
				overflow-x: auto;
			}
			.dinia-rest-code-box code {
				white-space: pre-wrap;
			}
			.dinia-rest-copy-btn {
				position: absolute;
				top: 8px;
				right: 8px;
				background: #ff6b00;
				color: #fff;
				border: none;
				padding: 4px 12px;
				border-radius: 4px;
				cursor: pointer;
				font-size: 12px;
			}
			.dinia-rest-copy-btn:hover {
				background: #e05e00;
			}
			.dinia-rest-hours-table th {
				background: #ff6b00;
				color: #fff;
				padding: 8px 12px;
				text-align: left;
				font-size: 13px;
			}
			.dinia-rest-hours-table td {
				padding: 8px 12px;
				border-bottom: 1px solid #e5e5e5;
				vertical-align: middle;
			}
			.dinia-rest-hours-table tr:hover td {
				background: #fff3e6;
			}
			.dinia-rest-filter-bar {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 16px;
				margin-bottom: 16px;
				display: flex;
				gap: 12px;
				align-items: end;
				flex-wrap: wrap;
			}
			.dinia-rest-filter-bar label {
				font-weight: 600;
				font-size: 13px;
				color: #2c3338;
			}
			.dinia-rest-filter-bar select,
			.dinia-rest-filter-bar input {
				padding: 6px 10px;
				border: 1px solid #8c8f94;
				border-radius: 4px;
			}
			.dinia-rest-success {
				background: #d4f4d4;
				border: 1px solid #00a32a;
				border-radius: 4px;
				padding: 12px 16px;
				margin-bottom: 16px;
				color: #005c1f;
				font-size: 14px;
			}
			.dinia-rest-error {
				background: #fce5e5;
				border: 1px solid #d63638;
				border-radius: 4px;
				padding: 12px 16px;
				margin-bottom: 16px;
				color: #8a1f1f;
				font-size: 14px;
			}
			.dinia-rest-badge-confirmed {
				background: #d4f4d4;
				color: #005c1f;
			}
			.dinia-rest-badge-cancelled {
				background: #fce5e5;
				color: #8a1f1f;
			}
			.dinia-rest-badge-pending {
				background: #fef5d4;
				color: #996b00;
			}
		</style>
		<?php
	}

	/**
	 * Admin-Menü und Unterseiten registrieren.
	 */
	public function add_menu_page() {
		add_menu_page(
			'Dinia – GoBookMe SaaS',
			'Dinia',
			'manage_options',
			'dinia',
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page(
			'dinia',
			'Kunden verwalten',
			'Kunden',
			'manage_options',
			'dinia-customers',
			array( $this, 'render_customers' )
		);

		add_submenu_page(
			'dinia',
			'Pläne verwalten',
			'Pläne',
			'manage_options',
			'dinia-plans',
			array( $this, 'render_plans' )
		);

		add_submenu_page(
			'dinia',
			'Rechnungen',
			'Rechnungen',
			'manage_options',
			'dinia-invoices',
			array( $this, 'render_invoices' )
		);

		add_submenu_page(
			'dinia',
			'Backups',
			'Backups',
			'manage_options',
			'dinia-backups',
			array( $this, 'render_backups' )
		);

		add_submenu_page(
			'dinia',
			'Einstellungen',
			'Einstellungen',
			'manage_options',
			'dinia-settings',
			array( $this, 'render_settings' )
		);

		// ─── Restaurant-Konfiguration (9 neue Tabs) ───

		add_submenu_page(
			'dinia',
			'Öffnungszeiten',
			'Öffnungszeiten',
			'manage_options',
			'dinia-rest-hours',
			array( $this, 'render_rest_hours' )
		);

		add_submenu_page(
			'dinia',
			'Tische',
			'Tische',
			'manage_options',
			'dinia-rest-tables',
			array( $this, 'render_rest_tables' )
		);

		add_submenu_page(
			'dinia',
			'Restaurant-Einstellungen',
			'Restaurant',
			'manage_options',
			'dinia-rest-settings',
			array( $this, 'render_rest_settings' )
		);

		add_submenu_page(
			'dinia',
			'E-Mail (Brevo)',
			'E-Mail',
			'manage_options',
			'dinia-rest-email',
			array( $this, 'render_rest_email' )
		);

		add_submenu_page(
			'dinia',
			'CalDAV-Kalender',
			'CalDAV',
			'manage_options',
			'dinia-rest-caldav',
			array( $this, 'render_rest_caldav' )
		);

		add_submenu_page(
			'dinia',
			'Neue Buchung',
			'Neue Buchung',
			'manage_options',
			'dinia-rest-new-booking',
			array( $this, 'render_rest_new_booking' )
		);

		add_submenu_page(
			'dinia',
			'Reservierungen',
			'Reservierungen',
			'manage_options',
			'dinia-rest-reservations',
			array( $this, 'render_rest_reservations' )
		);

		add_submenu_page(
			'dinia',
			'Einbetten',
			'Einbetten',
			'manage_options',
			'dinia-rest-embed',
			array( $this, 'render_rest_embed' )
		);

		add_submenu_page(
			'dinia',
			'Affiliate',
			'Affiliate',
			'manage_options',
			'dinia-rest-affiliate',
			array( $this, 'render_rest_affiliate' )
		);
	}

	/**
	 * Einstellungen registrieren.
	 */
	public function register_settings() {
		register_setting( 'dinia_settings', 'dinia_mollie_api_key' );
		register_setting( 'dinia_settings', 'dinia_mollie_profile_id' );
		register_setting( 'dinia_settings', 'dinia_turnstile_site_key' );
		register_setting( 'dinia_settings', 'dinia_turnstile_secret_key' );
		register_setting( 'dinia_settings', 'dinia_admin_email' );
	}

	/**
	 * POST-Formulare verarbeiten.
	 */
	public function handle_form_submissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// --- Kunde speichern (anlegen / aktualisieren) ---
		if ( isset( $_POST['dinia_save_customer'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_customer_nonce' ) ) {
			$customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;
			$data        = array(
				'company'       => sanitize_text_field( $_POST['company'] ?? '' ),
				'slug'          => sanitize_title( $_POST['slug'] ?? '' ),
				'email'         => sanitize_email( $_POST['email'] ?? '' ),
				'contact_name'  => sanitize_text_field( $_POST['contact_name'] ?? '' ),
				'contact_phone' => sanitize_text_field( $_POST['contact_phone'] ?? '' ),
				'plan_id'       => ! empty( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : null,
				'status'        => sanitize_text_field( $_POST['status'] ?? 'pending' ),
			);

			if ( $customer_id > 0 ) {
				$this->customers->update( $customer_id, $data );
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-customers', 'action' => 'edit', 'id' => $customer_id, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
			} else {
				$data['company'] = sanitize_text_field( $_POST['company'] ?? '' );
				$data['slug']    = sanitize_title( $_POST['slug'] ?? '' );
				$new_id          = $this->customers->create( $data );
				if ( $new_id ) {
					wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-customers', 'action' => 'edit', 'id' => $new_id, 'created' => '1' ), admin_url( 'admin.php' ) ) );
				}
			}
			exit;
		}

		// --- API-Key generieren ---
		if ( isset( $_POST['dinia_generate_api_key'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_customer_nonce' ) ) {
			$customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;
			if ( $customer_id > 0 ) {
				$result = $this->customers->generate_api_key( $customer_id );
				if ( $result ) {
					$redirect = add_query_arg(
						array(
							'page'        => 'dinia-customers',
							'action'      => 'edit',
							'id'          => $customer_id,
							'api_key'     => urlencode( $result['key'] ),
							'api_generated' => '1',
						),
						admin_url( 'admin.php' )
					);
					wp_safe_redirect( $redirect );
					exit;
				}
			}
		}

		// --- Kunde löschen ---
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete_customer' === $_GET['action'] ) {
			if ( wp_verify_nonce( $_GET['_wpnonce'], 'dinia_delete_customer_' . (int) $_GET['id'] ) ) {
				$this->customers->delete( (int) $_GET['id'] );
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-customers', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		// --- Plan speichern (anlegen / aktualisieren) ---
		if ( isset( $_POST['dinia_save_plan'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_plan_nonce' ) ) {
			$plan_id = isset( $_POST['plan_id'] ) ? (int) $_POST['plan_id'] : 0;
			$data    = array(
				'name'              => sanitize_text_field( $_POST['name'] ?? '' ),
				'description'       => sanitize_textarea_field( $_POST['description'] ?? '' ),
				'price_monthly'     => (float) ( $_POST['price_monthly'] ?? 0 ),
				'price_yearly'      => (float) ( $_POST['price_yearly'] ?? 0 ),
				'max_tables'        => (int) ( $_POST['max_tables'] ?? 5 ),
				'max_reservations_day' => (int) ( $_POST['max_reservations_day'] ?? 50 ),
				'max_employees'     => (int) ( $_POST['max_employees'] ?? 1 ),
				'sort_order'        => (int) ( $_POST['sort_order'] ?? 0 ),
				'active'            => isset( $_POST['active'] ) ? 1 : 0,
			);

			if ( $plan_id > 0 ) {
				$this->plans->update( $plan_id, $data );
			} else {
				$plan_id = $this->plans->create( $data );
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-plans', 'updated' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// --- Plan löschen ---
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete_plan' === $_GET['action'] ) {
			if ( wp_verify_nonce( $_GET['_wpnonce'], 'dinia_delete_plan_' . (int) $_GET['id'] ) ) {
				$this->plans->delete( (int) $_GET['id'] );
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-plans', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		// --- Backup erstellen ---
		if ( isset( $_POST['dinia_create_backup'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_backup_nonce' ) ) {
			$result = $this->backup->create_backup( 'manual' );
			if ( $result ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-backups', 'backup_created' => '1' ), admin_url( 'admin.php' ) ) );
			} else {
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-backups', 'backup_error' => '1' ), admin_url( 'admin.php' ) ) );
			}
			exit;
		}

		// --- Backup löschen ---
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && 'delete_backup' === $_GET['action'] ) {
			if ( wp_verify_nonce( $_GET['_wpnonce'], 'dinia_delete_backup_' . (int) $_GET['id'] ) ) {
				$this->backup->delete_backup( (int) $_GET['id'] );
				wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-backups', 'deleted' => '1' ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		// ─── VERTRAG KÜNDIGEN (Cancel Contract) ───
		if ( isset( $_POST['action'] ) && 'dinia_cancel_contract' === $_POST['action']
			&& wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_cancel_contract_nonce' ) ) {
			$customer_id = isset( $_POST['customer_id'] ) ? (int) $_POST['customer_id'] : 0;
			if ( $customer_id > 0 ) {
				$customer = $this->customers->get_by_id( $customer_id );
				if ( $customer && ! empty( $customer->mollie_customer_id ) ) {
					// 1) Mollie-Subscriptions holen & kündigen
					$mollie        = new DINA_Mollie();
					$subs_result   = $mollie->get_subscriptions( $customer->mollie_customer_id );
					$cancelled_ids = array();
					if ( $subs_result['success'] && ! empty( $subs_result['subscriptions'] ) ) {
						foreach ( $subs_result['subscriptions'] as $sub ) {
							if ( in_array( $sub['status'], array( 'active', 'pending' ), true ) ) {
								$cancel_result = $mollie->cancel_subscription( $customer->mollie_customer_id, $sub['id'] );
								if ( $cancel_result['success'] ) {
									$cancelled_ids[] = $sub['id'];
								}
							}
						}
					}

					// 2) Lokales Abonnement kündigen
					$subscriptions = new DINA_Subscriptions();
					$subscriptions->cancel( $customer_id );

					// 3) Customer-Status auf cancelled setzen
					$this->wpdb->update(
						$this->prefix . 'dinia_customers',
						array( 'status' => 'cancelled' ),
						array( 'id' => $customer_id )
					);

					// 4) Email an Kunden senden
					if ( ! empty( $customer->email ) ) {
						$date_formatted = date_i18n( 'l, j. F Y H:i' );
						$subject        = sprintf( __( 'Vertrag gekündigt – %s', 'dinia' ), $customer->company );
						$html  = '<h2>🔴 Vertrag gekündigt</h2>';
						$html .= '<p>Hallo <strong>' . esc_html( $customer->company ) . '</strong>,</p>';
						$html .= '<p>Ihr Vertrag wurde zum <strong>' . esc_html( $date_formatted ) . '</strong> gekündigt.</p>';
						if ( ! empty( $cancelled_ids ) ) {
							$html .= '<p>Das Mollie-Abo wurde gestoppt. Es werden keine weiteren Zahlungen eingezogen.</p>';
						}
						$html .= '<p>Bei Fragen kontaktieren Sie uns bitte.</p>';
						$html_body = DINA_Mailer::build_html( $subject, $html, $customer->company );
						DINA_Mailer::send( $customer->email, $subject, 'Ihr Vertrag wurde gekündigt', $html_body );
					}
				}
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-customers', 'action' => 'edit', 'id' => $customer_id, 'contract_cancelled' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ─── RESTAURANT: Öffnungszeiten speichern ───
		if ( isset( $_POST['dinia_save_hours'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_rest_hours_nonce' ) ) {
			$customer_id = isset( $_POST['rest_customer_id'] ) ? (int) $_POST['rest_customer_id'] : 0;
			if ( $customer_id > 0 && isset( $_POST['hours'] ) && is_array( $_POST['hours'] ) ) {
				$table = $this->prefix . 'dinia_hours';
				$day_keys = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
				foreach ( $day_keys as $key ) {
					$h = isset( $_POST['hours'][ $key ] ) ? $_POST['hours'][ $key ] : array();
					$data = array(
						'customer_id' => $customer_id,
						'day_key'     => $key,
						'open'        => sanitize_text_field( $h['open'] ?? '11:00' ),
						'close'       => sanitize_text_field( $h['close'] ?? '22:00' ),
						'open2'       => sanitize_text_field( $h['open2'] ?? '' ),
						'close2'      => sanitize_text_field( $h['close2'] ?? '' ),
						'closed'      => isset( $h['closed'] ) ? 1 : 0,
					);
					$exists = $this->wpdb->get_var( $this->wpdb->prepare(
						"SELECT id FROM {$table} WHERE customer_id = %d AND day_key = %s LIMIT 1",
						$customer_id, $key
					) );
					if ( $exists ) {
						$this->wpdb->update( $table, $data, array( 'customer_id' => $customer_id, 'day_key' => $key ) );
					} else {
						$this->wpdb->insert( $table, $data );
					}
				}
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-rest-hours', 'rest_customer_id' => $customer_id, 'hours_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ─── RESTAURANT: Einstellungen speichern (JSON in dinia_customers.settings) ───
		if ( isset( $_POST['dinia_save_rest_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_rest_settings_nonce' ) ) {
			$customer_id = isset( $_POST['rest_customer_id'] ) ? (int) $_POST['rest_customer_id'] : 0;
			if ( $customer_id > 0 ) {
				$settings = array(
					'restaurant_name'    => sanitize_text_field( $_POST['restaurant_name'] ?? '' ),

					'slot_duration'      => min( 180, max( 30, (int) ( $_POST['slot_duration'] ?? 120 ) ) ),
					'slot_interval'      => in_array( (int) ( $_POST['slot_interval'] ?? 30 ), array( 15, 30, 60 ) ) ? (int) $_POST['slot_interval'] : 30,
					'min_advance_hours'  => min( 48, max( 1, (int) ( $_POST['min_advance_hours'] ?? 2 ) ) ),
					'max_advance_days'   => min( 90, max( 1, (int) ( $_POST['max_advance_days'] ?? 30 ) ) ),
					'primary_color'      => sanitize_hex_color( $_POST['primary_color'] ?? '#ff6b00' ),
					'email_confirm'      => isset( $_POST['email_confirm'] ) ? 1 : 0,
					'email_reminder'     => isset( $_POST['email_reminder'] ) ? 1 : 0,
					'reminder_hours'     => min( 168, max( 1, (int) ( $_POST['reminder_hours'] ?? 24 ) ) ),
					'admin_notify_email' => sanitize_email( $_POST['admin_notify_email'] ?? get_option( 'admin_email' ) ),
				);
				$this->wpdb->update(
					$this->prefix . 'dinia_customers',
					array( 'settings' => wp_json_encode( $settings ) ),
					array( 'id' => $customer_id )
				);
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-rest-settings', 'rest_customer_id' => $customer_id, 'rest_settings_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ─── E-MAIL (BREVO) speichern ───
		if ( isset( $_POST['dinia_save_brevo'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_brevo_nonce' ) ) {
			update_option( 'dinia_brevo_api_key', sanitize_text_field( $_POST['dinia_brevo_api_key'] ?? '' ) );
			update_option( 'dinia_sender_email', sanitize_email( $_POST['dinia_sender_email'] ?? 'noreply@gofonia.de' ) );
			update_option( 'dinia_sender_name', sanitize_text_field( $_POST['dinia_sender_name'] ?? 'GoFonIA' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-rest-email', 'brevo_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ─── CalDAV speichern ───
		if ( isset( $_POST['dinia_save_caldav'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_caldav_nonce' ) ) {
			update_option( 'dinia_caldav_provider', sanitize_text_field( $_POST['dinia_caldav_provider'] ?? 'infomaniak' ) );
			update_option( 'dinia_caldav_url', esc_url_raw( $_POST['dinia_caldav_url'] ?? '' ) );
			update_option( 'dinia_caldav_username', sanitize_text_field( $_POST['dinia_caldav_username'] ?? '' ) );
			if ( ! empty( $_POST['dinia_caldav_password'] ) ) {
				update_option( 'dinia_caldav_password', sanitize_text_field( $_POST['dinia_caldav_password'] ) );
			}
			update_option( 'dinia_caldav_calendar', sanitize_text_field( $_POST['dinia_caldav_calendar'] ?? '' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-rest-caldav', 'caldav_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// ─── AFFILIATE speichern ───
		if ( isset( $_POST['dinia_save_affiliate'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_affiliate_nonce' ) ) {
			update_option( 'dinia_affiliate_url', esc_url_raw( $_POST['dinia_affiliate_url'] ?? '' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'dinia-rest-affiliate', 'affiliate_saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Dashboard-Seite rendern.
	 */
	public function render_dashboard() {
		$customer_count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->prefix}dinia_customers" );
		$plan_count     = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->prefix}dinia_plans WHERE active = 1" );
		$invoice_count  = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->prefix}dinia_invoices WHERE status = 'pending'" );
		$backup_count   = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->prefix}dinia_backups" );
		?>
		<div class="wrap dinia-wrap">
			<h1>Dinia – GoBookMe SaaS Dashboard</h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
				<div class="dinia-card" style="text-align:center;">
					<div style="font-size:36px;font-weight:700;color:#2271b1;"><?php echo esc_html( $customer_count ); ?></div>
					<div style="color:#646970;font-size:14px;">Kunden (Restaurants)</div>
				</div>
				<div class="dinia-card" style="text-align:center;">
					<div style="font-size:36px;font-weight:700;color:#00a32a;"><?php echo esc_html( $plan_count ); ?></div>
					<div style="color:#646970;font-size:14px;">aktive Pläne</div>
				</div>
				<div class="dinia-card" style="text-align:center;">
					<div style="font-size:36px;font-weight:700;color:#d63638;"><?php echo esc_html( $invoice_count ); ?></div>
					<div style="color:#646970;font-size:14px;">offene Rechnungen</div>
				</div>
				<div class="dinia-card" style="text-align:center;">
					<div style="font-size:36px;font-weight:700;color:#996b00;"><?php echo esc_html( $backup_count ); ?></div>
					<div style="color:#646970;font-size:14px;">Backups gesamt</div>
				</div>
			</div>
			<div class="dinia-card">
				<h2>Schnellzugriff</h2>
				<p style="color:#646970;">Verwalten Sie Ihre SaaS-Plattform über die Seiten in der linken Navigation.</p>
				<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers' ) ); ?>" class="dinia-btn dinia-btn-primary">Kunden verwalten</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-plans' ) ); ?>" class="dinia-btn dinia-btn-secondary">Pläne verwalten</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-backups' ) ); ?>" class="dinia-btn dinia-btn-secondary">Backup erstellen</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-settings' ) ); ?>" class="dinia-btn dinia-btn-secondary">Einstellungen</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Kunden-Seite rendern.
	 */
	public function render_customers() {
		$action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';

		if ( 'edit' === $action && isset( $_GET['id'] ) ) {
			$this->render_customer_edit( (int) $_GET['id'] );
		} elseif ( 'add' === $action ) {
			$this->render_customer_edit( 0 );
		} else {
			$this->render_customer_list();
		}
	}

	/**
	 * Kunden-Liste rendern.
	 */
	private function render_customer_list() {
		$customers = $this->customers->get_all();

		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Kunde wurde gelöscht.</div>';
		}
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Kunde wurde aktualisiert.</div>';
		}
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;">Kunden</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers&action=add' ) ); ?>" class="dinia-btn dinia-btn-primary">+ Neuen Kunden anlegen</a>
			</div>
			<div class="dinia-card">
				<div class="dinia-table-wrap">
					<table class="dinia-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Slug</th>
								<th>E-Mail</th>
								<th>Plan</th>
								<th>Status</th>
								<th>API-Key</th>
								<th>Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $customers ) ) : ?>
								<tr><td colspan="7" style="text-align:center;color:#646970;padding:30px;">Keine Kunden vorhanden.</td></tr>
							<?php else : ?>
								<?php foreach ( $customers as $c ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $c->company ); ?></strong></td>
										<td><code><?php echo esc_html( $c->slug ); ?></code></td>
										<td><?php echo esc_html( $c->email ); ?></td>
										<td>
											<?php
											if ( $c->plan_id ) {
												$plan = $this->plans->get_by_id( (int) $c->plan_id );
												echo esc_html( $plan ? $plan->name : 'Plan #' . $c->plan_id );
											} else {
												echo '<span class="dinia-meta">–</span>';
											}
											?>
										</td>
										<td><?php $this->render_status_badge( $c->status ); ?></td>
										<td>
											<?php if ( ! empty( $c->api_key_hint ) ) : ?>
												<code><?php echo esc_html( $c->api_key_hint ); ?></code>
											<?php else : ?>
												<span class="dinia-meta">–</span>
											<?php endif; ?>
										</td>
										<td>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dinia-customers&action=edit&id=' . $c->id ), 'dinia_edit_customer_' . $c->id ) ); ?>" class="dinia-btn dinia-btn-secondary dinia-btn-sm">Bearbeiten</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dinia-customers&action=delete_customer&id=' . $c->id ), 'dinia_delete_customer_' . $c->id ) ); ?>" class="dinia-btn dinia-btn-danger dinia-btn-sm" onclick="return confirm('Kunden wirklich löschen?')">Löschen</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Kunden-Bearbeitungsformular rendern.
	 *
	 * @param int $customer_id Kunden-ID (0 = neuer Kunde).
	 */
	private function render_customer_edit( $customer_id ) {
		$customer = $customer_id > 0 ? $this->customers->get_by_id( $customer_id ) : null;
		$plans    = $this->plans->get_all();

		$is_new  = ! $customer;
		$title   = $is_new ? 'Neuen Kunden anlegen' : 'Kunden bearbeiten: ' . esc_html( $customer->company );
		$api_key = isset( $_GET['api_key'] ) ? sanitize_text_field( $_GET['api_key'] ) : '';

		if ( isset( $_GET['api_generated'] ) && $api_key ) {
			echo '<div class="dinia-alert dinia-alert-success">Ein neuer API-Key wurde generiert. <strong>Bitte kopieren Sie ihn jetzt – er wird nicht erneut angezeigt.</strong></div>';
		}
		if ( isset( $_GET['created'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Kunde wurde angelegt.</div>';
		}
		if ( isset( $_GET['contract_cancelled'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">✅ Vertrag wurde gekündigt. Mollie-Abo gestoppt, Kunde per E-Mail benachrichtigt.</div>';
		}
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;"><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers' ) ); ?>" class="dinia-btn dinia-btn-secondary">&larr; Zurück zur Liste</a>
			</div>
			<div class="dinia-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers' ) ); ?>" class="dinia-form">
					<?php wp_nonce_field( 'dinia_customer_nonce' ); ?>
					<input type="hidden" name="customer_id" value="<?php echo $customer_id; ?>" />

					<div class="form-row">
						<label for="company">Firmenname *</label>
						<input type="text" id="company" name="company" value="<?php echo $is_new ? '' : esc_attr( $customer->company ); ?>" required />
					</div>

					<div class="form-row">
						<label for="slug">Slug</label>
						<input type="text" id="slug" name="slug" value="<?php echo $is_new ? '' : esc_attr( $customer->slug ); ?>" placeholder="wird automatisch generiert" />
						<span class="dinia-meta">Einmalig, wird für die Widget-URL verwendet.</span>
					</div>

					<div class="form-row">
						<label for="email">E-Mail-Adresse *</label>
						<input type="email" id="email" name="email" value="<?php echo $is_new ? '' : esc_attr( $customer->email ); ?>" required />
					</div>

					<div class="form-row">
						<label for="contact_name">Ansprechpartner</label>
						<input type="text" id="contact_name" name="contact_name" value="<?php echo $is_new ? '' : esc_attr( $customer->contact_name ?? '' ); ?>" />
					</div>

					<div class="form-row">
						<label for="contact_phone">Telefon</label>
						<input type="text" id="contact_phone" name="contact_phone" value="<?php echo $is_new ? '' : esc_attr( $customer->contact_phone ?? '' ); ?>" />
					</div>

					<div class="form-row">
						<label for="plan_id">Plan</label>
						<select id="plan_id" name="plan_id">
							<option value="">– Kein Plan –</option>
							<?php foreach ( $plans as $p ) : ?>
								<option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $is_new ? 0 : $customer->plan_id, $p->id ); ?>>
									<?php echo esc_html( $p->name ); ?> (<?php echo esc_html( number_format( $p->price_monthly, 2 ) ); ?> € / Monat)
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="form-row">
						<label for="status">Status</label>
						<select id="status" name="status">
							<?php
							$statuses = array( 'pending', 'active', 'suspended', 'cancelled' );
							$current_status = $is_new ? 'pending' : $customer->status;
							foreach ( $statuses as $s ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $s ),
									selected( $current_status, $s, false ),
									esc_html( ucfirst( $s ) )
								);
							}
							?>
						</select>
					</div>

					<div class="form-row">
						<button type="submit" name="dinia_save_customer" class="dinia-btn dinia-btn-primary">
							<?php echo $is_new ? 'Kunden anlegen' : 'Änderungen speichern'; ?>
						</button>
					</div>
				</form>
			</div>

			<?php if ( ! $is_new ) : ?>
				<div class="dinia-card">
					<h2>API-Key verwalten</h2>
					<?php if ( ! empty( $customer->api_key_hint ) ) : ?>
						<p><strong>Aktueller API-Key (Hinweis):</strong> <code><?php echo esc_html( $customer->api_key_hint ); ?></code></p>
					<?php endif; ?>

					<?php if ( $api_key ) : ?>
						<div class="dinia-api-key-box">
							<?php echo esc_html( $api_key ); ?>
						</div>
						<p class="dinia-meta">Dieser Schlüssel wird nur einmal angezeigt. Bitte sicher aufbewahren.</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers' ) ); ?>" style="margin-top:12px;">
						<?php wp_nonce_field( 'dinia_customer_nonce' ); ?>
						<input type="hidden" name="customer_id" value="<?php echo (int) $customer->id; ?>" />
						<button type="submit" name="dinia_generate_api_key" class="dinia-btn dinia-btn-success" onclick="return confirm('Einen neuen API-Key generieren? Der alte Key wird ungültig.');">
							🔑 Neuen API-Key generieren
						</button>
					</form>
				</div>

				<div class="dinia-card">
					<h2>Kunden-Informationen</h2>
					<table class="dinia-table" style="max-width:480px;">
						<tr><td style="font-weight:600;">Kunden-ID</td><td><?php echo (int) $customer->id; ?></td></tr>
						<tr><td style="font-weight:600;">Mollie Customer ID</td><td><code><?php echo esc_html( $customer->mollie_customer_id ?? '–' ); ?></code></td></tr>
						<tr><td style="font-weight:600;">Erstellt am</td><td><?php echo esc_html( $customer->created_at ?? '–' ); ?></td></tr>
						<tr><td style="font-weight:600;">Status</td><td><?php echo esc_html( $customer->status ?? '–' ); ?></td></tr>
					</table>

					<?php if ( ! empty( $customer->mollie_customer_id ) && in_array( $customer->status, array( 'active', 'suspended' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-customers' ) ); ?>" style="margin-top:16px;" onsubmit="return confirm('<?php esc_attr_e( 'Vertrag wirklich kündigen? Der Kunde erhält eine Bestätigungs-E-Mail. Mollie-Abo wird gestoppt.', 'dinia' ); ?>');">
							<?php wp_nonce_field( 'dinia_cancel_contract_nonce' ); ?>
							<input type="hidden" name="action" value="dinia_cancel_contract">
							<input type="hidden" name="customer_id" value="<?php echo (int) $customer->id; ?>">
							<button type="submit" class="dinia-btn dinia-btn-danger">🔴 Vertrag kündigen & Mollie-Abo stoppen</button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Pläne-Seite rendern.
	 */
	public function render_plans() {
		$edit_id = isset( $_GET['action'], $_GET['id'] ) && 'edit' === $_GET['action'] ? (int) $_GET['id'] : 0;

		if ( $edit_id ) {
			$this->render_plan_edit( $edit_id );
		} elseif ( isset( $_GET['action'] ) && 'add' === $_GET['action'] ) {
			$this->render_plan_edit( 0 );
		} else {
			$this->render_plan_list();
		}
	}

	/**
	 * Pläne-Liste rendern.
	 */
	private function render_plan_list() {
		$all_plans = $this->plans->get_all();

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Plan wurde gespeichert.</div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Plan wurde gelöscht.</div>';
		}
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;">Pläne (Tarife)</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-plans&action=add' ) ); ?>" class="dinia-btn dinia-btn-primary">+ Neuen Plan anlegen</a>
			</div>
			<div class="dinia-card">
				<div class="dinia-table-wrap">
					<table class="dinia-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Monatlich</th>
								<th>Jährlich</th>
								<th>Tische</th>
								<th>Reserv./Tag</th>
								<th>Mitarbeiter</th>
								<th>Sortierung</th>
								<th>Status</th>
								<th>Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $all_plans ) ) : ?>
								<tr><td colspan="10" style="text-align:center;color:#646970;padding:30px;">Keine Pläne vorhanden.</td></tr>
							<?php else : ?>
								<?php foreach ( $all_plans as $p ) : ?>
									<tr>
										<td><?php echo (int) $p->id; ?></td>
										<td><strong><?php echo esc_html( $p->name ); ?></strong></td>
										<td><?php echo esc_html( number_format( $p->price_monthly, 2 ) ); ?> €</td>
										<td><?php echo esc_html( number_format( $p->price_yearly, 2 ) ); ?> €</td>
										<td><?php echo (int) $p->max_tables; ?></td>
										<td><?php echo (int) $p->max_reservations_day; ?></td>
										<td><?php echo (int) $p->max_employees; ?></td>
										<td><?php echo (int) $p->sort_order; ?></td>
										<td><?php $p->active ? $this->render_status_badge( 'active' ) : $this->render_status_badge( 'inactive' ); ?></td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-plans&action=edit&id=' . $p->id ) ); ?>" class="dinia-btn dinia-btn-secondary dinia-btn-sm">Bearbeiten</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dinia-plans&action=delete_plan&id=' . $p->id ), 'dinia_delete_plan_' . $p->id ) ); ?>" class="dinia-btn dinia-btn-danger dinia-btn-sm" onclick="return confirm('Plan wirklich löschen?')">Löschen</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Plan-Bearbeitungsformular rendern.
	 *
	 * @param int $plan_id Plan-ID (0 = neuer Plan).
	 */
	private function render_plan_edit( $plan_id ) {
		$plan = $plan_id > 0 ? $this->plans->get_by_id( $plan_id ) : null;
		$is_new = ! $plan;
		$title  = $is_new ? 'Neuen Plan anlegen' : 'Plan bearbeiten: ' . esc_html( $plan->name );
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;"><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=dinia-plans' ) ); ?>" class="dinia-btn dinia-btn-secondary">&larr; Zurück zur Liste</a>
			</div>
			<div class="dinia-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-plans' ) ); ?>" class="dinia-form">
					<?php wp_nonce_field( 'dinia_plan_nonce' ); ?>
					<input type="hidden" name="plan_id" value="<?php echo $plan_id; ?>" />

					<div class="form-row">
						<label for="plan_name">Name *</label>
						<input type="text" id="plan_name" name="name" value="<?php echo $is_new ? '' : esc_attr( $plan->name ); ?>" required />
					</div>

					<div class="form-row">
						<label for="plan_description">Beschreibung</label>
						<textarea id="plan_description" name="description"><?php echo $is_new ? '' : esc_textarea( $plan->description ?? '' ); ?></textarea>
					</div>

					<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
						<div class="form-row">
							<label for="price_monthly">Preis monatlich (€)</label>
							<input type="number" id="price_monthly" name="price_monthly" step="0.01" min="0" value="<?php echo $is_new ? '0' : esc_attr( $plan->price_monthly ); ?>" />
						</div>
						<div class="form-row">
							<label for="price_yearly">Preis jährlich (€)</label>
							<input type="number" id="price_yearly" name="price_yearly" step="0.01" min="0" value="<?php echo $is_new ? '0' : esc_attr( $plan->price_yearly ); ?>" />
						</div>
					</div>

					<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
						<div class="form-row">
							<label for="max_tables">Max. Tische</label>
							<input type="number" id="max_tables" name="max_tables" min="1" value="<?php echo $is_new ? '5' : esc_attr( $plan->max_tables ); ?>" />
						</div>
						<div class="form-row">
							<label for="max_reservations_day">Max. Reservierungen/Tag</label>
							<input type="number" id="max_reservations_day" name="max_reservations_day" min="1" value="<?php echo $is_new ? '50' : esc_attr( $plan->max_reservations_day ); ?>" />
						</div>
						<div class="form-row">
							<label for="max_employees">Max. Mitarbeiter</label>
							<input type="number" id="max_employees" name="max_employees" min="1" value="<?php echo $is_new ? '1' : esc_attr( $plan->max_employees ); ?>" />
						</div>
					</div>

					<div class="form-row">
						<label for="sort_order">Sortierreihenfolge</label>
						<input type="number" id="sort_order" name="sort_order" min="0" value="<?php echo $is_new ? '0' : esc_attr( $plan->sort_order ?? '0' ); ?>" />
					</div>

					<div class="form-row">
						<label>
							<input type="checkbox" name="active" value="1" <?php checked( $is_new ? true : (bool) $plan->active ); ?> />
							Aktiv (für Neukunden sichtbar)
						</label>
					</div>

					<div class="form-row">
						<button type="submit" name="dinia_save_plan" class="dinia-btn dinia-btn-primary">
							<?php echo $is_new ? 'Plan anlegen' : 'Änderungen speichern'; ?>
						</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Rechnungen-Seite rendern.
	 */
	public function render_invoices() {
		$all_invoices = $this->invoices->get_all();
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;">Rechnungen</h1>
			</div>
			<div class="dinia-card">
				<div class="dinia-table-wrap">
					<table class="dinia-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Kunde</th>
								<th>Betrag</th>
								<th>Status</th>
								<th>Beschreibung</th>
								<th>Zahlungseingang</th>
								<th>Erstellt</th>
								<th>Rechnung (PDF)</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $all_invoices ) ) : ?>
								<tr><td colspan="8" style="text-align:center;color:#646970;padding:30px;">Keine Rechnungen vorhanden.</td></tr>
							<?php else : ?>
								<?php foreach ( $all_invoices as $inv ) : ?>
									<?php
									$customer_name = '–';
									if ( $inv->customer_id ) {
										$cust = $this->customers->get_by_id( (int) $inv->customer_id );
										$customer_name = $cust ? $cust->company : '#' . $inv->customer_id;
									}
									?>
									<tr>
										<td><?php echo (int) $inv->id; ?></td>
										<td><?php echo esc_html( $customer_name ); ?></td>
										<td><?php echo esc_html( number_format( $inv->amount, 2 ) ); ?> <?php echo esc_html( $inv->currency ?? 'EUR' ); ?></td>
										<td><?php $this->render_status_badge( $inv->status ); ?></td>
										<td><?php echo esc_html( $inv->description ?? '–' ); ?></td>
										<td><?php echo esc_html( $inv->paid_at ?? '–' ); ?></td>
										<td><?php echo esc_html( $inv->created_at ?? '–' ); ?></td>
										<td>
											<?php if ( ! empty( $inv->invoice_pdf_url ) ) : ?>
												<a href="<?php echo esc_url( $inv->invoice_pdf_url ); ?>" target="_blank" class="dinia-btn dinia-btn-secondary dinia-btn-sm">PDF</a>
											<?php else : ?>
												<span class="dinia-meta">–</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Backups-Seite rendern.
	 */
	public function render_backups() {
		$backups = $this->backup->list_backups();

		if ( isset( $_GET['backup_created'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Backup wurde erfolgreich erstellt.</div>';
		}
		if ( isset( $_GET['backup_error'] ) ) {
			echo '<div class="dinia-alert dinia-alert-error">Backup-Fehler: Das Backup konnte nicht erstellt werden. Bitte Logs prüfen.</div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Backup wurde gelöscht.</div>';
		}

		$backup_url = content_url( 'uploads/dinia-backups/' );
		?>
		<div class="wrap dinia-wrap">
			<div class="dinia-flex dinia-mb-20">
				<h1 style="margin:0;">Backups</h1>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-backups' ) ); ?>" style="margin:0;">
					<?php wp_nonce_field( 'dinia_backup_nonce' ); ?>
					<button type="submit" name="dinia_create_backup" class="dinia-btn dinia-btn-primary">+ Backup erstellen</button>
				</form>
			</div>

			<div class="dinia-card">
				<h2>Verfügbare Backups</h2>
				<div class="dinia-table-wrap">
					<table class="dinia-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Dateiname</th>
								<th>Größe</th>
								<th>Typ</th>
								<th>Status</th>
								<th>Erstellt am</th>
								<th>Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $backups ) ) : ?>
								<tr><td colspan="7" style="text-align:center;color:#646970;padding:30px;">Keine Backups vorhanden.</td></tr>
							<?php else : ?>
								<?php foreach ( $backups as $b ) : ?>
									<tr>
										<td><?php echo (int) $b->id; ?></td>
										<td><code><?php echo esc_html( $b->filename ); ?></code></td>
										<td>
											<?php
											$size = (int) $b->filesize;
											if ( $size >= 1048576 ) {
												echo esc_html( number_format( $size / 1048576, 2 ) ) . ' MB';
											} elseif ( $size >= 1024 ) {
												echo esc_html( number_format( $size / 1024, 1 ) ) . ' KB';
											} else {
												echo esc_html( $size ) . ' Bytes';
											}
											?>
										</td>
										<td><?php echo esc_html( $b->type ?? 'manual' ); ?></td>
										<td><?php $this->render_status_badge( $b->status ?? 'completed' ); ?></td>
										<td><?php echo esc_html( $b->created_at ?? '–' ); ?></td>
										<td>
											<a href="<?php echo esc_url( $backup_url . $b->filename ); ?>" class="dinia-btn dinia-btn-secondary dinia-btn-sm" download>📥 Download</a>
											<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dinia-backups&action=delete_backup&id=' . $b->id ), 'dinia_delete_backup_' . $b->id ) ); ?>" class="dinia-btn dinia-btn-danger dinia-btn-sm" onclick="return confirm('Backup wirklich löschen?')">Löschen</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Einstellungen-Seite rendern.
	 */
	public function render_settings() {
		$mollie_api_key         = get_option( 'dinia_mollie_api_key', '' );
		$mollie_profile_id      = get_option( 'dinia_mollie_profile_id', '' );
		$turnstile_site_key     = get_option( 'dinia_turnstile_site_key', '' );
		$turnstile_secret_key   = get_option( 'dinia_turnstile_secret_key', '' );
		$admin_email            = get_option( 'dinia_admin_email', get_option( 'admin_email' ) );

		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="dinia-alert dinia-alert-success">Einstellungen wurden gespeichert.</div>';
		}
		?>
		<div class="wrap dinia-wrap">
			<h1>Einstellungen</h1>
			<div class="dinia-card">
				<form method="post" action="options.php" class="dinia-form">
					<?php settings_fields( 'dinia_settings' ); ?>

					<h2>🔑 Mollie API</h2>
					<div class="form-row">
						<label for="dinia_mollie_api_key">Mollie API-Key (Live)</label>
						<input type="password" id="dinia_mollie_api_key" name="dinia_mollie_api_key" value="<?php echo esc_attr( $mollie_api_key ); ?>" class="regular-text" autocomplete="off" />
						<span class="dinia-meta">Live-API-Key aus dem Mollie-Dashboard. Beginnt mit <code>live_</code>.</span>
					</div>
					<div class="form-row">
						<label for="dinia_mollie_profile_id">Mollie Profil-ID</label>
						<input type="text" id="dinia_mollie_profile_id" name="dinia_mollie_profile_id" value="<?php echo esc_attr( $mollie_profile_id ); ?>" class="regular-text" />
						<span class="dinia-meta">Optional – wird für bestimmte Mollie-Endpunkte benötigt.</span>
					</div>

					<h2 style="margin-top:32px;">🛡️ Cloudflare Turnstile</h2>
					<div class="form-row">
						<label for="dinia_turnstile_site_key">Turnstile Site-Key</label>
						<input type="text" id="dinia_turnstile_site_key" name="dinia_turnstile_site_key" value="<?php echo esc_attr( $turnstile_site_key ); ?>" class="regular-text" />
					</div>
					<div class="form-row">
						<label for="dinia_turnstile_secret_key">Turnstile Secret-Key</label>
						<input type="password" id="dinia_turnstile_secret_key" name="dinia_turnstile_secret_key" value="<?php echo esc_attr( $turnstile_secret_key ); ?>" class="regular-text" autocomplete="off" />
					</div>

					<h2 style="margin-top:32px;">📧 Administrations-E-Mail</h2>
					<div class="form-row">
						<label for="dinia_admin_email">Admin-E-Mail-Adresse</label>
						<input type="email" id="dinia_admin_email" name="dinia_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" />
						<span class="dinia-meta">Benachrichtigungen werden an diese Adresse gesendet (z. B. Backup-Fehler, Zahlungsbenachrichtigungen).</span>
					</div>

					<div class="form-row" style="margin-top:24px;">
						<button type="submit" class="dinia-btn dinia-btn-primary">Einstellungen speichern</button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Status-Badge rendern.
	 *
	 * @param string $status Der Status (active, pending, paid, cancelled, suspended, inactive, completed).
	 */
	private function render_status_badge( $status ) {
		$status = strtolower( $status );
		$labels = array(
			'active'    => 'Aktiv',
			'pending'   => 'Ausstehend',
			'inactive'  => 'Inaktiv',
			'paid'      => 'Bezahlt',
			'unpaid'    => 'Unbezahlt',
			'cancelled' => 'Gekündigt',
			'suspended' => 'Gesperrt',
			'completed' => 'Abgeschlossen',
		);

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );

		$css_map = array(
			'active'    => 'dinia-badge-active',
			'paid'      => 'dinia-badge-active',
			'completed' => 'dinia-badge-active',
			'pending'   => 'dinia-badge-pending',
			'inactive'  => 'dinia-badge-inactive',
			'unpaid'    => 'dinia-badge-pending',
			'suspended' => 'dinia-badge-suspended',
			'cancelled' => 'dinia-badge-cancelled',
		);
		$css  = isset( $css_map[ $status ] ) ? $css_map[ $status ] : 'dinia-badge-pending';

		printf( '<span class="dinia-badge %s">%s</span>', esc_attr( $css ), esc_html( $label ) );
	}

	// ═══════════════════════════════════════════════════════════════
	//  9 NEUE ADMIN-TABS – RESTAURANT-KONFIGURATION (Deutsch / #ff6b00)
	// ═══════════════════════════════════════════════════════════════

	/**
	 * Customer-Selector für Restaurant-Seiten rendern.
	 *
	 * @return int Gewählte Customer-ID.
	 */
	private function rest_get_active_customer_id() {
		if ( isset( $_GET['rest_customer_id'] ) ) {
			$cid = (int) $_GET['rest_customer_id'];
			if ( $cid > 0 ) {
				update_user_meta( get_current_user_id(), 'dinia_rest_active_customer', $cid );
				return $cid;
			}
		}
		$saved = (int) get_user_meta( get_current_user_id(), 'dinia_rest_active_customer', true );
		if ( $saved > 0 ) {
			return $saved;
		}
		// Ersten Kunden als Default
		$first = $this->wpdb->get_var( "SELECT id FROM {$this->prefix}dinia_customers ORDER BY id ASC LIMIT 1" );
		return $first ? (int) $first : 0;
	}

	/**
	 * Customer-Selector-Dropdown rendern.
	 */
	private function render_rest_customer_selector() {
		$customers = $this->wpdb->get_results( "SELECT id, company, slug FROM {$this->prefix}dinia_customers ORDER BY company ASC" );
		$active    = $this->rest_get_active_customer_id();
		$page      = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
		?>
		<div class="dinia-rest-customer-selector">
			<label for="rest-customer-select">🏪 Restaurant auswählen:</label>
			<select id="rest-customer-select" onchange="if(this.value) window.location.href='<?php echo esc_url( admin_url( 'admin.php' ) ); ?>?page=<?php echo esc_attr( $page ); ?>&rest_customer_id='+this.value;">
				<option value="">– Bitte wählen –</option>
				<?php foreach ( $customers as $c ) : ?>
					<option value="<?php echo (int) $c->id; ?>" <?php selected( $active, (int) $c->id ); ?>>
						<?php echo esc_html( $c->company ); ?> (<?php echo esc_html( $c->slug ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
		return $active;
	}

	// ─── 1. ÖFFNUNGSZEITEN ───

	public function render_rest_hours() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$table = $this->prefix . 'dinia_hours';
		$rows  = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT day_key, open, close, open2, close2, closed FROM {$table} WHERE customer_id = %d",
			$customer_id
		) );
		$hours = array();
		foreach ( $rows as $r ) {
			$hours[ $r->day_key ] = $r;
		}

		$day_labels = array(
			'mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch',
			'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag',
		);

		if ( isset( $_GET['hours_saved'] ) ) {
			echo '<div class="dinia-rest-success">Öffnungszeiten wurden gespeichert.</div>';
		}

		?>
		<div class="wrap dinia-rest-wrap">
			<h1>🕐 Öffnungszeiten</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-rest-hours&rest_customer_id=' . $customer_id ) ); ?>">
				<?php wp_nonce_field( 'dinia_rest_hours_nonce' ); ?>
				<input type="hidden" name="rest_customer_id" value="<?php echo (int) $customer_id; ?>">
				<div class="dinia-rest-card">
					<table class="widefat dinia-rest-hours-table">
						<thead>
							<tr>
								<th>Tag</th>
								<th>Geschlossen</th>
								<th>Öffnet</th>
								<th>Schließt</th>
								<th>Mittag: öffnet</th>
								<th>Mittag: schließt</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $day_labels as $key => $label ) :
							$h = isset( $hours[ $key ] ) ? $hours[ $key ] : (object) array( 'open' => '11:00', 'close' => '22:00', 'open2' => '', 'close2' => '', 'closed' => 0 );
						?>
							<tr>
								<td><strong><?php echo $label; ?></strong></td>
								<td><input type="checkbox" name="hours[<?php echo $key; ?>][closed]" value="1" <?php checked( $h->closed ?? 0, 1 ); ?>></td>
								<td><input type="time" name="hours[<?php echo $key; ?>][open]" value="<?php echo esc_attr( $h->open ?? '11:00' ); ?>"></td>
								<td><input type="time" name="hours[<?php echo $key; ?>][close]" value="<?php echo esc_attr( $h->close ?? '22:00' ); ?>"></td>
								<td><input type="time" name="hours[<?php echo $key; ?>][open2]" value="<?php echo esc_attr( $h->open2 ?? '' ); ?>" placeholder="–"></td>
								<td><input type="time" name="hours[<?php echo $key; ?>][close2]" value="<?php echo esc_attr( $h->close2 ?? '' ); ?>" placeholder="–"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top:16px;">
						<button type="submit" name="dinia_save_hours" class="dinia-rest-btn-primary dinia-btn">Öffnungszeiten speichern</button>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── 2. TISCHE ───

	public function render_rest_tables() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$tables = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT * FROM {$this->prefix}dinia_tables WHERE customer_id = %d ORDER BY seats ASC, id ASC",
			$customer_id
		) );

		$position_labels = array( 'indoor' => 'Innen', 'outdoor' => 'Terrasse', 'bar' => 'Bar' );

		if ( isset( $_GET['table_saved'] ) ) {
			echo '<div class="dinia-rest-success">Tisch gespeichert.</div>';
		}
		if ( isset( $_GET['table_deleted'] ) ) {
			echo '<div class="dinia-rest-success">Tisch gelöscht.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>🪑 Tische verwalten</h1>
			<div class="dinia-rest-card">
				<div class="dinia-rest-filter-bar">
					<button class="dinia-btn dinia-rest-btn-primary" id="dinia-add-table-btn">+ Neuen Tisch hinzufügen</button>
				</div>
				<table class="dinia-rest-table">
					<thead>
						<tr>
							<th>ID</th>
							<th>Name</th>
							<th>Plätze</th>
							<th>Position</th>
							<th>Kombinierbar</th>
							<th>Aktiv</th>
							<th>Aktionen</th>
						</tr>
					</thead>
					<tbody id="dinia-tables-body">
						<?php if ( empty( $tables ) ) : ?>
							<tr><td colspan="7" style="text-align:center;color:#999;padding:20px;">Keine Tische vorhanden.</td></tr>
						<?php else : ?>
							<?php foreach ( $tables as $t ) : ?>
							<tr data-id="<?php echo (int) $t->id; ?>">
								<td><?php echo (int) $t->id; ?></td>
								<td><strong><?php echo esc_html( $t->name ); ?></strong></td>
								<td><?php echo (int) $t->seats; ?>er</td>
								<td><?php echo esc_html( $position_labels[ $t->position ] ?? $t->position ); ?></td>
								<td><?php echo ! empty( $t->combinable ) ? '✅' : '—'; ?></td>
								<td><?php echo ! empty( $t->active ) ? '✅' : '❌'; ?></td>
								<td>
									<button class="dinia-btn dinia-rest-btn-secondary dinia-btn-sm dinia-edit-table" data-id="<?php echo (int) $t->id; ?>" data-name="<?php echo esc_attr( $t->name ); ?>" data-seats="<?php echo (int) $t->seats; ?>" data-position="<?php echo esc_attr( $t->position ); ?>" data-active="<?php echo (int) $t->active; ?>" data-combinable="<?php echo (int) ( $t->combinable ?? 0 ); ?>">Bearbeiten</button>
									<button class="dinia-btn dinia-rest-btn-danger dinia-btn-sm dinia-delete-table" data-id="<?php echo (int) $t->id; ?>">Löschen</button>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Modal Tisch hinzufügen/bearbeiten -->
		<div id="dinia-table-modal" class="dinia-rest-modal" style="display:none;">
			<div class="dinia-rest-modal-content">
				<h2 id="dinia-table-modal-title">Tisch hinzufügen</h2>
				<input type="hidden" id="dinia-table-id" value="">
				<input type="hidden" id="dinia-table-customer-id" value="<?php echo (int) $customer_id; ?>">
				<p>
					<label>Name *</label>
					<input type="text" id="dinia-table-name" class="regular-text" placeholder="z.B. Tisch 1, Terrasse 3">
				</p>
				<p>
					<label>Plätze *</label>
					<select id="dinia-table-seats">
						<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
							<option value="<?php echo $i; ?>"><?php echo $i; ?>er Tisch</option>
						<?php endfor; ?>
					</select>
				</p>
				<p>
					<label>Position</label>
					<select id="dinia-table-position">
						<option value="indoor">Innen</option>
						<option value="outdoor">Terrasse</option>
						<option value="bar">Bar</option>
					</select>
				</p>
				<p>
					<label><input type="checkbox" id="dinia-table-active" checked> Aktiv</label>
				</p>
				<p>
					<label><input type="checkbox" id="dinia-table-combinable"> Kombinierbar</label>
					<span style="display:block;color:#666;font-size:12px;margin-top:4px;">Mehrere Tische zusammen für große Gruppen nutzen</span>
				</p>
				<p>
					<button class="dinia-btn dinia-rest-btn-primary" id="dinia-table-save">Speichern</button>
					<button class="dinia-btn dinia-rest-btn-secondary" id="dinia-table-cancel">Abbrechen</button>
				</p>
			</div>
		</div>

		<script>
		(function() {
			var modal = document.getElementById('dinia-table-modal');
			var btnAdd = document.getElementById('dinia-add-table-btn');
			var btnSave = document.getElementById('dinia-table-save');
			var btnCancel = document.getElementById('dinia-table-cancel');
			var tableId = document.getElementById('dinia-table-id');
			var tableName = document.getElementById('dinia-table-name');
			var tableSeats = document.getElementById('dinia-table-seats');
			var tablePosition = document.getElementById('dinia-table-position');
			var tableActive = document.getElementById('dinia-table-active');
			var tableCombinable = document.getElementById('dinia-table-combinable');
			var tableModalTitle = document.getElementById('dinia-table-modal-title');
			var customerId = document.getElementById('dinia-table-customer-id');

			function openModal(id, name, seats, position, active, combinable) {
				tableId.value = id || '';
				tableName.value = name || '';
				tableSeats.value = seats || '2';
				tablePosition.value = position || 'indoor';
				tableActive.checked = active ? true : false;
				tableCombinable.checked = combinable ? true : false;
				tableModalTitle.textContent = id ? 'Tisch bearbeiten' : 'Tisch hinzufügen';
				modal.style.display = 'block';
			}

			btnAdd.addEventListener('click', function() { openModal(null, '', 2, 'indoor', true, false); });
			btnCancel.addEventListener('click', function() { modal.style.display = 'none'; });
			window.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });

			document.querySelectorAll('.dinia-edit-table').forEach(function(btn) {
				btn.addEventListener('click', function() {
					openModal(this.dataset.id, this.dataset.name, this.dataset.seats, this.dataset.position, this.dataset.active, this.dataset.combinable);
				});
			});

			document.querySelectorAll('.dinia-delete-table').forEach(function(btn) {
				btn.addEventListener('click', function() {
					if (!confirm('Tisch wirklich löschen?')) return;
					var id = this.dataset.id;
					fetch('<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/table/' ) ); ?>' + id, {
						method: 'DELETE',
						headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' }
					}).then(function(r) { return r.json(); }).then(function(data) {
						window.location.reload();
					}).catch(function() { window.location.reload(); });
				});
			});

			btnSave.addEventListener('click', function() {
				var id = tableId.value;
				var data = {
					customer_id: customerId.value,
					name: tableName.value,
					seats: tableSeats.value,
					position: tablePosition.value,
					active: tableActive.checked ? 1 : 0,
					combinable: tableCombinable.checked ? 1 : 0
				};
				var url = id ? '<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/table/' ) ); ?>' + id : '<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/table' ) ); ?>';
				var method = id ? 'PUT' : 'POST';
				fetch(url, {
					method: method,
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
					body: JSON.stringify(data)
				}).then(function(r) { return r.json(); }).then(function(resp) {
					modal.style.display = 'none';
					window.location.reload();
				}).catch(function() { modal.style.display = 'none'; window.location.reload(); });
			});
		})();
		</script>
		<?php
	}

	// ─── 3. RESTAURANT-EINSTELLUNGEN ───

	public function render_rest_settings() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$settings = DINA_Booking::get_settings( $customer_id );

		if ( isset( $_GET['rest_settings_saved'] ) ) {
			echo '<div class="dinia-rest-success">Einstellungen wurden gespeichert.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>⚙️ Restaurant-Einstellungen</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-rest-settings&rest_customer_id=' . $customer_id ) ); ?>">
				<?php wp_nonce_field( 'dinia_rest_settings_nonce' ); ?>
				<input type="hidden" name="rest_customer_id" value="<?php echo (int) $customer_id; ?>">
				<div class="dinia-rest-card">
					<h2>Allgemein</h2>
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>Restaurant-Name</label>
							<input type="text" name="restaurant_name" value="<?php echo esc_attr( $settings['restaurant_name'] ?? '' ); ?>" class="regular-text">
						</div>
						<div class="form-row">
							<label>Primärfarbe</label>
							<input type="color" name="primary_color" value="<?php echo esc_attr( $settings['primary_color'] ?? '#ff6b00' ); ?>">
							<code style="margin-left:8px;"><?php echo esc_html( $settings['primary_color'] ?? '#ff6b00' ); ?></code>
						</div>
					</div>

					<h2>Slot-Konfiguration</h2>
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>Slot-Dauer (Minuten)</label>
							<select name="slot_duration">
								<?php foreach ( array( 30, 60, 90, 120, 150, 180 ) as $val ) : ?>
									<option value="<?php echo $val; ?>" <?php selected( $settings['slot_duration'] ?? 120, $val ); ?>><?php echo $val; ?> Min</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="form-row">
							<label>Slot-Intervall (Minuten)</label>
							<select name="slot_interval">
								<option value="15" <?php selected( $settings['slot_interval'] ?? 30, 15 ); ?>>15 Min</option>
								<option value="30" <?php selected( $settings['slot_interval'] ?? 30, 30 ); ?>>30 Min</option>
								<option value="60" <?php selected( $settings['slot_interval'] ?? 30, 60 ); ?>>60 Min</option>
							</select>
						</div>
						<div class="form-row">
							<label>Min. Vorlaufzeit (Stunden)</label>
							<select name="min_advance_hours">
								<?php for ( $i = 1; $i <= 48; $i++ ) : ?>
									<option value="<?php echo $i; ?>" <?php selected( $settings['min_advance_hours'] ?? 2, $i ); ?>><?php echo $i; ?>h</option>
								<?php endfor; ?>
							</select>
						</div>
						<div class="form-row">
							<label>Max. Vorausbuchung (Tage)</label>
							<select name="max_advance_days">
								<?php for ( $i = 1; $i <= 90; $i++ ) : ?>
									<option value="<?php echo $i; ?>" <?php selected( $settings['max_advance_days'] ?? 30, $i ); ?>><?php echo $i; ?> Tage</option>
								<?php endfor; ?>
							</select>
						</div>
					</div>

					<h2>E-Mail-Benachrichtigungen</h2>
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>
								<input type="checkbox" name="email_confirm" value="1" <?php checked( $settings['email_confirm'] ?? 1, 1 ); ?>>
								Bestätigungs-E-Mail senden
							</label>
						</div>
						<div class="form-row">
							<label>
								<input type="checkbox" name="email_reminder" value="1" <?php checked( $settings['email_reminder'] ?? 0, 1 ); ?>>
								Erinnerungs-E-Mail senden
							</label>
							<select name="reminder_hours" style="max-width:120px;">
								<option value="4" <?php selected( $settings['reminder_hours'] ?? 24, 4 ); ?>>4h</option>
								<option value="12" <?php selected( $settings['reminder_hours'] ?? 24, 12 ); ?>>12h</option>
								<option value="24" <?php selected( $settings['reminder_hours'] ?? 24, 24 ); ?>>24h</option>
								<option value="48" <?php selected( $settings['reminder_hours'] ?? 24, 48 ); ?>>48h</option>
							</select>
							<span style="color:#666;font-size:12px;">Stunden vorher</span>
						</div>
						<div class="form-row">
							<label>Admin-Benachrichtigung an</label>
							<input type="email" name="admin_notify_email" value="<?php echo esc_attr( $settings['admin_notify_email'] ?? get_option( 'admin_email' ) ); ?>" placeholder="admin@example.com">
						</div>
					</div>

					<p style="margin-top:16px;">
						<button type="submit" name="dinia_save_rest_settings" class="dinia-btn dinia-rest-btn-primary">Einstellungen speichern</button>
					</p>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── 4. E-MAIL (BREVO) ───

	public function render_rest_email() {
		$api_key     = get_option( 'dinia_brevo_api_key', '' );
		$sender_email = get_option( 'dinia_sender_email', 'noreply@gofonia.de' );
		$sender_name  = get_option( 'dinia_sender_name', 'GoFonIA' );

		if ( isset( $_GET['brevo_saved'] ) ) {
			echo '<div class="dinia-rest-success">Brevo-Einstellungen gespeichert.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>📧 E-Mail-Versand (Brevo)</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-rest-email' ) ); ?>">
				<?php wp_nonce_field( 'dinia_brevo_nonce' ); ?>
				<div class="dinia-rest-card">
					<p class="description" style="margin-top:0;">E-Mails werden über Brevo (Sendinblue) versendet statt über PHP sendmail.</p>
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>Brevo API-Key</label>
							<input type="password" name="dinia_brevo_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" placeholder="xkeysib-..." autocomplete="off">
							<span style="color:#666;font-size:12px;">API-Key v3 aus dem Brevo-Dashboard → SMTP & API → API-Keys.</span>
						</div>
						<div class="form-row">
							<label>Absender-E-Mail</label>
							<input type="email" name="dinia_sender_email" value="<?php echo esc_attr( $sender_email ); ?>" class="regular-text" placeholder="noreply@domain.de">
							<span style="color:#666;font-size:12px;">Muss in Brevo als Absender verifiziert sein.</span>
						</div>
						<div class="form-row">
							<label>Absender-Name</label>
							<input type="text" name="dinia_sender_name" value="<?php echo esc_attr( $sender_name ); ?>" class="regular-text" placeholder="GoFonIA">
						</div>
						<div class="form-row">
							<label>Test-E-Mail senden</label>
							<button type="button" class="dinia-btn dinia-rest-btn-primary" id="dinia-test-brevo">Test senden</button>
							<span id="dinia-brevo-status" style="margin-left:10px;"></span>
							<span style="display:block;color:#666;font-size:12px;margin-top:4px;">Sendet eine Test-E-Mail an <code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>.</span>
						</div>
					</div>
					<p style="margin-top:16px;">
						<button type="submit" name="dinia_save_brevo" class="dinia-btn dinia-rest-btn-primary">Brevo-Einstellungen speichern</button>
					</p>
				</div>
			</form>
		</div>

		<script>
		document.getElementById('dinia-test-brevo').addEventListener('click', function() {
			var btn = this;
			var status = document.getElementById('dinia-brevo-status');
			btn.disabled = true;
			status.innerHTML = '⏳ Sende...';
			fetch('<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/test-email' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' }
			}).then(function(r) { return r.json(); }).then(function(data) {
				if (data.success) {
					status.innerHTML = '✅ ' + (data.data || 'Test-E-Mail gesendet!');
				} else {
					status.innerHTML = '❌ ' + (data.data || data.message || 'Fehler');
				}
				btn.disabled = false;
			}).catch(function(err) {
				status.innerHTML = '❌ Verbindungsfehler';
				btn.disabled = false;
			});
		});
		</script>
		<?php
	}

	// ─── 5. CalDAV ───

	public function render_rest_caldav() {
		$provider   = get_option( 'dinia_caldav_provider', 'infomaniak' );
		$url        = get_option( 'dinia_caldav_url', '' );
		$username   = get_option( 'dinia_caldav_username', '' );
		$caldav_pass = get_option( 'dinia_caldav_password', '' );
		$calendar   = get_option( 'dinia_caldav_calendar', '' );

		$providers = array(
			'infomaniak' => 'Infomaniak',
			'google'     => 'Google Calendar',
			'gmx'        => 'GMX',
			'webde'      => 'web.de',
			'icloud'     => 'Apple iCloud',
			'custom'     => 'Eigener Server',
		);

		if ( isset( $_GET['caldav_saved'] ) ) {
			echo '<div class="dinia-rest-success">CalDAV-Einstellungen gespeichert.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>📅 CalDAV-Kalender</h1>
			<p class="description">Reservierungen werden automatisch in den Kalender synchronisiert.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-rest-caldav' ) ); ?>">
				<?php wp_nonce_field( 'dinia_caldav_nonce' ); ?>
				<div class="dinia-rest-card">
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>Anbieter</label>
							<select name="dinia_caldav_provider" id="dinia-caldav-provider">
								<?php foreach ( $providers as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span id="dinia-provider-hint" style="display:block;color:#666;font-size:12px;margin-top:4px;">Wähle einen Anbieter – Felder werden automatisch ausgefüllt.</span>
						</div>
						<div class="form-row">
							<label>CalDAV-URL</label>
							<input type="url" name="dinia_caldav_url" id="dinia-caldav-url" value="<?php echo esc_attr( $url ); ?>" class="regular-text" placeholder="https://sync.infomaniak.com/calendars/">
						</div>
						<div class="form-row">
							<label>Benutzername</label>
							<input type="text" name="dinia_caldav_username" id="dinia-caldav-username" value="<?php echo esc_attr( $username ); ?>" class="regular-text" placeholder="z.B. GO01132">
						</div>
						<div class="form-row">
							<label>Passwort</label>
							<input type="password" name="dinia_caldav_password" id="dinia-caldav-password" value="<?php echo esc_attr( $caldav_pass ); ?>" class="regular-text" placeholder="App-Passwort" autocomplete="off">
						</div>
						<div class="form-row">
							<label>Kalender-Name</label>
							<input type="text" name="dinia_caldav_calendar" id="dinia-caldav-calendar" value="<?php echo esc_attr( $calendar ); ?>" class="regular-text" placeholder="default">
						</div>
						<div class="form-row">
							<label>Verbindung testen</label>
							<button type="button" class="dinia-btn dinia-rest-btn-primary" id="dinia-test-caldav">Testen</button>
							<span id="dinia-caldav-status" style="margin-left:10px;"></span>
						</div>
					</div>
					<p style="margin-top:16px;">
						<button type="submit" name="dinia_save_caldav" class="dinia-btn dinia-rest-btn-primary">CalDAV-Einstellungen speichern</button>
					</p>
				</div>
			</form>
		</div>

		<script>
		var caldavPresets = {
			'infomaniak': { url: 'https://sync.infomaniak.com/calendars/', username: 'GO...', calendar: 'dbc10e70-...', hint: 'Benutzername = Infomaniak-Kundennummer (z.B. GO01132). Passwort = Account-Passwort.' },
			'google':     { url: 'https://apidata.googleusercontent.com/caldav/v2', username: 'name@gmail.com', calendar: 'default', hint: 'Benutzername = Gmail-Adresse. Passwort = App-Passwort (kein Google-Account-Passwort!).' },
			'gmx':        { url: 'https://caldav.gmx.net', username: 'name@gmx.de', calendar: 'default', hint: 'Benutzername = Vollständige E-Mail-Adresse. Passwort = GMX-Passwort.' },
			'webde':      { url: 'https://caldav.web.de', username: 'name@web.de', calendar: 'default', hint: 'Benutzername = Vollständige E-Mail-Adresse. Passwort = web.de-Passwort.' },
			'icloud':     { url: 'https://caldav.icloud.com/', username: 'appleid@icloud.com', calendar: 'default', hint: 'Benutzername = Apple-ID-E-Mail. Passwort = App-spezifisches Passwort.' },
			'custom':     { url: '', username: '', calendar: '', hint: 'Trage URL, Benutzername und Passwort deines eigenen CalDAV-Servers ein.' }
		};
		document.getElementById('dinia-caldav-provider').addEventListener('change', function() {
			var p = caldavPresets[this.value];
			if (p) {
				var urlField = document.getElementById('dinia-caldav-url');
				if (urlField.value === '' || confirm('Felder mit den Presets überschreiben?')) {
					urlField.value = p.url;
					if (p.username) document.getElementById('dinia-caldav-username').placeholder = p.username;
					document.getElementById('dinia-caldav-calendar').placeholder = p.calendar;
					document.getElementById('dinia-provider-hint').textContent = p.hint;
				}
			}
		});

		document.getElementById('dinia-test-caldav').addEventListener('click', function() {
			var btn = this;
			var status = document.getElementById('dinia-caldav-status');
			btn.disabled = true;
			status.innerHTML = '⏳ Teste Verbindung...';
			var data = {
				dinia_caldav_url: document.getElementById('dinia-caldav-url').value,
				dinia_caldav_username: document.getElementById('dinia-caldav-username').value,
				dinia_caldav_password: document.getElementById('dinia-caldav-password').value,
				dinia_caldav_calendar: document.getElementById('dinia-caldav-calendar').value,
				dinia_caldav_provider: document.getElementById('dinia-caldav-provider').value
			};
			fetch('<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/test-caldav' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
				body: JSON.stringify(data)
			}).then(function(r) { return r.json(); }).then(function(data) {
				if (data.success) {
					status.innerHTML = '✅ ' + (data.data || 'Verbindung erfolgreich!');
				} else {
					status.innerHTML = '❌ ' + (data.data || data.message || 'Fehler');
				}
				btn.disabled = false;
			}).catch(function(err) {
				status.innerHTML = '❌ Verbindungsfehler';
				btn.disabled = false;
			});
		});
		</script>
		<?php
	}

	// ─── 6. NEUE BUCHUNG ───

	public function render_rest_new_booking() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$tables = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT id, name, seats, combinable FROM {$this->prefix}dinia_tables WHERE active = 1 AND customer_id = %d ORDER BY seats ASC",
			$customer_id
		) );
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>➕ Neue Buchung</h1>
			<div class="dinia-rest-card">
				<form id="dinia-admin-booking-form" class="dinia-rest-form">
					<input type="hidden" name="customer_id" id="dinia-booking-customer-id" value="<?php echo (int) $customer_id; ?>">
					<div class="form-row">
						<label for="dinia-booking-date">Datum *</label>
						<input type="date" id="dinia-booking-date" name="date" required min="<?php echo current_time( 'Y-m-d' ); ?>" value="<?php echo current_time( 'Y-m-d' ); ?>">
					</div>
					<div class="form-row">
						<label for="dinia-booking-time">Uhrzeit *</label>
						<input type="time" id="dinia-booking-time" name="time_start" required value="<?php echo current_time( 'H:i' ); ?>">
					</div>
					<div class="form-row">
						<label for="dinia-booking-guests">Personen *</label>
						<select id="dinia-booking-guests" name="guest_count">
							<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
								<option value="<?php echo $i; ?>"><?php echo $i; ?> Person<?php echo $i > 1 ? 'en' : ''; ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div class="form-row">
						<label for="dinia-booking-table">Tisch</label>
						<select id="dinia-booking-table" name="table_id">
							<option value="">– Automatisch zuweisen –</option>
							<?php foreach ( $tables as $t ) : ?>
								<option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name ); ?> (<?php echo (int) $t->seats; ?>er<?php echo ! empty( $t->combinable ) ? ', kombinierbar' : ''; ?>)</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="form-row">
						<label for="dinia-booking-name">Name *</label>
						<input type="text" id="dinia-booking-name" name="guest_name" required class="regular-text">
					</div>
					<div class="form-row">
						<label for="dinia-booking-email">E-Mail</label>
						<input type="email" id="dinia-booking-email" name="guest_email" class="regular-text">
					</div>
					<div class="form-row">
						<label for="dinia-booking-phone">Telefon</label>
						<input type="tel" id="dinia-booking-phone" name="guest_phone" class="regular-text">
					</div>
					<div class="form-row">
						<label for="dinia-booking-notes">Notiz</label>
						<textarea id="dinia-booking-notes" name="notes" rows="3" class="large-text"></textarea>
					</div>
					<p>
						<button type="submit" class="dinia-btn dinia-rest-btn-primary">Buchung erstellen</button>
						<span id="dinia-booking-status" style="margin-left:10px;"></span>
					</p>
				</form>
			</div>
		</div>

		<script>
		document.getElementById('dinia-admin-booking-form').addEventListener('submit', function(e) {
			e.preventDefault();
			var btn = this.querySelector('button[type="submit"]');
			var status = document.getElementById('dinia-booking-status');
			btn.disabled = true;
			status.innerHTML = '⏳ Erstelle Buchung...';
			var data = {
				customer_id: document.getElementById('dinia-booking-customer-id').value,
				date: document.getElementById('dinia-booking-date').value,
				time_start: document.getElementById('dinia-booking-time').value,
				guest_count: document.getElementById('dinia-booking-guests').value,
				table_id: document.getElementById('dinia-booking-table').value,
				guest_name: document.getElementById('dinia-booking-name').value,
				guest_email: document.getElementById('dinia-booking-email').value,
				guest_phone: document.getElementById('dinia-booking-phone').value,
				notes: document.getElementById('dinia-booking-notes').value,
				source: 'admin'
			};
			fetch('<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/create-booking' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
				body: JSON.stringify(data)
			}).then(function(r) { return r.json(); }).then(function(resp) {
				if (resp.success) {
					status.innerHTML = '✅ Buchung erstellt! (ID: ' + (resp.data || '') + ')';
					document.getElementById('dinia-admin-booking-form').reset();
				} else {
					status.innerHTML = '❌ ' + (resp.data || resp.message || 'Fehler');
				}
				btn.disabled = false;
			}).catch(function(err) {
				status.innerHTML = '❌ Verbindungsfehler';
				btn.disabled = false;
			});
		});
		</script>
		<?php
	}

	// ─── 7. RESERVIERUNGEN ───

	public function render_rest_reservations() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$filter_date   = isset( $_GET['filter_date'] ) ? sanitize_text_field( $_GET['filter_date'] ) : current_time( 'Y-m-d' );
		$filter_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';

		$where  = $this->wpdb->prepare( 'WHERE r.customer_id = %d', $customer_id );
		$params = array( $customer_id );
		if ( $filter_date ) {
			$where   .= $this->wpdb->prepare( ' AND r.date = %s', $filter_date );
			$params[] = $filter_date;
		}
		if ( $filter_status ) {
			$where   .= $this->wpdb->prepare( ' AND r.status = %s', $filter_status );
			$params[] = $filter_status;
		}

		$reservations = $this->wpdb->get_results(
			"SELECT r.*, t.name as table_name, t.seats
			 FROM {$this->prefix}dinia_reservations r
			 LEFT JOIN {$this->prefix}dinia_tables t ON r.table_id = t.id
			 {$where}
			 ORDER BY r.date DESC, r.time_start ASC"
		);

		$status_labels = array(
			'confirmed' => 'Bestätigt',
			'cancelled' => 'Storniert',
			'no-show'   => 'Nicht erschienen',
		);

		if ( isset( $_GET['status_updated'] ) ) {
			echo '<div class="dinia-rest-success">Status aktualisiert.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>📋 Reservierungen</h1>
			<div class="dinia-rest-filter-bar">
				<form method="get" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;width:100%;">
					<input type="hidden" name="page" value="dinia-rest-reservations">
					<input type="hidden" name="rest_customer_id" value="<?php echo (int) $customer_id; ?>">
					<label>Datum: <input type="date" name="filter_date" value="<?php echo esc_attr( $filter_date ); ?>"></label>
					<label>Status:
						<select name="filter_status">
							<option value="">Alle</option>
							<option value="confirmed" <?php selected( $filter_status, 'confirmed' ); ?>>Bestätigt</option>
							<option value="cancelled" <?php selected( $filter_status, 'cancelled' ); ?>>Storniert</option>
							<option value="no-show" <?php selected( $filter_status, 'no-show' ); ?>>Nicht erschienen</option>
						</select>
					</label>
					<button type="submit" class="dinia-btn dinia-rest-btn-primary">Filtern</button>
				</form>
			</div>
			<div class="dinia-rest-card">
				<table class="dinia-rest-table">
					<thead>
						<tr>
							<th>Datum</th>
							<th>Uhrzeit</th>
							<th>Gast</th>
							<th>Pers.</th>
							<th>Tisch</th>
							<th>Telefon</th>
							<th>Status</th>
							<th>Quelle</th>
							<th>Aktion</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $reservations ) ) : ?>
							<tr><td colspan="9" style="text-align:center;color:#999;padding:30px;">Keine Reservierungen gefunden.</td></tr>
						<?php else : ?>
							<?php foreach ( $reservations as $r ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $r->date ) ) ); ?></td>
								<td><?php echo esc_html( substr( $r->time_start, 0, 5 ) ); ?></td>
								<td><strong><?php echo esc_html( $r->guest_name ); ?></strong></td>
								<td><?php echo (int) $r->guest_count; ?></td>
								<td><?php echo esc_html( $r->table_name ?: '—' ); ?></td>
								<td><?php echo esc_html( $r->guest_phone ?: '—' ); ?></td>
								<td><span class="dinia-rest-status dinia-rest-badge-<?php echo esc_attr( $r->status === 'confirmed' ? 'confirmed' : ( $r->status === 'cancelled' ? 'cancelled' : 'pending' ) ); ?>"><?php echo esc_html( $status_labels[ $r->status ] ?? $r->status ); ?></span></td>
								<td><?php echo $r->source === 'admin' ? '📞 Admin' : '🌐 Online'; ?></td>
								<td>
									<select class="dinia-status-select" data-id="<?php echo (int) $r->id; ?>" data-customer="<?php echo (int) $customer_id; ?>">
										<option value="confirmed" <?php selected( $r->status, 'confirmed' ); ?>>Bestätigt</option>
										<option value="cancelled" <?php selected( $r->status, 'cancelled' ); ?>>Storniert</option>
									</select>
								</td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		document.querySelectorAll('.dinia-status-select').forEach(function(sel) {
			sel.addEventListener('change', function() {
				var status = this.value;
				var id = this.dataset.id;
				var customerId = this.dataset.customer;
				if (!confirm('Status ändern zu: ' + (status === 'confirmed' ? 'Bestätigt' : 'Storniert') + '?')) {
					this.value = this.querySelector('option[selected]') ? this.querySelector('option[selected]').value : 'confirmed';
					return;
				}
				fetch('<?php echo esc_url_raw( rest_url( 'dinia/v1/admin/update-status' ) ); ?>', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
					body: JSON.stringify({ id: id, status: status, customer_id: customerId })
				}).then(function(r) { return r.json(); }).then(function(data) {
					window.location.reload();
				}).catch(function() { window.location.reload(); });
			});
		});
		</script>
		<?php
	}

	// ─── 8. EINBETTEN ───

	public function render_rest_embed() {
		$customer_id = $this->render_rest_customer_selector();
		if ( ! $customer_id ) {
			echo '<div class="dinia-rest-wrap"><p>Bitte wählen Sie ein Restaurant aus.</p></div>';
			return;
		}

		$customer = $this->customers->get_by_id( $customer_id );
		$slug     = $customer ? $customer->slug : '';
		$home_url = home_url();
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>🔌 Einbetten</h1>
			<p style="font-size:15px;color:#555;">Kopiere den Code, der zu deiner Website passt, und füge ihn ein.</p>

			<div class="dinia-rest-card">
				<h2>📋 Shortcode (WordPress)</h2>
				<p>Einfach in eine beliebige Seite oder Beitrag einfügen.</p>
				<div class="dinia-rest-code-box">
					<code id="dinia-code-shortcode">[dinia slug="<?php echo esc_attr( $slug ); ?>"]</code>
					<button class="dinia-rest-copy-btn" data-target="dinia-code-shortcode">📋 Kopieren</button>
				</div>
			</div>

			<div class="dinia-rest-card">
				<h2>🐘 PHP-Code (Theme)</h2>
				<p>Direkt in eine Template-Datei einfügen (z.B. <code>page.php</code> oder <code>footer.php</code>).</p>
				<div class="dinia-rest-code-box">
					<code id="dinia-code-php">&lt;?php echo do_shortcode( '[dinia slug="<?php echo esc_attr( $slug ); ?>"]' ); ?&gt;</code>
					<button class="dinia-rest-copy-btn" data-target="dinia-code-php">📋 Kopieren</button>
				</div>
			</div>

			<div class="dinia-rest-card">
				<h2>🌐 Widget-JS (externe Website)</h2>
				<p>Für Websites ohne WordPress (z.B. Jimdo, Wix, HTML).</p>
				<div class="dinia-rest-code-box">
					<code id="dinia-code-js">&lt;div id="dinia-booking-widget"&gt;&lt;/div&gt;
&lt;script src="<?php echo esc_url( $home_url ); ?>/wp-content/plugins/gobookme-saas/public/js/widget.js" data-slug="<?php echo esc_attr( $slug ); ?>"&gt;&lt;/script&gt;</code>
					<button class="dinia-rest-copy-btn" data-target="dinia-code-js">📋 Kopieren</button>
				</div>
			</div>
		</div>

		<script>
		document.querySelectorAll('.dinia-rest-copy-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var target = document.getElementById(this.dataset.target);
				if (!target) return;
				var text = target.textContent || target.innerText;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(function() {
						var orig = btn.innerHTML;
						btn.innerHTML = '✅ Kopiert!';
						setTimeout(function() { btn.innerHTML = orig; }, 2000);
					}).catch(function() { fallbackCopy(text, btn); });
				} else {
					fallbackCopy(text, btn);
				}
			});
		});
		function fallbackCopy(text, btn) {
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			document.execCommand('copy');
			document.body.removeChild(ta);
			var orig = btn.innerHTML;
			btn.innerHTML = '✅ Kopiert!';
			setTimeout(function() { btn.innerHTML = orig; }, 2000);
		}
		</script>
		<?php
	}

	// ─── 9. AFFILIATE ───

	public function render_rest_affiliate() {
		$affiliate_url = get_option( 'dinia_affiliate_url', '' );

		if ( isset( $_GET['affiliate_saved'] ) ) {
			echo '<div class="dinia-rest-success">Affiliate-Link gespeichert.</div>';
		}
		?>
		<div class="wrap dinia-rest-wrap">
			<h1>🔗 Affiliate</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dinia-rest-affiliate' ) ); ?>">
				<?php wp_nonce_field( 'dinia_affiliate_nonce' ); ?>
				<div class="dinia-rest-card">
					<div class="dinia-rest-form">
						<div class="form-row">
							<label>Eigener Powered-by-Link</label>
							<input type="url" name="dinia_affiliate_url" value="<?php echo esc_attr( $affiliate_url ); ?>" class="regular-text" placeholder="https://partner.example.com/ref=123">
							<span style="display:block;color:#666;font-size:12px;margin-top:4px;">Wird im "Powered by Dinia"-Link im Buchungsformular verwendet. Leer = Standard-Link.</span>
						</div>
					</div>
					<p style="margin-top:16px;">
						<button type="submit" name="dinia_save_affiliate" class="dinia-btn dinia-rest-btn-primary">Affiliate-Link speichern</button>
					</p>
				</div>
			</form>
		</div>
		<?php
	}
}
