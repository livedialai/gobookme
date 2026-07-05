<?php
/**
 * Plan-Verwaltung für GoBookMe SaaS
 *
 * @package GoBookMe_SaaS
 * @since 1.0.0
 */

// Sicherheitsabfrage: Direkten Zugriff verhindern
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Klasse DINA_Plans
 *
 * Verwaltet Buchungs-Pläne (Tarife/Abonnements) in der Datenbank.
 * Bietet CRUD-Operationen sowie einen Join mit der customers-Tabelle
 * zur Ermittlung des aktuellen Plans eines Kunden.
 *
 * @since 1.0.0
 */
class DINA_Plans {

    /**
     * Name der Pläne-Datenbanktabelle (mit WordPress-Präfix).
     *
     * @var string
     */
    private string $table;

    /**
     * Name der Kunden-Datenbanktabelle (mit WordPress-Präfix).
     *
     * @var string
     */
    private string $customer_table;

    /**
     * Referenz auf die globale $wpdb-Instanz.
     *
     * @var wpdb
     */
    private wpdb $db;

    /**
     * Konstruktor.
     *
     * Initialisiert Datenbank-Tabellennamen und holt $wpdb.
     *
     * @since 1.0.0
     */
    public function __construct() {
        global $wpdb;

        $this->db             = $wpdb;
        $this->table          = $wpdb->prefix . 'dinia_plans';
        $this->customer_table = $wpdb->prefix . 'dinia_customers';
    }

    /**
     * Gibt alle Pläne zurück.
     *
     * @since 1.0.0
     *
     * @param string $order Sortierreihenfolge (z. B. 'id ASC', 'name ASC').
     * @return array List of plan objects.
     */
    public function get_all(string $order = 'id ASC'): array {
        $sql = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY %s",
            $order
        );

        $results = $this->db->get_results($sql);

        return is_array($results) ? $results : [];
    }

    /**
     * Ruft einen einzelnen Plan anhand seiner ID ab.
     *
     * @since 1.0.0
     *
     * @param int $id Plan-ID.
     * @return object|null Plan-Objekt oder null, wenn nicht gefunden.
     */
    public function get_by_id(int $id): ?object {
        $sql = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        );

        $result = $this->db->get_row($sql);

        return $result !== null && $result !== false ? $result : null;
    }

    /**
     * Erstellt einen neuen Plan.
     *
     * @since 1.0.0
     *
     * @param array $data Plan-Daten (z. B. name, description, price, duration, features usw.).
     *
     * @return int|false Die ID des erstellten Plans bei Erfolg, false bei Fehler.
     */
    public function create(array $data): int|false {
        $defaults = [
            'name'        => '',
            'description' => '',
            'price'       => 0.00,
            'duration'    => 30,
            'features'    => '',
            'status'      => 'active',
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $inserted = $this->db->insert($this->table, $data);

        if ($inserted === false) {
            return false;
        }

        return (int) $this->db->insert_id;
    }

    /**
     * Aktualisiert einen vorhandenen Plan.
     *
     * @since 1.0.0
     *
     * @param int   $id   Plan-ID.
     * @param array $data Zu aktualisierende Daten (Spaltenname => Wert).
     *
     * @return bool True bei Erfolg, false bei Fehler.
     */
    public function update(int $id, array $data): bool {
        if (empty($data)) {
            return false;
        }

        // Automatisch updated_at setzen, falls nicht explizit übergeben
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }

        $updated = $this->db->update(
            $this->table,
            $data,
            ['id' => $id]
        );

        return $updated !== false;
    }

    /**
     * Löscht einen Plan anhand seiner ID.
     *
     * @since 1.0.0
     *
     * @param int $id Plan-ID.
     *
     * @return bool True bei Erfolg, false bei Fehler.
     */
    public function delete(int $id): bool {
        $deleted = $this->db->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );

        return $deleted !== false;
    }

    /**
     * Ruft den aktuellen Plan eines Kunden ab.
     *
     * Führt einen JOIN mit der customers-Tabelle durch, um den
     * Plan eines bestimmten Kunden zu ermitteln. Es wird davon
     * ausgegangen, dass die customers-Tabelle eine Spalte
     * `plan_id` enthält, die auf `dina_plans.id` verweist.
     *
     * @since 1.0.0
     *
     * @param int $customer_id Kunden-ID.
     *
     * @return object|null Plan-Objekt oder null, wenn kein Plan gefunden wurde.
     */
    public function get_customer_plan(int $customer_id): ?object {
        $sql = $this->db->prepare(
            "SELECT p.*
             FROM {$this->table} AS p
             INNER JOIN {$this->customer_table} AS c
                 ON c.plan_id = p.id
             WHERE c.id = %d
             LIMIT 1",
            $customer_id
        );

        $result = $this->db->get_row($sql);

        return $result !== null && $result !== false ? $result : null;
    }
}
