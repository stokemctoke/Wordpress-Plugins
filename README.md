# WordPress Globe Plugins

Two self-contained WordPress plugins that render interactive 3D globes in the browser using Three.js. No API keys, CDNs, or external services — all imagery and elevation data is bundled with the plugins.

| Plugin | Folder | Shortcode |
|--------|--------|-----------|
| Sea Level Globe | [`globe-sea-level-plugin/`](globe-sea-level-plugin/) | `[sea_level_globe]` |
| World Builder Globe | [`globe-custom-world-plugin/`](globe-custom-world-plugin/) | `[world_builder_globe]` |

## Sea Level Globe

An interactive globe of Earth with an adjustable sea level. Zero on the scale is today's sea level; visitors can raise or lower the oceans between -10,000 ft and +10,000 ft with a slider or by typing an exact value, and toggle between feet and metres.

- Rotate by dragging, zoom with scroll or pinch
- Newly flooded land is tinted so it reads as flooding rather than existing ocean; newly exposed seabed renders as sand, darkening with depth
- Sea level changes are a single GPU shader uniform, so recoloring is instant
- Slider range, step, starting level, unit, and widget height are configurable per page via shortcode attributes or the Gutenberg block sidebar

## World Builder Globe

Everything the sea level globe does, extended into a full planet builder with eight live controls, each with a slider and an editable number field:

- **Sea level** (±10,000 ft), **ice caps**, **vegetation** (desert through lush), **cloud cover**, **atmosphere** glow, **ocean hue** (0-360°), **sun angle** (day-night terminator), and **spin**
- Two modes, switchable live: **Earth** (real NASA imagery and NOAA elevation) or **Custom world** (continents generated from seeded 3D noise, with a Randomize button)

## Common features

- Insert via shortcode or Gutenberg block; multiple globes per page work independently
- Admin menu entry for each plugin (custom icon) with a live preview and full shortcode reference
- Assets load only on pages that use a globe
- Data sources: NASA Blue Marble Next Generation imagery and NOAA ETOPO1 global relief, both public domain, baked into a 16-bit elevation texture (~1 m precision)
- GPL-2.0-or-later, structured for WordPress.org distribution

## Installation

Copy either plugin folder into `wp-content/plugins/` and activate it in the WordPress admin. Only the plugin's PHP files, `readme.txt`, `build/`, and `assets/` are needed at runtime; `src/`, `tools/`, `test/`, and `node_modules/` are development-only.

## Development

Each plugin builds independently:

```bash
cd globe-sea-level-plugin   # or globe-custom-world-plugin
npm install                 # esbuild + three
npm run build               # bundles src/ into build/
python3 tools/build_textures.py  # regenerate textures (needs numpy pillow requests scipy)
```

Each plugin also has a `test/index.html` that runs the globe without WordPress — serve the plugin folder with any static server (e.g. `python3 -m http.server`) and open it. Settings can be overridden via query string, e.g. `?mode=custom&ice=60`.
