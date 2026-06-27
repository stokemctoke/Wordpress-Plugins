# Gallus QR

A free, self-hosted QR code generator built as a WordPress plugin — a no-paywall
clone of sites like QRCode Monkey, with optional scan tracking (the part the paid
sites charge for).

**Brand:** Gallus QR · **Runs on:** stokemctoke.com (self-hosted WordPress) ·
**Status:** v0.3.0 — generator ✅ · scan tracking ✅ (verified live) · export-size + rename/delete ✅ · Patreon layer (v2) next.

> Note: the brand name is "Gallus QR" even though it lives on stokemctoke.com.
> gallusgadgets.com stays dedicated to sales/info. The plugin name and the host
> domain are independent — the brand is just a label.

---

## What it does

A page in wp-admin where you design custom QR codes and (optionally) track how
often each one is scanned.

- **Centre logo** — drop a `.png` into the middle of the code.
- **Custom shapes** — square + rounded body dots and corner (eye) shapes.
- **Colours / gradients** — gradients supported but **off by default**; default
  is pure black-on-white (or inverted) for PCB silkscreen layers.
- **Export** — PNG + SVG (SVG matters for clean print/silkscreen scaling); adjustable
  PNG resolution 128–1024 px.
- **Scan tracking** — total scans + a 30-day chart, per code (via the Trackable toggle);
  rename or delete saved codes from the Scan Stats screen.
- **URLs only** for now; other types (WiFi, vCard, text…) come later.
- **You only** (wp-admin) for now; a Patreon/members layer is planned for v2.

---

## The one big architectural idea: static vs dynamic codes

This is the decision that shapes everything, so it's worth being clear:

| | **Direct (static)** | **Trackable (dynamic)** |
|---|---|---|
| What's encoded | the real destination URL | a short link back to our site (`/qr/ab12cd`) |
| Scans countable? | ❌ never | ✅ yes — every scan passes our server |
| Works if site is down? | ✅ forever | ❌ depends on the site staying up |
| Can change destination later? | ❌ no | ✅ yes |
| Best for | permanent hardware (PCB silkscreen) | marketing / packaging you can reprint |

**Decision: a per-code "Trackable" toggle.** Marketing codes → trackable.
Permanent PCB-etched codes → direct. Chosen per QR.

You cannot count scans on a static code — no front-end trick changes this. Counting
requires the scan to pass through a server, which only the dynamic form does.

---

## How it works on WordPress

A plugin is a folder in `wp-content/plugins/` with a main PHP file whose header
comment WordPress reads. It then "hooks" into WordPress:

- **Admin menu** — a "Gallus QR" item in the wp-admin sidebar (Generator + Scan Stats).
- **REST endpoint** — `POST /wp-json/gallus-qr/v1/codes`, admin-only, saves a code.
- **Rewrite rule** — teaches WP that `/qr/{slug}` isn't a normal page; route it to
  our redirect handler.
- **Activation hook** — creates the database tables and flushes the rewrite rule.
- **Shortcode** `[qr_generator]` — *planned for the Patreon/front-end layer (v2)*; not
  registered yet, the tool currently lives in wp-admin.

QR **drawing** happens in the browser via the open-source [`qr-code-styling`]
library (logo, shapes, colours, PNG/SVG export). **Tracking** happens in PHP + MySQL.
Clean split: design = client-side, counting = server-side.

[`qr-code-styling`]: https://github.com/kozakdenys/qr-code-styling

---

## Scan-tracking flow (end to end)

1. Open **Gallus QR** in wp-admin, paste a URL, design the look, tick **Trackable**,
   give it a label, and hit **Save & make trackable**.
2. JS posts it to the REST endpoint → PHP saves the code, returns the slug + short URL.
3. The QR re-renders to encode `stokemctoke.com/qr/ab12cd`; you download PNG/SVG.
4. Someone scans → WP routes `/qr/ab12cd` to our handler → logs the scan (timestamp,
   salted IP hash, user-agent) → 302-redirects to the real URL. The scanner notices nothing.
5. **Scan Stats** shows each code's total + a 30-day bar chart.

---

## Plugin structure

```
gallus-qr/
├── gallus-qr.php           ← header, constants, activation hook, bootstrap
├── includes/
│   ├── class-admin.php      ← admin menu, generator page, Scan Stats dashboard
│   ├── class-database.php   ← creates/queries the two tables, slug generation
│   ├── class-rest.php       ← save-a-code endpoint (admin only)
│   └── class-redirect.php   ← handles /qr/{slug}: log scan → 302 redirect
└── assets/
    ├── js/generator.js          ← the live generator UI + trackable save flow
    ├── js/lib/qr-code-styling.js← bundled engine (no CDN)
    └── css/admin.css            ← two-column generator + stats styling
```

All files are built (Milestones 1 + 2).

---

## Database schema (Milestone 2)

**`qr_codes`**

| column | purpose |
|---|---|
| `id` | code id |
| `slug` | the `ab12cd` in the URL |
| `destination` | the real target URL |
| `title` | your label |
| `trackable` | dynamic vs direct |
| `created_at` | timestamp |

**`qr_scans`**

| column | purpose |
|---|---|
| `id` | scan id |
| `code_id` | which code |
| `scanned_at` | timestamp |
| `ip_hash` | hashed IP, for unique-ish counts |
| `user_agent` | device — for v2 breakdowns |

Total scans = count rows for a code. Scans-over-time = group by day. The schema
already has room for v2 breakdowns (device, location) without a rebuild.

---

## Roadmap

- **Milestone 1 — Generator ✅:** plugin skeleton, admin page, `qr-code-styling`
  with logo + square/rounded shapes, colours/invert, PNG/SVG export.
- **Milestone 2 — Tracking ✅:** DB tables, save endpoint, `/qr/{slug}` redirect +
  logging, Scan Stats dashboard (total + 30-day chart), the Trackable toggle.
- **v2 — Patreon layer:** open the generator to logged-in/members, per-user code
  lists, richer analytics (device/location), a store-wide 10% discount hook for
  gallusgadgets.com, optional logo saving to the Media Library, more code types
  (WiFi, vCard, text), more shapes.

---

## Locked decisions

- **Name / slug:** Gallus QR · plugin folder `gallus-qr` · shortcode `[qr_generator]` reserved for v2.
- **Host:** stokemctoke.com — self-hosted WordPress, Ubuntu 24.04 + CloudPanel.
- **Redirect path:** `/qr/{slug}`.
- **Permalinks:** "pretty" (not Plain) — required for `/qr/{slug}` to route. ✅
- **Trackable toggle:** per-code static-vs-dynamic choice (for the permanent-PCB case).
- **Shapes for v1:** square + rounded only; expand when opening to users.
- **Gradients:** built in but off by default (default = black-on-white / inverted).
- **Logo storage:** not saved server-side for now (browser-only); Media Library save is a v2 add.
- **Audience:** just you (wp-admin) for v1; Patreon/members for v2.

---

## Install

1. Zip the inner **`gallus-qr/`** folder (the one containing `gallus-qr.php`).
2. In wp-admin on stokemctoke.com: **Plugins → Add New → Upload Plugin** → choose the
   zip → **Install Now** → **Activate**.
   *(Or copy the `gallus-qr/` folder straight into `wp-content/plugins/` and activate.)*
   On activation the plugin creates its two tables and registers the `/qr/` route.
3. **Gallus QR** appears in the admin sidebar (QR-code icon), with a **Scan Stats** subpage.
4. Design a code → **Download SVG/PNG**. For tracking, tick **Trackable**, label it,
   **Save & make trackable**, then download — scan it and watch the count in Scan Stats.

**Requirements:** Permalinks must not be set to "Plain" (Settings → Permalinks) for the
`/qr/{slug}` redirect to route. The bundled engine (`qr-code-styling` 1.6.0 UMD) ships
with the plugin — no CDN, works offline.

> Upgrading from v0.1.0? Deactivate + reactivate once so the activation hook creates the
> new tables and flushes the rewrite rule.

---

## Reference — leading QR sites studied

- **QRCode Monkey** — closest free analogue; logo + shapes + colours, PNG/SVG/PDF/EPS,
  100% client-side → free but **no tracking**.
- **QRCodeChimp** — widest shape library; analytics + bulk are paid.
- **QR Tiger / Uniqode / Hovercode** — lead with dynamic codes + analytics (the paid tier).
  Uniqode has a nice live "scannability" preview.

Key takeaway: the free part (design + logo + shapes) is client-side and cheap; the
paid part (tracking) needs a server + database — which is exactly the half we own
via WordPress.
