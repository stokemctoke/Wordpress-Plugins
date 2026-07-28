=== Gallus QR ===
Contributors: gallusgadgets
Donate link: https://ko-fi.com/stoke
Tags: qr code, qr, qr code generator, dynamic qr, analytics
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.1.1
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
* Role-based access control — extra roles only see and manage their own codes;
  administrators see everything

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

= Can Subscribers manage their own QR codes without seeing mine? =

Yes. Under Settings → Gallus QR, set “Extra role with access” to Subscriber
(or another role). Those users get the Gallus QR screens, but each person
only sees codes they created. Administrators still see every code.

== Changelog ==

= 2.1.1 =
Follow-up to the 2.1.0 hardening after a full audit of the plugin. Several of
the 2.1.0 fixes turned out to be incomplete in ways that cancelled them out on
common hosting setups, so this release supersedes it — install this one.

* Fixed forged visitor addresses. X-Forwarded-For was read left-to-right, but
  reverse proxies *append* to that header, so the entry a visitor sent
  themselves came first and was believed. The chain is now walked from the
  right. Separately, IPv4-mapped IPv6 peers (`::ffff:203.0.113.9`, which is how
  a dual-stack server reports ordinary visitors) were classified as private,
  which trusted the whole internet. Trusted ranges are now an explicit list —
  see the new `gallus_qr_trusted_proxies` filter if you sit behind a CDN.
* Country detection had the same flaw: `CF-IPCountry` and `X-Country-Code` were
  read straight from the request with no checks, so anyone could write your
  analytics. They are now only believed behind a trusted proxy.
* Scan caps are actually enforceable now. An exhausted code still redirected for
  anyone sending a bot-like (or empty) user agent; de-duplication was not atomic,
  so simultaneous requests all counted; and because de-duplication is per
  visitor, it never stopped someone with many addresses. Capped codes now also
  limit how fast the cap can be spent, regardless of who is asking
  (`gallus_qr_cap_spend_interval`).
* A database hiccup no longer looks like an expired code. A deadlock or timeout
  used to show visitors the permanent "no longer active" page.
* Closed an embed loophole: an editor could read another user's WiFi password or
  vCard through the block renderer by pointing it at the owner's post.
  Non-trackable URL codes now follow the same ownership rule, and paused or
  expired codes no longer render as though live.
* `/qr/` links only work as real `/qr/…` URLs now. The slug could previously be
  appended to any address on the site, which sidestepped edge/firewall rules.
* Inline logos are capped at 256KB (`gallus_qr_max_logo_bytes`), and a media
  item you cannot view is no longer resolved into a design.
* When a user is deleted their codes are handed to whoever you nominate, or to
  the first administrator — never left pointing at a freed account, which a
  later user could inherit. Printed codes keep working.
* The Scan Stats screen is paginated and much lighter: it no longer runs four
  queries for every code on the site, and no longer sends every saved design to
  the browser on each load. New indexes speed up the breakdowns.
* Upgrade note: this is database version 7. The upgrade runs automatically, and
  the version marker is now written only after the data work finishes, so an
  interrupted upgrade retries instead of silently skipping.

= 2.1.0 =
Security and abuse hardening. Recommended for any site where more than one
person holds Gallus QR access.

* Design presets are now per-user, matching codes: you see and delete your own,
  administrators see all. Previously anyone with access could read and delete
  everyone's presets. Existing presets are assigned to the first administrator
  on upgrade.
* The [gallus_qr] shortcode and block no longer render non-URL codes (WiFi,
  vCard and friends) on a post written by someone other than the code's owner.
  Those types encode their payload verbatim in the page, so this stops one user
  publishing another's WiFi password or contact details by guessing a slug.
  Override with the `gallus_qr_can_embed_code` filter.
* Scans are counted once per visitor per code per minute. This stops a scripted
  request loop from inflating stats or — far worse — burning through a code's
  scan limit and permanently disabling a QR that has already been printed.
  Tune or disable with the `gallus_qr_scan_dedupe_window` filter.
* X-Forwarded-For is only trusted when the request actually arrived through a
  reverse proxy (loopback/private peer by default), and every address is
  validated. Previously anyone could forge a fresh "unique visitor" per request.
  Sites behind a public-facing CDN should allow its ranges with the
  `gallus_qr_is_trusted_proxy` filter.
* The settings screen now spells out what granting the extra role means: those
  users can point links on your own domain anywhere they like.
* Hardened the shared renderer's SVG escaping and added client-side colour
  validation, so a hand-edited design can never inject markup.
* Added a capability check to the donation-notice dismissal.
* Internals: reworked three analytics queries so placeholders stay inside a
  single prepare() call, and made fgetcsv()'s arguments explicit for PHP 8.4.

= 2.0.2 =
* Per-user code ownership: an extra role (e.g. Subscriber) with Gallus QR
  access only lists, edits, exports and deletes their own codes.
  Administrators still see and manage every code. Existing codes are assigned
  to the first administrator on upgrade.
* Admin Owner filter on Scan Stats: All codes, My codes, or a specific user
  (with an Owner column when browsing beyond your own).

= 2.0.1 =
* Added a public roadmap (trackable vCard/event codes via hosted payloads,
  PDF export, multi-step schedules)
* Added optional donation touchpoints: readme donate link, a footer line and
  plugins-page link, and a single dismissible thank-you nudge after 10 saved
  codes — shown only on the plugin's own screens

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
