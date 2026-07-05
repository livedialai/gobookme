<?php
/**
 * DINA_Backup – Datenbank-Backup-Verwaltung für GoBookMe SaaS
 *
 * Erstellt MySQL-Dumps via mysqldump, speichert sie als ZIP-Dateien
 * und protokolliert alle Vorgänge in der WordPress-Datenbank.
 *
 * @package GoBookMe_SaaS
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasse DINA_Backup
 */
class DINA_Backup {

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
	 * Backup-Verzeichnis (absoluter Pfad).
	 *
	 * @var string
	 */
	private $backup_dir;

	/**
	 * Backup-Verzeichnis-URL.
	 *
	 * @var string
	 */
	private $backup_url;

	/**
	 * Konstruktor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb       = $wpdb;
		$this->table      = $wpdb->prefix . 'dinia_backups';
		$this->backup_dir = WP_CONTENT_DIR . '/uploads/dinia-backups/';
		$this->backup_url = WP_CONTENT_URL . '/uploads/dinia-backups/';
	}

	/**
	 * Datenbank-Backup erstellen.
	 *
	 * Liest DB-Zugangsdaten aus den WordPress-Konstanten (DB_NAME, DB_USER,
	 * DB_PASSWORD, DB_HOST), führt mysqldump aus und speichert das Ergebnis
	 * als ZIP-Datei im Uploads-Verzeichnis.
	 *
	 * @param string $type Backup-Typ (z. B. 'manual' oder 'scheduled').
	 * @return array|false Array mit Backup-Daten bei Erfolg, false bei Fehler.
	 */
	public function create_backup( $type = 'manual' ) {
		// Sicherstellen, dass das Backup-Verzeichnis existiert.
		if ( ! is_dir( $this->backup_dir ) ) {
			wp_mkdir_p( $this->backup_dir );
		}

		// Prüfen, ob mysqldump ausführbar ist.
		$mysqldump_path = $this->find_mysqldump();
		if ( ! $mysqldump_path ) {
			$this->log_error( 'mysqldump not found or not executable.' );
			return false;
		}

		// DB-Zugangsdaten aus den WordPress-Konstanten.
		$db_host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
		$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$db_user = defined( 'DB_USER' ) ? DB_USER : '';
		$db_pass = defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '';

		if ( empty( $db_name ) || empty( $db_user ) ) {
			$this->log_error( 'Database credentials not found.' );
			return false;
		}

		// Temporäre SQL-Datei.
		$tmp_sql = wp_tempnam( 'dinia-backup-' . gmdate( 'Y-m-d-Hi' ), sys_get_temp_dir() );
		if ( ! $tmp_sql ) {
			$this->log_error( 'Could not create temporary file.' );
			return false;
		}

		// Dateiname und Pfad für das finale ZIP.
		$filename    = 'dinia-' . gmdate( 'Y-m-d-Hi' ) . '.zip';
		$backup_path = $this->backup_dir . $filename;

		// Port aus DB_HOST extrahieren (z. B. "localhost:3306").
		$host_parts = explode( ':', $db_host, 2 );
		$host       = $host_parts[0];
		$port       = isset( $host_parts[1] ) ? (int) $host_parts[1] : 0;

		// mysqldump-Kommando bauen.
		$cmd = escapeshellcmd( $mysqldump_path )
			. ' --host=' . escapeshellarg( $host )
			. ' --user=' . escapeshellarg( $db_user )
			. ' --password=' . escapeshellarg( $db_pass );

		if ( $port > 0 ) {
			$cmd .= ' --port=' . (int) $port;
		}

		// Zeichensatz und Optionen.
		$cmd .= ' --opt --default-character-set=utf8mb4 --routines --events --triggers'
			. ' ' . escapeshellarg( $db_name )
			. ' > ' . escapeshellarg( $tmp_sql ) . ' 2>&1';

		exec( $cmd, $output_lines, $exit_code );

		if ( 0 !== $exit_code ) {
			// Fehlschlag – temporäre Datei aufräumen.
			$this->cleanup_temp_file( $tmp_sql );
			$error_msg = ! empty( $output_lines )
				? 'mysqldump failed: ' . implode( "\n", $output_lines )
				: 'mysqldump failed with exit code ' . $exit_code;
			$this->log_error( $error_msg );
			return false;
		}

		// SQL-Datei in ZIP packen.
		$zip = new ZipArchive();
		$zip_open = $zip->open( $backup_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		if ( true !== $zip_open ) {
			$this->cleanup_temp_file( $tmp_sql );
			$this->log_error( 'Could not create ZIP archive (ZipArchive error: ' . $zip_open . ').' );
			return false;
		}

		// SQL-Datei ohne Pfad ins ZIP legen (Name: backup.sql).
		$zip->addFile( $tmp_sql, 'backup.sql' );
		$zip->close();

		// Temporäre SQL-Datei löschen.
		$this->cleanup_temp_file( $tmp_sql );

		// Dateigröße ermitteln.
		$filesize = file_exists( $backup_path ) ? filesize( $backup_path ) : 0;

		// Log-Eintrag in der Datenbank.
		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'filename'   => $filename,
				'filesize'   => $filesize,
				'type'       => $type,
				'status'     => 'completed',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// Backup-Datei existiert, aber DB-Eintrag fehlgeschlagen – trotzdem melden.
			return array(
				'id'       => 0,
				'filename' => $filename,
				'filesize' => $filesize,
				'type'     => $type,
				'status'   => 'completed',
				'path'     => $backup_path,
				'url'      => $this->backup_url . $filename,
			);
		}

		return array(
			'id'       => (int) $this->wpdb->insert_id,
			'filename' => $filename,
			'filesize' => $filesize,
			'type'     => $type,
			'status'   => 'completed',
			'path'     => $backup_path,
			'url'      => $this->backup_url . $filename,
		);
	}

	/**
	 * Alle Backup-Einträge abrufen.
	 *
	 * Gibt ein Array von Backup-Objekten aus der Datenbank zurück.
	 * Jedes Objekt enthält: id, filename, filesize, type, status, created_at.
	 *
	 * @param int $limit  Maximale Anzahl Einträge (0 = alle).
	 * @return array Array von Backup-Objekten.
	 */
	public function list_backups( $limit = 0 ) {
		if ( $limit > 0 ) {
			return $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
					(int) $limit
				)
			);
		}

		return $this->wpdb->get_results(
			"SELECT * FROM {$this->table} ORDER BY created_at DESC"
		);
	}

	/**
	 * Backup-Eintrag löschen.
	 *
	 * Entfernt den Datenbank-Eintrag und die dazugehörige ZIP-Datei.
	 *
	 * @param int $id Backup-ID.
	 * @return bool True bei Erfolg, false bei Fehler.
	 */
	public function delete_backup( $id ) {
		$backup = $this->get_by_id( $id );
		if ( ! $backup ) {
			return false;
		}

		// Datenbank-Eintrag löschen.
		$deleted = $this->wpdb->delete(
			$this->table,
			array( 'id' => (int) $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return false;
		}

		// Dazugehörige Datei löschen.
		$file_path = $this->backup_dir . $backup->filename;
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}

		return true;
	}

	/**
	 * Einzelnes Backup-Objekt nach ID abrufen.
	 *
	 * @param int $id Backup-ID.
	 * @return object|null Backup-Objekt oder null.
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
	 * mysqldump-Pfad ermitteln.
	 *
	 * Prüft, ob mysqldump über den System-PATH verfügbar ist.
	 * Fallback: sucht in typischen PHP-/MySQL-Pfaden.
	 *
	 * @return string|false Pfad zu mysqldump oder false.
	 */
	private function find_mysqldump() {
		// 1. Direkt über which/command -v prüfen.
		$paths = array(
			'mysqldump',
			'/usr/bin/mysqldump',
			'/usr/local/bin/mysqldump',
			'/usr/bin/mariadb-dump',
			'/usr/local/mysql/bin/mysqldump',
			'/opt/homebrew/bin/mysqldump',
		);

		foreach ( $paths as $path ) {
			$test_cmd = 'command -v ' . escapeshellarg( $path ) . ' 2>/dev/null';
			if ( 'mysqldump' === $path ) {
				$output = trim( shell_exec( $test_cmd ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
				if ( ! empty( $output ) && is_executable( $output ) ) {
					return $output;
				}
			} elseif ( is_executable( $path ) ) {
				return $path;
			}
		}

		return false;
	}

	/**
	 * Temporäre Datei sicher löschen.
	 *
	 * @param string $file_path Pfad zur temporären Datei.
	 * @return void
	 */
	private function cleanup_temp_file( $file_path ) {
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}

	/**
	 * Fehler im WordPress-Debug-Log protokollieren.
	 *
	 * @param string $message Fehlermeldung.
	 * @return void
	 */
	private function log_error( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'DINA_Backup: ' . $message );
	}
}
