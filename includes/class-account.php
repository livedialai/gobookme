<?php
/**
 * DINA_Account – Tenant-Konto (Self-Service)
 *
 * Shortcode [dinia_account] – zeigt Abo-Infos + Kündigungsbutton
 * für den eingeloggten Tenant (WP-User).
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

		add_shortcode( 'dinia_account', array( $this, 'render_account_page' ) );

		// POST-Handler für Kündigung
		add_action( 'init', array( $this, 'handle_cancel_request' ) );
	}

	/**
	 * Rendert die Account-Seite per Shortcode.
	 *
	 * @since 1.1.7
	 *
	 * @return string HTML.
	 */
	public function render_account_page(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="dinia-account-wrap"><p>Bitte <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">einloggen</a>, um Ihr Konto zu verwalten.</p></div>';
		}

		$current_user = wp_get_current_user();
		$customer     = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->prefix}dinia_customers WHERE email = %s LIMIT 1",
				$current_user->user_email
			)
		);

		if ( ! $customer ) {
			return '<div class="dinia-account-wrap"><p>Kein Dinia-Konto zu Ihrer E-Mail-Adresse gefunden.</p></div>';
		}

		$settings = DINA_Booking::get_settings( (int) $customer->id );

		ob_start();
		?>
		<div class="dinia-account-wrap" style="max-width:600px;margin:20px auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<?php if ( isset( $_GET['dinia_cancelled'] ) ) : ?>
				<div style="background:#d63638;color:#fff;padding:16px 24px;border-radius:8px;margin-bottom:16px;font-size:15px;">
					✅ Ihr Vertrag wurde gekündigt. Mollie-Abo gestoppt. Sie erhalten eine Bestätigungs-E-Mail.
				</div>
			<?php endif; ?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:12px;padding:24px;">
				<h2 style="margin-top:0;color:#1d2327;">Mein Konto</h2>

				<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
					<tr><td style="padding:8px 0;font-weight:600;color:#50575e;width:140px;">Restaurant</td><td><?php echo esc_html( $settings['restaurant_name'] ?: $customer->company ); ?></td></tr>
					<tr><td style="padding:8px 0;font-weight:600;color:#50575e;">E-Mail</td><td><?php echo esc_html( $customer->email ); ?></td></tr>
					<tr><td style="padding:8px 0;font-weight:600;color:#50575e;">Status</td><td><?php echo esc_html( $customer->status ); ?></td></tr>
					<tr><td style="padding:8px 0;font-weight:600;color:#50575e;">Mitglied seit</td><td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $customer->created_at ) ) ); ?></td></tr>
				</table>

				<?php if ( in_array( $customer->status, array( 'active', 'suspended' ), true ) ) : ?>
					<form method="post" onsubmit="return confirm('Möchten Sie Ihren Vertrag wirklich kündigen? Das Mollie-Abo wird gestoppt und Sie können keine Buchungen mehr annehmen.');">
						<?php wp_nonce_field( 'dinia_tenant_cancel_nonce' ); ?>
						<input type="hidden" name="dinia_tenant_cancel" value="1">
						<input type="hidden" name="customer_id" value="<?php echo (int) $customer->id; ?>">
						<button type="submit" style="background:#d63638;color:#fff;border:none;padding:12px 24px;border-radius:6px;font-size:16px;cursor:pointer;">
							🔴 Vertrag kündigen
						</button>
						<p style="color:#666;font-size:13px;margin-top:8px;">Nach der Kündigung werden keine weiteren Zahlungen eingezogen. Ihr Konto wird deaktiviert.</p>
					</form>
				<?php elseif ( 'cancelled' === $customer->status ) : ?>
					<div style="background:#f0f0f1;padding:16px;border-radius:8px;color:#646970;">
						Ihr Vertrag wurde bereits gekündigt. Bei Fragen kontaktieren Sie uns bitte.
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Verarbeitet Kündigungs-Formular-POST.
	 *
	 * @since 1.1.7
	 *
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

		$customer_id = (int) $_POST['customer_id'];
		$current_user = wp_get_current_user();

		// Sicherstellen, dass der eingeloggte User auch der Tenant ist
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

		// Redirect zurück zur Account-Seite
		wp_safe_redirect( add_query_arg( 'dinia_cancelled', '1', remove_query_arg( 'dinia_cancelled' ) ) );
		exit;
	}
}
