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
	}

	/**
	 * Admin-Styles einbinden.
	 */
	public function enqueue_styles() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'dinia' ) === false ) {
			return;
		}
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
					</table>
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
}
