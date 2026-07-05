<?php
/**
 * DINA_Tenant – Tenant-Identifikation und Query-Filter
 *
 * Verwaltet den aktuellen Mandanten (Customer) über die gesamte Anfrage.
 * Bietet Lookup-Methoden per API-Key (SHA256-gehasst), Slug oder
 * automatischer Erkennung aus Request-Daten.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Tenant
 */
class DINA_Tenant {

    /**
     * Singleton-Instanz.
     *
     * @var DINA_Tenant|null
     */
    private static $instance = null;

    /**
     * Singleton-Instanz holen.
     *
     * @return DINA_Tenant
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Aktuelle Customer-ID des identifizierten Mandanten.
     *
     * @var int|null
     */
    protected $customer_id = null;

    /**
     * WordPress-Datenbank-Objekt (global $wpdb).
     *
     * @var wpdb
     */
    protected $wpdb;

    /**
     * Konstruktor.
     *
     * @param wpdb|null $db WordPress-Datenbank-Objekt (optional).
     */
    public function __construct( $db = null ) {
        global $wpdb;

        $this->wpdb = null !== $db ? $db : $wpdb;
    }

	/**
	 * Setzt die Customer-ID des aktuellen Mandanten.
	 *
	 * @param int $customer_id Die zu setzende Customer-ID.
	 * @return $this
	 */
	public function set( $customer_id ) {
		$this->customer_id = (int) $customer_id;
		return $this;
	}

	/**
	 * Gibt die aktuell gesetzte Customer-ID zurück.
	 *
	 * @return int|null
	 */
	public function get() {
		return $this->customer_id;
	}

	/**
	 * Gibt eine SQL-WHERE-Klausel für die Mandanten-Filterung zurück.
	 *
	 * @param string $table_alias Optional. Tabellen-Alias (z. B. 't' → 't.customer_id').
	 * @return string Leerer String, wenn kein Mandant gesetzt; sonst 'AND customer_id = X'.
	 */
	public function where_clause( $table_alias = '' ) {
		if ( null === $this->customer_id ) {
			return '';
		}

		$column = 'customer_id';

		if ( '' !== $table_alias ) {
			// Nur sichere Zeichen für den Alias zulassen.
			$safe_alias = preg_replace( '/[^a-zA-Z0-9_]/', '', $table_alias );
			$column     = $safe_alias . '.customer_id';
		}

		return 'AND ' . $column . ' = ' . (int) $this->customer_id;
	}

	/**
	 * Identifiziert einen Mandanten anhand seines API-Keys (SHA256-gehasst).
	 *
	 * Der übergebene Key wird mit SHA256 gehasht und mit dem gespeicherten
	 * Hash in der Datenbank verglichen.
	 *
	 * @param string $key Der rohe API-Key.
	 * @return int|false Customer-ID bei Erfolg, false bei keinem Treffer.
	 */
	public function identify_by_api_key( $key ) {
		if ( empty( $key ) ) {
			return false;
		}

		$key_hash = hash( 'sha256', $key );

		$table = $this->wpdb->prefix . 'dinia_customers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$customer_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table} WHERE api_key_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$key_hash
			)
		);

		if ( null !== $customer_id && false !== $customer_id ) {
			$this->customer_id = (int) $customer_id;
			return $this->customer_id;
		}

		return false;
	}

	/**
	 * Identifiziert einen Mandanten anhand seines Slugs.
	 *
	 * @param string $slug Der Mandanten-Slug.
	 * @return int|false Customer-ID bei Erfolg, false bei keinem Treffer.
	 */
	public function identify_by_slug( $slug ) {
		if ( empty( $slug ) ) {
			return false;
		}

		$table = $this->wpdb->prefix . 'dinia_customers';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$customer_id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$table} WHERE slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);

		if ( null !== $customer_id && false !== $customer_id ) {
			$this->customer_id = (int) $customer_id;
			return $this->customer_id;
		}

		return false;
	}

	/**
	 * Automatische Mandanten-Identifikation aus der aktuellen Anfrage.
	 *
	 * Versucht in folgender Reihenfolge:
	 *  1. X-API-Key HTTP-Header (REST-API / Server-to-Server)
	 *  2. 'api_key' GET-Parameter (Client-Abfragen)
	 *  3. 'tid' GET-Parameter (Widget-Einbindung)
	 *
	 * @return int|false Customer-ID bei erfolgreicher Identifikation, sonst false.
	 */
	public function identify() {
		// 1. X-API-Key Header.
		if ( isset( $_SERVER['HTTP_X_API_KEY'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) );
			$result = $this->identify_by_api_key( $key );
			if ( false !== $result ) {
				return $result;
			}
		}

		// 2. api_key GET-Parameter.
		if ( isset( $_GET['api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = sanitize_text_field( wp_unslash( $_GET['api_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = $this->identify_by_api_key( $key );
			if ( false !== $result ) {
				return $result;
			}
		}

		// 3. 'tid' GET-Parameter (Widget).
		if ( isset( $_GET['tid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$slug = sanitize_text_field( wp_unslash( $_GET['tid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result = $this->identify_by_slug( $slug );
			if ( false !== $result ) {
				return $result;
			}
		}

		return false;
	}
}
