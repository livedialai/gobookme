<?php
/**
 * DINA_Coupon – Promo-Code-Verwaltung
 *
 * Verwaltet Gutscheincodes (Rabatt-Aktionen) in der wp_dinia_coupons-Tabelle.
 * Bietet Validierung, Rabattberechnung und Admin-CRUD.
 *
 * @package GoBookMe_SaaS
 * @since   1.1.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Coupon
 *
 * @since 1.1.7
 */
class DINA_Coupon {

	/**
	 * wpdb-Instanz.
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
	 *
	 * @since 1.1.7
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'dinia_coupons';
	}

	/**
	 * Erzeugt die wp_dinia_coupons-Tabelle.
	 * Wird beim Plugin-Aktivierungs-Hook aufgerufen.
	 *
	 * @since 1.1.7
	 * @return void
	 */
	public function create_table(): void {
		$charset = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id              mediumint(9)     NOT NULL AUTO_INCREMENT,
			code            varchar(50)      NOT NULL,
			discount_type   varchar(10)      NOT NULL DEFAULT 'fixed',
			discount_value  decimal(10,2)    NOT NULL DEFAULT 0.00,
			max_uses        int              NOT NULL DEFAULT 0,
			used_count      int              NOT NULL DEFAULT 0,
			expires_at      datetime         DEFAULT NULL,
			is_active       tinyint(1)       NOT NULL DEFAULT 1,
			created_at      datetime         DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY code (code)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Seed-Datensatz: Sommer2026 – Erster Monat nur 1€
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE code = %s LIMIT 1",
				'Sommer2026'
			)
		);

		if ( ! $exists ) {
			$this->wpdb->insert(
				$this->table,
				array(
					'code'           => 'Sommer2026',
					'discount_type'  => 'fixed',
					'discount_value' => 1.00,
					'max_uses'       => 100,
					'used_count'     => 0,
					'is_active'      => 1,
				)
			);
		}
	}

	/**
	 * Validiert einen Gutscheincode.
	 *
	 * Prüft: Existenz, Aktiv-Status, Ablaufdatum und Nutzungslimit.
	 *
	 * @since 1.1.7
	 * @param string $code Der einzulösende Gutscheincode.
	 * @return array ['success' => bool, 'data' => array|string]
	 */
	public function validate_code( string $code ): array {
		$coupon = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE code = %s LIMIT 1",
				$code
			),
			ARRAY_A
		);

		if ( ! $coupon ) {
			return array(
				'success' => false,
				'data'    => 'Ungültiger Gutscheincode.',
			);
		}

		if ( empty( $coupon['is_active'] ) ) {
			return array(
				'success' => false,
				'data'    => 'Dieser Gutscheincode ist nicht mehr aktiv.',
			);
		}

		if ( ! empty( $coupon['expires_at'] ) ) {
			$expires = strtotime( $coupon['expires_at'] );
			if ( $expires && $expires < time() ) {
				return array(
					'success' => false,
					'data'    => 'Dieser Gutscheincode ist abgelaufen.',
				);
			}
		}

		$max_uses = (int) $coupon['max_uses'];
		if ( $max_uses > 0 && (int) $coupon['used_count'] >= $max_uses ) {
			return array(
				'success' => false,
				'data'    => 'Dieser Gutscheincode wurde bereits maximal genutzt.',
			);
		}

		return array(
			'success' => true,
			'data'    => $coupon,
		);
	}

	/**
	 * Wendet einen Gutschein auf einen Preis an.
	 *
	 * @since 1.1.7
	 * @param string $code           Der Gutscheincode.
	 * @param float  $original_price Der ursprüngliche Preis.
	 * @return array ['success' => bool, 'data' => float|string]
	 */
	public function apply_discount( string $code, float $original_price ): array {
		$validation = $this->validate_code( $code );

		if ( ! $validation['success'] ) {
			return $validation;
		}

		$coupon = $validation['data'];

		if ( 'percent' === $coupon['discount_type'] ) {
			$discount = $original_price * ( (float) $coupon['discount_value'] / 100 );
		} else {
			// fixed
			$discount = (float) $coupon['discount_value'];
		}

		$final_price = max( 0, $original_price - $discount );

		return array(
			'success' => true,
			'data'    => round( $final_price, 2 ),
		);
	}

	/**
	 * Erhöht den Nutzungszähler eines Gutscheins um 1.
	 *
	 * @since 1.1.7
	 * @param string $code Der Gutscheincode.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function increment_usage( string $code ): bool {
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table} SET used_count = used_count + 1 WHERE code = %s",
				$code
			)
		);

		return false !== $updated;
	}

	/**
	 * Gibt alle Gutscheine zurück.
	 *
	 * @since 1.1.7
	 * @return array Liste aller Gutscheine als assoziative Arrays.
	 */
	public function get_all(): array {
		$results = $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY created_at DESC",
			ARRAY_A
		);

		return $results ?: array();
	}

	/**
	 * Gibt einen Gutschein anhand seiner ID zurück.
	 *
	 * @since 1.1.7
	 * @param int $id Die Gutschein-ID.
	 * @return array|null Assoziatives Array oder null bei Nicht-Fund.
	 */
	public function get_by_id( int $id ): ?array {
		$coupon = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		return $coupon ?: null;
	}

	/**
	 * Erstellt einen neuen Gutschein.
	 *
	 * @since 1.1.7
	 * @param array $data Gutschein-Daten (code, discount_type, discount_value, max_uses, expires_at, is_active).
	 * @return array ['success' => bool, 'data' => int|string]
	 */
	public function create( array $data ): array {
		$fields = array(
			'code'           => $data['code'] ?? '',
			'discount_type'  => in_array( $data['discount_type'] ?? '', array( 'fixed', 'percent' ), true )
				? $data['discount_type']
				: 'fixed',
			'discount_value' => (float) ( $data['discount_value'] ?? 0 ),
			'max_uses'       => (int) ( $data['max_uses'] ?? 0 ),
			'used_count'     => 0,
			'is_active'      => ! empty( $data['is_active'] ) ? 1 : 0,
			'expires_at'     => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
		);

		if ( empty( $fields['code'] ) ) {
			return array(
				'success' => false,
				'data'    => 'Gutscheincode darf nicht leer sein.',
			);
		}

		$inserted = $this->wpdb->insert( $this->table, $fields );

		if ( ! $inserted ) {
			return array(
				'success' => false,
				'data'    => 'Gutschein konnte nicht erstellt werden. Code existiert möglicherweise bereits.',
			);
		}

		return array(
			'success' => true,
			'data'    => $this->wpdb->insert_id,
		);
	}

	/**
	 * Aktualisiert einen bestehenden Gutschein.
	 *
	 * @since 1.1.7
	 * @param int   $id   Die Gutschein-ID.
	 * @param array $data Zu aktualisierende Felder.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function update( int $id, array $data ): bool {
		$allowed = array( 'code', 'discount_type', 'discount_value', 'max_uses', 'expires_at', 'is_active' );
		$fields  = array();

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				if ( 'is_active' === $key ) {
					$fields[ $key ] = ! empty( $data[ $key ] ) ? 1 : 0;
				} elseif ( 'discount_value' === $key ) {
					$fields[ $key ] = (float) $data[ $key ];
				} elseif ( 'max_uses' === $key ) {
					$fields[ $key ] = (int) $data[ $key ];
				} elseif ( 'discount_type' === $key ) {
					$fields[ $key ] = in_array( $data[ $key ], array( 'fixed', 'percent' ), true )
						? $data[ $key ]
						: 'fixed';
				} else {
					$fields[ $key ] = $data[ $key ];
				}
			}
		}

		if ( empty( $fields ) ) {
			return false;
		}

		$updated = $this->wpdb->update( $this->table, $fields, array( 'id' => $id ) );

		return false !== $updated;
	}

	/**
	 * Löscht einen Gutschein.
	 *
	 * @since 1.1.7
	 * @param int $id Die Gutschein-ID.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete( $this->table, array( 'id' => $id ) );

		return false !== $deleted;
	}
}
