<?php
/**
 * CalDAV Client for Dinia (GoBookMe SaaS)
 * Syncs reservations to Infomaniak, Google Calendar, GMX, web.de, iCloud.
 *
 * @package GoBookMe_SaaS
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DINA_CalDAV {

    private $base_url;
    private $username;
    private $password;
    private $calendar;
    private $provider;

    public function __construct() {
        $this->provider  = get_option( 'dinia_caldav_provider', 'infomaniak' );
        $this->base_url  = rtrim( get_option( 'dinia_caldav_url', '' ), '/' ) . '/';
        $this->username  = get_option( 'dinia_caldav_username', '' );
        $this->password  = get_option( 'dinia_caldav_password', '' );
        $this->calendar  = get_option( 'dinia_caldav_calendar', '' );
    }

    public function is_configured() {
        return ! empty( $this->base_url ) && ! empty( $this->username ) && ! empty( $this->password ) && ! empty( $this->calendar );
    }

    public static function get_provider_info( $provider ) {
        $info = array(
            'infomaniak' => array(
                'name'     => 'Infomaniak',
                'url'      => 'https://sync.infomaniak.com/calendars/GO01132/',
                'username' => 'GO01132',
                'calendar' => 'dbc10e70-...',
                'hint'     => 'Benutzername = Kundennummer (GO…). Passwort = Account-Passwort.',
            ),
            'google' => array(
                'name'     => 'Google Calendar',
                'url'      => 'https://apidata.googleusercontent.com/caldav/v2',
                'username' => 'name@gmail.com',
                'calendar' => 'default',
                'hint'     => 'Benutzername = Gmail-Adresse. Passwort = App-Passwort.',
            ),
            'gmx' => array(
                'name'     => 'GMX',
                'url'      => 'https://caldav.gmx.net',
                'username' => 'name@gmx.de',
                'calendar' => 'default',
                'hint'     => 'Benutzername = Vollständige GMX-Adresse.',
            ),
            'webde' => array(
                'name'     => 'web.de',
                'url'      => 'https://caldav.web.de',
                'username' => 'name@web.de',
                'calendar' => 'default',
                'hint'     => 'Benutzername = Vollständige web.de-Adresse.',
            ),
            'icloud' => array(
                'name'     => 'Apple iCloud',
                'url'      => 'https://caldav.icloud.com/',
                'username' => 'appleid@icloud.com',
                'calendar' => 'default',
                'hint'     => 'Benutzername = Apple-ID. Passwort = App-Passwort.',
            ),
        );
        return $info[ $provider ] ?? $info['infomaniak'];
    }

    private function request( $method, $url, $args = array() ) {
        $defaults = array(
            'method'    => $method,
            'timeout'   => 15,
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
                'Content-Type'  => 'application/xml; charset=utf-8',
            ),
            'sslverify' => true,
        );
        $merged = array_merge_recursive( $defaults, $args );
        if ( isset( $args['headers']['Content-Type'] ) ) {
            $merged['headers']['Content-Type'] = $args['headers']['Content-Type'];
        }

        $response = wp_remote_request( $url, $merged );
        if ( is_wp_error( $response ) ) {
            error_log( '[Dinia CalDAV] Error: ' . $response->get_error_message() );
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code >= 400 ) {
            error_log( "[Dinia CalDAV] HTTP {$code} on {$method} {$url}" );
            return new WP_Error( 'caldav_error', "HTTP {$code}" );
        }
        return array( 'code' => $code, 'body' => $body );
    }

    /**
     * Create a VEVENT in the calendar.
     */
    public function create_event( $date, $time_start, $time_end, $guest_name, $guest_count, $table_name, $notes = '', $guest_email = '', $guest_phone = '' ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'not_configured', 'CalDAV nicht konfiguriert.' );
        }

        $uid = 'dinia-' . wp_generate_password( 16, false ) . '@dinia.gomeetme.com';
        $tz  = get_option( 'timezone_string', 'Europe/Berlin' );

        $dtstart = new DateTime( $date . ' ' . $time_start, new DateTimeZone( $tz ) );
        $dtend   = new DateTime( $date . ' ' . $time_end, new DateTimeZone( $tz ) );

        $phone_str   = $guest_phone ? " [Tel: {$guest_phone}]" : '';
        $email_str   = $guest_email ? " [{$guest_email}]" : '';
        $summary     = "Reservierung: {$guest_name} ({$guest_count} Pers.){$phone_str}{$email_str} - {$table_name}";
        $description = "Gast: {$guest_name}\nE-Mail: {$guest_email}\nTelefon: {$guest_phone}\nPersonen: {$guest_count}\nTisch: {$table_name}";
        if ( $notes ) {
            $description .= "\nNotiz: {$notes}";
        }

        $vcal  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n";
        $vcal .= "UID:{$uid}\r\n";
        $vcal .= "DTSTART;TZID={$tz}:" . $dtstart->format( 'Ymd\THis' ) . "\r\n";
        $vcal .= "DTEND;TZID={$tz}:" . $dtend->format( 'Ymd\THis' ) . "\r\n";
        $vcal .= "SUMMARY:{$summary}\r\n";
        $vcal .= "DESCRIPTION:{$description}\r\n";
        $vcal .= "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $cal_path = ( $this->provider === 'google' ) ? ltrim( $this->calendar, '/' ) : $this->calendar;
        $url      = $this->base_url . $cal_path . '/' . $uid . '.ics';

        $result = $this->request( 'PUT', $url, array(
            'headers' => array( 'Content-Type' => 'text/calendar; charset=utf-8' ),
            'body'    => $vcal,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return $uid;
    }

    /**
     * Delete a VEVENT by UID.
     */
    public function delete_event( $uid ) {
        if ( ! $this->is_configured() || ! $uid ) {
            return false;
        }
        $cal_path = ( $this->provider === 'google' ) ? ltrim( $this->calendar, '/' ) : $this->calendar;
        $url      = $this->base_url . $cal_path . '/' . $uid . '.ics';
        $this->request( 'DELETE', $url );
        return true;
    }

    /**
     * Test connection via PROPFIND.
     */
    public function test_connection() {
        if ( ! $this->is_configured() ) {
            return array( 'success' => false, 'message' => 'Bitte alle CalDAV-Felder ausfüllen.' );
        }
        $cal_path = ( $this->provider === 'google' ) ? ltrim( $this->calendar, '/' ) : $this->calendar;
        $url      = $this->base_url . $cal_path . '/';
        $result   = $this->request( 'PROPFIND', $url, array(
            'headers' => array( 'Content-Type' => 'application/xml; charset=utf-8', 'Depth' => '0' ),
            'body'    => '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
        ) );
        if ( is_wp_error( $result ) ) {
            return array( 'success' => false, 'message' => $result->get_error_message() );
        }
        return array( 'success' => true, 'message' => 'Verbindung erfolgreich!' );
    }
}
