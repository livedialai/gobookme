<?php
/**
 * DINA_Customers – Kunden-CRUD für GoBookMe SaaS
 *
 * Verwaltet Kunden (Restaurants) in der WordPress-Datenbank.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Customers
 */
class DINA_Customers {

	/**
	 * WordPress-Datenbank-Objekt.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Tabellenname (mit Prefix).
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dinia_customers';
	}

	/**
	 * Alle Kunden abrufen.
	 *
	 * @return array Array von Kunden-Objekten.
	 */
	public function get_all() {
		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY id DESC"
		);
	}

	/**
	 * Kunden nach ID abrufen.
	 *
	 * @param int $id Kunden-ID.
	 * @return object|null Kunden-Objekt oder null.
	 */
	public function get_by_id( $id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				(int) $id
			)
		);
	}

	/**
	 * Kunden nach Slug abrufen.
	 *
	 * @param string $slug Kundenslug.
	 * @return object|null Kunden-Objekt oder null.
	 */
	public function get_by_slug( $slug ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s",
				sanitize_title( $slug )
			)
		);
	}

	/**
	 * Kunden nach API-Key (SHA-256 Hash) abrufen.
	 *
	 * @param string $key API-Key im Klartext.
	 * @return object|null Kunden-Objekt oder null.
	 */
	public function get_by_api_key( $key ) {
		$hash = hash( 'sha256', $key );
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE api_key_hash = %s",
				$hash
			)
		);
	}

	/**
	 * Kunden anlegen.
	 * Der Slug wird automatisch aus dem Feld 'company' generiert.
	 *
	 * @param array $data Kundendaten (company, email, phone, settings, plan_id, plan_name, restaurant_name, …).
	 * @return int|false Insert-ID oder false bei Fehler.
	 */
	public function create( $data ) {
		$defaults = array(
			'company'         => '',
			'slug'            => '',
			'email'           => '',
			'phone'           => '',
			'restaurant_name' => '',
			'settings'        => '',
			'plan_id'         => null,
			'plan_name'       => '',
			'api_key_hash'    => '',
			'api_key_hint'    => '',
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// Auto-Slug aus company generieren, falls kein Slug übergeben wurde.
		if ( empty( $data['slug'] ) && ! empty( $data['company'] ) ) {
			$data['slug'] = sanitize_title( $data['company'] );
		}

		// Sicherstellen, dass der Slug unique ist.
		$data['slug'] = $this->make_unique_slug( $data['slug'] );

		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'company'         => sanitize_text_field( $data['company'] ),
				'slug'            => $data['slug'],
				'email'           => sanitize_email( $data['email'] ),
				'phone'           => sanitize_text_field( $data['phone'] ),
				'restaurant_name' => sanitize_text_field( $data['restaurant_name'] ),
				'settings'        => is_string( $data['settings'] ) ? $data['settings'] : wp_json_encode( $data['settings'] ),
				'plan_id'         => is_numeric( $data['plan_id'] ) ? (int) $data['plan_id'] : null,
				'plan_name'       => sanitize_text_field( $data['plan_name'] ),
				'api_key_hash'    => $data['api_key_hash'],
				'api_key_hint'    => $data['api_key_hint'],
				'created_at'      => $data['created_at'],
				'updated_at'      => $data['updated_at'],
			),
			array(
				'%s', // company
				'%s', // slug
				'%s', // email
				'%s', // phone
				'%s', // restaurant_name
				'%s', // settings
				'%d', // plan_id
				'%s', // plan_name
				'%s', // api_key_hash
				'%s', // api_key_hint
				'%s', // created_at
				'%s', // updated_at
			)
		);

		return $inserted ? $this->wpdb->insert_id : false;
	}

	/**
	 * Kunden aktualisieren.
	 *
	 * @param int   $id   Kunden-ID.
	 * @param array $data Zu aktualisierende Felder.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function update( $id, $data ) {
		$allowed_fields = array(
			'company',
			'slug',
			'email',
			'phone',
			'restaurant_name',
			'settings',
			'plan_id',
			'plan_name',
			'api_key_hash',
			'api_key_hint',
		);

		$set    = array();
		$types  = array();

		foreach ( $allowed_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				switch ( $field ) {
					case 'email':
						$set[ $field ] = sanitize_email( $data[ $field ] );
						$types[]       = '%s';
						break;
					case 'plan_id':
						$set[ $field ] = is_numeric( $data[ $field ] ) ? (int) $data[ $field ] : null;
						$types[]       = '%d';
						break;
					case 'settings':
						$set[ $field ] = is_string( $data[ $field ] ) ? $data[ $field ] : wp_json_encode( $data[ $field ] );
						$types[]       = '%s';
						break;
					default:
						$set[ $field ] = sanitize_text_field( $data[ $field ] );
						$types[]       = '%s';
						break;
				}
			}
		}

		// Bei Slug-Änderung auf Eindeutigkeit prüfen.
		if ( isset( $set['slug'] ) ) {
			$set['slug'] = $this->make_unique_slug( $set['slug'], $id );
		}

		$set['updated_at'] = current_time( 'mysql' );
		$types[]           = '%s';

		$updated = $this->wpdb->update(
			$this->table,
			$set,
			array( 'id' => (int) $id ),
			$types,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Kunden löschen.
	 *
	 * @param int $id Kunden-ID.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function delete( $id ) {
		$deleted = $this->wpdb->delete(
			$this->table,
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * API-Key generieren und speichern.
	 *
	 * Erzeugt einen 32 Byte (256 Bit) langen Zufallsstring,
	 * speichert den SHA-256-Hash in der Datenbank und
	 * gibt einen Hinweis (erste 4 Zeichen + '****') zusammen mit dem
	 * Klartext-Key zurück.
	 *
	 * @param int $id Kunden-ID.
	 * @return array|false Array mit 'key' (Klartext) und 'hint' oder false.
	 */
	public function generate_api_key( $id ) {
		$customer = $this->get_by_id( $id );
		if ( ! $customer ) {
			return false;
		}

		// 32 Byte Zufallsstring → hex-kodiert (64 Zeichen).
		$raw_key   = random_bytes( 32 );
		$api_key   = bin2hex( $raw_key );
		$hash      = hash( 'sha256', $api_key );
		$hint      = substr( $api_key, 0, 4 ) . '****';

		$updated = $this->wpdb->update(
			$this->table,
			array(
				'api_key_hash' => $hash,
				'api_key_hint' => $hint,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		return array(
			'key'  => $api_key,
			'hint' => $hint,
		);
	}

	/**
	 * Widget-Konfiguration für einen Kunden (per Slug) abrufen.
	 *
	 * Gibt Restaurant-Name, Settings (als Array) und Plan-Info zurück.
	 *
	 * @param string $slug Kundenslug.
	 * @return array|false Array mit 'restaurant_name', 'settings', 'plan' oder false.
	 */
	public function get_widget_config( $slug ) {
		$customer = $this->get_by_slug( $slug );
		if ( ! $customer ) {
			return false;
		}

		$settings = $customer->settings;
		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true );
		}

		return array(
			'restaurant_name' => $customer->restaurant_name,
			'settings'        => is_array( $settings ) ? $settings : array(),
			'plan'            => array(
				'plan_id'   => (int) $customer->plan_id,
				'plan_name' => $customer->plan_name,
			),
		);
	}

	/**
	 * Stellt sicher, dass ein Slug in der Tabelle unique ist.
	 * Hängt bei Bedarf einen numerischen Suffix an.
	 *
	 * @param string   $slug     Der gewünschte Slug.
	 * @param int|null $exclude_id ID, die von der Prüfung ausgeschlossen werden soll (bei Updates).
	 * @return string Unique Slug.
	 */
	private function make_unique_slug( $slug, $exclude_id = null ) {
		$original_slug = $slug;
		$suffix        = 1;

		while ( true ) {
			if ( $exclude_id ) {
				$exists = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table} WHERE slug = %s AND id != %d",
						$slug,
						(int) $exclude_id
					)
				);
			} else {
				$exists = $this->wpdb->get_var(
					$this->wpdb->prepare(
						"SELECT COUNT(*) FROM {$this->table} WHERE slug = %s",
						$slug
					)
				);
			}

			if ( ! $exists ) {
				break;
			}

			$slug = $original_slug . '-' . ( ++$suffix );
		}

		return $slug;
	}
}
