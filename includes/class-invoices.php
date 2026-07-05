<?php
/**
 * DINA_Invoices – Rechnungs-CRUD für GoBookMe SaaS
 *
 * Verwaltet Rechnungen (Invoices) in der WordPress-Datenbank.
 * Bietet Methoden zum Abrufen, Anlegen und Bezahlen-Markieren von Rechnungen
 * über Mollie-Transaktionen.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Invoices
 *
 * @since 1.0.0
 */
class DINA_Invoices {

	/**
	 * WordPress-Datenbank-Objekt.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Tabellenname (mit WordPress-Präfix).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Konstruktor.
	 *
	 * Initialisiert Datenbank-Tabellennamen und holt $wpdb.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->db    = $wpdb;
		$this->table = $wpdb->prefix . 'dinia_invoices';
	}

	/**
	 * Gibt alle Rechnungen zurück.
	 *
	 * Optional kann nach einer bestimmten Kunden-ID gefiltert werden.
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id Kunden-ID (0 = alle Rechnungen).
	 * @return array Liste von Rechnungs-Objekten.
	 */
	public function get_all( int $customer_id = 0 ): array {
		if ( $customer_id > 0 ) {
			$sql = $this->db->prepare(
				"SELECT * FROM {$this->table} WHERE customer_id = %d ORDER BY created_at DESC",
				$customer_id
			);
		} else {
			$sql = "SELECT * FROM {$this->table} ORDER BY created_at DESC";
		}

		$results = $this->db->get_results( $sql );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Ruft eine einzelne Rechnung anhand ihrer ID ab.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Rechnungs-ID.
	 * @return object|null Rechnungs-Objekt oder null, wenn nicht gefunden.
	 */
	public function get_by_id( int $id ): ?object {
		$sql = $this->db->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d",
			$id
		);

		$result = $this->db->get_row( $sql );

		return ( $result !== null && $result !== false ) ? $result : null;
	}

	/**
	 * Erstellt eine neue Rechnung.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Rechnungsdaten.
	 *                     Erwartet wird mindestens 'customer_id' und 'amount'.
	 *                     Optional: 'subscription_id', 'currency', 'status',
	 *                     'description', 'invoice_pdf_url'.
	 *
	 * @return int|false Die ID der erstellten Rechnung bei Erfolg, false bei Fehler.
	 */
	public function create( array $data ): int|false {
		$defaults = array(
			'customer_id'      => 0,
			'subscription_id'  => null,
			'mollie_payment_id' => '',
			'amount'           => 0.00,
			'currency'         => 'EUR',
			'status'           => 'pending',
			'description'      => '',
			'invoice_pdf_url'  => '',
			'created_at'       => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		$inserted = $this->db->insert(
			$this->table,
			array(
				'customer_id'       => (int) $data['customer_id'],
				'subscription_id'   => is_numeric( $data['subscription_id'] ) ? (int) $data['subscription_id'] : null,
				'mollie_payment_id' => sanitize_text_field( $data['mollie_payment_id'] ),
				'amount'            => (float) $data['amount'],
				'currency'          => strtoupper( sanitize_text_field( $data['currency'] ) ),
				'status'            => sanitize_text_field( $data['status'] ),
				'description'       => sanitize_text_field( $data['description'] ),
				'invoice_pdf_url'   => esc_url_raw( $data['invoice_pdf_url'] ),
				'created_at'        => $data['created_at'],
			),
			array(
				'%d',   // customer_id
				'%d',   // subscription_id
				'%s',   // mollie_payment_id
				'%f',   // amount
				'%s',   // currency
				'%s',   // status
				'%s',   // description
				'%s',   // invoice_pdf_url
				'%s',   // created_at
			)
		);

		if ( $inserted === false ) {
			return false;
		}

		return (int) $this->db->insert_id;
	}

	/**
	 * Markiert eine Rechnung als bezahlt.
	 *
	 * Sucht die Rechnung anhand der Mollie-Payment-ID und aktualisiert
	 * den Status auf 'paid', setzt den Zahlungsbetrag (falls abweichend),
	 * die Beschreibung und den Zahlungszeitpunkt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mollie_payment_id Die Mollie-Transaktions-ID.
	 * @param float  $amount            Tatsächlich gezahlter Betrag.
	 * @param string $description       Beschreibung der Zahlung.
	 *
	 * @return bool True bei Erfolg, false wenn keine Rechnung gefunden oder Update fehlschlug.
	 */
	public function mark_paid( string $mollie_payment_id, float $amount, string $description = '' ): bool {
		$sql = $this->db->prepare(
			"SELECT id FROM {$this->table} WHERE mollie_payment_id = %s LIMIT 1",
			$mollie_payment_id
		);

		$invoice_id = $this->db->get_var( $sql );

		if ( $invoice_id === null || $invoice_id === false ) {
			return false;
		}

		$updated = $this->db->update(
			$this->table,
			array(
				'status'       => 'paid',
				'amount'       => $amount,
				'description'  => sanitize_text_field( $description ),
				'paid_at'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $invoice_id ),
			array(
				'%s', // status
				'%f', // amount
				'%s', // description
				'%s', // paid_at
			),
			array( '%d' ) // where: id
		);

		return $updated !== false;
	}
}
