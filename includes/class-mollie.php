<?php
/**
 * DINA_Mollie – Mollie-Zahlungsintegration für GoBookMe SaaS
 *
 * Stellt Methoden zur Kommunikation mit der Mollie-API v2 bereit.
 * Das Mollie SDK wird NICHT verwendet; sämtliche API-Aufrufe erfolgen
 * direkt über wp_remote_get() / wp_remote_post().
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Mollie
 *
 * @since 1.0.0
 */
class DINA_Mollie {

	/**
	 * Mollie-API-Basis-URL (v2).
	 *
	 * @var string
	 */
	private string $api_base = 'https://api.mollie.com/v2/';

	/**
	 * Gibt den hinterlegten Mollie-API-Key zurück.
	 *
	 * Liest den Live- oder Test-API-Key aus der WordPress-Option 'dinia_mollie_api_key'.
	 *
	 * @since 1.0.0
	 *
	 * @return string Der API-Key oder ein leerer String, wenn keiner gesetzt ist.
	 */
	public function get_api_key(): string {
		$key = get_option( 'dinia_mollie_api_key', '' );

		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Kündigt eine bestehende Mollie-Subscription.
	 *
	 * @since 1.1.7
	 *
	 * @param string $mollie_customer_id Die Mollie-Kunden-ID.
	 * @param string $subscription_id    Die Mollie-Subscription-ID.
	 *
	 * @return array{success: bool, error?: string}
	 */
	public function cancel_subscription( string $mollie_customer_id, string $subscription_id ): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Mollie API-Key ist nicht konfiguriert.',
			);
		}

		$response = wp_remote_request(
			$this->api_base . 'customers/' . rawurlencode( $mollie_customer_id ) . '/subscriptions/' . rawurlencode( $subscription_id ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );
			return array(
				'success' => false,
				'error'   => $data['title'] ?? "HTTP $code",
			);
		}

		return array( 'success' => true );
	}

	/**
	 * Holt alle aktiven Subscriptions eines Mollie-Kunden.
	 *
	 * @since 1.1.7
	 *
	 * @param string $mollie_customer_id Die Mollie-Kunden-ID.
	 *
	 * @return array{success: bool, subscriptions?: array, error?: string}
	 */
	public function get_subscriptions( string $mollie_customer_id ): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Mollie API-Key ist nicht konfiguriert.',
			);
		}

		$response = wp_remote_get(
			$this->api_base . 'customers/' . rawurlencode( $mollie_customer_id ) . '/subscriptions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'error'   => $decoded['title'] ?? "HTTP $code",
			);
		}

		return array(
			'success'       => true,
			'subscriptions' => $decoded['_embedded']['subscriptions'] ?? array(),
		);
	}

	/**
	 * Erstellt einen neuen Kunden bei Mollie.
	 *
	 * @since 1.0.0
	 *
	 * @param string $company Firmenname (wird als 'name' an Mollie übergeben).
	 * @param string $email   E-Mail-Adresse des Kunden.
	 *
	 * @return array{success: bool, customer_id?: string, error?: string} Ergebnis-Array.
	 */
	public function create_customer( string $company, string $email ): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Mollie API-Key ist nicht konfiguriert.',
			);
		}

		$body = wp_json_encode(
			array(
				'name'  => sanitize_text_field( $company ),
				'email' => sanitize_email( $email ),
			)
		);

		$response = wp_remote_post(
			$this->api_base . 'customers',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! isset( $decoded['id'] ) ) {
			$error_msg = isset( $decoded['title'] )
				? ( $decoded['title'] . ( isset( $decoded['detail'] ) ? ': ' . $decoded['detail'] : '' ) )
				: ( is_wp_error( $response ) ? $response->get_error_message() : "HTTP $code" );

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		return array(
			'success'     => true,
			'customer_id' => sanitize_text_field( $decoded['id'] ),
		);
	}

	/**
	 * Erstellt ein reguläres (wiederkehrendes) Abonnement bei Mollie.
	 *
	 * Das Abonnement wird für einen bestehenden Mollie-Kunden angelegt.
	 * Mollie zieht den Betrag automatisch zum vereinbarten Intervall ein.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mollie_customer_id Die Mollie-Kunden-ID (z. B. 'cst_...').
	 * @param float  $amount             Betrag in Euro (z. B. 29.00).
	 * @param string $description        Beschreibung, die auf dem Kontoauszug erscheint.
	 * @param string $webhook_url        Webhook-URL für Zahlungsbenachrichtigungen.
	 *
	 * @return array{success: bool, subscription_id?: string, error?: string} Ergebnis-Array.
	 */
	public function create_subscription( string $mollie_customer_id, float $amount, string $description, string $webhook_url ): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Mollie API-Key ist nicht konfiguriert.',
			);
		}

		$body = wp_json_encode(
			array(
				'amount'      => array(
					'currency' => 'EUR',
					'value'    => number_format( $amount, 2, '.', '' ),
				),
				'interval'    => '1 month',
				'description' => sanitize_text_field( $description ),
				'webhookUrl'  => esc_url_raw( $webhook_url ),
			)
		);

		$response = wp_remote_post(
			$this->api_base . 'customers/' . rawurlencode( $mollie_customer_id ) . '/subscriptions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! isset( $decoded['id'] ) ) {
			$error_msg = isset( $decoded['title'] )
				? ( $decoded['title'] . ( isset( $decoded['detail'] ) ? ': ' . $decoded['detail'] : '' ) )
				: "HTTP $code";

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		return array(
			'success'         => true,
			'subscription_id' => sanitize_text_field( $decoded['id'] ),
		);
	}

	/**
	 * Erstellt eine einmalige Zahlung (First Payment) bei Mollie.
	 *
	 * Diese Methode wird für den initialen Zahlungsvorgang genutzt,
	 * bevor ein Abonnement aktiviert wird. Der Kunde wird zu Mollie
	 * weitergeleitet, um die Zahlung zu autorisieren.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mollie_customer_id Die Mollie-Kunden-ID (z. B. 'cst_...').
	 * @param float  $amount             Betrag in Euro (z. B. 29.00).
	 * @param string $description        Beschreibung der Zahlung.
	 * @param string $webhook_url        Webhook-URL für Zahlungsbenachrichtigungen.
	 * @param string $redirect_url       URL, zu der der Kunde nach der Zahlung weitergeleitet wird.
	 *
	 * @return array{success: bool, payment_id?: string, checkout_url?: string, error?: string} Ergebnis-Array.
	 */
	public function create_first_payment( string $mollie_customer_id, float $amount, string $description, string $webhook_url, string $redirect_url ): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => 'Mollie API-Key ist nicht konfiguriert.',
			);
		}

		$body = wp_json_encode(
			array(
				'amount'       => array(
					'currency' => 'EUR',
					'value'    => number_format( $amount, 2, '.', '' ),
				),
				'description'  => sanitize_text_field( $description ),
				'customerId'   => $mollie_customer_id,
				'sequenceType' => 'first',
				'webhookUrl'   => esc_url_raw( $webhook_url ),
				'redirectUrl'  => esc_url_raw( $redirect_url ),
			)
		);

		$response = wp_remote_post(
			$this->api_base . 'payments',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 || ! isset( $decoded['id'] ) ) {
			$error_msg = isset( $decoded['title'] )
				? ( $decoded['title'] . ( isset( $decoded['detail'] ) ? ': ' . $decoded['detail'] : '' ) )
				: "HTTP $code";

			return array(
				'success' => false,
				'error'   => $error_msg,
			);
		}

		$checkout_url = isset( $decoded['_links']['checkout']['href'] )
			? $decoded['_links']['checkout']['href']
			: '';

		return array(
			'success'      => true,
			'payment_id'   => sanitize_text_field( $decoded['id'] ),
			'checkout_url' => esc_url_raw( $checkout_url ),
		);
	}

	/**
	 * Verarbeitet eingehende Mollie-Webhook-Events.
	 *
	 * Interpretiert die von Mollie gesendeten Webhook-Daten und führt
	 * die entsprechenden Aktionen im Plugin aus:
	 *
	 * - 'payment.paid'          → Markiert die zugehörige Rechnung als bezahlt
	 *                            (DINA_Invoices::mark_paid()).
	 * - 'subscription.cancelled' → Kündigt das lokale Abonnement
	 *                            (DINA_Subscriptions::cancel() via Customer-ID).
	 *
	 * @since 1.0.0
	 *
	 * @param array $webhook_data Die von Mollie übermittelten Daten (bereits als assoziatives Array).
	 *
	 * @return array{handled: bool, event?: string, error?: string} Ergebnis-Array.
	 */
	/**
	 * Ruft Payment-Details von der Mollie-API ab.
	 *
	 * @since 1.1.8
	 * @param string $payment_id Mollie-Payment-ID.
	 * @return array|null Payment-Daten oder null bei Fehler.
	 */
	public function get_payment( string $payment_id ): ?array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return null;
		}
		$response = wp_remote_get(
			$this->api_base . 'payments/' . rawurlencode( $payment_id ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $decoded ) ) {
			return null;
		}
		return $decoded;
	}

	public function handle_webhook( array $webhook_data ): array {
		// ---- 1) Mollie sendet nur id=tr_xxx (kein resource/status/customerId) ----
		$payment_id = $webhook_data['id'] ?? '';
		if ( empty( $payment_id ) ) {
			return array(
				'handled' => false,
				'error'   => 'Keine Payment-ID im Webhook.',
			);
		}

		// ---- 2) Payment-Details von Mollie-API holen ----
		$payment = $this->get_payment( $payment_id );
		if ( null === $payment ) {
			return array(
				'handled' => false,
				'error'   => "Payment {$payment_id} konnte nicht von Mollie-API geladen werden.",
			);
		}

		$resource = $payment['resource'] ?? '';
		$mollie_customer_id = $payment['customerId'] ?? '';

		// ---- 3) Event-Dispatching ----
		switch ( $resource ) {
			case 'payment':
				return $this->handle_payment_event( $payment, $payment_id, $mollie_customer_id );

			default:
				// Kein payment-event – nichts zu tun
				return array(
					'handled' => true,
					'event'   => $resource,
					'note'    => "Nicht verarbeitet: {$resource}",
				);
		}
	}

	/**
	 * Verarbeitet ein Payment-Webhook-Event.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $webhook_data Vollständige Webhook-Daten.
	 * @param string $payment_id   Mollie-Payment-ID.
	 * @param string $mollie_customer_id Mollie-Customer-ID.
	 *
	 * @return array{handled: bool, event?: string, error?: string}
	 */
	private function handle_payment_event( array $webhook_data, string $payment_id, string $mollie_customer_id ): array {
		$status = $webhook_data['status'] ?? '';

		if ( 'paid' !== $status ) {
			return array(
				'handled' => true,
				'event'   => 'payment.' . $status,
				'note'    => "Zahlung {$payment_id} hat Status '{$status}' – keine Aktion erforderlich.",
			);
		}

		global $wpdb;

		// Betrag aus dem verschachtelten 'amount'-Objekt extrahieren.
		$amount_value = 0.00;
		if ( isset( $webhook_data['amount']['value'] ) ) {
			$amount_value = (float) $webhook_data['amount']['value'];
		}

		$description = $webhook_data['description'] ?? '';

		// Rechnung als bezahlt markieren.
		$invoices = new DINA_Invoices();
		$marked   = $invoices->mark_paid( $payment_id, $amount_value, $description );

		if ( ! $marked ) {
			return array(
				'handled' => false,
				'event'   => 'payment.paid',
				'error'   => "Rechnung mit Mollie-Payment-ID {$payment_id} konnte nicht als bezahlt markiert werden (nicht gefunden oder Update fehlgeschlagen).",
			);
		}

		// Kunde auf 'active' setzen
		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, company, email, plan_id FROM {$wpdb->prefix}dinia_customers WHERE mollie_customer_id = %s LIMIT 1",
				$mollie_customer_id
			)
		);

		if ( $customer ) {
			$wpdb->update(
				$wpdb->prefix . 'dinia_customers',
				array( 'status' => 'active' ),
				array( 'id' => (int) $customer->id )
			);

			// Subscription bei Mollie anlegen (monatlich)
			if ( ! empty( $customer->plan_id ) ) {
				$plan = ( new DINA_Plans() )->get_by_id( (int) $customer->plan_id );
				$monthly_price = ( $plan && isset( $plan->price_monthly ) ) ? (float) $plan->price_monthly : 19.95;

				$subscription = $this->create_subscription(
					$mollie_customer_id,
					$monthly_price,
					sprintf( '%s – %s', $plan ? $plan->name : 'Dinia Basic', $customer->company ),
					rest_url( 'dinia/v1/webhook/mollie' )
				);

				if ( $subscription['success'] && isset( $subscription['subscription_id'] ) ) {
					$wpdb->update(
						$wpdb->prefix . 'dinia_customers',
						array( 'subscription_id' => $subscription['subscription_id'] ),
						array( 'id' => (int) $customer->id )
					);
				}
			}
		}

		// ── Email an Kunden bei erfolgreicher Zahlung ──
		$this->send_payment_success_email( $mollie_customer_id, $amount_value, $payment_id );

		return array(
			'handled' => true,
			'event'   => 'payment.paid',
		);
	}

	/**
	 * Sendet eine Bestätigungsmail bei erfolgreicher Zahlung.
	 *
	 * @since 1.1.7
	 *
	 * @param string $mollie_customer_id Mollie-Customer-ID.
	 * @param float  $amount             Bezahlter Betrag.
	 * @param string $payment_id         Mollie-Payment-ID.
	 *
	 * @return bool
	 */
	private function send_payment_success_email( string $mollie_customer_id, float $amount, string $payment_id ): bool {
		global $wpdb;

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT company, email FROM {$wpdb->prefix}dinia_customers WHERE mollie_customer_id = %s LIMIT 1",
				$mollie_customer_id
			)
		);

		if ( ! $customer || empty( $customer->email ) ) {
			return false;
		}

		$date_formatted = date_i18n( 'l, j. F Y H:i' );
		$subject        = sprintf( __( 'Zahlung bestätigt – %s', 'dinia' ), $customer->company );

		$html  = '<h2>✅ Zahlung erfolgreich</h2>';
		$html .= '<p>Hallo <strong>' . esc_html( $customer->company ) . '</strong>,</p>';
		$html .= '<p>Ihre Zahlung in Höhe von <strong>' . number_format( $amount, 2, ',', '.' ) . ' €</strong> wurde erfolgreich abgeschlossen.</p>';
		$html .= '<p><strong>Datum:</strong> ' . esc_html( $date_formatted ) . '<br>';
		$html .= '<strong>Transaktions-ID:</strong> ' . esc_html( $payment_id ) . '</p>';
		$html .= '<p>Ihr Abonnement ist aktiv und läuft automatisch weiter.</p>';

		$html_body = DINA_Mailer::build_html( $subject, $html, $customer->company );

		return DINA_Mailer::send(
			$customer->email,
			$subject,
			"Zahlung bestätigt: {$amount} €",
			$html_body
		);
	}

	/**
	 * Verarbeitet ein Subscription-Webhook-Event.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $webhook_data Vollständige Webhook-Daten.
	 * @param string $subscription_id Mollie-Subscription-ID.
	 * @param string $mollie_customer_id Mollie-Customer-ID.
	 *
	 * @return array{handled: bool, event?: string, error?: string}
	 */
	private function handle_subscription_event( array $webhook_data, string $subscription_id, string $mollie_customer_id ): array {
		$status = $webhook_data['status'] ?? '';

		if ( 'cancelled' !== $status ) {
			return array(
				'handled' => true,
				'event'   => 'subscription.' . $status,
				'note'    => "Subscription {$subscription_id} hat Status '{$status}' – keine Aktion erforderlich.",
			);
		}

		// Lokale Customer-ID anhand der Mollie-Customer-ID ermitteln.
		$customer_id = $this->get_local_customer_id_by_mollie_id( $mollie_customer_id );

		if ( null === $customer_id ) {
			return array(
				'handled' => false,
				'event'   => 'subscription.cancelled',
				'error'   => "Kein lokaler Kunde mit Mollie-Customer-ID {$mollie_customer_id} gefunden.",
			);
		}

		// Lokales Abonnement kündigen.
		$subscriptions = new DINA_Subscriptions();
		$result        = $subscriptions->cancel( $customer_id );

		if ( false === $result ) {
			return array(
				'handled' => false,
				'event'   => 'subscription.cancelled',
				'error'   => "Lokales Abonnement für Kunde {$customer_id} konnte nicht gekündigt werden.",
			);
		}

		// ── Email an Kunden bei Kündigung ──
		$this->send_cancellation_email( $mollie_customer_id, $subscription_id );

		return array(
			'handled' => true,
			'event'   => 'subscription.cancelled',
		);
	}

	/**
	 * Sendet eine Kündigungsbestätigungsmail an den Kunden.
	 *
	 * @since 1.1.7
	 *
	 * @param string $mollie_customer_id Mollie-Customer-ID.
	 * @param string $subscription_id    Mollie-Subscription-ID.
	 *
	 * @return bool
	 */
	private function send_cancellation_email( string $mollie_customer_id, string $subscription_id ): bool {
		global $wpdb;

		$customer = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT company, email FROM {$wpdb->prefix}dinia_customers WHERE mollie_customer_id = %s LIMIT 1",
				$mollie_customer_id
			)
		);

		if ( ! $customer || empty( $customer->email ) ) {
			return false;
		}

		$date_formatted = date_i18n( 'l, j. F Y H:i' );
		$subject        = sprintf( __( 'Vertrag gekündigt – %s', 'dinia' ), $customer->company );

		$html  = '<h2>🔴 Vertrag gekündigt</h2>';
		$html .= '<p>Hallo <strong>' . esc_html( $customer->company ) . '</strong>,</p>';
		$html .= '<p>Ihr Abonnement wurde zum <strong>' . esc_html( $date_formatted ) . '</strong> gekündigt.</p>';
		$html .= '<p>Es werden keine weiteren Zahlungen von Mollie eingezogen.</p>';
		$html .= '<p>Sie können uns jederzeit kontaktieren, um den Vertrag wieder zu aktivieren.</p>';

		$html_body = DINA_Mailer::build_html( $subject, $html, $customer->company );

		return DINA_Mailer::send(
			$customer->email,
			$subject,
			"Ihr Vertrag wurde gekündigt",
			$html_body
		);
	}

	/**
	 * Ermittelt die lokale Kunden-ID anhand der Mollie-Customer-ID.
	 *
	 * Sucht in der Tabelle 'dinia_customers' nach der Mollie-Customer-ID
	 * und gibt die lokale ID zurück.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mollie_customer_id Die Mollie-Customer-ID (z. B. 'cst_...').
	 *
	 * @return int|null Die lokale Kunden-ID oder null, wenn nicht gefunden.
	 */
	private function get_local_customer_id_by_mollie_id( string $mollie_customer_id ): ?int {
		global $wpdb;

		$table = $wpdb->prefix . 'dinia_customers';

		$customer_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE mollie_customer_id = %s LIMIT 1",
				$mollie_customer_id
			)
		);

		if ( null === $customer_id || false === $customer_id ) {
			return null;
		}

		return (int) $customer_id;
	}
}
