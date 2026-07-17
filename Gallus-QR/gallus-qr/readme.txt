=== Gallus QR ===
Contributors: gallusgadgets
Tags: qr code, qr, qr code generator, dynamic qr, analytics
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, self-hosted QR code studio: styled designs, dynamic short links, WiFi/vCard/event payloads, scan analytics, and a Gutenberg block.

== Description ==

Gallus QR is a complete, self-hosted QR code manager. No external services,
no accounts, no per-scan fees — your codes, your links, your data.

**Content types**

* Website URL (with optional UTM campaign tagging)
* WiFi network (WPA/WEP/open, hidden networks)
* Contact card (vCard 3.0)
* Email, SMS, phone number
* Calendar event (iCal)
* Plain text

**Dynamic short links (URL codes)**

* Trackable `/qr/{slug}` short links — re-point a printed code any time
* Custom slugs (`/qr/summer-sale`)
* Pause/resume, expiry dates, scan limits, per-code fallback URLs
* Scheduled destination switching and A/B rotation with per-variant stats

**Design studio**

* Six dot shapes, three corner styles, separate corner-dot control
* Solid colours or linear/radial gradients, transparent backgrounds
* Centre logo from the Media Library (or a direct upload)
* "SCAN ME" frame labels above or below the code
* PNG / JPEG / SVG export up to 1024 px (SVG stays vector)
* Saveable design presets

**Analytics (privacy-first)**

* Total + unique scans, per-day charts, hour-of-day heatmap
* Device / OS / browser split, country breakdown
* Bot filtering, CSV export, dashboard widget
* Configurable data retention with daily pruning

**Site integration**

* Gutenberg block and `[gallus_qr slug=""]` shortcode
* "QR code" row action on posts, pages and WooCommerce products
* Admin-bar "QR for this page" shortcut
* Bulk creation from a CSV upload
* Role-based access control

== Privacy ==

Gallus QR makes **no external requests** of any kind. Scan analytics store a
salted SHA-256 hash of the visitor IP (never the address itself) and the
user-agent string. Country statistics are only recorded when your server or
CDN (e.g. Cloudflare) already provides a country header — no lookups are
performed. A retention setting prunes old scan rows automatically, and an
uninstall option removes every trace of the plugin.

== Source code ==

The bundled QR rendering engine `assets/js/lib/qr-code-styling.js` is
qr-code-styling v1.6.0 (MIT). Human-readable source:
https://github.com/kozakdenys/qr-code-styling

== Roadmap ==

* **Trackable vCard and calendar codes (hosted payloads)** — instead of
  encoding the contact/event text directly, the QR encodes your short
  `/qr/{slug}` link; the site logs the scan and serves the `.vcf`/`.ics`
  file. Scan counts *and* the ability to fix a typo after your business
  cards are printed. (WiFi/SMS/phone codes can't work this way — those are
  parsed on the phone with no request to intercept.)
* **PDF export** — print-shop friendly output alongside PNG/JPEG/SVG.
* **Multi-step destination schedules** — more than one switch-over date.

== Frequently Asked Questions ==

= What's the difference between Direct and Trackable codes? =

Direct codes encode your URL itself — they work forever with zero dependency
on this site, but scans can't be counted. Trackable codes encode a short
`/qr/{slug}` link on your site that logs the scan and redirects, which also
lets you change the destination after the code is printed.

= Can WiFi or vCard codes be tracked? =

No — tracking works by routing an HTTP redirect, and WiFi/vCard/etc. payloads
are read directly by the phone. The generator makes this explicit.

= My scans show country "Unknown". =

Country detection reads a header your CDN or server adds (such as
Cloudflare's `CF-IPCountry`). Without such a header the plugin records
nothing — it never calls external geolocation services.

== Changelog ==

= 2.0.0 =
* New content types: WiFi, vCard, email, SMS, phone, calendar event, plain text
* UTM campaign builder for URL codes
* Custom slugs with live availability checking
* Pause/resume, expiry dates, scan limits and fallback URLs
* Scheduled destination switching and A/B rotation
* Design studio: more shapes, gradients, transparent backgrounds, frames,
  media-library logos, JPEG export, design presets
* Analytics: OS/browser/country breakdowns, hour-of-day heatmap, bot
  filtering, CSV export, dashboard widget, data retention
* Gutenberg block + shortcode for displaying saved codes
* Full REST API, bulk CSV import, role-based access, settings screen
* All timestamps now stored in UTC (fixes range queries on offset timezones)

= 1.0.0 =
* First stable release: styled generator, trackable short links, scan stats.

== Upgrade Notice ==

= 2.0.0 =
Major upgrade. Database schema is migrated automatically; existing codes,
designs and scan history are preserved.
