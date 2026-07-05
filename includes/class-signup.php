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

		// GET: Bestätigungs-Link
		if ( isset( $_GET['dinia_confirm'] ) && ! empty( $_GET['dinia_confirm'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET['dinia_confirm'] ) );
			$this->handle_confirmation( $token );
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
		// Erfolgsmeldungen nach Weiterleitung anzeigen
		if ( isset( $_GET['dinia_success'] ) && '1' === $_GET['dinia_success'] ) {
			return $this->render_success();
		}
		if ( isset( $_GET['dinia_confirmed'] ) && '1' === $_GET['dinia_confirmed'] ) {
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
						<?php esc_html_e( 'Kostenlos registrieren', 'dinia' ); ?>
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
			'created_at'    => current_time( 'mysql' ),
		);

		// Slug unique machen
		$customer_data['slug'] = $this->make_unique_customer_slug( $customer_data['slug'] );

		$inserted = $this->wpdb->insert(
			$this->customers_table,
			$customer_data,
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$redirect = add_query_arg(
				array(
					'dinia_error' => rawurlencode( __( 'Registrierung fehlgeschlagen. Bitte versuchen Sie es später erneut.', 'dinia' ) ),
					'dn_company'  => rawurlencode( $company ),
					'dn_email'    => rawurlencode( $email ),
					'dn_phone'    => rawurlencode( $phone ),
				),
				remove_query_arg( array( 'dinia_success', 'dinia_confirmed', 'dinia_confirm' ) )
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

		// Bestätigungs-Email senden
		$confirm_url = add_query_arg(
			array( 'dinia_confirm' => $confirm_token ),
			trailingslashit( home_url() )
		);

		$email_sent = $this->send_confirmation_email( $email, $company, $confirm_url );

		if ( ! $email_sent ) {
			// Email-Fehler loggen
			error_log( 'DINA Signup: Confirmation email failed to send to ' . $email );
		}

		// Erfolg: Weiterleitung zur Success-Seite
		$redirect = add_query_arg(
			array( 'dinia_success' => '1' ),
			remove_query_arg( array( 'dinia_error', 'dn_company', 'dn_email', 'dn_phone', 'dinia_confirm' ) )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Verarbeitet den Bestätigungs-Link.
	 *
	 * Setzt Kunde auf 'active', erzeugt Mollie-Kunde und First Payment,
	 * leitet zur Mollie-Checkout-Seite weiter.
	 *
	 * @param string $token Der Confirm-Token aus der URL.
	 */
	public function handle_confirmation( $token ) {
		// Kunde anhand des Tokens suchen
		$customer = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->customers_table} WHERE confirm_token = %s AND status = 'pending' LIMIT 1",
				$token
			)
		);

		if ( ! $customer ) {
			// Token ungültig oder bereits bestätigt
			wp_die(
				esc_html__( 'Dieser Bestätigungs-Link ist ungültig oder wurde bereits verwendet.', 'dinia' ),
				esc_html__( 'Ungültiger Link', 'dinia' ),
				array( 'response' => 404 )
			);
		}

		// Plan-Info holen
		$plan = $this->plans->get_by_id( (int) $customer->plan_id );
		$price = 0.00;
		if ( $plan && isset( $plan->price_monthly ) ) {
			$price = (float) $plan->price_monthly;
		}

		// Mollie-Kunden-ID besorgen oder neuen Mollie-Kunden anlegen
		$mollie_customer_id = '';
		if ( class_exists( 'DINA_Mollie' ) ) {
			$mollie   = new DINA_Mollie();
			$api_key  = $mollie->get_api_key();
			if ( ! empty( $api_key ) ) {
				$mollie_customer_result = $mollie->create_customer( $customer->company, $customer->email );
				if ( $mollie_customer_result['success'] ) {
					$mollie_customer_id = $mollie_customer_result['customer_id'];
				} else {
					error_log( 'DINA Signup: Mollie customer creation failed: ' . ( $mollie_customer_result['error'] ?? 'unknown' ) );
				}
			}
		}

		// Kunde aktivieren: status='active', confirm_token leeren, Mollie-Customer-ID speichern
		$update_data = array(
			'status'        => 'active',
			'confirm_token' => '',
		);
		$update_types = array( '%s', '%s' );

		if ( ! empty( $mollie_customer_id ) ) {
			$update_data['mollie_customer_id'] = $mollie_customer_id;
			$update_types[] = '%s';
		}

		$this->wpdb->update(
			$this->customers_table,
			$update_data,
			array( 'id' => (int) $customer->id ),
			$update_types,
			array( '%d' )
		);

		// Weiterleitung zu Mollie-Zahlung
		if ( ! empty( $mollie_customer_id ) && $price > 0 && class_exists( 'DINA_Mollie' ) ) {
			$mollie        = new DINA_Mollie();
			$webhook_url   = rest_url( 'dinia/v1/webhook/mollie' );
			$redirect_url  = add_query_arg(
				array( 'dinia_confirmed' => '1' ),
				trailingslashit( home_url() )
			);
			$plan_label    = $plan ? $plan->name : 'Dinia Basic';
			$description   = sprintf(
				/* translators: %1$s: Plan-Name, %2$s: Firmenname */
				__( '%1$s – %2$s', 'dinia' ),
				$plan_label,
				$customer->company
			);

			$payment_result = $mollie->create_first_payment(
				$mollie_customer_id,
				$price,
				$description,
				$webhook_url,
				$redirect_url
			);

			if ( $payment_result['success'] && ! empty( $payment_result['checkout_url'] ) ) {
				// Rechnung anlegen
				if ( class_exists( 'DINA_Invoices' ) ) {
					$invoices = new DINA_Invoices();
					$invoices->create( array(
						'customer_id'      => (int) $customer->id,
						'mollie_payment_id' => $payment_result['payment_id'],
						'amount'           => $price,
						'currency'         => 'EUR',
						'status'           => 'pending',
						'description'      => $description,
					) );
				}

				wp_redirect( esc_url_raw( $payment_result['checkout_url'] ) );
				exit;
			} else {
				// Mollie-Zahlung fehlgeschlagen – trotzdem zur Erfolgsseite
				error_log( 'DINA Signup: First payment creation failed: ' . ( $payment_result['error'] ?? 'unknown' ) );
			}
		}

		// Fallback: Wenn kein Preis oder Mollie nicht verfügbar, direkt zur Bestätigungsseite
		$redirect = add_query_arg(
			array( 'dinia_confirmed' => '1' ),
			trailingslashit( home_url() )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Bestätigungsnachricht nach erfolgreicher Registrierung.
	 *
	 * @return string HTML.
	 */
	public function render_success() {
		ob_start();
		?>
		<div class="dinia-signup-success">
			<h3><?php esc_html_e( 'Fast geschafft! 📧', 'dinia' ); ?></h3>
			<p><?php esc_html_e( 'Vielen Dank für Ihre Registrierung!', 'dinia' ); ?></p>
			<p><?php esc_html_e( 'Wir haben Ihnen eine Bestätigungs-E-Mail gesendet. Bitte klicken Sie auf den Link in der E-Mail, um Ihren Account zu aktivieren und die Einrichtung abzuschließen.', 'dinia' ); ?></p>
			<p><strong><?php esc_html_e( 'Keine E-Mail erhalten?', 'dinia' ); ?></strong><br>
			<?php esc_html_e( 'Bitte überprüfen Sie auch Ihren Spam-Ordner. Falls Sie keine E-Mail finden, kontaktieren Sie uns bitte.', 'dinia' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Erfolgsnachricht nach erfolgreicher Bestätigung.
	 *
	 * @return string HTML.
	 */
	public function render_confirmed() {
		ob_start();
		?>
		<div class="dinia-signup-confirmed">
			<h3><?php esc_html_e( 'Account bestätigt! ✅', 'dinia' ); ?></h3>
			<p><?php esc_html_e( 'Ihr Restaurant-Account wurde erfolgreich aktiviert.', 'dinia' ); ?></p>
			<p><?php esc_html_e( 'Sie werden nun zur Zahlungsseite weitergeleitet, um Ihren Plan einzurichten.', 'dinia' ); ?></p>
			<p><?php esc_html_e( 'Falls Sie nicht weitergeleitet wurden, können Sie sich in Ihrem Account anmelden und die Zahlung manuell durchführen.', 'dinia' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
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

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_bloginfo( 'admin_email' ) . '>',
		);

		$message  = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;padding:20px;">';
		$message .= '<h2 style="color:#4a90d9;">' . esc_html__( 'Bestätigen Sie Ihre Registrierung', 'dinia' ) . '</h2>';
		$message .= '<p>' . sprintf(
			/* translators: %s: Restaurant-Name */
			esc_html__( 'Hallo %s,', 'dinia' ),
			esc_html( $company )
		) . '</p>';
		$message .= '<p>' . esc_html__( 'vielen Dank für Ihre Registrierung bei Dinia!', 'dinia' ) . '</p>';
		$message .= '<p>' . esc_html__( 'Bitte klicken Sie auf den folgenden Button, um Ihre E-Mail-Adresse zu bestätigen und den Registrierungsprozess abzuschließen:', 'dinia' ) . '</p>';
		$message .= '<p style="text-align:center;">';
		$message .= '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;padding:12px 28px;background:#4a90d9;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px;">'
			. esc_html__( 'E-Mail bestätigen', 'dinia' ) . '</a></p>';
		$message .= '<p>' . esc_html__( 'Falls der Button nicht funktioniert, kopieren Sie folgenden Link in Ihren Browser:', 'dinia' ) . '<br>';
		$message .= '<a href="' . esc_url( $confirm_url ) . '">' . esc_url( $confirm_url ) . '</a></p>';
		$message .= '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">';
		$message .= '<p style="color:#888;font-size:12px;">' . esc_html__( 'Wenn Sie keine Registrierung bei Dinia beantragt haben, ignorieren Sie bitte diese E-Mail.', 'dinia' ) . '</p>';
		$message .= '</body></html>';

		return wp_mail( $email, $subject, $message, $headers );
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
}
