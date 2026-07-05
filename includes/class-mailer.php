<?php
/**
 * Brevo SMTP Mailer for Dinia (GoBookMe SaaS)
 * Sends reservation confirmation emails via Brevo (Sendinblue) API v3.
 *
 * @package GoBookMe_SaaS
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DINA_Mailer {

    public static function send( $to, $subject, $body, $html_body = null, $tenant_settings = array() ) {
        $api_key = get_option( 'dinia_brevo_api_key', '' );
        if ( empty( $api_key ) ) {
            return 'Kein Brevo API-Key konfiguriert.';
        }

        $sender_email = get_option( 'dinia_sender_email', 'noreply@gofonia.de' );
        $sender_name  = get_option( 'dinia_sender_name', 'GoFonIA' );

        // Use tenant-specific name if available
        if ( ! empty( $tenant_settings['restaurant_name'] ) ) {
            $sender_name = $tenant_settings['restaurant_name'];
        }

        if ( is_string( $to ) ) {
            $to = array( array( 'email' => $to ) );
        } elseif ( is_array( $to ) && isset( $to['email'] ) ) {
            $to = array( $to );
        }

        $payload = array(
            'sender'      => array(
                'name'  => $sender_name,
                'email' => $sender_email,
            ),
            'to'          => $to,
            'subject'     => $subject,
        );

        if ( $html_body !== null ) {
            $payload['htmlContent'] = $html_body;
            // Erzeuge Plain-Text aus HTML, falls $body leer ist
            if ( empty( $body ) ) {
                $payload['textContent'] = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>', '</tr>' ), "\n", $html_body ) );
            } else {
                $payload['textContent'] = $body;
            }
        } else {
            $payload['htmlContent'] = self::plain_to_html( $subject, $body );
            $payload['textContent'] = $body;
        }

        $response = wp_remote_post( 'https://api.brevo.com/v3/smtp/email', array(
            'timeout' => 15,
            'headers' => array(
                'api-key'      => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            // Fallback: wp_mail() versuchen.
            $fallback_body = ! empty( $body ) ? $body : ( $html_body ?? $subject );
            if ( ! empty( $to ) && ! empty( $subject ) ) {
                $wp_to = is_array( $to ) ? $to[0]['email'] : $to;
                wp_mail( $wp_to, $subject, $fallback_body );
            }
            return 'Brevo Fehler: ' . $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            return true;
        }

        $body_resp = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_resp, true );
        $msg = isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;

        // Fallback: wp_mail() bei Brevo-Fehler versuchen.
        $fallback_body = ! empty( $body ) ? $body : ( $html_body ?? $subject );
        if ( ! empty( $to ) && ! empty( $subject ) ) {
            $wp_to = is_array( $to ) ? $to[0]['email'] : $to;
            wp_mail( $wp_to, $subject, $body );
        }

        return 'Brevo Fehler: ' . $msg;
    }

    public static function build_html( $subject, $content, $restaurant = 'Restaurant' ) {
        $primary = get_option( 'dinia_primary_color', '#ff6b00' );
        $year = date( 'Y' );

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
  body{margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
  .wrapper{max-width:600px;margin:0 auto;padding:20px 10px}
  .header{background:{$primary};color:#fff;padding:24px 30px;border-radius:8px 8px 0 0;text-align:center}
  .header h1{margin:0;font-size:20px;font-weight:600}
  .body{background:#fff;padding:30px;border-radius:0 0 8px 8px;color:#333;font-size:15px;line-height:1.6}
  .body h2{margin:0 0 16px;font-size:18px;color:#222}
  .details{background:#f9f9f9;border-left:4px solid {$primary};padding:16px 20px;margin:16px 0;border-radius:4px}
  .details p{margin:6px 0}
  .details strong{color:#555}
  .footer{text-align:center;padding:16px;color:#999;font-size:12px}
  a{color:{$primary}}
  @media(max-width:480px){.body{padding:20px}.header{padding:18px 20px}}
</style></head>
<body>
<div class="wrapper">
  <div class="header"><h1>{$restaurant}</h1></div>
  <div class="body">
    {$content}
  </div>
  <div class="footer">{$restaurant} &middot; &copy; {$year} Dinia<br>Diese E-Mail wurde automatisch erstellt.</div>
</div>
</body></html>
HTML;
    }

    private static function plain_to_html( $subject, $plain ) {
        $html = '<p>' . nl2br( esc_html( $plain ) ) . '</p>';
        return self::build_html( $subject, $html );
    }

    public static function test( $test_email ) {
        $plain = "Hallo,\n\ndiese E-Mail bestätigt, dass der Brevo E-Mail-Versand funktioniert.\n\n-- Dinia";
        $html  = self::build_html(
            'Dinia – Test-E-Mail',
            '<p style="font-size:16px;">✅ <strong>E-Mail-Versand funktioniert!</strong></p><p>Diese E-Mail bestätigt, dass der Brevo E-Mail-Versand korrekt konfiguriert ist.</p>'
        );
        return self::send( $test_email, 'Dinia – Test-E-Mail', $plain, $html );
    }
}
