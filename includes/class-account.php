<?php
/**
 * DINA_Account – Tenant-Konto (Self-Service im WP-Admin)
 *
 * Zeigt Abo-Infos + Kündigungsbutton für den eingeloggten Tenant.
 * Sichtbar im Dinia-WP-Admin-Menü für Benutzer mit 'read'-Capability.
 *
 * @package GoBookMe_SaaS
 * @since   1.1.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Account
 *
 * @since 1.1.7
 */
class DINA_Account {

	/**
	 * wpdb-Instanz.
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
	 *
	 * @since 1.1.7
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb   = $wpdb;
		$this->prefix = $wpdb->prefix;

		add_action( 'admin_menu', array( $this, 'add_account_page' ) );
		add_action( 'admin_init', array( $this, 'handle_cancel_request' ) );
	}

	/**
	 * Admin-Menü-Punkt für Tenant (eigener Menüeintrag, kein Submenu).
	 *
	 * @since 1.1.7
	 * @return void
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
	 * Rendert die Account-Seite.
	 *
	 * @since 1.1.7
	 * @return void
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
		<div class="wrap dinia-wrap">
			<h1>Mein Konto</h1>

			<?php if ( isset( $_GET['dinia_cancelled'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>✅ Vertrag wurde gekündigt. Mollie-Abo gestoppt. Sie erhalten eine Bestätigungs-E-Mail.</p>
				</div>
			<?php endif; ?>

			<?php if ( ! $customer ) : ?>
				<div class="notice notice-warning"><p>Kein Dinia-Konto zu Ihrer E-Mail-Adresse gefunden.</p></div>
				</div>
				<?php
				return;
			endif;

			$settings = DINA_Booking::get_settings( (int) $customer->id );
			?>
			<div class="dinia-card" style="max-width:600px;">
				<table class="dinia-table" style="margin-bottom:16px;">
					<tr><td style="font-weight:600;width:140px;">Restaurant</td><td><?php echo esc_html( $settings['restaurant_name'] ?: $customer->company ); ?></td></tr>
					<tr><td style="font-weight:600;">E-Mail</td><td><?php echo esc_html( $customer->email ); ?></td></tr>
					<tr><td style="font-weight:600;">Status</td><td><?php echo esc_html( $customer->status ); ?></td></tr>
					<tr><td style="font-weight:600;">Mitglied seit</td><td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $customer->created_at ) ) ); ?></td></tr>
				</table>

				<?php if ( in_array( $customer->status, array( 'active', 'suspended' ), true ) ) : ?>
					<form method="post" onsubmit="return confirm('Möchten Sie Ihren Vertrag wirklich kündigen? Das Mollie-Abo wird gestoppt.');">
						<?php wp_nonce_field( 'dinia_tenant_cancel_nonce' ); ?>
						<input type="hidden" name="dinia_tenant_cancel" value="1">
						<input type="hidden" name="customer_id" value="<?php echo (int) $customer->id; ?>">
						<button type="submit" class="dinia-btn dinia-btn-danger">🔴 Vertrag kündigen</button>
						<p class="description" style="margin-top:8px;">Nach der Kündigung werden keine weiteren Zahlungen eingezogen. Ihr Konto wird deaktiviert.</p>
					</form>
				<?php elseif ( 'cancelled' === $customer->status ) : ?>
					<div class="notice notice-info inline"><p>Ihr Vertrag wurde bereits gekündigt.</p></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Verarbeitet Kündigungs-Formular-POST.
	 *
	 * @since 1.1.7
	 * @return void
	 */
	public function handle_cancel_request(): void {
		if ( empty( $_POST['dinia_tenant_cancel'] ) || empty( $_POST['customer_id'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_tenant_cancel_nonce' ) ) {
			return;
		}

		$customer_id  = (int) $_POST['customer_id'];
		$current_user = wp_get_current_user();

		$customer = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->prefix}dinia_customers WHERE id = %d AND email = %s LIMIT 1",
				$customer_id,
				$current_user->user_email
			)
		);

		if ( ! $customer ) {
			return;
		}

		// 1) Mollie-Subscriptions kündigen
		if ( ! empty( $customer->mollie_customer_id ) ) {
			$mollie      = new DINA_Mollie();
			$subs_result = $mollie->get_subscriptions( $customer->mollie_customer_id );
			if ( $subs_result['success'] && ! empty( $subs_result['subscriptions'] ) ) {
				foreach ( $subs_result['subscriptions'] as $sub ) {
					if ( in_array( $sub['status'], array( 'active', 'pending' ), true ) ) {
						$mollie->cancel_subscription( $customer->mollie_customer_id, $sub['id'] );
					}
				}
			}
		}

		// 2) Lokales Abonnement kündigen
		$subscriptions = new DINA_Subscriptions();
		$subscriptions->cancel( $customer_id );

		// 3) Customer-Status auf cancelled
		$this->wpdb->update(
			$this->prefix . 'dinia_customers',
			array( 'status' => 'cancelled' ),
			array( 'id' => $customer_id )
		);

		// 4) Bestätigungsmail
		if ( ! empty( $customer->email ) ) {
			$date_formatted = date_i18n( 'l, j. F Y H:i' );
			$subject        = sprintf( __( 'Vertrag gekündigt – %s', 'dinia' ), $customer->company );
			$html  = '<h2>🔴 Vertrag gekündigt</h2>';
			$html .= '<p>Hallo <strong>' . esc_html( $customer->company ) . '</strong>,</p>';
			$html .= '<p>Ihr Vertrag wurde zum <strong>' . esc_html( $date_formatted ) . '</strong> gekündigt.</p>';
			$html .= '<p>Das Mollie-Abo wurde gestoppt. Es werden keine weiteren Zahlungen eingezogen.</p>';
			$html .= '<p>Bei Fragen kontaktieren Sie uns bitte.</p>';
			$html_body = DINA_Mailer::build_html( $subject, $html, $customer->company );
			DINA_Mailer::send( $customer->email, $subject, 'Ihr Vertrag wurde gekündigt', $html_body );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=dinia-my-account&dinia_cancelled=1' ) );
		exit;
	}
}
