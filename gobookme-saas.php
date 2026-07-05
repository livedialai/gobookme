<?php
/**
 * Plugin Name: Dinia – GoBookMe SaaS
 * Plugin URI:  https://dinia.gomeetme.com
 * Description: Multi-User-Reservierungssystem für Restaurants. Mollie Billing, JS-Widget, API-Key-Auth.
 * Version:     1.1.7
 * Author:      Dinia (GoFonIA)
 * Text Domain: dinia
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires WP:  6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DINIA_VERSION', '1.1.7' );
define( 'DINIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DINIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload
require_once DINIA_PLUGIN_DIR . 'includes/class-tenant.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-plans.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-customers.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-subscriptions.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-invoices.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-backup.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-mollie.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-booking.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-caldav.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-mailer.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-admin.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-signup.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-account.php';
require_once DINIA_PLUGIN_DIR . 'includes/class-coupon.php';

// WP Cron Hook for reminder emails
add_action( 'dinia_reminder_event', array( 'DINA_Booking', 'send_reminder_email' ) );

// Bootstrap
new DINA_REST_API();
new DINA_Admin();
new DINA_Account();
new DINA_Signup();
register_activation_hook( __FILE__, 'dinia_activate' );

/**
 * Activation: Create/upgrade DB tables.
 */
function dinia_activate() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_plans (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        description text,
        price_monthly decimal(10,2) NOT NULL DEFAULT 0.00,
        price_yearly decimal(10,2) NOT NULL DEFAULT 0.00,
        mollie_price_id varchar(100) DEFAULT '',
        max_tables int NOT NULL DEFAULT 5,
        max_reservations_day int NOT NULL DEFAULT 50,
        max_employees int NOT NULL DEFAULT 1,
        features text,
        sort_order int NOT NULL DEFAULT 0,
        active tinyint(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_customers (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        company varchar(200) NOT NULL DEFAULT '',
        slug varchar(100) NOT NULL,
        email varchar(200) NOT NULL,
        contact_name varchar(200) DEFAULT '',
        contact_phone varchar(50) DEFAULT '',
        api_key_hash varchar(64) NOT NULL DEFAULT '',
        api_key_hint varchar(8) DEFAULT '',
        plan_id mediumint(9) DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        mollie_customer_id varchar(100) DEFAULT '',
        settings text,
        confirm_token varchar(64) DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_subscriptions (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_id mediumint(9) NOT NULL,
        plan_id mediumint(9) NOT NULL,
        interval_type varchar(10) NOT NULL DEFAULT 'month',
        status varchar(20) NOT NULL DEFAULT 'active',
        mollie_subscription_id varchar(100) DEFAULT '',
        current_period_start date NOT NULL,
        current_period_end date NOT NULL,
        trial_end date DEFAULT NULL,
        cancelled_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY customer_id (customer_id)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_invoices (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_id mediumint(9) NOT NULL,
        subscription_id mediumint(9) DEFAULT NULL,
        mollie_payment_id varchar(100) DEFAULT '',
        amount decimal(10,2) NOT NULL,
        currency varchar(3) NOT NULL DEFAULT 'EUR',
        status varchar(20) NOT NULL DEFAULT 'pending',
        description varchar(255) DEFAULT '',
        paid_at datetime DEFAULT NULL,
        invoice_pdf_url varchar(500) DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY customer_id (customer_id)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_backups (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        filename varchar(255) NOT NULL,
        filesize bigint NOT NULL DEFAULT 0,
        type varchar(20) NOT NULL DEFAULT 'manual',
        status varchar(20) NOT NULL DEFAULT 'completed',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";
    dbDelta( $sql );

    // Coupon table
    $coupon = new DINA_Coupon();
    $coupon->create_table();

    // Coupon-Code-Spalte in customers-Tabelle sicherstellen
    $row = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}dinia_customers LIKE 'coupon_code'" );
    if ( empty( $row ) ) {
        $wpdb->query( "ALTER TABLE {$wpdb->prefix}dinia_customers ADD COLUMN coupon_code VARCHAR(50) NOT NULL DEFAULT '' AFTER confirm_token" );
    }

    // Booking tables (tables, reservations, hours)
    $sql = "CREATE TABLE {$wpdb->prefix}dinia_tables (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_id mediumint(9) NOT NULL DEFAULT 0,
        name varchar(100) NOT NULL,
        seats tinyint(2) NOT NULL DEFAULT 2,
        position varchar(20) NOT NULL DEFAULT 'indoor',
        active tinyint(1) NOT NULL DEFAULT 1,
        combinable tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY customer_id (customer_id)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_reservations (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_id mediumint(9) NOT NULL DEFAULT 0,
        table_id mediumint(9) NOT NULL,
        table_ids varchar(50) NOT NULL DEFAULT '',
        date date NOT NULL,
        time_start time NOT NULL,
        time_end time NOT NULL,
        guest_name varchar(200) NOT NULL DEFAULT '',
        guest_email varchar(200) NOT NULL DEFAULT '',
        guest_phone varchar(50) NOT NULL DEFAULT '',
        guest_count tinyint(2) NOT NULL DEFAULT 2,
        notes text,
        status varchar(20) NOT NULL DEFAULT 'confirmed',
        source varchar(20) NOT NULL DEFAULT 'api',
        caldav_uid varchar(100) NOT NULL DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY customer_id (customer_id),
        KEY date_idx (date, customer_id)
    ) $charset;";
    dbDelta( $sql );

    $sql = "CREATE TABLE {$wpdb->prefix}dinia_hours (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_id mediumint(9) NOT NULL DEFAULT 0,
        day_key varchar(3) NOT NULL,
        open time NOT NULL,
        close time NOT NULL,
        open2 time DEFAULT NULL,
        close2 time DEFAULT NULL,
        closed tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY customer_id (customer_id),
        UNIQUE KEY day_per_customer (customer_id, day_key)
    ) $charset;";
    dbDelta( $sql );

    // Default plan – nur 19,95€ Basic
    $plans = get_option( 'dinia_default_plans_installed', false );
    if ( ! $plans ) {
        $wpdb->insert( $wpdb->prefix . 'dinia_plans', [
            'name'             => 'Dinia Basic',
            'description'      => 'Alles inklusive – 19,95€ pro Monat',
            'price_monthly'    => 19.95,
            'price_yearly'     => 0.00,
            'max_tables'       => 999,
            'max_reservations_day' => 999,
            'max_employees'    => 999,
            'features'         => json_encode( [ 'caldav', 'email', 'multi_caldav', 'voice', 'table_combine' ] ),
            'sort_order'       => 1,
        ] );
        update_option( 'dinia_default_plans_installed', true );
    }

    // Demo-Tische anlegen (nur wenn keine existieren)
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}dinia_tables'" );
    if ( $table_exists ) {
        $has_tables = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dinia_tables WHERE customer_id = 0" );
        if ( ! $has_tables ) {
            $demo_tables = [
                ['name' => 'Tisch 1', 'seats' => 2, 'position' => 'indoor', 'combinable' => 1],
                ['name' => 'Tisch 2', 'seats' => 2, 'position' => 'indoor', 'combinable' => 1],
                ['name' => 'Tisch 3', 'seats' => 4, 'position' => 'indoor', 'combinable' => 0],
                ['name' => 'Tisch 4', 'seats' => 4, 'position' => 'indoor', 'combinable' => 0],
                ['name' => 'Tisch 5', 'seats' => 6, 'position' => 'indoor', 'combinable' => 0],
                ['name' => 'Tisch 6', 'seats' => 6, 'position' => 'indoor', 'combinable' => 0],
                ['name' => 'Terrasse 1', 'seats' => 4, 'position' => 'outdoor', 'combinable' => 1],
                ['name' => 'Terrasse 2', 'seats' => 4, 'position' => 'outdoor', 'combinable' => 1],
                ['name' => 'Bar', 'seats' => 2, 'position' => 'bar', 'combinable' => 0],
            ];
            foreach ( $demo_tables as $t ) {
                $wpdb->insert( $wpdb->prefix . 'dinia_tables', [
                    'customer_id' => 0, 'name' => $t['name'], 'seats' => $t['seats'],
                    'position' => $t['position'], 'combinable' => $t['combinable'], 'active' => 1,
                ] );
            }
        }
    }

    // Flush rewrite rules
    flush_rewrite_rules();
}

// Activation on every admin request (for updates)
add_action( 'init', 'dinia_activate' );

/**
 * Load text domain.
 */
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'dinia', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Clean up on uninstall.
 */
register_uninstall_hook( __FILE__, 'dinia_uninstall' );
function dinia_uninstall() {
    global $wpdb;
    $tables = [ 'dinia_plans', 'dinia_customers', 'dinia_subscriptions', 'dinia_invoices', 'dinia_backups', 'dinia_coupons', 'dinia_tables', 'dinia_reservations', 'dinia_hours' ];
    foreach ( $tables as $t ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t}" );
    }
    delete_option( 'dinia_default_plans_installed' );
    delete_option( 'dinia_mollie_api_key' );
    delete_option( 'dinia_mollie_profile_id' );
    delete_option( 'dinia_turnstile_site_key' );
    delete_option( 'dinia_turnstile_secret_key' );
}

/**
 * Enqueue widget CSS/JS for shortcode and public pages.
 */
add_action( 'wp_enqueue_scripts', function() {
    $ver = DINIA_VERSION;
    wp_register_style( 'dinia-widget', DINIA_PLUGIN_URL . 'assets/widget.css', [], $ver );
    wp_register_script( 'dinia-widget', DINIA_PLUGIN_URL . 'assets/widget.js', [], $ver, true );
} );

/**
 * Shortcode [dinia_booking] für Client-Plugin-Ersatz.
 * Nutzt data-tenant aus Query-Parameter ?tid= oder shortcode-Attribut.
 */
add_shortcode( 'dinia_booking', function( $atts ) {
    // Tenant aus Attribut (normale Quotes), Positional oder ?tid= ermitteln
    $tenant = '';

    // 1. Attribut [dinia_booking tenant="test-restaurant"]
    $a = shortcode_atts( [ 'tenant' => '' ], $atts );
    if ( ! empty( $a['tenant'] ) ) {
        $tenant = $a['tenant'];
    }

    // 2. Positional [dinia_booking test-restaurant]
    if ( ! $tenant && is_array( $atts ) && isset( $atts[0] ) && is_string( $atts[0] ) && trim( $atts[0] ) ) {
        $tenant = trim( $atts[0] );
    }

    // 3. GET-Parameter ?tid=
    if ( ! $tenant ) {
        $tenant = $_GET['tid'] ?? '';
    }

    if ( ! $tenant ) {
        return '<p style="color:#999;">Dinia: Bitte Tenant-ID angeben (z.B. <code>[dinia_booking tenant="mein-restaurant"]</code>).</p>';
    }

    $tenant = esc_attr( $tenant );

    wp_enqueue_style( 'dinia-widget' );
    wp_enqueue_script( 'dinia-widget' );
    wp_add_inline_script( 'dinia-widget',
        'var DINIA_TENANT="' . esc_js( $tenant ) . '";',
        'before'
    );

    return '<div id="dinia-widget"></div>';
} );

/**
 * Darken a hex color by a given amount.
 */
function dinia_darken_color( $hex, $amount = 20 ) {
    $hex = ltrim( $hex, '#' );
    $r = max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount );
    $g = max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount );
    $b = max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount );
    return sprintf( '#%02x%02x%02x', $r, $g, $b );
}

/**
 * Translate position key to German label.
 */
function dinia_position_label( $pos ) {
    $labels = array( 'indoor' => 'Innen', 'outdoor' => 'Terrasse', 'bar' => 'Bar' );
    return $labels[ $pos ] ?? $pos;
}
