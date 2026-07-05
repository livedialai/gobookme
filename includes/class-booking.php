<?php
/**
 * Multi-Tenant-Booking-Engine für Dinia (GoBookMe SaaS)
 *
 * Stellt alle Methoden für Slot-Generierung, Tischverwaltung,
 * Reservierungs-CRUD und Tischzuweisung bereit.
 * Alle Abfragen werden per customer_id auf den aktiven Mandanten gefiltert.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Booking
 *
 * @since 1.0.0
 */
class DINA_Booking {

	/**
	 * Löst die Customer-ID auf. Falls $customer_id = 0,
	 * wird DINA_Tenant::get() verwendet.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Übergebene Customer-ID.
	 * @return int|null Aufgelöste Customer-ID oder null.
	 */
	private static function resolve_customer_id( $customer_id = 0 ) {
		if ( empty( $customer_id ) ) {
			$customer_id = DINA_Tenant::instance()->get();
		}
		return $customer_id;
	}

	/**
	 * Holt die Einstellungen eines Mandanten aus der DB.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Customer-ID.
	 * @return array Settings-Array.
	 */
	public static function get_settings( $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return self::get_default_settings();
		}

		$table    = $wpdb->prefix . 'dinia_customers';
		$settings = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT settings FROM {$table} WHERE id = %d LIMIT 1",
				$customer_id
			)
		);

		if ( empty( $settings ) ) {
			return self::get_default_settings();
		}

		$decoded = json_decode( $settings, true );
		if ( ! is_array( $decoded ) ) {
			return self::get_default_settings();
		}

		return wp_parse_args( $decoded, self::get_default_settings() );
	}

	/**
	 * Gibt den Tages-Kürzel (mon, tue, …) für ein Datum zurück.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date_str Datum (Y-m-d oder von strtotime verarbeitbar).
	 * @return string day_key (mon–sun).
	 */
	private static function get_day_key( $date_str ) {
		$ts   = strtotime( $date_str );
		$days = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
		return $days[ (int) date( 'w', $ts ) ];
	}

	/**
	 * Holt die Öffnungszeiten für einen bestimmten Tag.
	 *
	 * Liest aus der dinia_hours-Tabelle für den Mandanten.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date_str   Datum im Format Y-m-d.
	 * @param int    $customer_id Customer-ID (0 = automatisch via DINA_Tenant).
	 * @return array|false Array mit open/close/open2/close2 oder false, wenn geschlossen.
	 */
	public static function get_hours_for_date( $date_str, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return false;
		}

		$day_key = self::get_day_key( $date_str );
		$table   = $wpdb->prefix . 'dinia_hours';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT open, close, open2, close2, closed
				 FROM {$table}
				 WHERE customer_id = %d AND day_key = %s
				 LIMIT 1",
				$customer_id,
				$day_key
			)
		);

		if ( ! $row || ! empty( $row->closed ) ) {
			return false;
		}

		return array(
			'open'   => $row->open,
			'close'  => $row->close,
			'open2'  => $row->open2,
			'close2' => $row->close2,
		);
	}

	/**
	 * Generiert alle buchbaren Zeit-Slots für einen Tag.
	 *
	 * Unterstützt zwei Öffnungsblöcke (z. B. Mittag + Abend).
	 * Slot-Dauer und Intervall werden aus den Mandanten-Einstellungen gelesen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date_str   Datum im Format Y-m-d.
	 * @param int    $customer_id Customer-ID (0 = automatisch).
	 * @return array Liste der Slots mit 'start' und 'end' (H:i).
	 */
	public static function get_time_slots( $date_str, $customer_id = 0 ) {
		$hours = self::get_hours_for_date( $date_str, $customer_id );
		if ( ! $hours ) {
			return array();
		}

		$settings = self::get_settings( $customer_id );

		$duration = (int) ( $settings['slot_duration'] ?? 120 );
		$interval = (int) ( $settings['slot_interval'] ?? 30 );

		$slots = array();

		$blocks = array(
			array( 'open' => $hours['open'], 'close' => $hours['close'] ),
		);
		if ( ! empty( $hours['open2'] ) && ! empty( $hours['close2'] ) ) {
			$blocks[] = array( 'open' => $hours['open2'], 'close' => $hours['close2'] );
		}

		foreach ( $blocks as $block ) {
			$t       = strtotime( $block['open'] );
			$end_day = strtotime( $block['close'] );

			while ( $t + ( $duration * 60 ) <= $end_day ) {
				$slots[] = array(
					'start' => date( 'H:i', $t ),
					'end'   => date( 'H:i', $t + $duration * 60 ),
				);
				$t      += $interval * 60;
			}
		}

		return $slots;
	}

	/**
	 * Liefert alle aktiven Tische eines Mandanten.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @return array|null Liste der aktiven Tische (Object-Array).
	 */
	public static function get_active_tables( $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'dinia_tables';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE active = 1 AND customer_id = %d
				 ORDER BY seats ASC, id ASC",
				$customer_id
			)
		);
	}

	/**
	 * Findet Reservierungen, die einen bestimmten Slot überschneiden.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date_str    Datum (Y-m-d).
	 * @param string $time_start  Startzeit (H:i).
	 * @param string $time_end    Endzeit (H:i).
	 * @param int    $customer_id Customer-ID (0 = automatisch).
	 * @return array Liste der überschneidenden Reservierungen.
	 */
	public static function get_reservations_for_slot( $date_str, $time_start, $time_end, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE customer_id = %d
				 AND date = %s
				 AND status = 'confirmed'
				 AND time_start < %s
				 AND time_end > %s",
				$customer_id,
				$date_str,
				$time_end,
				$time_start
			)
		);
	}

	/**
	 * Prüft, ob ein bestimmter Tisch in einem Slot frei ist.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $table_id    Tisch-ID.
	 * @param string $date_str    Datum (Y-m-d).
	 * @param string $time_start  Startzeit (H:i).
	 * @param string $time_end    Endzeit (H:i).
	 * @param int    $customer_id Customer-ID (0 = automatisch).
	 * @return bool True, wenn der Tisch frei ist.
	 */
	public static function is_table_available( $table_id, $date_str, $time_start, $time_end, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return false;
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE customer_id = %d
				 AND table_id = %d
				 AND date = %s
				 AND status = 'confirmed'
				 AND time_start < %s
				 AND time_end > %s",
				$customer_id,
				$table_id,
				$date_str,
				$time_end,
				$time_start
			)
		);

		return $count === 0;
	}

	/**
	 * Erstellt eine neue Reservierung mit automatischer Tischzuweisung.
	 *
	 * Falls $data['table_id'] nicht gesetzt oder 0 ist, wird automatisch
	 * der erste passende Tisch (ausreichend Sitzplätze) zugewiesen.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data {
	 *     Reservierungsdaten.
	 *
	 *     @type string $date        Datum (Y-m-d). Erforderlich.
	 *     @type string $time_start  Startzeit (H:i). Erforderlich.
	 *     @type string $time_end    Endzeit (H:i). Erforderlich.
	 *     @type string $guest_name  Gastname. Erforderlich.
	 *     @type string $guest_email Gast-E-Mail.
	 *     @type string $guest_phone Gast-Telefon.
	 *     @type int    $guest_count Personenzahl. Erforderlich.
	 *     @type string $notes       Notizen.
	 *     @type int    $table_id    Tisch-ID (0 = automatisch).
	 *     @type string $table_ids   Komma-separierte Tisch-IDs für Kombination.
	 *     @type string $source      Quelle (default 'api').
	 * }
	 * @param int   $customer_id Customer-ID (0 = automatisch).
	 * @return int|WP_Error Reservierungs-ID bei Erfolg, WP_Error bei Fehler.
	 */
	public static function create_reservation( $data, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return new WP_Error(
				'no_tenant',
				__( 'Kein Mandant identifiziert.', 'dinia' )
			);
		}

		// Pflichtfelder prüfen.
		$required = array( 'date', 'time_start', 'time_end', 'guest_name', 'guest_count' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error(
					'missing_field',
					sprintf(
						/* translators: %s: Feldname */
						__( 'Pflichtfeld fehlt: %s', 'dinia' ),
						$field
					)
				);
			}
		}

		$guest_count = (int) $data['guest_count'];
		if ( $guest_count < 1 ) {
			return new WP_Error(
				'invalid_guest_count',
				__( 'Ungültige Personenzahl.', 'dinia' )
			);
		}

		// Automatische Tischzuweisung, wenn keine Tisch-ID angegeben.
		$table_id  = ! empty( $data['table_id'] ) ? (int) $data['table_id'] : 0;
		$table_ids = ! empty( $data['table_ids'] ) ? sanitize_text_field( $data['table_ids'] ) : '';

		if ( ! $table_id && empty( $table_ids ) ) {
			$assigned = self::auto_assign_table(
				$data['date'],
				$data['time_start'],
				$data['time_end'],
				$guest_count,
				$customer_id
			);

			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}

			$table_id  = (int) $assigned['table_id'];
			$table_ids = $assigned['table_ids'];
		}

		// Verfügbarkeit prüfen.
		$ids_to_check = ! empty( $table_ids ) ? explode( ',', $table_ids ) : array( $table_id );
		if ( ! self::are_tables_available( $ids_to_check, $data['date'], $data['time_start'], $data['time_end'], $customer_id ) ) {
			return new WP_Error(
				'table_unavailable',
				__( 'Der Tisch ist nicht mehr verfügbar.', 'dinia' )
			);
		}

		// Reservierung einfügen.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'dinia_reservations',
			array(
				'customer_id' => $customer_id,
				'table_id'    => $table_id,
				'table_ids'   => $table_ids,
				'date'        => sanitize_text_field( $data['date'] ),
				'time_start'  => sanitize_text_field( $data['time_start'] ),
				'time_end'    => sanitize_text_field( $data['time_end'] ),
				'guest_name'  => sanitize_text_field( $data['guest_name'] ),
				'guest_email' => sanitize_email( $data['guest_email'] ?? '' ),
				'guest_phone' => sanitize_text_field( $data['guest_phone'] ?? '' ),
				'guest_count' => $guest_count,
				'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
				'status'      => 'confirmed',
				'source'      => ! empty( $data['source'] ) ? sanitize_text_field( $data['source'] ) : 'api',
			)
		);

		if ( ! $inserted ) {
			return new WP_Error(
				'db_error',
				__( 'Fehler beim Speichern der Reservierung.', 'dinia' )
			);
		}

		$res_id = (int) $wpdb->insert_id;

		self::schedule_reminder( $res_id, $data['date'], $data['time_start'] );
		self::send_confirmation_email( $res_id );
		self::send_admin_notification( $res_id );

		return $res_id;
	}

	/**
	 * Automatische Tischzuweisung: findet einen passenden Tisch
	 * oder eine Kombination aus kombinierbaren Tischen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date_str    Datum (Y-m-d).
	 * @param string $time_start  Startzeit (H:i).
	 * @param string $time_end    Endzeit (H:i).
	 * @param int    $guest_count Personenzahl.
	 * @param int    $customer_id Customer-ID.
	 * @return array|WP_Error Array mit table_id + table_ids bei Erfolg.
	 */
	private static function auto_assign_table( $date_str, $time_start, $time_end, $guest_count, $customer_id ) {
		$tables = self::get_active_tables( $customer_id );
		if ( empty( $tables ) ) {
			return new WP_Error(
				'no_tables',
				__( 'Keine aktiven Tische verfügbar.', 'dinia' )
			);
		}

		$reserved = self::get_reservations_for_slot( $date_str, $time_start, $time_end, $customer_id );

		$taken_ids = array();
		foreach ( $reserved as $r ) {
			$taken_ids[] = (int) $r->table_id;
			if ( ! empty( $r->table_ids ) ) {
				foreach ( explode( ',', $r->table_ids ) as $tid ) {
					$taken_ids[] = (int) $tid;
				}
			}
		}
		$taken_ids = array_unique( $taken_ids );

		// 1. Einzeltisch mit ausreichend Plätzen.
		foreach ( $tables as $t ) {
			if ( (int) $t->seats >= $guest_count && ! in_array( (int) $t->id, $taken_ids, true ) ) {
				return array(
					'table_id'  => (int) $t->id,
					'table_ids' => (string) $t->id,
				);
			}
		}

		// 2. Kombinierbare Tische.
		$available_combinable = array();
		foreach ( $tables as $t ) {
			if ( ! empty( $t->combinable ) && ! in_array( (int) $t->id, $taken_ids, true ) ) {
				$available_combinable[] = $t;
			}
		}

		if ( count( $available_combinable ) >= 2 ) {
			$combo = self::find_combination( $available_combinable, $guest_count );
			if ( null !== $combo ) {
				$ids = array();
				foreach ( $combo as $ct ) {
					$ids[] = (int) $ct->id;
				}
				return array(
					'table_id'  => $ids[0],
					'table_ids' => implode( ',', $ids ),
				);
			}
		}

		return new WP_Error(
			'no_table_available',
			__( 'Kein passender Tisch für diese Personenzahl verfügbar.', 'dinia' )
		);
	}

	/**
	 * Findet eine Kombination von Tischen, die zusammen genug Plätze bieten.
	 *
	 * @since 1.0.0
	 *
	 * @param array $available_combinable Liste verfügbarer, kombinierbarer Tische.
	 * @param int   $party                Benötigte Personenzahl.
	 * @return array|null Array mit Tisch-Objekten oder null.
	 */
	private static function find_combination( $available_combinable, $party ) {
		usort(
			$available_combinable,
			function ( $a, $b ) {
				return $b->seats - $a->seats;
			}
		);

		$selected = array();
		$total    = 0;

		foreach ( $available_combinable as $t ) {
			$selected[] = $t;
			$total     += (int) $t->seats;

			if ( $total >= $party ) {
				return $selected;
			}
		}

		return null;
	}

	/**
	 * Prüft, ob mehrere Tische in einem Slot verfügbar sind.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $table_ids  Liste der Tisch-IDs.
	 * @param string $date_str   Datum (Y-m-d).
	 * @param string $time_start Startzeit (H:i).
	 * @param string $time_end   Endzeit (H:i).
	 * @param int    $customer_id Customer-ID (0 = automatisch).
	 * @return bool True, wenn alle Tische verfügbar sind.
	 */
	public static function are_tables_available( $table_ids, $date_str, $time_start, $time_end, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id || empty( $table_ids ) ) {
			return false;
		}

		$ids           = array_map( 'intval', $table_ids );
		$placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table         = $wpdb->prefix . 'dinia_reservations';

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE customer_id = %d
			 AND table_id IN ({$placeholders})
			 AND date = %s
			 AND status = 'confirmed'
			 AND time_start < %s
			 AND time_end > %s",
			array_merge( array( $customer_id ), $ids, array( $date_str, $time_end, $time_start ) )
		);

		return (int) $wpdb->get_var( $sql ) === 0;
	}

	/**
	 * Storniert eine Reservierung (setzt status = 'cancelled').
	 *
	 * @since 1.0.0
	 *
	 * @param int $id          Reservierungs-ID.
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @return bool|WP_Error True bei Erfolg, WP_Error bei Fehler.
	 */
	public static function cancel_reservation( $id, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return new WP_Error(
				'no_tenant',
				__( 'Kein Mandant identifiziert.', 'dinia' )
			);
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		$updated = $wpdb->update(
			$table,
			array( 'status' => 'cancelled' ),
			array(
				'id'          => (int) $id,
				'customer_id' => $customer_id,
			)
		);

		if ( false === $updated ) {
			return new WP_Error(
				'db_error',
				__( 'Fehler beim Stornieren der Reservierung.', 'dinia' )
			);
		}

		if ( 0 === $updated ) {
			return new WP_Error(
				'not_found',
				__( 'Reservierung nicht gefunden oder bereits storniert.', 'dinia' )
			);
		}

		return true;
	}

	/**
	 * Ruft eine einzelne Reservierung ab.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id          Reservierungs-ID.
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @return object|null Reservierungs-Objekt oder null.
	 */
	public static function get_reservation( $id, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return null;
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE id = %d AND customer_id = %d
				 LIMIT 1",
				(int) $id,
				$customer_id
			)
		);
	}

	/**
	 * Holt die nächsten (bevorstehenden) Reservierungen eines Mandanten.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @param int $limit       Maximale Anzahl (default 20).
	 * @return array Liste der Reservierungs-Objekte.
	 */
	public static function get_upcoming( $customer_id = 0, $limit = 20 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'dinia_reservations';
		$limit = max( 1, (int) $limit );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE customer_id = %d
				 AND status = 'confirmed'
				 AND (date > CURDATE() OR (date = CURDATE() AND time_start >= CURTIME()))
				 ORDER BY date ASC, time_start ASC
				 LIMIT %d",
				$customer_id,
				$limit
			)
		);
	}

	/**
	 * Zählt die Reservierungen eines Mandanten für den heutigen Tag.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @return int Anzahl der Reservierungen heute.
	 */
	public static function get_today_count( $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return 0;
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE customer_id = %d
				 AND date = CURDATE()
				 AND status = 'confirmed'",
				$customer_id
			)
		);
	}

	/**
	 * Gibt die Standard-Einstellungen für einen Mandanten zurück.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default-Settings.
	 */
	public static function get_default_settings() {
		return array(
			'slot_duration'      => 120,
			'slot_interval'      => 30,
			'max_advance_days'   => 30,
			'min_advance_hours'  => 2,
			'restaurant_name'    => '',

			'email_reminder'     => 0,
			'reminder_hours'     => 24,
			'email_confirm'      => 1,
			'admin_notify_email' => '',
		);
	}

	/**
	 * Ruft eine Reservierung mit zugehörigem Tisch-Namen ab.
	 *
	 * @since 1.0.0
	 *
	 * @param int $res_id      Reservierungs-ID.
	 * @param int $customer_id Customer-ID (0 = automatisch).
	 * @return object|null Reservierungs-Objekt mit table_name oder null.
	 */
	public static function get_reservation_data( $res_id, $customer_id = 0 ) {
		global $wpdb;

		$customer_id = self::resolve_customer_id( $customer_id );
		if ( ! $customer_id ) {
			return null;
		}

		$table = $wpdb->prefix . 'dinia_reservations';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.*, t.name AS table_name
				 FROM {$table} r
				 LEFT JOIN {$wpdb->prefix}dinia_tables t ON r.table_id = t.id
				 WHERE r.id = %d
				 LIMIT 1",
				(int) $res_id
			)
		);
	}

	/**
	 * Plant eine Erinnerungs-E-Mail per WP Cron, 24h vor Reservierungsbeginn.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $res_id    Reservierungs-ID.
	 * @param string $date_str  Datum (Y-m-d).
	 * @param string $time_start Startzeit (H:i).
	 * @return void
	 */
	public static function schedule_reminder( $res_id, $date_str, $time_start ) {
		$settings = self::get_settings();

		if ( empty( $settings['email_reminder'] ) ) {
			return;
		}

		$hours_before = (int) ( $settings['reminder_hours'] ?? 24 );
		$timestamp    = strtotime( $date_str . ' ' . $time_start ) - ( $hours_before * 3600 );

		if ( $timestamp > time() ) {
			wp_schedule_single_event( $timestamp, 'dinia_reminder_event', array( $res_id ) );
		}
	}

	/**
	 * Sendet eine HTML-Erinnerungs-E-Mail an den Gast.
	 *
	 * @since 1.0.0
	 *
	 * @param int $res_id Reservierungs-ID.
	 * @return void
	 */
	public static function send_reminder_email( $res_id ) {
		$r = self::get_reservation_data( $res_id );
		if ( ! $r ) {
			return;
		}

		$settings   = self::get_settings();
		$restaurant = ! empty( $settings['restaurant_name'] ) ? $settings['restaurant_name'] : 'Restaurant';
		$date_fmt   = date_i18n( 'd.m.Y', strtotime( $r->date ) );
		$subject    = 'Erinnerung: Ihre Reservierung morgen – ' . $restaurant;

		$plain  = "Hallo {$r->guest_name},\n\n";
		$plain .= "nur zur Erinnerung – Sie haben morgen eine Reservierung:\n\n";
		$plain .= "Restaurant: {$restaurant}\n";
		$plain .= "Datum: {$date_fmt}\n";
		$plain .= "Uhrzeit: {$r->time_start} – {$r->time_end}\n";
		$plain .= "Tisch: {$r->table_name}\n";
		$plain .= "Personen: {$r->guest_count}\n";
		$plain .= "\nWir freuen uns auf Ihren Besuch!\n";

		$html_content = '
<h2>🔔 Reservierungs-Erinnerung</h2>
<p>Hallo <strong>' . esc_html( $r->guest_name ) . '</strong>,</p>
<p>nur zur Erinnerung – Sie haben morgen eine Reservierung bei uns:</p>
<div class="details">
<p><strong>Restaurant:</strong> ' . esc_html( $restaurant ) . '</p>
<p><strong>Datum:</strong> ' . esc_html( $date_fmt ) . '</p>
<p><strong>Uhrzeit:</strong> ' . esc_html( $r->time_start ) . ' – ' . esc_html( $r->time_end ) . '</p>
<p><strong>Tisch:</strong> ' . esc_html( $r->table_name ) . '</p>
<p><strong>Personen:</strong> ' . (int) $r->guest_count . '</p>
</div>
<p>Wir freuen uns auf Ihren Besuch!</p>';

		$html = DINA_Mailer::build_html( $subject, $html_content, $restaurant );

		$sent = DINA_Mailer::send( $r->guest_email, $subject, $plain, $html, $settings );
		if ( $sent !== true ) {
			wp_mail( $r->guest_email, $subject, $plain );
		}
	}

	/**
	 * Sendet eine HTML-Bestätigungs-E-Mail an den Gast.
	 *
	 * @since 1.0.0
	 *
	 * @param int $res_id Reservierungs-ID.
	 * @return void
	 */
	public static function send_confirmation_email( $res_id ) {
		$settings = self::get_settings();

		if ( empty( $settings['email_confirm'] ) ) {
			return;
		}

		$r = self::get_reservation_data( $res_id );
		if ( ! $r ) {
			return;
		}

		$restaurant = ! empty( $settings['restaurant_name'] ) ? $settings['restaurant_name'] : 'Restaurant';
		$date_fmt   = date_i18n( 'd.m.Y', strtotime( $r->date ) );
		$subject    = 'Reservierungsbestätigung – ' . $restaurant;

		$plain  = "Hallo {$r->guest_name},\n\nIhre Reservierung wurde bestätigt:\n\n";
		$plain .= "Restaurant: {$restaurant}\nDatum: {$date_fmt}\n";
		$plain .= "Uhrzeit: {$r->time_start} – {$r->time_end}\n";
		$plain .= "Tisch: {$r->table_name}\nPersonen: {$r->guest_count}\n";
		if ( $r->notes ) {
			$plain .= "Bemerkung: {$r->notes}\n";
		}
		$plain .= "\nWir freuen uns auf Ihren Besuch!\n";

		$html_content = '
<h2>✅ Reservierung bestätigt</h2>
<p>Hallo <strong>' . esc_html( $r->guest_name ) . '</strong>,</p>
<p>Ihre Reservierung wurde erfolgreich bestätigt:</p>
<div class="details">
<p><strong>Restaurant:</strong> ' . esc_html( $restaurant ) . '</p>
<p><strong>Datum:</strong> ' . esc_html( $date_fmt ) . '</p>
<p><strong>Uhrzeit:</strong> ' . esc_html( $r->time_start ) . ' – ' . esc_html( $r->time_end ) . '</p>
<p><strong>Tisch:</strong> ' . esc_html( $r->table_name ) . '</p>
<p><strong>Personen:</strong> ' . (int) $r->guest_count . '</p>
' . ( $r->notes ? '<p><strong>Bemerkung:</strong> ' . esc_html( $r->notes ) . '</p>' : '' ) . '
</div>
<p>Wir freuen uns auf Ihren Besuch!</p>';

		$html = DINA_Mailer::build_html( $subject, $html_content, $restaurant );

		$sent = DINA_Mailer::send( $r->guest_email, $subject, $plain, $html, $settings );
		if ( $sent !== true ) {
			wp_mail( $r->guest_email, $subject, $plain );
		}
	}

	/**
	 * Sendet eine HTML-Benachrichtigung an den Admin bei neuer Reservierung.
	 *
	 * @since 1.0.0
	 *
	 * @param int $res_id Reservierungs-ID.
	 * @return void
	 */
	public static function send_admin_notification( $res_id ) {
		$r = self::get_reservation_data( $res_id );
		if ( ! $r ) {
			return;
		}

		$settings   = self::get_settings();
		$restaurant = ! empty( $settings['restaurant_name'] ) ? $settings['restaurant_name'] : 'Restaurant';
		$date_fmt   = date_i18n( 'd.m.Y', strtotime( $r->date ) );
		$subject    = '[Neue Reservierung] ' . $r->guest_name . ' – ' . $date_fmt . ' ' . $r->time_start;

		$plain  = "Neue Online-Reservierung:\n\n";
		$plain .= "Name: {$r->guest_name}\nE-Mail: {$r->guest_email}\n";
		$plain .= "Telefon: {$r->guest_phone}\nDatum: {$date_fmt}\n";
		$plain .= "Uhrzeit: {$r->time_start} – {$r->time_end}\n";
		$plain .= "Tisch: {$r->table_name}\nPersonen: {$r->guest_count}\n";
		if ( $r->notes ) {
			$plain .= "Bemerkung: {$r->notes}\n";
		}

		$html_content = '
<h2>📞 Neue Reservierung</h2>
<div class="details">
<p><strong>Name:</strong> ' . esc_html( $r->guest_name ) . '</p>
<p><strong>E-Mail:</strong> ' . esc_html( $r->guest_email ) . '</p>
<p><strong>Telefon:</strong> ' . esc_html( $r->guest_phone ) . '</p>
<p><strong>Datum:</strong> ' . esc_html( $date_fmt ) . '</p>
<p><strong>Uhrzeit:</strong> ' . esc_html( $r->time_start ) . ' – ' . esc_html( $r->time_end ) . '</p>
<p><strong>Tisch:</strong> ' . esc_html( $r->table_name ) . '</p>
<p><strong>Personen:</strong> ' . (int) $r->guest_count . '</p>
' . ( $r->notes ? '<p><strong>Bemerkung:</strong> ' . esc_html( $r->notes ) . '</p>' : '' ) . '
</div>';

		$html = DINA_Mailer::build_html( $subject, $html_content, $restaurant );

		$admin_email = ! empty( $settings['admin_notify_email'] ) ? $settings['admin_notify_email'] : get_option( 'admin_email' );
		$sent        = DINA_Mailer::send( $admin_email, $subject, $plain, $html, $settings );
		if ( $sent !== true ) {
			wp_mail( $admin_email, $subject, $plain );
		}
	}
}
