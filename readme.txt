=== Dinia – GoBookMe SaaS ===
Contributors: gofonia
Tags: restaurant, booking, reservation, saas, multi-tenant
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-User-Reservierungssystem für Restaurants mit Mollie Billing, JS-Widget und API-Key-Auth.

== Description ==

Dinia ist ein vollständiges SaaS-Reservierungssystem für Restaurants auf WordPress-Basis.

Features:
* Multi-Tenant: Beliebig viele Restaurants auf einer WordPress-Instanz
* Mollie-Billing: Automatische monatliche Abbuchung, Webhooks
* JS-Widget: Einfache Einbindung per <script>-Tag auf jeder Website
* Client-Plugin: WordPress-Shortcode [dinia_booking]
* API-Key-Auth: Sicherer Zugriff per SHA256-gehashtem API-Key
* Backup: Admin-Datenbank-Backup mit einem Klick
* Plan-Limits: Verschiedene Tarife mit Tisch-/Reservierungs-Limits
* Cloudflare Turnstile: Bot-Schutz bei Self-Signup

== Installation ==

1. Plugin-Verzeichnis nach /wp-content/plugins/ kopieren
2. In WordPress unter "Plugins" aktivieren
3. Unter "Dinia" → "Einstellungen" Mollie API-Key eintragen
4. Unter "Dinia" → "Pläne" Tarife konfigurieren
5. Unter "Dinia" → "Kunden" erste Restaurants anlegen

== Widget-Einbindung ==

<script src="https://IHRE-DOMAIN.de/wp-content/plugins/gobookme-saas/assets/widget.js"
        data-tenant="KUNDEN-SLUG"
        defer></script>
<div id="dinia-widget"></div>

== Shortcode (Client-Plugin) ==

[dinia_booking]

== Changelog ==

= 1.0.0 =
* Initial release
