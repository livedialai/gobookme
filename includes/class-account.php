<?php
/**
 * DINA_Account – Tenant-Konto (Self-Service im WP-Admin)
 *
 * Zeigt Abo-Infos, Plugin-Download, Embed-Code, API-Key,
 * Einstellungen, Öffnungszeiten, Tische und Kündigung.
 *
 * @package GoBookMe_SaaS
 * @since   1.1.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Account
 */
class DINA_Account {

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
	 * Konstruktor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb   = $wpdb;
		$this->prefix = $wpdb->prefix;

		add_action( 'admin_menu', array( $this, 'add_account_page' ) );
		add_action( 'admin_init', array( $this, 'handle_account_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * CSS für Account-Seite.
	 */
	public function enqueue_styles( $hook ) {
		if ( 'toplevel_page_dinia-my-account' !== $hook ) {
			return;
		}
		?>
		<style>
			.dinia-acc-wrap { max-width:900px; margin:20px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
			.dinia-acc-card { background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:20px; box-shadow:0 1px 4px rgba(0,0,0,0.04); }
			.dinia-acc-card h2 { margin:0 0 16px; font-size:1.2rem; border-bottom:2px solid #ff6b0015; padding-bottom:8px; }
			.dinia-acc-row { display:flex; gap:12px; margin-bottom:12px; align-items:center; flex-wrap:wrap; }
			.dinia-acc-row label { min-width:160px; font-weight:600; font-size:0.9rem; color:#333; }
			.dinia-acc-row input[type="text"],
			.dinia-acc-row input[type="number"],
			.dinia-acc-row input[type="email"],
			.dinia-acc-row input[type="password"],
			.dinia-acc-row select { flex:1; min-width:200px; padding:6px 10px; border:1px solid #ccc; border-radius:4px; font-size:0.9rem; }
			.dinia-acc-row .description { flex-basis:100%; color:#666; font-size:0.82rem; margin:2px 0 0 160px; }
			.dinia-acc-table { width:100%; border-collapse:collapse; }
			.dinia-acc-table th { text-align:left; font-weight:600; padding:8px; border-bottom:2px solid #eee; }
			.dinia-acc-table td { padding:8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
			.dinia-btn-s { display:inline-block; padding:4px 12px; font-size:0.82rem; border:none; border-radius:4px; cursor:pointer; }
			.dinia-btn-primary-s { background:#ff6b00; color:#fff; }
			.dinia-btn-primary-s:hover { background:#e05f00; }
			.dinia-btn-danger-s { background:#dc3545; color:#fff; }
			.dinia-btn-danger-s:hover { background:#b02a37; }
			.dinia-btn-ghost-s { background:#f5f5f5; color:#333; border:1px solid #ddd; }
			.dinia-btn-ghost-s:hover { background:#e8e8e8; }
			.dinia-code-block { background:#f5f5f5; border:1px solid #ddd; border-radius:4px; padding:12px 16px; font-family:monospace; font-size:0.85rem; white-space:pre-wrap; word-break:break-all; cursor:pointer; position:relative; }
			.dinia-code-block:hover::after { content:'📋 Klicken zum Kopieren'; position:absolute; right:8px; top:4px; font-size:0.75rem; color:#666; }
			.dinia-hours-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
			.dinia-hours-day { border:1px solid #eee; border-radius:6px; padding:12px; background:#fafafa; }
			.dinia-hours-day label { font-weight:600; display:block; margin-bottom:6px; font-size:0.9rem; }
			.dinia-hours-day input[type="time"] { width:100px; padding:4px 6px; border:1px solid #ccc; border-radius:4px; }
			.dinia-hours-day .dinia-hour-row { display:flex; gap:8px; align-items:center; margin:4px 0; }
			.dinia-acc-tabs { display:flex; gap:4px; margin-bottom:0; }
			.dinia-acc-tab { padding:10px 20px; background:#f5f5f5; border:1px solid #ddd; border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; font-weight:600; font-size:0.9rem; color:#666; }
			.dinia-acc-tab.active { background:#fff; color:#ff6b00; border-color:#ddd; position:relative; top:1px; }
			.dinia-acc-tab:hover:not(.active) { background:#eee; }
			.dinia-acc-notice { background:#fff3e6; border-left:4px solid #ff6b00; padding:12px 16px; margin-bottom:16px; border-radius:0 4px 4px 0; font-size:0.9rem; }
			@media (max-width:600px) { .dinia-hours-grid { grid-template-columns:1fr; } .dinia-acc-row { flex-direction:column; align-items:stretch; } .dinia-acc-row label { min-width:auto; } }
		</style>
		<?php
	}

	/**
	 * Admin-Menü-Punkt für Tenant.
	 */
	public function add_account_page(): void {
		add_menu_page(
			'Mein Konto',
			'👤 Mein Konto',
			'read',
			'dinia-my-account',
			array( $this, 'render_account_page' ),
			'dashicons-admin-users',
			31
		);
	}

	/**
	 * Verarbeitet alle Formular-Aktionen.
	 */
	public function handle_account_actions(): void {
		if ( empty( $_POST['dinia_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_account_nonce' ) ) {
			return;
		}

		$current_user = wp_get_current_user();
		$customer     = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->prefix}dinia_customers WHERE email = %s LIMIT 1",
				$current_user->user_email
			)
		);
		if ( ! $customer ) {
			return;
		}

		$action = sanitize_text_field( $_POST['dinia_action'] );
		$redirect = admin_url( 'admin.php?page=dinia-my-account' );

		switch ( $action ) {

			case 'save_settings':
				$settings = DINA_Booking::get_settings( (int) $customer->id );
				$settings['restaurant_name']   = sanitize_text_field( $_POST['restaurant_name'] ?? $settings['restaurant_name'] );
				$settings['slot_duration']     = max( 15, min( 240, (int) ( $_POST['slot_duration'] ?? $settings['slot_duration'] ) ) );
				$settings['slot_interval']     = max( 5, min( 120, (int) ( $_POST['slot_interval'] ?? $settings['slot_interval'] ) ) );
				$settings['max_advance_days']  = max( 1, min( 365, (int) ( $_POST['max_advance_days'] ?? $settings['max_advance_days'] ) ) );
				$settings['min_advance_hours'] = max( 0, min( 168, (int) ( $_POST['min_advance_hours'] ?? $settings['min_advance_hours'] ) ) );
				$settings['email_reminder']    = ! empty( $_POST['email_reminder'] ) ? 1 : 0;
				$settings['reminder_hours']    = max( 1, min( 168, (int) ( $_POST['reminder_hours'] ?? $settings['reminder_hours'] ) ) );
				$settings['email_confirm']     = ! empty( $_POST['email_confirm'] ) ? 1 : 0;
				$settings['admin_notify_email'] = sanitize_email( $_POST['admin_notify_email'] ?? $settings['admin_notify_email'] );

				$this->wpdb->update(
					$this->prefix . 'dinia_customers',
					array( 'settings' => wp_json_encode( $settings ) ),
					array( 'id' => (int) $customer->id )
				);
				$redirect = add_query_arg( 'saved', 'settings', $redirect );
				break;

			case 'save_hours':
				$days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
				foreach ( $days as $day ) {
					$closed = ! empty( $_POST[ "{$day}_closed" ] );
					$open   = sanitize_text_field( $_POST[ "{$day}_open" ] ?? '09:00' );
					$close  = sanitize_text_field( $_POST[ "{$day}_close" ] ?? '18:00' );

					$existing = $this->wpdb->get_var( $this->wpdb->prepare(
						"SELECT id FROM {$this->prefix}dinia_hours WHERE customer_id = %d AND day = %s LIMIT 1",
						(int) $customer->id, $day
					) );

					$data = array(
						'customer_id' => (int) $customer->id,
						'day'         => $day,
						'open'        => $closed ? '' : $open,
						'close'       => $closed ? '' : $close,
						'open2'       => '',
						'close2'      => '',
					);

					if ( $existing ) {
						$this->wpdb->update( $this->prefix . 'dinia_hours', $data, array( 'id' => $existing ) );
					} else {
						$this->wpdb->insert( $this->prefix . 'dinia_hours', $data );
					}
				}
				$redirect = add_query_arg( 'saved', 'hours', $redirect );
				break;

			case 'add_table':
				$name        = sanitize_text_field( $_POST['table_name'] ?? '' );
				$capacity    = max( 1, (int) ( $_POST['table_capacity'] ?? 2 ) );
				$area        = sanitize_text_field( $_POST['table_area'] ?? 'innen' );
				$description = sanitize_text_field( $_POST['table_description'] ?? '' );
				if ( ! empty( $name ) ) {
					$this->wpdb->insert(
						$this->prefix . 'dinia_tables',
						array(
							'customer_id'  => (int) $customer->id,
							'name'         => $name,
							'capacity'     => $capacity,
							'area'         => $area,
							'description'  => $description,
							'is_active'    => 1,
							'sort_order'   => 0,
						)
					);
				}
				$redirect = add_query_arg( 'saved', 'table', $redirect );
				break;

			case 'delete_table':
				$table_id = (int) ( $_POST['table_id'] ?? 0 );
				if ( $table_id > 0 ) {
					$this->wpdb->delete(
						$this->prefix . 'dinia_tables',
						array( 'id' => $table_id, 'customer_id' => (int) $customer->id )
					);
				}
				$redirect = add_query_arg( 'deleted', 'table', $redirect );
				break;

			case 'regenerate_api_key':
				$new_key = $this->regenerate_api_key( (int) $customer->id );
				if ( $new_key ) {
					$redirect = add_query_arg( 'api_key', $new_key, $redirect );
				}
				break;

			case 'cancel_contract':
				$subscriptions = new DINA_Subscriptions();
				$subscriptions->cancel( (int) $customer->id );

				// Mollie-Subscription kündigen
				if ( ! empty( $customer->subscription_id ) && ! empty( $customer->mollie_customer_id ) ) {
					$api_key = ( new DINA_Mollie() )->get_api_key();
					if ( ! empty( $api_key ) ) {
						$ch = curl_init();
						curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
						curl_setopt( $ch, CURLOPT_URL, "https://api.mollie.com/v2/customers/{$customer->mollie_customer_id}/subscriptions/{$customer->subscription_id}" );
						curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Authorization: Bearer ' . $api_key ) );
						curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
						curl_exec( $ch );
						curl_close( $ch );
					}
				}

				$this->wpdb->update(
					$this->prefix . 'dinia_customers',
					array( 'status' => 'cancelled' ),
					array( 'id' => (int) $customer->id )
				);

				// Bestätigungsmail
				if ( ! empty( $customer->email ) ) {
					$subject = sprintf( 'Vertrag gekündigt – %s', $customer->company );
					$html    = '<h2>🔴 Vertrag gekündigt</h2><p>Hallo <strong>' . esc_html( $customer->company ) . '</strong>,</p><p>Ihr Vertrag wurde gekündigt. Das Mollie-Abo wurde gestoppt.</p>';
					DINA_Mailer::send( $customer->email, $subject, 'Ihr Vertrag wurde gekündigt', DINA_Mailer::build_html( $subject, $html, $customer->company ) );
				}

				$redirect = add_query_arg( 'cancelled', '1', $redirect );
				break;
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * API-Key regenerieren.
	 */
	private function regenerate_api_key( int $customer_id ): string {
		$new_key = 'dina_' . bin2hex( random_bytes( 20 ) );
		$this->wpdb->update(
			$this->prefix . 'dinia_customers',
			array(
				'api_key_hash' => hash( 'sha256', $new_key ),
				'api_key_hint' => '...' . substr( $new_key, -4 ),
			),
			array( 'id' => $customer_id )
		);
		return $new_key;
	}

	/**
	 * Rendert die Account-Seite.
	 */
	public function render_account_page(): void {
		$current_user = wp_get_current_user();
		$customer     = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->prefix}dinia_customers WHERE email = %s LIMIT 1",
				$current_user->user_email
			)
		);

		?>
		<div class="wrap dinia-acc-wrap">
			<h1>👤 Mein Konto – Dinia</h1>

			<?php if ( isset( $_GET['cancelled'] ) ) : ?>
				<div class="dinia-acc-notice" style="border-left-color:#dc3545;background:#fce4e4;">✅ Vertrag wurde gekündigt. Mollie-Abo gestoppt.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="dinia-acc-notice">✅ Einstellungen gespeichert.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="dinia-acc-notice">✅ Gelöscht.</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['api_key'] ) ) : ?>
				<div class="dinia-acc-notice">
					<strong>🔑 Neuer API-Key:</strong><br>
					<code style="font-size:1rem;user-select:all;"><?php echo esc_html( $_GET['api_key'] ); ?></code><br>
					<span style="color:#dc3545;">⚠️ Diesen Key sofort kopieren – wird nur einmal angezeigt!</span>
				</div>
			<?php endif; ?>

			<?php if ( ! $customer ) : ?>
				<div class="dinia-acc-card"><p style="color:#888;">Kein Dinia-Konto zu Ihrer E-Mail gefunden.</p></div>
				</div><?php return;
			endif;

			$settings   = DINA_Booking::get_settings( (int) $customer->id );
			$api_key    = $customer->api_key_hint ?: '—';
			$mollie_key = ( new DINA_Mollie() )->get_api_key();
			$is_test    = strpos( $mollie_key, 'test_' ) === 0;
			?>

			<!-- ─── TABS ─── -->
			<div class="dinia-acc-tabs" id="dinia-acc-tabs">
				<div class="dinia-acc-tab active" data-tab="dashboard">📊 Übersicht</div>
				<div class="dinia-acc-tab" data-tab="settings">⚙️ Einstellungen</div>
				<div class="dinia-acc-tab" data-tab="hours">🕐 Öffnungszeiten</div>
				<div class="dinia-acc-tab" data-tab="tables">🪑 Tische</div>
				<div class="dinia-acc-tab" data-tab="embed">🔗 Einbindung</div>
				<div class="dinia-acc-tab" data-tab="account">👤 Abo</div>
			</div>

			<!-- TAB: Dashboard -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-dashboard">
				<h2>📊 Übersicht</h2>
				<table class="dinia-acc-table">
					<tr><td style="font-weight:600;width:180px;">Restaurant</td><td><?php echo esc_html( $settings['restaurant_name'] ?: $customer->company ); ?></td></tr>
					<tr><td style="font-weight:600;">E-Mail</td><td><?php echo esc_html( $customer->email ); ?></td></tr>
					<tr><td style="font-weight:600;">Status</td><td><?php echo esc_html( $customer->status ); ?></td></tr>
					<tr><td style="font-weight:600;">Mitglied seit</td><td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $customer->created_at ) ) ); ?></td></tr>
					<tr><td style="font-weight:600;">Plan</td><td>Dinia Basic – 19,95 € / Monat</td></tr>
					<tr><td style="font-weight:600;">Rabattcode</td><td><?php echo esc_html( $customer->coupon_code ?: '—' ); ?></td></tr>
					<tr><td style="font-weight:600;">API-Key (Hash)</td><td><code><?php echo esc_html( $api_key ); ?></code></td></tr>
					<tr><td style="font-weight:600;">Subscription</td><td><?php echo esc_html( $customer->subscription_id ?: '—' ); ?></td></tr>
				</table>

				<div style="margin-top:12px;font-size:0.85rem;color:#888;">
					<?php if ( $is_test ) : ?>
						🔧 <strong>Testmodus</strong> – Zahlungen laufen über Mollie Test-Umgebung.
					<?php endif; ?>
				</div>
			</div>

			<!-- TAB: Settings -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-settings" style="display:none;">
				<h2>⚙️ Restaurant-Einstellungen</h2>
				<form method="post">
					<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
					<input type="hidden" name="dinia_action" value="save_settings">

					<div class="dinia-acc-row">
						<label>Restaurant-Name</label>
						<input type="text" name="restaurant_name" value="<?php echo esc_attr( $settings['restaurant_name'] ?: $customer->company ); ?>">
					</div>
					<div class="dinia-acc-row">
						<label>Slot-Dauer (Min.)</label>
						<input type="number" name="slot_duration" min="15" max="240" value="<?php echo (int) $settings['slot_duration']; ?>">
						<span class="description">Wie lange ein Slot dauert (z.B. 120 = 2 Stunden)</span>
					</div>
					<div class="dinia-acc-row">
						<label>Slot-Intervall (Min.)</label>
						<input type="number" name="slot_interval" min="5" max="120" value="<?php echo (int) $settings['slot_interval']; ?>">
						<span class="description">Abstand zwischen Slots (z.B. 30 = alle 30 Min.)</span>
					</div>
					<div class="dinia-acc-row">
						<label>Max. Vorausbuchung (Tage)</label>
						<input type="number" name="max_advance_days" min="1" max="365" value="<?php echo (int) $settings['max_advance_days']; ?>">
					</div>
					<div class="dinia-acc-row">
						<label>Min. Vorlauf (Stunden)</label>
						<input type="number" name="min_advance_hours" min="0" max="168" value="<?php echo (int) $settings['min_advance_hours']; ?>">
					</div>

					<h3 style="margin:20px 0 8px;">📧 E-Mail-Benachrichtigungen</h3>
					<div class="dinia-acc-row">
						<label>Bestätigungs-E-Mail</label>
						<label style="font-weight:400;min-width:auto;"><input type="checkbox" name="email_confirm" value="1" <?php checked( $settings['email_confirm'], 1 ); ?>> Aktivieren</label>
					</div>
					<div class="dinia-acc-row">
						<label>Erinnerungs-E-Mail</label>
						<label style="font-weight:400;min-width:auto;"><input type="checkbox" name="email_reminder" value="1" <?php checked( $settings['email_reminder'], 1 ); ?>> Aktivieren</label>
					</div>
					<div class="dinia-acc-row">
						<label>Erinnerung (Std. vorher)</label>
						<input type="number" name="reminder_hours" min="1" max="168" value="<?php echo (int) $settings['reminder_hours']; ?>">
					</div>
					<div class="dinia-acc-row">
						<label>Admin-Benachrichtigung</label>
						<input type="email" name="admin_notify_email" value="<?php echo esc_attr( $settings['admin_notify_email'] ); ?>" placeholder="leer = keine Benachrichtigung">
						<span class="description">E-Mail für neue Buchungs-Benachrichtigungen</span>
					</div>

					<div style="margin-top:16px;">
						<button type="submit" class="dinia-btn-primary-s dinia-btn-s">Einstellungen speichern</button>
					</div>
				</form>
			</div>

			<!-- TAB: Öffnungszeiten -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-hours" style="display:none;">
				<h2>🕐 Öffnungszeiten</h2>
				<form method="post">
					<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
					<input type="hidden" name="dinia_action" value="save_hours">

					<div class="dinia-hours-grid">
					<?php
					$days_de = array( 'mon' => 'Montag', 'tue' => 'Dienstag', 'wed' => 'Mittwoch', 'thu' => 'Donnerstag', 'fri' => 'Freitag', 'sat' => 'Samstag', 'sun' => 'Sonntag' );
					foreach ( $days_de as $dk => $dl ) :
						$h = $this->wpdb->get_row( $this->wpdb->prepare(
							"SELECT * FROM {$this->prefix}dinia_hours WHERE customer_id = %d AND day = %s LIMIT 1",
							(int) $customer->id, $dk
						) );
						$open   = $h ? $h->open : '09:00';
						$close  = $h ? $h->close : '18:00';
						$closed = $h && empty( $h->open ) && empty( $h->close );
					?>
						<div class="dinia-hours-day">
							<label><?php echo esc_html( $dl ); ?></label>
							<div class="dinia-hour-row">
								<label style="font-weight:400;min-width:auto;"><input type="checkbox" name="<?php echo $dk; ?>_closed" value="1" <?php checked( $closed ); ?>> Geschlossen</label>
							</div>
							<div class="dinia-hour-row">
								<span>Von</span>
								<input type="time" name="<?php echo $dk; ?>_open" value="<?php echo esc_attr( $open ); ?>">
								<span>bis</span>
								<input type="time" name="<?php echo $dk; ?>_close" value="<?php echo esc_attr( $close ); ?>">
							</div>
						</div>
					<?php endforeach; ?>
					</div>

					<div style="margin-top:16px;">
						<button type="submit" class="dinia-btn-primary-s dinia-btn-s">Öffnungszeiten speichern</button>
					</div>
				</form>
			</div>

			<!-- TAB: Tische -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-tables" style="display:none;">
				<h2>🪑 Tische verwalten</h2>

				<table class="dinia-acc-table">
					<thead><tr><th>Name</th><th>Kapazität</th><th>Bereich</th><th>Aktion</th></tr></thead>
					<tbody>
					<?php
					$tables = $this->wpdb->get_results( $this->wpdb->prepare(
						"SELECT * FROM {$this->prefix}dinia_tables WHERE customer_id = %d ORDER BY sort_order, name",
						(int) $customer->id
					) );
					foreach ( $tables as $t ) :
					?>
						<tr>
							<td><?php echo esc_html( $t->name ); ?></td>
							<td><?php echo (int) $t->capacity; ?> Pers.</td>
							<td><?php echo esc_html( $t->area ); ?></td>
							<td>
								<form method="post" style="display:inline;" onsubmit="return confirm('Tisch löschen?');">
									<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
									<input type="hidden" name="dinia_action" value="delete_table">
									<input type="hidden" name="table_id" value="<?php echo (int) $t->id; ?>">
									<button type="submit" class="dinia-btn-danger-s dinia-btn-s">Löschen</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $tables ) ) : ?>
						<tr><td colspan="4" style="color:#888;">Noch keine Tische angelegt.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<h3 style="margin:16px 0 8px;">➕ Neuen Tisch anlegen</h3>
				<form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;">
					<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
					<input type="hidden" name="dinia_action" value="add_table">
					<div>
						<input type="text" name="table_name" placeholder="Tisch-Name" required style="padding:6px 10px;border:1px solid #ccc;border-radius:4px;">
					</div>
					<div>
						<input type="number" name="table_capacity" value="2" min="1" max="20" style="width:70px;padding:6px;border:1px solid #ccc;border-radius:4px;">
					</div>
					<div>
						<select name="table_area" style="padding:6px;border:1px solid #ccc;border-radius:4px;">
							<option value="innen">Innen</option>
							<option value="außen">Außen</option>
							<option value="bar">Bar</option>
							<option value="terrasse">Terrasse</option>
						</select>
					</div>
					<button type="submit" class="dinia-btn-primary-s dinia-btn-s">Hinzufügen</button>
				</form>
			</div>

			<!-- TAB: Embed / Einbindung -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-embed" style="display:none;">
				<h2>🔗 Einbindung</h2>

				<h3 style="margin:16px 0 8px;">🔑 API-Key</h3>
				<div class="dinia-acc-row">
					<label>Aktueller Key</label>
					<code style="font-size:1rem;"><?php echo esc_html( $api_key ); ?></code>
					<form method="post" style="display:inline;">
						<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
						<input type="hidden" name="dinia_action" value="regenerate_api_key">
						<button type="submit" class="dinia-btn-ghost-s dinia-btn-s" onclick="return confirm('Neuen API-Key generieren? Alter Key wird ungültig.');">Neu generieren</button>
					</form>
				</div>

				<h3 style="margin:20px 0 8px;">📦 WordPress-Plugin (Client)</h3>
				<p>Installieren Sie das <strong>GoBookMe Client</strong>-Plugin auf Ihrer WordPress-Seite:</p>
				<ol style="margin:8px 0 8px 20px;">
					<li>Plugin herunterladen: <a href="https://github.com/livedialai/gobookme-client/releases/latest" target="_blank" class="dinia-btn-primary-s dinia-btn-s" style="text-decoration:none;">⬇️ GoBookMe Client herunterladen (GitHub)</a></li>
					<li>In WordPress unter <strong>Plugins → Installieren → Plugin hochladen</strong> einspielen</li>
					<li>Plugin aktivieren</li>
					<li>Unter <strong>Einstellungen → GoBookMe Client</strong> konfigurieren:</li>
				</ol>
				<div class="dinia-code-block">SaaS-URL: <?php echo esc_url( home_url() ); ?>
API-Key: <?php echo esc_html( $customer->api_key_hint ?: 'Nach Generierung oben eintragen' ); ?></div>

				<h3 style="margin:20px 0 8px;">📄 Shortcode (WP-Seite)</h3>
				<div class="dinia-code-block">[gobookme_booking slug="<?php echo esc_attr( $customer->slug ); ?>"]</div>
				<p style="color:#666;font-size:0.85rem;">Mit eigener Farbe: <code>[gobookme_booking slug="<?php echo esc_attr( $customer->slug ); ?>" color="#ff6b00"]</code></p>

				<h3 style="margin:20px 0 8px;">🌐 Embed-Code (Nicht-WP-Seite)</h3>
				<p>Fügen Sie diesen Code in den <code>&lt;body&gt;</code> Ihrer Website ein:</p>
				<?php
				$embed_code = '<div id="dinia-booking-widget"></div>
<script src="' . esc_url( home_url( '/wp-json/dinia/v1/widget/' . $customer->slug . '/embed.js' ) ) . '"></script>';
				?>
				<div class="dinia-code-block" onclick="navigator.clipboard.writeText(this.textContent.trim())"><?php echo esc_html( $embed_code ); ?></div>
				<p style="color:#666;font-size:0.85rem;">📋 Klick auf den Block = Kopieren</p>

				<h3 style="margin:20px 0 8px;">📘 Anleitung (PDF)</h3>
				<p><a href="https://gomeetme.com/gobookme-doc.md" target="_blank">📄 Online-Dokumentation</a></p>
			</div>

			<!-- TAB: Abo -->
			<div class="dinia-acc-card dinia-tab-content" id="tab-account" style="display:none;">
				<h2>👤 Abo & Vertrag</h2>
				<table class="dinia-acc-table">
					<tr><td style="font-weight:600;width:180px;">Status</td><td><?php echo esc_html( $customer->status ); ?></td></tr>
					<tr><td style="font-weight:600;">Plan</td><td>Dinia Basic – 19,95 € / Monat</td></tr>
					<tr><td style="font-weight:600;">Rabatt</td><td><?php echo $customer->coupon_code ? esc_html( $customer->coupon_code ) . ' (1. Monat 1€)' : '—'; ?></td></tr>
					<tr><td style="font-weight:600;">Mitglied seit</td><td><?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $customer->created_at ) ) ); ?></td></tr>
					<tr><td style="font-weight:600;">Mollie-Kunde</td><td><code><?php echo esc_html( $customer->mollie_customer_id ?: '—' ); ?></code></td></tr>
					<tr><td style="font-weight:600;">Subscription-ID</td><td><code><?php echo esc_html( $customer->subscription_id ?: '—' ); ?></code></td></tr>
				</table>

				<?php if ( in_array( $customer->status, array( 'active', 'suspended' ), true ) ) : ?>
					<form method="post" style="margin-top:16px;" onsubmit="return confirm('Möchten Sie Ihren Vertrag wirklich kündigen? Das Mollie-Abo wird gestoppt.');">
						<?php wp_nonce_field( 'dinia_account_nonce' ); ?>
						<input type="hidden" name="dinia_action" value="cancel_contract">
						<button type="submit" class="dinia-btn-danger-s dinia-btn-s">🔴 Vertrag kündigen</button>
						<p class="description" style="margin-top:4px;">Nach der Kündigung werden keine weiteren Zahlungen eingezogen. Ihr Konto wird deaktiviert.</p>
					</form>
				<?php elseif ( 'cancelled' === $customer->status ) : ?>
					<div style="margin-top:12px;padding:12px;background:#fce4e4;border-radius:6px;">Ihr Vertrag wurde bereits gekündigt.</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(function($) {
			$('#dinia-acc-tabs .dinia-acc-tab').on('click', function() {
				var tab = $(this).data('tab');
				$('#dinia-acc-tabs .dinia-acc-tab').removeClass('active');
				$(this).addClass('active');
				$('.dinia-tab-content').hide();
				$('#tab-' + tab).fadeIn(200);
			});
		});
		</script>
		<?php
	}
}
