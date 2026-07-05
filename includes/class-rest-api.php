<?php
/**
 * DINA_REST_API – REST-Routen-Registrierung für GoBookMe SaaS
 *
 * Registriert alle REST-API-Endpunkte unter dem Namespace 'dinia/v1'.
 * Bietet Widget-, Client-, Admin-, Webhook- und Backup-Routen.
 * Für Slots/Reserve wird versucht, auf das GoBookMe-Plugin (GMR_Booking)
 * zuzugreifen; falls nicht verfügbar, werden Dummy-Responses zurückgegeben.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_REST_API
 *
 * @since 1.0.0
 */
class DINA_REST_API {

	/**
	 * REST-API-Namespace.
	 *
	 * @var string
	 */
	private $namespace = 'dinia/v1';

	/**
	 * Tenant-Identifikations-Instanz.
	 *
	 * @var DINA_Tenant
	 */
	private $tenant;

	/**
	 * Kunden-CRUD-Instanz.
	 *
	 * @var DINA_Customers
	 */
	private $customers;

	/**
	 * Plan-Verwaltungs-Instanz.
	 *
	 * @var DINA_Plans
	 */
	private $plans;

	/**
	 * Backup-Verwaltungs-Instanz.
	 *
	 * @var DINA_Backup
	 */
	private $backup;

	/**
	 * WordPress-Datenbank-Objekt.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Konstruktor.
	 *
	 * Initialisiert Hilfsklassen und hängt die Routen-Registrierung
	 * an den 'rest_api_init'-Hook.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb = $wpdb;

		// Hilfsklassen instanziieren.
		$this->tenant    = DINA_Tenant::instance();
		$this->customers = new DINA_Customers();
		$this->plans     = new DINA_Plans();
		$this->backup    = new DINA_Backup();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registriert alle REST-Routen.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		$this->register_widget_routes();
		$this->register_client_routes();
		$this->register_admin_routes();
		$this->register_webhook_routes();
		$this->register_backup_routes();
	}

	/**
	 * ---------------------------------------------------------------
	 *  WIDGET-ROUTEN (öffentlich, per Slug/Tenant-ID)
	 * ---------------------------------------------------------------
	 */

	/**
	 * Registriert die öffentlichen Widget-Endpunkte.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_widget_routes() {
		// GET /dinia/v1/widget/{slug}/config
		register_rest_route(
			$this->namespace,
			'/widget/(?P<slug>[a-zA-Z0-9_-]+)/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'widget_config' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		// GET /dinia/v1/widget/{slug}/slots
		register_rest_route(
			$this->namespace,
			'/widget/(?P<slug>[a-zA-Z0-9_-]+)/slots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'widget_slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug'       => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
					'date'       => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'party_size' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /dinia/v1/widget/{slug}/reserve
		register_rest_route(
			$this->namespace,
			'/widget/(?P<slug>[a-zA-Z0-9_-]+)/reserve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'widget_reserve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  CLIENT-ROUTEN (per API-Key)
	 * ---------------------------------------------------------------
	 */

	/**
	 * Registriert die Client-Endpunkte (API-Key-Auth).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_client_routes() {
		// GET /dinia/v1/client/slots
		register_rest_route(
			$this->namespace,
			'/client/slots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'client_slots' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'date'       => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'party_size' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// POST /dinia/v1/client/reserve
		register_rest_route(
			$this->namespace,
			'/client/reserve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'client_reserve' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  ADMIN-ROUTEN (current_user_can manage_options)
	 * ---------------------------------------------------------------
	 */

	/**
	 * Registriert die Admin-Endpunkte.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_admin_routes() {
		// GET/POST /dinia/v1/admin/customers
		register_rest_route(
			$this->namespace,
			'/admin/customers',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_get_customers' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'admin_create_customer' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);

		// GET/PUT/DELETE /dinia/v1/admin/customer/{id}
		register_rest_route(
			$this->namespace,
			'/admin/customer/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_get_customer' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'admin_update_customer' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'admin_delete_customer' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
			)
		);

		// GET/POST /dinia/v1/admin/plans
		register_rest_route(
			$this->namespace,
			'/admin/plans',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_get_plans' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'admin_create_plan' ),
					'permission_callback' => array( $this, 'check_admin' ),
				),
			)
		);

		// GET/PUT/DELETE /dinia/v1/admin/plan/{id}
		register_rest_route(
			$this->namespace,
			'/admin/plan/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_get_plan' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'admin_update_plan' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'admin_delete_plan' ),
					'permission_callback' => array( $this, 'check_admin' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => 'is_numeric',
						),
					),
				),
			)
		);

		// ─── ADMIN RESTAURANT-ROUTEN ──────────────────────────────

		// GET /dinia/v1/admin/tables
		register_rest_route( $this->namespace, '/admin/tables', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'admin_get_tables' ), 'permission_callback' => array( $this, 'check_admin' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_create_table' ), 'permission_callback' => array( $this, 'check_admin' ) ),
		) );
		// PUT/DELETE /dinia/v1/admin/tables/{id}
		register_rest_route( $this->namespace, '/admin/tables/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'admin_update_table' ), 'permission_callback' => array( $this, 'check_admin' ), 'args' => array( 'id' => array( 'required' => true, 'validate_callback' => 'is_numeric' ) ) ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'admin_delete_table' ), 'permission_callback' => array( $this, 'check_admin' ), 'args' => array( 'id' => array( 'required' => true, 'validate_callback' => 'is_numeric' ) ) ),
		) );
		// GET /dinia/v1/admin/reservations
		register_rest_route( $this->namespace, '/admin/reservations', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'admin_get_reservations' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// PUT /dinia/v1/admin/reservations/{id}/status
		register_rest_route( $this->namespace, '/admin/reservations/(?P<id>\d+)/status', array(
			'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'admin_update_reservation_status' ), 'permission_callback' => array( $this, 'check_admin' ), 'args' => array( 'id' => array( 'required' => true, 'validate_callback' => 'is_numeric' ) ),
		) );
		// POST /dinia/v1/admin/hours
		register_rest_route( $this->namespace, '/admin/hours', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_save_hours' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// POST /dinia/v1/admin/settings
		register_rest_route( $this->namespace, '/admin/settings', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_save_settings' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// POST /dinia/v1/admin/test-email
		register_rest_route( $this->namespace, '/admin/test-email', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_test_email' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// POST /dinia/v1/admin/test-caldav
		register_rest_route( $this->namespace, '/admin/test-caldav', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_test_caldav' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// POST /dinia/v1/admin/create-booking
		register_rest_route( $this->namespace, '/admin/create-booking', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'admin_create_booking' ), 'permission_callback' => array( $this, 'check_admin' ),
		) );
		// GET /dinia/v1/admin/customer-settings/{id}
		register_rest_route( $this->namespace, '/admin/customer-settings/(?P<id>\d+)', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'admin_get_customer_settings' ), 'permission_callback' => array( $this, 'check_admin' ), 'args' => array( 'id' => array( 'required' => true, 'validate_callback' => 'is_numeric' ) ),
		) );
	}

	/**
	 * ---------------------------------------------------------------
	 *  WEBHOOK-ROUTEN (öffentlich)
	 * ---------------------------------------------------------------
	 */

	/**
	 * Registriert den Mollie-Webhook-Endpunkt.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_webhook_routes() {
		// POST /dinia/v1/webhook/mollie
		register_rest_route(
			$this->namespace,
			'/webhook/mollie',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook_mollie' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  BACKUP-ROUTEN (Admin)
	 * ---------------------------------------------------------------
	 */

	/**
	 * Registriert die Backup-Endpunkte.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function register_backup_routes() {
		// POST /dinia/v1/admin/backup – Backup erstellen
		register_rest_route(
			$this->namespace,
			'/admin/backup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'admin_create_backup' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// GET /dinia/v1/admin/backups – Liste
		register_rest_route(
			$this->namespace,
			'/admin/backups',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_list_backups' ),
				'permission_callback' => array( $this, 'check_admin' ),
			)
		);

		// DELETE /dinia/v1/admin/backup/{id} – Löschen
		register_rest_route(
			$this->namespace,
			'/admin/backup/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'admin_delete_backup' ),
				'permission_callback' => array( $this, 'check_admin' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => 'is_numeric',
					),
				),
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  PERMISSION CALLBACKS
	 * ---------------------------------------------------------------
	 */

	/**
	 * Prüft, ob der aktuelle Benutzer Administrator-Rechte hat.
	 *
	 * @since 1.0.0
	 * @return bool|WP_Error True bei Erfolg, WP_Error bei fehlenden Rechten.
	 */
	public function check_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sie haben keine Berechtigung für diese Aktion.', 'dinia' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Prüft den API-Key aus dem X-API-Key-Header oder GET-Parameter.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return bool|WP_Error True bei gültigem Key, sonst WP_Error.
	 */
	public function check_api_key( $request ) {
		$api_key = $request->get_header( 'X-API-Key' );

		if ( empty( $api_key ) ) {
			$api_key = $request->get_param( 'api_key' );
		}

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'rest_missing_api_key',
				__( 'API-Key fehlt. Bitte via X-API-Key-Header oder ?api_key= übergeben.', 'dinia' ),
				array( 'status' => 401 )
			);
		}

		$customer_id = $this->tenant->identify_by_api_key( $api_key );

		if ( false === $customer_id ) {
			return new WP_Error(
				'rest_invalid_api_key',
				__( 'Ungültiger API-Key.', 'dinia' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * ---------------------------------------------------------------
	 *  WIDGET-CALLBACKS
	 * ---------------------------------------------------------------
	 */

	/**
	 * GET /dinia/v1/widget/{slug}/config
	 *
	 * Gibt die Widget-Konfiguration (Restaurant-Name, Einstellungen, Plan) zurück.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function widget_config( $request ) {
		$slug = $request->get_param( 'slug' );

		$customer = $this->customers->get_by_slug( $slug );

		if ( ! $customer ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Widget-Konfiguration nicht gefunden.', 'dinia' ),
				array( 'status' => 404 )
			);
		}

		$settings = $customer->settings;
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}

		$data = array(
			'restaurant_name' => $customer->restaurant_name ?? '',
			'settings'        => is_array( $settings ) ? $settings : array(),
			'plan'            => array(
				'plan_id'   => (int) $customer->plan_id,
				'plan_name' => $customer->plan_name ?? '',
			),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * GET /dinia/v1/widget/{slug}/slots
	 *
	 * Ruft verfügbare Zeit-Slots ab. Proxyt an GoBookMe-Plugin falls verfügbar,
	 * sonst Dummy-Response.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function widget_slots( $request ) {
		$slug       = $request->get_param( 'slug' );
		$date       = $request->get_param( 'date' );
		$party_size = $request->get_param( 'party_size' );

		$customer = $this->customers->get_by_slug( $slug );
		if ( ! $customer ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Restaurant nicht gefunden.', 'dinia' ),
				array( 'status' => 404 )
			);
		}

		$this->tenant->set( (int) $customer->id );

		$result = $this->proxy_slots( $date, $party_size );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /dinia/v1/widget/{slug}/reserve
	 *
	 * Führt eine Buchung durch. Proxyt an GoBookMe-Plugin falls verfügbar,
	 * sonst Dummy-Response.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function widget_reserve( $request ) {
		$slug = $request->get_param( 'slug' );

		$customer = $this->customers->get_by_slug( $slug );
		if ( ! $customer ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Restaurant nicht gefunden.', 'dinia' ),
				array( 'status' => 404 )
			);
		}

		$this->tenant->set( (int) $customer->id );

		$params = $request->get_params();
		$result = $this->proxy_reserve( $params );

		return rest_ensure_response( $result );
	}

	/**
	 * ---------------------------------------------------------------
	 *  CLIENT-CALLBACKS
	 * ---------------------------------------------------------------
	 */

	/**
	 * GET /dinia/v1/client/slots
	 *
	 * Verfügbare Slots per API-Key (wie widget_slots, jedoch ohne Slug).
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function client_slots( $request ) {
		$date       = $request->get_param( 'date' );
		$party_size = $request->get_param( 'party_size' );

		$result = $this->proxy_slots( $date, $party_size );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /dinia/v1/client/reserve
	 *
	 * Buchung per API-Key (wie widget_reserve, jedoch ohne Slug).
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function client_reserve( $request ) {
		$params = $request->get_params();
		$result = $this->proxy_reserve( $params );

		return rest_ensure_response( $result );
	}

	/**
	 * ---------------------------------------------------------------
	 *  ADMIN-CALLBACKS – Customers
	 * ---------------------------------------------------------------
	 */

	/**
	 * GET /dinia/v1/admin/customers – Alle Kunden abrufen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response
	 */
	public function admin_get_customers( $request ) {
		$customers = $this->customers->get_all();

		// Sensible Daten entfernen.
		$safe = array_map(
			function ( $c ) {
				$data = (array) $c;
				unset( $data['api_key_hash'] );
				unset( $data['confirm_token'] );
				return $data;
			},
			$customers
		);

		return rest_ensure_response( $safe );
	}

	/**
	 * POST /dinia/v1/admin/customers – Kunden anlegen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_customer( $request ) {
		$params = $request->get_params();

		$customer_id = $this->customers->create( $params );

		if ( false === $customer_id ) {
			return new WP_Error(
				'rest_create_failed',
				__( 'Kunde konnte nicht angelegt werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		$customer = $this->customers->get_by_id( $customer_id );
		$data     = (array) $customer;
		unset( $data['api_key_hash'] );
		unset( $data['confirm_token'] );

		return rest_ensure_response(
			array(
				'message' => __( 'Kunde angelegt.', 'dinia' ),
				'customer' => $data,
			)
		);
	}

	/**
	 * GET /dinia/v1/admin/customer/{id} – Einzelnen Kunden abrufen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_get_customer( $request ) {
		$id       = (int) $request->get_param( 'id' );
		$customer = $this->customers->get_by_id( $id );

		if ( ! $customer ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Kunde nicht gefunden.', 'dinia' ),
				array( 'status' => 404 )
			);
		}

		$data = (array) $customer;
		unset( $data['api_key_hash'] );
		unset( $data['confirm_token'] );

		return rest_ensure_response( $data );
	}

	/**
	 * PUT /dinia/v1/admin/customer/{id} – Kunden aktualisieren.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_customer( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_params();

		// Die ID aus den Parametern entfernen, da sie nicht aktualisiert werden darf.
		unset( $params['id'] );

		$updated = $this->customers->update( $id, $params );

		if ( false === $updated ) {
			return new WP_Error(
				'rest_update_failed',
				__( 'Kunde konnte nicht aktualisiert werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		$customer = $this->customers->get_by_id( $id );
		$data     = (array) $customer;
		unset( $data['api_key_hash'] );
		unset( $data['confirm_token'] );

		return rest_ensure_response(
			array(
				'message'  => __( 'Kunde aktualisiert.', 'dinia' ),
				'customer' => $data,
			)
		);
	}

	/**
	 * DELETE /dinia/v1/admin/customer/{id} – Kunden löschen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_customer( $request ) {
		$id = (int) $request->get_param( 'id' );

		$deleted = $this->customers->delete( $id );

		if ( false === $deleted ) {
			return new WP_Error(
				'rest_delete_failed',
				__( 'Kunde konnte nicht gelöscht werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Kunde gelöscht.', 'dinia' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  ADMIN-CALLBACKS – Plans
	 * ---------------------------------------------------------------
	 */

	/**
	 * GET /dinia/v1/admin/plans – Alle Pläne abrufen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response
	 */
	public function admin_get_plans( $request ) {
		$plans = $this->plans->get_all();

		return rest_ensure_response( $plans );
	}

	/**
	 * POST /dinia/v1/admin/plans – Plan anlegen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_plan( $request ) {
		$params = $request->get_params();

		$plan_id = $this->plans->create( $params );

		if ( false === $plan_id ) {
			return new WP_Error(
				'rest_create_failed',
				__( 'Plan konnte nicht angelegt werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		$plan = $this->plans->get_by_id( $plan_id );

		return rest_ensure_response(
			array(
				'message' => __( 'Plan angelegt.', 'dinia' ),
				'plan'    => $plan,
			)
		);
	}

	/**
	 * GET /dinia/v1/admin/plan/{id} – Einzelnen Plan abrufen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_get_plan( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$plan = $this->plans->get_by_id( $id );

		if ( ! $plan ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Plan nicht gefunden.', 'dinia' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $plan );
	}

	/**
	 * PUT /dinia/v1/admin/plan/{id} – Plan aktualisieren.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_update_plan( $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = $request->get_params();

		unset( $params['id'] );

		$updated = $this->plans->update( $id, $params );

		if ( false === $updated ) {
			return new WP_Error(
				'rest_update_failed',
				__( 'Plan konnte nicht aktualisiert werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		$plan = $this->plans->get_by_id( $id );

		return rest_ensure_response(
			array(
				'message' => __( 'Plan aktualisiert.', 'dinia' ),
				'plan'    => $plan,
			)
		);
	}

	/**
	 * DELETE /dinia/v1/admin/plan/{id} – Plan löschen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_plan( $request ) {
		$id = (int) $request->get_param( 'id' );

		$deleted = $this->plans->delete( $id );

		if ( false === $deleted ) {
			return new WP_Error(
				'rest_delete_failed',
				__( 'Plan konnte nicht gelöscht werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Plan gelöscht.', 'dinia' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  WEBHOOK-CALLBACKS
	 * ---------------------------------------------------------------
	 */

	/**
	 * POST /dinia/v1/webhook/mollie
	 *
	 * Verarbeitet eingehende Mollie-Webhook-Ereignisse (z. B. Zahlungsstatus).
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response
	 */
	public function webhook_mollie( $request ) {
		$body = $request->get_body_params();

		// Minimale Webhook-Verarbeitung: Logging und 200 OK.
		do_action( 'dinia_mollie_webhook_received', $body );

		// Wenn eine DINA_Mollie-Klasse existiert, Webhook dort verarbeiten.
		if ( class_exists( 'DINA_Mollie' ) ) {
			$mollie = new DINA_Mollie();
			$mollie->handle_webhook( $body );
		}

		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'message' => 'Webhook received.',
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  BACKUP-CALLBACKS
	 * ---------------------------------------------------------------
	 */

	/**
	 * POST /dinia/v1/admin/backup – Backup erstellen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_create_backup( $request ) {
		$type     = $request->get_param( 'type' );
		$backup   = $this->backup->create_backup( ! empty( $type ) ? $type : 'manual' );

		if ( false === $backup ) {
			return new WP_Error(
				'rest_backup_failed',
				__( 'Backup konnte nicht erstellt werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Backup erstellt.', 'dinia' ),
				'backup'  => $backup,
			)
		);
	}

	/**
	 * GET /dinia/v1/admin/backups – Backup-Liste abrufen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response
	 */
	public function admin_list_backups( $request ) {
		$limit   = $request->get_param( 'limit' );
		$backups = $this->backup->list_backups( ! empty( $limit ) ? (int) $limit : 0 );

		return rest_ensure_response( $backups );
	}

	/**
	 * DELETE /dinia/v1/admin/backup/{id} – Backup löschen.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Die aktuelle Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public function admin_delete_backup( $request ) {
		$id = (int) $request->get_param( 'id' );

		$deleted = $this->backup->delete_backup( $id );

		if ( false === $deleted ) {
			return new WP_Error(
				'rest_delete_failed',
				__( 'Backup konnte nicht gelöscht werden.', 'dinia' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Backup gelöscht.', 'dinia' ),
				'id'      => $id,
			)
		);
	}

	/**
	 * ---------------------------------------------------------------
	 *  PROXY / FALLBACK-METHODEN
	 * ---------------------------------------------------------------
	 */

	/**
	 * Proxyt die Slot-Abfrage an das GoBookMe-Plugin oder liefert
	 * eine Dummy-Response.
	 *
	 * @since 1.0.0
	 * @param string|null $date       Datum im YYYY-MM-DD-Format.
	 * @param int|null    $party_size Anzahl der Gäste.
	 * @return array Slots oder Dummy-Daten.
	 */
	private function proxy_slots( $date = null, $party_size = null ) {
		// DINA_Booking (Multi-Tenant) bevorzugen – VOLLE Logik inkl. Tisch-Kombinierung.
		if ( class_exists( 'DINA_Booking' ) && method_exists( 'DINA_Booking', 'get_time_slots' ) ) {
			try {
				$slots = DINA_Booking::get_time_slots( $date );
				if ( is_array( $slots ) && ! empty( $slots ) ) {
					$result = [];
					$party = max( 1, (int) ( $party_size ?? 2 ) );
					$customer_id = DINA_Tenant::instance()->get();
					$tables = DINA_Booking::get_active_tables( $customer_id );

					// Settings für min_advance_hours-Prüfung laden.
				$settings = DINA_Booking::get_settings( $customer_id );
				$min_advance_hours = (int) ( $settings['min_advance_hours'] ?? 2 );

				foreach ( $slots as $slot ) {
						// Bei heutigem Datum: Slots überspringen, die < jetzt + min_advance_hours sind.
						if ( $date === current_time( 'Y-m-d' ) ) {
							$slot_ts = strtotime( $date . ' ' . $slot['start'] );
							$min_ts  = current_time( 'timestamp' ) + ( $min_advance_hours * 3600 );
							if ( $slot_ts < $min_ts ) {
								continue;
							}
						}

						$reserved = DINA_Booking::get_reservations_for_slot( $date, $slot['start'], $slot['end'], $customer_id );
						// Alle belegten Tisch-IDs sammeln (inkl. kombinierte table_ids)
						$taken_ids = [];
						foreach ( $reserved as $r ) {
							$taken_ids[] = (int) $r->table_id;
							if ( ! empty( $r->table_ids ) ) {
								foreach ( explode( ',', $r->table_ids ) as $tid ) {
									$taken_ids[] = (int) $tid;
								}
							}
						}
						$taken_ids = array_unique( $taken_ids );

						// 1) Einzeltisch mit ausreichend Plätzen
						$assigned_table = null;
						foreach ( $tables as $t ) {
							if ( $t->seats >= $party && ! in_array( (int) $t->id, $taken_ids ) ) {
								$assigned_table = $t;
								break;
							}
						}

						if ( $assigned_table ) {
							$result[] = [
								'time'        => $slot['start'],
								'time_end'    => $slot['end'],
								'date'        => $date,
								'available'   => true,
								'party_size'  => $party,
								'table_id'    => (int) $assigned_table->id,
								'table_ids'   => (string) $assigned_table->id,
								'table_name'  => $assigned_table->name,
								'table_seats' => (int) $assigned_table->seats,
								'combined'    => false,
							];
							continue;
						}

						// 2) Kombinierbare Tische versuchen
						$available_combinable = [];
						foreach ( $tables as $t ) {
							if ( ! empty( $t->combinable ) && ! in_array( (int) $t->id, $taken_ids ) ) {
								$available_combinable[] = $t;
							}
						}

						if ( count( $available_combinable ) >= 2 ) {
							$combo = DINA_Booking::find_combination( $available_combinable, $party );
							if ( null !== $combo ) {
								$ids   = [];
								$names = [];
								$total_seats = 0;
								foreach ( $combo as $ct ) {
									$ids[]   = (int) $ct->id;
									$names[] = $ct->name;
									$total_seats += (int) $ct->seats;
								}
								$result[] = [
									'time'        => $slot['start'],
									'time_end'    => $slot['end'],
									'date'        => $date,
									'available'   => true,
									'party_size'  => $party,
									'table_id'    => $ids[0],
									'table_ids'   => implode( ',', $ids ),
									'table_name'  => implode( ' + ', $names ),
									'table_seats' => $total_seats,
									'combined'    => true,
								];
							}
						}
					}

					if ( ! empty( $result ) ) {
						return $result;
					}
				}
			} catch ( \Exception $e ) {
				$this->log_error( 'DINA_Booking::get_time_slots failed: ' . $e->getMessage() );
			}
		}

		// Fallback: GoBookMe-Plugin (Single-Tenant).
		if ( class_exists( 'GMR_Booking' ) && method_exists( 'GMR_Booking', 'get_time_slots' ) ) {
			try {
				$result = GMR_Booking::get_time_slots( $date, $party_size );
				if ( is_array( $result ) ) {
					return $result;
				}
			} catch ( \Exception $e ) {
				// Fallback bei Fehler im GoBookMe-Plugin.
				$this->log_error( 'GMR_Booking::get_time_slots failed: ' . $e->getMessage() );
			}
		}

		// Fallback: Dummy-Slots generieren.
		return $this->get_dummy_slots( $date, $party_size );
	}

	/**
	 * Proxyt eine Buchungsanfrage an das GoBookMe-Plugin oder liefert
	 * eine Dummy-Response.
	 *
	 * @since 1.0.0
	 * @param array $params Buchungsdaten.
	 * @return array Buchungsergebnis oder Dummy-Daten.
	 */
	private function proxy_reserve( $params ) {
		// DINA_Booking (Multi-Tenant) bevorzugen.
		if ( class_exists( 'DINA_Booking' ) && method_exists( 'DINA_Booking', 'create_reservation' ) ) {
			try {
				$customer_id = DINA_Tenant::instance()->get();
				// Slot-Dauer aus Settings berechnen
				if ( $customer_id ) {
					$settings = DINA_Booking::get_settings( $customer_id );
					$duration = $settings['slot_duration'] ?? 120;
				} else {
					$duration = 120;
				}
				$time_start = $params['time'] ?? $params['time_start'] ?? '';
				$time_end   = $params['time_end'] ?? '';
				if ( empty( $time_end ) && ! empty( $time_start ) ) {
					$time_end = date( 'H:i', strtotime( $time_start ) + $duration * 60 );
				}
				$data = [
					'date'        => $params['date'] ?? '',
					'time_start'  => $time_start,
					'time_end'    => $time_end,
					'party_size'  => (int) ( $params['party_size'] ?? $params['persons'] ?? 2 ),
					'guest_name'  => $params['guest_name'] ?? $params['name'] ?? '',
					'guest_phone' => $params['guest_phone'] ?? $params['phone'] ?? '',
					'guest_email' => $params['guest_email'] ?? $params['email'] ?? '',
					'notes'       => $params['notes'] ?? $params['note'] ?? '',
					'table_id'    => (int) ( $params['table_id'] ?? 0 ),
					'table_ids'   => sanitize_text_field( $params['table_ids'] ?? '' ),
				];
				$data['guest_count'] = $data['party_size'];

				// Tisch-Name für CalDAV auflösen
				$table_name = '';
				if ( ! empty( $data['table_id'] ) ) {
					global $wpdb;
					$table_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT name FROM {$wpdb->prefix}dinia_tables WHERE id = %d", $data['table_id']
					) );
					if ( $table_row ) {
						$table_name = $table_row->name;
					}
				}

				$result = DINA_Booking::create_reservation( $data );
				if ( $result && ! is_wp_error( $result ) ) {
					// CalDAV-Sync (Infomaniak etc.)
					try {
						$caldav = new DINA_CalDAV();
						if ( $caldav->is_configured() ) {
							$caldav->create_event(
								$data['date'],
								$data['time_start'],
								$data['time_end'],
								$data['guest_name'],
								$data['party_size'],
								$table_name,
								$data['notes'],
								$data['guest_email'],
								$data['guest_phone']
							);
						}
					} catch ( Exception $e ) {
						error_log( '[Dinia] CalDAV sync failed: ' . $e->getMessage() );
					}

					// E-Mail-Bestätigung via Brevo
					try {
						if ( ! empty( $data['guest_email'] ) ) {
							$restaurant_name = ( isset( $settings ) ? ( $settings['restaurant_name'] ?? 'Restaurant' ) : 'Restaurant' );
							$date_formatted  = date_i18n( 'l, j. F Y', strtotime( $data['date'] ) );
							$subject         = sprintf( __( 'Ihre Reservierung bei %s', 'dinia' ), $restaurant_name );

							$html  = '<h2>Reservierung bestätigt</h2>';
							$html .= '<div class="details">';
							$html .= '<p><strong>Datum:</strong> ' . esc_html( $date_formatted ) . '</p>';
							$html .= '<p><strong>Uhrzeit:</strong> ' . esc_html( $data['time_start'] ) . ' – ' . esc_html( $data['time_end'] ) . '</p>';
							$html .= '<p><strong>Personen:</strong> ' . (int) $data['party_size'] . '</p>';
							$html .= '<p><strong>Name:</strong> ' . esc_html( $data['guest_name'] ) . '</p>';
							if ( ! empty( $data['guest_phone'] ) ) {
								$html .= '<p><strong>Telefon:</strong> ' . esc_html( $data['guest_phone'] ) . '</p>';
							}
							if ( ! empty( $data['notes'] ) ) {
								$html .= '<p><strong>Notiz:</strong> ' . esc_html( $data['notes'] ) . '</p>';
							}
							$html .= '</div>';
							$html .= '<p>Vielen Dank für Ihre Reservierung! Wir freuen uns auf Ihren Besuch.</p>';
							$cancel_phone = $settings['cancel_phone'] ?? '';
							$cancel_email = $settings['cancel_email'] ?? '';
							if ( ! empty( $cancel_phone ) || ! empty( $cancel_email ) ) {
								$html .= '<p style="font-size:12px;color:#999;">Sollten Sie Ihren Termin nicht wahrnehmen können, bitten wir um rechtzeitige Absage';
								if ( ! empty( $cancel_phone ) ) {
									$html .= ' unter <strong>' . esc_html( $cancel_phone ) . '</strong>';
								}
								if ( ! empty( $cancel_email ) ) {
									$html .= ' oder per E-Mail an <strong>' . esc_html( $cancel_email ) . '</strong>';
								}
								$html .= '.</p>';
							} else {
								$html .= '<p style="font-size:12px;color:#999;">Sollten Sie Ihren Termin nicht wahrnehmen können, bitten wir um rechtzeitige Absage.</p>';
							}

							$html_body = DINA_Mailer::build_html( $subject, $html, $restaurant_name );
							DINA_Mailer::send(
								$data['guest_email'],
								$subject,
								"Reservierung bestätigt: {$date_formatted} um {$data['time_start']} Uhr",
								$html_body
							);
						}
					} catch ( Exception $e ) {
						error_log( '[Dinia] Brevo mail failed: ' . $e->getMessage() );
					}

					return [
						'success'        => true,
						'reservation_id' => $result,
						'message'        => __( 'Reservierung bestätigt.', 'dinia' ),
						'cancel_phone'   => $settings['cancel_phone'] ?? '',
						'cancel_email'   => $settings['cancel_email'] ?? '',
					];
				}
				if ( is_wp_error( $result ) ) {
					return [
						'success' => false,
						'error'   => $result->get_error_message(),
					];
				}
			} catch ( \Exception $e ) {
				$this->log_error( 'DINA_Booking::create_reservation failed: ' . $e->getMessage() );
			}
		}

		// Fallback: GoBookMe-Plugin (Single-Tenant).
		if ( class_exists( 'GMR_Booking' ) && method_exists( 'GMR_Booking', 'create_booking' ) ) {
			try {
				$result = GMR_Booking::create_booking( $params );
				if ( is_array( $result ) || is_object( $result ) ) {
					return (array) $result;
				}
			} catch ( \Exception $e ) {
				$this->log_error( 'GMR_Booking::create_booking failed: ' . $e->getMessage() );
			}
		}

		// Fallback: Dummy-Buchungsbestätigung.
		return $this->get_dummy_reservation( $params );
	}

	/**
	 * Generiert Dummy-Slots für den Fall, dass kein GoBookMe-Plugin
	 * installiert ist.
	 *
	 * @since 1.0.0
	 * @param string|null $date       Datum im YYYY-MM-DD-Format.
	 * @param int|null    $party_size Anzahl der Gäste.
	 * @return array Dummy-Slot-Daten.
	 */
	private function get_dummy_slots( $date = null, $party_size = null ) {
		if ( empty( $date ) ) {
			$date = current_time( 'Y-m-d' );
		}

		$slots = array();
		$start = 11; // 11:00 Uhr
		$end   = 21; // 21:00 Uhr

		for ( $hour = $start; $hour < $end; $hour++ ) {
			$slots[] = array(
				'time'       => sprintf( '%02d:00', $hour ),
				'date'       => $date,
				'available'  => true,
				'party_size' => $party_size ?? 2,
			);

			$slots[] = array(
				'time'       => sprintf( '%02d:30', $hour ),
				'date'       => $date,
				'available'  => true,
				'party_size' => $party_size ?? 2,
			);
		}

		return array(
			'date'       => $date,
			'party_size' => $party_size ?? 2,
			'slots'      => $slots,
		);
	}

	/**
	 * Generiert eine Dummy-Buchungsbestätigung für den Fall, dass
	 * kein GoBookMe-Plugin installiert ist.
	 *
	 * @since 1.0.0
	 * @param array $params Buchungsdaten.
	 * @return array Dummy-Buchungsbestätigung.
	 */
	private function get_dummy_reservation( $params ) {
		$reservation_id = 'dummy-' . strtolower( wp_generate_password( 12, false ) );

		return array(
			'id'             => $reservation_id,
			'status'         => 'confirmed',
			'message'        => __( 'Buchung bestätigt (Demo-Modus).', 'dinia' ),
			'date'           => $params['date'] ?? current_time( 'Y-m-d' ),
			'time'           => $params['time'] ?? '12:00',
			'party_size'     => $params['party_size'] ?? 2,
			'customer_name'  => $params['name'] ?? '',
			'customer_email' => $params['email'] ?? '',
			'customer_phone' => $params['phone'] ?? '',
			'note'           => $params['note'] ?? '',
		);
	}

	/**
	 * Fehler im WordPress-Debug-Log protokollieren.
	 *
	 * @since 1.0.0
	 * @param string $message Fehlermeldung.
	 * @return void
	 */
	private function log_error( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'DINA_REST_API: ' . $message );
	}

	// ─── ADMIN RESTAURANT CALLBACKS ──────────────────────────────

	/** Tisch-Liste */
	public function admin_get_tables() {
		global $wpdb;
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$tables = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}dinia_tables WHERE customer_id = %d ORDER BY seats ASC", $cid
		) );
		return rest_ensure_response( $tables );
	}

	/** Tisch anlegen */
	public function admin_create_table( $request ) {
		global $wpdb;
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$wpdb->insert( $wpdb->prefix . 'dinia_tables', array(
			'customer_id' => $cid,
			'name' => sanitize_text_field( $request->get_param( 'name' ) ?: 'Tisch' ),
			'seats' => (int) ( $request->get_param( 'seats' ) ?: 2 ),
			'position' => in_array( $request->get_param( 'position' ), array( 'indoor', 'outdoor', 'bar' ) ) ? $request->get_param( 'position' ) : 'indoor',
			'active' => $request->get_param( 'active' ) ? 1 : 0,
			'combinable' => $request->get_param( 'combinable' ) ? 1 : 0,
		) );
		return rest_ensure_response( array( 'success' => true, 'id' => $wpdb->insert_id ) );
	}

	/** Tisch aktualisieren */
	public function admin_update_table( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$wpdb->update( $wpdb->prefix . 'dinia_tables', array(
			'name' => sanitize_text_field( $request->get_param( 'name' ) ?: 'Tisch' ),
			'seats' => (int) ( $request->get_param( 'seats' ) ?: 2 ),
			'position' => in_array( $request->get_param( 'position' ), array( 'indoor', 'outdoor', 'bar' ) ) ? $request->get_param( 'position' ) : 'indoor',
			'active' => $request->get_param( 'active' ) ? 1 : 0,
			'combinable' => $request->get_param( 'combinable' ) ? 1 : 0,
		), array( 'id' => $id, 'customer_id' => $cid ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Tisch löschen */
	public function admin_delete_table( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$wpdb->delete( $wpdb->prefix . 'dinia_tables', array( 'id' => $id, 'customer_id' => $cid ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Reservierungs-Liste */
	public function admin_get_reservations( $request ) {
		global $wpdb;
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$date = $request->get_param( 'date' );
		$status = $request->get_param( 'status' );
		$sql = "SELECT r.*, t.name as table_name FROM {$wpdb->prefix}dinia_reservations r LEFT JOIN {$wpdb->prefix}dinia_tables t ON r.table_id = t.id WHERE r.customer_id = %d";
		$args = array( $cid );
		if ( $date ) { $sql .= " AND r.date = %s"; $args[] = $date; }
		if ( $status ) { $sql .= " AND r.status = %s"; $args[] = $status; }
		$sql .= " ORDER BY r.date DESC, r.time_start ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		return rest_ensure_response( $rows );
	}

	/** Reservierungs-Status ändern */
	public function admin_update_reservation_status( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$status = $request->get_param( 'status' );
		if ( ! in_array( $status, array( 'confirmed', 'cancelled' ) ) ) {
			return new WP_Error( 'invalid_status', 'Ungültiger Status.', array( 'status' => 400 ) );
		}
		$wpdb->update( $wpdb->prefix . 'dinia_reservations', array( 'status' => $status ), array( 'id' => $id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Öffnungszeiten speichern */
	public function admin_save_hours( $request ) {
		global $wpdb;
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$days = $request->get_param( 'days' );
		if ( ! is_array( $days ) ) return new WP_Error( 'invalid', 'Tage-Array erwartet.', array( 'status' => 400 ) );
		$valid_days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
		foreach ( $days as $day_key => $data ) {
			if ( ! in_array( $day_key, $valid_days ) ) continue;
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}dinia_hours WHERE customer_id = %d AND day_key = %s", $cid, $day_key
			) );
			$values = array(
				'open' => sanitize_text_field( $data['open'] ?? '' ),
				'close' => sanitize_text_field( $data['close'] ?? '' ),
				'open2' => sanitize_text_field( $data['open2'] ?? '' ),
				'close2' => sanitize_text_field( $data['close2'] ?? '' ),
				'closed' => ! empty( $data['closed'] ) ? 1 : 0,
			);
			if ( $exists ) {
				$wpdb->update( $wpdb->prefix . 'dinia_hours', $values, array( 'id' => $exists ) );
			} else {
				$values['customer_id'] = $cid;
				$values['day_key'] = $day_key;
				$wpdb->insert( $wpdb->prefix . 'dinia_hours', $values );
			}
		}
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Kundeneinstellungen speichern */
	public function admin_save_settings( $request ) {
		global $wpdb;
		$cid = DINA_Tenant::instance()->get();
		if ( ! $cid ) return new WP_Error( 'no_tenant', 'Kein Mandant.', array( 'status' => 400 ) );
		$settings = $request->get_param( 'settings' );
		if ( ! is_array( $settings ) ) return new WP_Error( 'invalid', 'Settings-Array erwartet.', array( 'status' => 400 ) );
		$wpdb->update( $wpdb->prefix . 'dinia_customers', array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $cid ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/** Kundeneinstellungen lesen */
	public function admin_get_customer_settings( $request ) {
		global $wpdb;
		$cid = (int) $request->get_param( 'id' );
		$settings_json = $wpdb->get_var( $wpdb->prepare(
			"SELECT settings FROM {$wpdb->prefix}dinia_customers WHERE id = %d", $cid
		) );
		$settings = $settings_json ? json_decode( $settings_json, true ) : array();
		return rest_ensure_response( array( 'settings' => $settings ) );
	}

	/** Test-E-Mail senden */
	public function admin_test_email( $request ) {
		if ( ! class_exists( 'DINA_Mailer' ) ) {
			return new WP_Error( 'no_mailer', 'Mailer nicht verfügbar.', array( 'status' => 500 ) );
		}
		$to = $request->get_param( 'email' );
		if ( ! $to || ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', 'Ungültige E-Mail.', array( 'status' => 400 ) );
		}
		$result = DINA_Mailer::test( $to );
		if ( true === $result ) {
			return rest_ensure_response( array( 'success' => true, 'message' => 'Testmail gesendet!' ) );
		}
		return rest_ensure_response( array( 'success' => false, 'message' => $result ) );
	}

	/** CalDAV-Verbindung testen */
	public function admin_test_caldav( $request ) {
		if ( ! class_exists( 'DINA_CalDAV' ) ) {
			return new WP_Error( 'no_caldav', 'CalDAV nicht verfügbar.', array( 'status' => 500 ) );
		}
		$caldav = new DINA_CalDAV();
		$result = $caldav->test_connection();
		return rest_ensure_response( $result );
	}

	/** Manuelle Buchung */
	public function admin_create_booking( $request ) {
		if ( ! class_exists( 'DINA_Booking' ) ) {
			return new WP_Error( 'no_booking', 'Booking nicht verfügbar.', array( 'status' => 500 ) );
		}
		$data = array(
			'date' => $request->get_param( 'date' ),
			'time_start' => $request->get_param( 'time' ),
			'time_end' => $request->get_param( 'time_end' ) ?: '',
			'guest_name' => sanitize_text_field( $request->get_param( 'name' ) ?: '' ),
			'guest_email' => sanitize_email( $request->get_param( 'email' ) ?: '' ),
			'guest_phone' => sanitize_text_field( $request->get_param( 'phone' ) ?: '' ),
			'guest_count' => (int) ( $request->get_param( 'party_size' ) ?: 2 ),
			'notes' => sanitize_textarea_field( $request->get_param( 'notes' ) ?: '' ),
			'table_id' => (int) ( $request->get_param( 'table_id' ) ?: 0 ),
			'source' => 'admin',
		);
		$result = DINA_Booking::create_reservation( $data );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'booking_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'reservation_id' => $result ) );
	}
}
