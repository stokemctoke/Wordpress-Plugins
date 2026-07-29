# Gallus QR

Free, self-hosted QR code studio for WordPress. Design styled codes, track scans on your own site, and manage a per-user code library — no external QR service, no per-scan fees.

**[⬇ Download the latest release](https://github.com/stokemctoke/Wordpress-Plugins/releases?q=Gallus+QR&expanded=true)** → WordPress admin → **Plugins → Add New → Upload Plugin**.

**License:** GPL-2.0-or-later · **Brand:** Gallus QR (runs on your WordPress install)

---

## Features

**Content types** — URL (with optional UTM), WiFi, vCard, email, SMS, phone, calendar event, plain text.

**Dynamic short links** — trackable `/qr/{slug}` links you can re-point after printing; custom slugs; pause/expiry/scan caps; scheduled switch-over and A/B rotation.

**Design studio** — shapes, gradients, transparent backgrounds, centre logo (Media Library or upload), frame labels, PNG/JPEG/SVG export up to 1024 px, saveable design presets.

**Analytics** — totals + unique scans, charts, device/OS/browser/country breakdowns, hour-of-day heatmap, bot filtering, CSV export, dashboard widget, retention pruning.

**Multi-user** — grant an extra WordPress role (e.g. Subscriber) access via Settings. Each of those users only sees and manages **their own** codes. Administrators see everything, with an Owner filter on Scan Stats: All codes / My codes / specific user.

**Site integration** — Gutenberg block, `[gallus_qr slug=""]` shortcode, post-list and admin-bar shortcuts, bulk CSV import.

---

## Install

1. Zip this `Gallus-QR/` folder (or download a release zip). Do **not** include `node_modules` / `vendor` if you recreate a local env.
2. WordPress → **Plugins → Add New → Upload Plugin** → install → activate.
3. Keep Permalinks off **Plain** so `/qr/{slug}` routes correctly.
4. Open **Gallus QR** in the admin sidebar. Optionally set **Settings → Gallus QR → Extra role with access** to share the tool with Subscribers (each gets their own library).

Runtime needs only the plugin PHP, `assets/`, `block/`, `languages/`, `readme.txt`, and `uninstall.php`. Dev folders (`node_modules`, `vendor`, tests tooling) are not required on the server.

---

## Direct vs trackable

| | **Direct** | **Trackable** |
|---|---|---|
| Encodes | the destination / payload itself | `/qr/{slug}` on your site |
| Scans countable? | No | Yes |
| Works if site is down? | Yes | Needs the site |
| Change destination later? | No | Yes |
| Best for | permanent hardware (e.g. PCB) | marketing / packaging |

---

## Plugin layout

```
Gallus-QR/
├── gallus-qr.php          # bootstrap, activation, constants
├── uninstall.php
├── readme.txt             # WordPress.org-style readme
├── includes/              # PHP classes (admin, REST, DB, redirect, …)
├── assets/                # admin/frontend CSS + JS (bundled QR engine)
├── block/                 # Gutenberg block
└── languages/
```

Tables (created on activate / upgraded in place): `{prefix}gallus_qr_codes`, `{prefix}gallus_qr_scans`, `{prefix}gallus_qr_presets`. Codes store a `user_id` owner for per-user isolation.

---

## Privacy

No external API calls. Scan rows store a salted SHA-256 of the visitor IP (never the raw address) and a user-agent. Country is recorded only when your server/CDN already supplies a country header. Retention and uninstall options can wipe history.

---

## Local development

Optional — the shipped plugin has no build step.

```bash
cd Gallus-QR
npm install          # @wordpress/env only
composer install     # PHPUnit for tests
npm run env:start    # http://localhost:8888  (admin / password)
npm run test:php
```

These create local `node_modules/` and `vendor/` — keep them gitignored; never zip them for WordPress upload.

---

## Roadmap

- Trackable vCard / calendar via hosted payloads
- PDF export
- Multi-step destination schedules

---

## Changelog (recent)

- **2.0.2** — Per-user code ownership; admin Owner filter on Scan Stats; Subscribers manage only their own codes.
- **2.0.1** — Roadmap + optional donation touchpoints.
- **2.0.0** — Full studio: payload types, lifecycle, A/B, analytics, block, REST, settings.

See `readme.txt` for the full WordPress changelog.
