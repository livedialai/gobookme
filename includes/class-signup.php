<?php
/**
 * DINA_Signup – Self-Signup-Formular für Restaurants
 *
 * Ermöglicht Restaurants, sich selbst zu registrieren.
 * Nutzt Cloudflare Turnstile als Captcha, legt Kunden in wp_dinia_customers
 * an (status='pending') und sendet eine Bestätigungs-Email.
 * Nach Bestätigung wird der Kunde aktiviert und zu Mollie weitergeleitet.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Signup
 */
class DINA_Signup {

	/**
	 * WordPress-Datenbank-Objekt.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Tabellenname der Kunden (mit Prefix).
	 *
	 * @var string
	 */
	private $customers_table;

	/**
	 * Plan-Verwaltung.
	 *
	 * @var DINA_Plans
	 */
	private $plans;

	/**
	 * Kunden-CRUD.
	 *
	 * @var DINA_Customers
	 */
	private $customers;

	/**
	 * Konstruktor.
	 *
	 * Hängt Shortcode, Scripts, Request-Routing und REST-Route ein.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb            = $wpdb;
		$this->customers_table = $wpdb->prefix . 'dinia_customers';
		$this->plans           = new DINA_Plans();
		$this->customers       = new DINA_Customers();

		add_shortcode( 'dinia_register', array( $this, 'render_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp', array( $this, 'route_request' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Turnstile JS nur auf Seiten mit [dinia_register] einbinden.
	 */
	public function enqueue_scripts() {
		global $post;

		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'dinia_register' ) ) {
			$site_key = get_option( 'dinia_turnstile_site_key', '' );
			if ( ! empty( $site_key ) ) {
				wp_enqueue_script(
					'cf-turnstile',
					'https://challenges.cloudflare.com/turnstile/v0/api.js',
					array(),
					null,
					true
				);
			}
		}
	}

	/**
	 * Routet eingehende Anfragen: POST-Submission und GET-Confirmation.
	 */
	public function route_request() {
		// POST: Formular-Absendung
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] )
			&& isset( $_POST['dinia_register_submit'] )
		) {
			$this->handle_submission();
			return;
		}

		// GET: Zahlungs-Seite (nach Formular-Submit)
		if ( isset( $_GET['dinia_pay'] ) && '1' === $_GET['dinia_pay'] && isset( $_GET['id'] ) && isset( $_GET['token'] ) ) {
			$customer_id = (int) $_GET['id'];
			$token       = sanitize_text_field( wp_unslash( $_GET['token'] ) );
			$this->handle_payment_action( $customer_id, $token );
			return;
		}

		// GET: Bestätigungs-Link (von Mollie-Redirect)
		if ( isset( $_GET['dinia_confirmed'] ) && '1' === $_GET['dinia_confirmed'] ) {
			$this->handle_confirmation();
			return;
		}
	}

	/**
	 * Shortcode-Callback: Gibt das Registrierungs-Formular oder Erfolgsmeldungen zurück.
	 *
	 * @param array  $atts Shortcode-Attribute (aktuell ungenutzt).
	 * @param string $content Enclosed content (optional).
	 * @return string HTML des Formulars oder der Meldung.
	 */
	public function render_form( $atts = array(), $content = '' ) {
		// Erfolgsseite nach Zahlung
		if ( isset( $_GET['dinia_confirmed'] ) && '1' === $_GET['dinia_confirmed'] ) {
			return $this->render_confirmed();
		}
		if ( isset( $_GET['dinia_success'] ) && '1' === $_GET['dinia_success'] ) {
			return $this->render_confirmed();
		}

		// Fehlermeldung aus Session/GET
		$error_message = '';
		if ( isset( $_GET['dinia_error'] ) ) {
			$error_message = sanitize_text_field( wp_unslash( $_GET['dinia_error'] ) );
		}

		// Vorausgefüllte Werte (nach Fehler)
		$restaurant_name = isset( $_GET['dn_company'] ) ? sanitize_text_field( wp_unslash( $_GET['dn_company'] ) ) : '';
		$email           = isset( $_GET['dn_email'] ) ? sanitize_email( wp_unslash( $_GET['dn_email'] ) ) : '';
		$phone           = isset( $_GET['dn_phone'] ) ? sanitize_text_field( wp_unslash( $_GET['dn_phone'] ) ) : '';

		$site_key = get_option( 'dinia_turnstile_site_key', '' );

		// Aktuelle URL als Formular-Action
		$form_action = esc_url( remove_query_arg( array( 'dinia_success', 'dinia_confirmed', 'dinia_error', 'dn_company', 'dn_email', 'dn_phone' ) ) );

		ob_start();
		?>
		<div class="dinia-signup-wrapper">
			<?php if ( ! empty( $error_message ) ) : ?>
				<div class="dinia-signup-error"><?php echo esc_html( $error_message ); ?></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $form_action ); ?>" class="dinia-signup-form">
				<h2><?php esc_html_e( 'Restaurant registrieren', 'dinia' ); ?></h2>
				<p class="dinia-signup-intro">
					<?php esc_html_e( 'Erstellen Sie Ihren Account und starten Sie mit dem Online-Reservierungssystem.', 'dinia' ); ?>
				</p>

				<div class="dinia-field">
					<label for="dinia_company"><?php esc_html_e( 'Restaurant-Name *', 'dinia' ); ?></label>
					<input type="text" id="dinia_company" name="dinia_company"
						value="<?php echo esc_attr( $restaurant_name ); ?>"
						required placeholder="<?php esc_attr_e( 'z. B. Meister Málzers Gasthaus', 'dinia' ); ?>">
				</div>

				<div class="dinia-field">
					<label for="dinia_email"><?php esc_html_e( 'E-Mail-Adresse *', 'dinia' ); ?></label>
					<input type="email" id="dinia_email" name="dinia_email"
						value="<?php echo esc_attr( $email ); ?>"
						required placeholder="<?php esc_attr_e( 'ihre@email.de', 'dinia' ); ?>">
				</div>

				<div class="dinia-field">
					<label for="dinia_phone"><?php esc_html_e( 'Telefon (optional)', 'dinia' ); ?></label>
					<input type="tel" id="dinia_phone" name="dinia_phone"
						value="<?php echo esc_attr( $phone ); ?>"
						placeholder="<?php esc_attr_e( 'z. B. +49 30 12345678', 'dinia' ); ?>">
				</div>

				<div class="dinia-field">
					<label for="dinia_coupon"><?php esc_html_e( 'Rabattcode (optional)', 'dinia' ); ?></label>
					<input type="text" id="dinia_coupon" name="dinia_coupon"
						value="<?php echo isset( $_GET['dn_coupon'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['dn_coupon'] ) ) ) : ''; ?>"
						placeholder="<?php esc_attr_e( 'z. B. SOMMER2026', 'dinia' ); ?>">
				</div>

				<div class="dinia-field">
					<label for="dinia_password"><?php esc_html_e( 'Passwort *', 'dinia' ); ?></label>
					<input type="password" id="dinia_password" name="dinia_password"
						required minlength="8"
						placeholder="<?php esc_attr_e( 'Mindestens 8 Zeichen', 'dinia' ); ?>">
				</div>

				<?php if ( ! empty( $site_key ) ) : ?>
					<div class="dinia-field dinia-turnstile-wrap">
						<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $site_key ); ?>" data-theme="light"></div>
					</div>
				<?php endif; ?>

				<?php wp_nonce_field( 'dinia_register_action', 'dinia_register_nonce' ); ?>

				<div class="dinia-field dinia-submit-wrap">
					<button type="submit" name="dinia_register_submit" class="dinia-btn dinia-btn-primary">
						<?php esc_html_e( 'Jetzt registrieren – 19,95 € / Monat', 'dinia' ); ?>
					</button>
				</div>

				<p class="dinia-signup-footer">
					<?php esc_html_e( 'Mit der Registrierung stimmen Sie unseren AGB und Datenschutzbestimmungen zu.', 'dinia' ); ?>
				</p>
			</form>
		</div>

		<style>
			.dinia-signup-wrapper {
				max-width: 520px;
				margin: 2rem auto;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
				line-height: 1.6;
				color: #333;
			}
			.dinia-signup-form {
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 8px;
				padding: 2rem;
				box-shadow: 0 2px 8px rgba(0,0,0,0.06);
			}
			.dinia-signup-form h2 {
				margin: 0 0 0.5rem;
				font-size: 1.5rem;
				color: #1a1a1a;
			}
			.dinia-signup-intro {
				margin: 0 0 1.5rem;
				color: #666;
				font-size: 0.95rem;
			}
			.dinia-field {
				margin-bottom: 1.25rem;
			}
			.dinia-field label {
				display: block;
				margin-bottom: 0.35rem;
				font-weight: 600;
				font-size: 0.9rem;
				color: #444;
			}
			.dinia-field input[type="text"],
			.dinia-field input[type="email"],
			.dinia-field input[type="tel"],
			.dinia-field input[type="password"] {
				width: 100%;
				padding: 0.65rem 0.75rem;
				font-size: 1rem;
				border: 1px solid #ccc;
				border-radius: 6px;
				background: #fafafa;
				transition: border-color 0.2s, box-shadow 0.2s;
				box-sizing: border-box;
			}
			.dinia-field input:focus {
				border-color: #4a90d9;
				box-shadow: 0 0 0 3px rgba(74,144,217,0.15);
				outline: none;
				background: #fff;
			}
			.dinia-turnstile-wrap {
				min-height: 70px;
				display: flex;
				justify-content: center;
			}
			.dinia-submit-wrap {
				margin-top: 0.5rem;
			}
			.dinia-btn {
				display: inline-block;
				padding: 0.75rem 2rem;
				font-size: 1rem;
				font-weight: 600;
				border: none;
				border-radius: 6px;
				cursor: pointer;
				text-align: center;
				transition: background 0.2s;
			}
			.dinia-btn-primary {
				background: #4a90d9;
				color: #fff;
			}
			.dinia-btn-primary:hover {
				background: #357abd;
			}
			.dinia-btn-primary:disabled {
				opacity: 0.6;
				cursor: not-allowed;
			}
			.dinia-signup-footer {
				margin: 1rem 0 0;
				font-size: 0.8rem;
				color: #888;
				text-align: center;
			}
			.dinia-signup-error {
				background: #fce4e4;
				border: 1px solid #f5c6cb;
				border-radius: 6px;
				padding: 0.75rem 1rem;
				margin-bottom: 1rem;
				color: #721c24;
				font-size: 0.95rem;
			}
			.dinia-signup-success,
			.dinia-signup-confirmed {
				background: #d4edda;
				border: 1px solid #c3e6cb;
				border-radius: 8px;
				padding: 2rem;
				text-align: center;
				color: #155724;
				max-width: 520px;
				margin: 2rem auto;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			}
			.dinia-signup-success h3,
			.dinia-signup-confirmed h3 {
				margin-top: 0;
				font-size: 1.3rem;
			}
			.dinia-signup-success p,
			.dinia-signup-confirmed p {
				margin: 0.5rem 0;
				font-size: 0.95rem;
			}
			@media (max-width: 600px) {
				.dinia-signup-form {
					padding: 1.25rem;
				}
			}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Verarbeitet das POST-Formular.
	 *
	 * Validiert Eingaben, prüft Turnstile-Captcha, legt Kunden an,
	 * generiert API-Key und sendet Bestätigungs-Email.
	 */
	public function handle_submission() {
		// Nonce-Prüfung
		if ( ! isset( $_POST['dinia_register_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dinia_register_nonce'] ) ), 'dinia_register_action' )
		) {
			$redirect = add_query_arg(
				array(
					'dinia_error' => rawurlencode( __( 'Sicherheitsprüfung fehlgeschlagen. Bitte versuchen Sie es erneut.', 'dinia' ) ),
				),
				remove_query_arg( array( 'dinia_success', 'dinia_confirmed', 'dinia_confirm' ) )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Eingaben holen und bereinigen
		$company = isset( $_POST['dinia_company'] ) ? sanitize_text_field( wp_unslash( $_POST['dinia_company'] ) ) : '';
		$email   = isset( $_POST['dinia_email'] ) ? sanitize_email( wp_unslash( $_POST['dinia_email'] ) ) : '';
		$phone   = isset( $_POST['dinia_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['dinia_phone'] ) ) : '';
		$password = isset( $_POST['dinia_password'] ) ? $_POST['dinia_password'] : ''; // raw for validation

		// Validierung
		$errors = array();

		if ( empty( $company ) ) {
			$errors[] = __( 'Bitte geben Sie einen Restaurant-Namen ein.', 'dinia' );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'dinia' );
		}

		if ( empty( $password ) || strlen( $password ) < 8 ) {
			$errors[] = __( 'Das Passwort muss mindestens 8 Zeichen lang sein.', 'dinia' );
		}

		// Prüfen, ob E-Mail bereits existiert
		if ( ! empty( $email ) ) {
			$existing_email = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->customers_table} WHERE email = %s LIMIT 1",
					$email
				)
			);
			if ( $existing_email ) {
				$errors[] = __( 'Diese E-Mail-Adresse ist bereits registriert.', 'dinia' );
			}

			// Auch prüfen, ob WP-User mit dieser Email existiert
			if ( email_exists( $email ) ) {
				$errors[] = __( 'Zu dieser E-Mail-Adresse existiert bereits ein Benutzerkonto.', 'dinia' );
			}
		}

		// Turnstile-Captcha prüfen
		$secret_key = get_option( 'dinia_turnstile_secret_key', '' );
		if ( ! empty( $secret_key ) ) {
			$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
			if ( empty( $token ) ) {
				$errors[] = __( 'Bitte bestätigen Sie das Captcha.', 'dinia' );
			} else {
				$verify = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
					'body' => array(
						'secret'   => $secret_key,
						'response' => $token,
						'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					),
					'timeout' => 10,
				) );

				if ( is_wp_error( $verify ) ) {
					$errors[] = __( 'Captcha-Verifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.', 'dinia' );
				} else {
					$result = json_decode( wp_remote_retrieve_body( $verify ), true );
					if ( empty( $result['success'] ) ) {
						$error_codes = isset( $result['error-codes'] ) ? implode( ', ', $result['error-codes'] ) : '';
						$errors[]    = sprintf(
							/* translators: %s: Fehlercode vom Captcha-Dienst */
							__( 'Captcha-Prüfung fehlgeschlagen (%s).', 'dinia' ),
							$error_codes
						);
					}
				}
			}
		}

		// Bei Fehlern zurück zum Formular
		if ( ! empty( $errors ) ) {
			$error_msg = implode( ' ', $errors );
			$redirect  = add_query_arg(
				array(
					'dinia_error' => rawurlencode( $error_msg ),
					'dn_company'  => rawurlencode( $company ),
					'dn_email'    => rawurlencode( $email ),
					'dn_phone'    => rawurlencode( $phone ),
				),
				remove_query_arg( array( 'dinia_success', 'dinia_confirmed', 'dinia_confirm' ) )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Aktiven Plan finden (ersten aktiven)
		$plan = $this->get_first_active_plan();
		$plan_id   = $plan ? (int) $plan->id : null;
		$plan_name = $plan ? $plan->name : 'Dinia Basic';

		// Coupon auswerten und speichern
		$coupon_code = isset( $_POST['dinia_coupon'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['dinia_coupon'] ) ) ) : '';
		$coupon_data = null;
		if ( ! empty( $coupon_code ) && class_exists( 'DINA_Coupon' ) ) {
			$coupon_check = ( new DINA_Coupon() )->validate_code( $coupon_code );
			if ( $coupon_check['success'] ) {
				$coupon_data = $coupon_check['data'];
			} else {
				$coupon_code = ''; // Ungültigen Code weg
			}
		}

		// Confirm-Token generieren (32 Byte = 64 Hex-Zeichen)
		$confirm_token = bin2hex( random_bytes( 32 ) );

		// Kunde anlegen — wir nutzen wpdb->insert direkt,
		// da DINA_Customers::create() Felder nutzt (phone, restaurant_name, plan_name, updated_at),
		// die in der tatsächlichen Tabellen-Struktur nicht existieren.
		$customer_data = array(
			'company'       => $company,
			'slug'          => sanitize_title( $company ),
			'email'         => $email,
			'contact_name'  => $company,
			'contact_phone' => $phone,
			'plan_id'       => $plan_id,
			'status'        => 'pending',
			'confirm_token' => $confirm_token,
			'coupon_code'   => $coupon_code,
			'created_at'    => current_time( 'mysql' ),
		);

		// Slug unique machen
		$customer_data['slug'] = $this->make_unique_customer_slug( $customer_data['slug'] );

		$inserted = $this->wpdb->insert(
			$this->customers_table,
			$customer_data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$redirect = add_query_arg(
				array(
					'dinia_error' => rawurlencode( __( 'Registrierung fehlgeschlagen. Bitte versuchen Sie es später erneut.', 'dinia' ) ),
					'dn_company'  => rawurlencode( $company ),
					'dn_email'    => rawurlencode( $email ),
					'dn_phone'    => rawurlencode( $phone ),
				),
				remove_query_arg( array( 'dinia_error', 'dn_company', 'dn_email', 'dn_phone', 'dinia_confirm' ) )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$customer_id = $this->wpdb->insert_id;

		// WordPress-Benutzer anlegen
		$username = sanitize_user( str_replace( ' ', '', $company ), true );
		if ( empty( $username ) ) {
			$username = 'restaurant-' . $customer_id;
		}
		// Eindeutigen Username sicherstellen
		if ( username_exists( $username ) ) {
			$username = $username . '-' . $customer_id;
		}

		$wp_user_id = wp_insert_user( array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'display_name' => $company,
			'role'       => 'subscriber',
		) );

		if ( is_wp_error( $wp_user_id ) ) {
			// WP-User konnte nicht angelegt werden – Customer existiert trotzdem
			// Wir loggen den Fehler und machen weiter (Email wird trotzdem gesendet).
			error_log( 'DINA Signup: WP user creation failed for ' . $email . ': ' . $wp_user_id->get_error_message() );
		}

		// API-Key generieren
		$api_key_data = $this->customers->generate_api_key( $customer_id );

		// Willkommens-Email mit Login-Info senden
		if ( ! empty( $wp_user_id ) && ! is_wp_error( $wp_user_id ) ) {
			$this->send_welcome_email( $email, $company, $username );
		}

		// Weiterleitung zur Zahlungs-Seite
		$pay_url = add_query_arg(
			array(
				'dinia_pay' => '1',
				'id'        => $customer_id,
				'token'     => $confirm_token,
			),
			trailingslashit( home_url() )
		);
		wp_safe_redirect( esc_url_raw( $pay_url ) );
		exit;
	}

	/**
	 * Verarbeitet den Bestätigungs-Link (von Mollie-Redirect nach Zahlung).
	 *
	 * Zeigt die Erfolgsseite. Die Aktivierung erfolgt async via Webhook.
	 */
	public function handle_confirmation() {
		// Einfach Erfolgsseite anzeigen – Webhook aktiviert den Kunden
		$this->render_confirmed_page();
		exit;
	}

	/**
	 * Zeigt die Zahlungs-Seite und verarbeitet den Klick auf "Jetzt bezahlen".
	 *
	 * @param int    $customer_id Kunden-ID.
	 * @param string $token       Confirm-Token zur Authentifizierung.
	 */
	public function handle_payment_action( $customer_id, $token ) {
		// Kunde anhand ID + Token prüfen
		$customer = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->customers_table} WHERE id = %d AND confirm_token = %s AND status = 'pending' LIMIT 1",
				$customer_id,
				$token
			)
		);

		if ( ! $customer ) {
			wp_die( 'Ungültiger Link oder bereits bezahlt.', 'Fehler', array( 'response' => 404 ) );
		}

		$plan       = $this->plans->get_by_id( (int) $customer->plan_id );
		$base_price = ( $plan && isset( $plan->price_monthly ) ) ? (float) $plan->price_monthly : 19.95;

		// Coupon-Rabatt berechnen
		$price       = $base_price;
		$coupon_info = '';
		if ( ! empty( $customer->coupon_code ) && class_exists( 'DINA_Coupon' ) ) {
			$coupon      = new DINA_Coupon();
			$discount    = $coupon->apply_discount( $customer->coupon_code, $base_price );
			if ( $discount['success'] ) {
				$price       = $discount['data'];
				$coupon_info = sprintf( ' (Rabattcode: %s)', $customer->coupon_code );
			}
		}

		// POST: Jetzt bezahlen Button wurde geklickt
		if ( isset( $_POST['dinia_do_payment'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'dinia_pay_nonce' ) ) {
			// Mollie-Kunde anlegen
			$mollie_customer_id = '';
			if ( class_exists( 'DINA_Mollie' ) ) {
				$mollie  = new DINA_Mollie();
				$api_key = $mollie->get_api_key();
				if ( ! empty( $api_key ) ) {
					$mollie_result = $mollie->create_customer( $customer->company, $customer->email );
					if ( $mollie_result['success'] ) {
						$mollie_customer_id = $mollie_result['customer_id'];
						$this->wpdb->update(
							$this->customers_table,
							array( 'mollie_customer_id' => $mollie_customer_id ),
							array( 'id' => $customer_id )
						);
					}
				}
			}

			if ( ! empty( $mollie_customer_id ) && $price > 0 ) {
				// First Payment bei Mollie erstellen
				$payment = $mollie->create_first_payment(
					$mollie_customer_id,
					$price,
					sprintf( '%s – %s', $plan ? $plan->name : 'Dinia Basic', $customer->company ),
					rest_url( 'dinia/v1/webhook/mollie' ),
					add_query_arg( array( 'dinia_confirmed' => '1' ), trailingslashit( home_url() ) )
				);

				if ( $payment['success'] && ! empty( $payment['checkout_url'] ) ) {
					// Coupon Usage increment
					if ( ! empty( $customer->coupon_code ) && class_exists( 'DINA_Coupon' ) ) {
						( new DINA_Coupon() )->increment_usage( $customer->coupon_code );
					}

					// Rechnung anlegen
					if ( class_exists( 'DINA_Invoices' ) ) {
						$invoices = new DINA_Invoices();
						$invoices->create( array(
							'customer_id'       => (int) $customer->id,
							'mollie_payment_id' => $payment['payment_id'],
							'amount'            => $price,
							'currency'          => 'EUR',
							'status'            => 'pending',
							'description'       => sprintf( '%s – %s', $plan ? $plan->name : 'Dinia Basic', $customer->company ),
						) );
					}

					wp_redirect( esc_url_raw( $payment['checkout_url'] ) );
					exit;
				}
			}

			// Fallback: Trotzdem Erfolgsseite zeigen
			$this->render_confirmed_page();
			exit;
		}

		// Anzeige: Zahlungs-Seite mit Button
		$login_url = wp_login_url( admin_url( 'admin.php?page=dinia-my-account' ) );
		$price_fmt = number_format( $price, 2, ',', '.' );

		?>
		<div style="max-width:520px;margin:2rem auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:2rem;box-shadow:0 2px 8px rgba(0,0,0,0.06);text-align:center;">
				<h2 style="margin-top:0;">Fast geschafft! 🎉</h2>
				<p style="color:#666;">Bitte schließen Sie die Zahlung ab, um Ihren Account zu aktivieren.</p>

				<table style="width:100%;border-collapse:collapse;margin:1.5rem auto;max-width:320px;text-align:left;">
					<tr><td style="padding:8px;font-weight:600;">Restaurant</td><td><?php echo esc_html( $customer->company ); ?></td></tr>
					<tr><td style="padding:8px;font-weight:600;">Plan</td><td><?php echo esc_html( $plan ? $plan->name : 'Dinia Basic' ); ?></td></tr>
					<tr><td style="padding:8px;font-weight:600;">Betrag</td><td><strong style="font-size:1.3rem;color:#1a1a1a;"><?php echo esc_html( $price_fmt ); ?> €</strong><?php echo esc_html( $coupon_info ); ?> / Monat</td></tr>
				</table>

				<form method="post">
					<?php wp_nonce_field( 'dinia_pay_nonce' ); ?>
					<button type="submit" name="dinia_do_payment" style="background:#4a90d9;color:#fff;border:none;padding:14px 40px;border-radius:6px;font-size:1.1rem;font-weight:600;cursor:pointer;">
						Jetzt bezahlen – <?php echo esc_html( $price_fmt ); ?> €
					</button>
				</form>

				<p style="color:#999;font-size:0.85rem;margin-top:1rem;">Nach der Zahlung erhalten Sie eine Bestätigungs-E-Mail mit Ihren Login-Daten.</p>
			</div>
		</div>
		<?php
		exit;
	}

	/**
	 * Zeigt die Erfolgsseite nach erfolgreicher Zahlung.
	 */
	public function render_confirmed_page() {
		$login_url = wp_login_url( admin_url( 'admin.php?page=dinia-my-account' ) );
		?>
		<div style="max-width:520px;margin:2rem auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:2rem;text-align:center;color:#155724;">
				<h2 style="margin-top:0;">🎉 Zahlung erfolgreich!</h2>
				<p>Ihr Restaurant-Account wurde eingerichtet.</p>
				<p><strong>So geht's weiter:</strong></p>
				<p style="margin:1rem 0;">
					<a href="<?php echo esc_url( $login_url ); ?>" style="display:inline-block;background:#28a745;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:600;">
						🔑 Jetzt einloggen & loslegen
					</a>
				</p>
				<p style="font-size:0.9rem;">Login-Bereich: <strong><?php echo esc_html( $login_url ); ?></strong></p>
				<p style="font-size:0.85rem;color:#666;">Sie haben zusätzlich eine E-Mail mit Ihren Login-Daten erhalten.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * REST-Endpunkt: POST /dinia/v1/register (AJAX-Version).
	 *
	 * @param WP_REST_Request $request Die REST-Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_register( $request ) {
		$company  = sanitize_text_field( $request->get_param( 'company' ) );
		$email    = sanitize_email( $request->get_param( 'email' ) );
		$phone    = sanitize_text_field( $request->get_param( 'phone' ) );
		$password = $request->get_param( 'password' );
		$token    = sanitize_text_field( $request->get_param( 'cf_token' ) );

		// Validierung
		$errors = array();

		if ( empty( $company ) ) {
			$errors[] = __( 'Bitte geben Sie einen Restaurant-Namen ein.', 'dinia' );
		}

		if ( empty( $email ) || ! is_email( $email ) ) {
			$errors[] = __( 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'dinia' );
		}

		if ( empty( $password ) || strlen( $password ) < 8 ) {
			$errors[] = __( 'Das Passwort muss mindestens 8 Zeichen lang sein.', 'dinia' );
		}

		if ( ! empty( $email ) ) {
			$existing = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->customers_table} WHERE email = %s LIMIT 1",
					$email
				)
			);
			if ( $existing ) {
				$errors[] = __( 'Diese E-Mail-Adresse ist bereits registriert.', 'dinia' );
			}
			if ( email_exists( $email ) ) {
				$errors[] = __( 'Zu dieser E-Mail-Adresse existiert bereits ein Benutzerkonto.', 'dinia' );
			}
		}

		// Turnstile
		$secret_key = get_option( 'dinia_turnstile_secret_key', '' );
		if ( ! empty( $secret_key ) && ! empty( $token ) ) {
			$verify = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
				'body' => array(
					'secret'   => $secret_key,
					'response' => $token,
					'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				),
				'timeout' => 10,
			) );

			if ( ! is_wp_error( $verify ) ) {
				$result = json_decode( wp_remote_retrieve_body( $verify ), true );
				if ( empty( $result['success'] ) ) {
					$errors[] = __( 'Captcha-Prüfung fehlgeschlagen.', 'dinia' );
				}
			} else {
				$errors[] = __( 'Captcha-Verifizierung fehlgeschlagen.', 'dinia' );
			}
		} elseif ( ! empty( $secret_key ) && empty( $token ) ) {
			$errors[] = __( 'Captcha-Token fehlt.', 'dinia' );
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'validation_error',
				implode( ' ', $errors ),
				array( 'status' => 400 )
			);
		}

		// Plan finden
		$plan      = $this->get_first_active_plan();
		$plan_id   = $plan ? (int) $plan->id : null;
		$plan_name = $plan ? $plan->name : 'Dinia Basic';

		// Confirm-Token
		$confirm_token = bin2hex( random_bytes( 32 ) );

		// Kunde anlegen
		$customer_data = array(
			'company'       => $company,
			'slug'          => sanitize_title( $company ),
			'email'         => $email,
			'contact_name'  => $company,
			'contact_phone' => $phone,
			'plan_id'       => $plan_id,
			'status'        => 'pending',
			'confirm_token' => $confirm_token,
			'created_at'    => current_time( 'mysql' ),
		);

		$customer_data['slug'] = $this->make_unique_customer_slug( $customer_data['slug'] );

		$inserted = $this->wpdb->insert(
			$this->customers_table,
			$customer_data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'creation_failed',
				__( 'Registrierung fehlgeschlagen. Bitte versuchen Sie es später erneut.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		$customer_id = $this->wpdb->insert_id;

		// WP-User anlegen
		$username = sanitize_user( str_replace( ' ', '', $company ), true );
		if ( empty( $username ) ) {
			$username = 'restaurant-' . $customer_id;
		}
		if ( username_exists( $username ) ) {
			$username = $username . '-' . $customer_id;
		}

		$wp_user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $company,
			'role'         => 'subscriber',
		) );

		if ( is_wp_error( $wp_user_id ) ) {
			error_log( 'DINA Signup (REST): WP user creation failed for ' . $email . ': ' . $wp_user_id->get_error_message() );
		}

		// API-Key generieren
		$this->customers->generate_api_key( $customer_id );

		// Bestätigungs-Email
		$confirm_url = add_query_arg(
			array( 'dinia_confirm' => $confirm_token ),
			trailingslashit( home_url() )
		);
		$this->send_confirmation_email( $email, $company, $confirm_url );

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Registrierung erfolgreich! Bitte bestätigen Sie Ihre E-Mail-Adresse.', 'dinia' ),
		) );
	}

	/**
	 * Registriert die REST-Route POST /dinia/v1/register.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'dinia/v1',
			'/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'company'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'phone'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'cf_token' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Sendet die Bestätigungs-E-Mail an den neuen Kunden.
	 *
	 * @param string $email       Empfänger-E-Mail.
	 * @param string $company     Restaurant-Name.
	 * @param string $confirm_url Bestätigungs-URL.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	private function send_confirmation_email( $email, $company, $confirm_url ) {
		$subject = sprintf(
			/* translators: %s: Restaurant-Name */
			__( 'Dinia – Bestätigen Sie Ihre Registrierung für %s', 'dinia' ),
			$company
		);

		$message_html  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:20px;">';
		$message_html .= '<h2 style="color:#ff6b00;">' . esc_html__( 'Bestätigen Sie Ihre Registrierung', 'dinia' ) . '</h2>';
		$message_html .= '<p>' . sprintf(
			/* translators: %s: Restaurant-Name */
			esc_html__( 'Hallo %s,', 'dinia' ),
			esc_html( $company )
		) . '</p>';
		$message_html .= '<p>' . esc_html__( 'vielen Dank für Ihre Registrierung bei Dinia!', 'dinia' ) . '</p>';
		$message_html .= '<p>' . esc_html__( 'Bitte klicken Sie auf den folgenden Button, um Ihre E-Mail-Adresse zu bestätigen und den Registrierungsprozess abzuschließen:', 'dinia' ) . '</p>';
		$message_html .= '<p style="text-align:center;">';
		$message_html .= '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;padding:12px 28px;background:#ff6b00;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;">'
			. esc_html__( 'E-Mail bestätigen', 'dinia' ) . '</a></p>';
		$message_html .= '<p>' . esc_html__( 'Falls der Button nicht funktioniert, kopieren Sie folgenden Link in Ihren Browser:', 'dinia' ) . '<br>';
		$message_html .= '<a href="' . esc_url( $confirm_url ) . '">' . esc_url( $confirm_url ) . '</a></p>';
		$message_html .= '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">';
		$message_html .= '<p style="color:#888;font-size:12px;">' . esc_html__( 'Wenn Sie keine Registrierung bei Dinia beantragt haben, ignorieren Sie bitte diese E-Mail.', 'dinia' ) . '</p>';
		$message_html .= '</body></html>';

		// Versuche Brevo (DINA_Mailer), fallback auf wp_mail()
		if ( class_exists( 'DINA_Mailer' ) ) {
			$result = DINA_Mailer::send( $email, $subject, '', $message_html );
			if ( true === $result ) {
				return true;
			}
			// Log Brevo-Fehler
			error_log( 'DINA Signup: DINA_Mailer failed for ' . $email . ': ' . ( is_string( $result ) ? $result : 'unknown' ) );
		}

		// Fallback: wp_mail()
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_bloginfo( 'admin_email' ) . '>',
		);
		return wp_mail( $email, $subject, $message_html, $headers );
	}

	/**
	 * Gibt den ersten aktiven Plan zurück.
	 *
	 * @return object|null Plan-Objekt oder null.
	 */
	private function get_first_active_plan() {
		$plans = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}dinia_plans WHERE active = %d ORDER BY sort_order ASC, id ASC LIMIT 1",
				1
			)
		);

		if ( is_array( $plans ) && ! empty( $plans ) ) {
			return $plans[0];
		}

		return null;
	}

	/**
	 * Stellt sicher, dass ein Customer-Slug unique ist.
	 *
	 * @param string $slug Der gewünschte Slug.
	 * @return string Unique Slug.
	 */
	private function make_unique_customer_slug( $slug ) {
		$original_slug = $slug;
		$suffix        = 0;

		while ( true ) {
			$check_slug = ( $suffix > 0 ) ? $original_slug . '-' . $suffix : $original_slug;
			$exists     = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->customers_table} WHERE slug = %s",
					$check_slug
				)
			);
			if ( ! $exists ) {
				return $check_slug;
			}
			$suffix++;
		}
	}

	/**
	 * Sendet eine Willkommens-Email mit Login-Link nach erfolgreicher Zahlung.
	 *
	 * @since 1.1.7
	 *
	 * @param string $email    Empfänger-E-Mail.
	 * @param string $company  Firmenname.
	 * @param string $username WP-Username.
	 *
	 * @return bool
	 */
	public function send_welcome_email( $email, $company, $username ) {
		$login_url = wp_login_url( admin_url( 'admin.php?page=dinia-my-account' ) );
		$subject   = sprintf( __( 'Willkommen bei Dinia – %s', 'dinia' ), $company );

		$html  = '<h2>🎉 Willkommen bei Dinia!</h2>';
		$html .= '<p>Hallo <strong>' . esc_html( $company ) . '</strong>,</p>';
		$html .= '<p>Ihre Zahlung war erfolgreich! Ihr Restaurant-Account ist jetzt aktiv.</p>';
		$html .= '<p><strong>So geht\'s weiter:</strong></p>';
		$html .= '<p>👉 <a href="' . esc_url( $login_url ) . '" style="background:#28a745;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;display:inline-block;">Jetzt einloggen & loslegen</a></p>';
		$html .= '<p><strong>Login-Bereich:</strong><br>' . esc_html( $login_url ) . '</p>';
		$html .= '<p>Dort können Sie:<br>';
		$html .= '• Ihr Restaurant-Profil einrichten<br>';
		$html .= '• Tische und Öffnungszeiten verwalten<br>';
		$html .= '• Den Buchungs-Widget auf Ihrer Seite einbinden<br>';
		$html .= '• Reservierungen einsehen und verwalten</p>';
		$html .= '<p>Viel Erfolg mit Dinia!</p>';

		$html_body = DINA_Mailer::build_html( $subject, $html, $company );

		return DINA_Mailer::send(
			$email,
			$subject,
			'Willkommen bei Dinia – Ihr Account ist aktiv',
			$html_body
		);
	}
}
