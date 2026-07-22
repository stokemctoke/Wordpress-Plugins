# Sea Level Globe

WordPress plugin that embeds an interactive 3D globe of Earth with an adjustable sea level. Visitors can rotate and zoom the globe, then raise or lower the oceans between -10,000 ft and +10,000 ft using a slider or by typing an exact value. Zero on the scale is today's sea level.

Everything renders in the visitor's browser using bundled textures — no API keys, CDNs, or external services.

## How it works

- The globe is a Three.js sphere with a custom fragment shader.
- Two textures are bundled: NASA Blue Marble imagery (color) and a NOAA ETOPO elevation heightmap with elevation encoded across two 8-bit channels (16-bit, ~1 m precision).
- The shader compares each pixel's elevation against a `seaLevel` uniform. Below it: water, shaded by depth, with a tint marking newly flooded land. Above it: land imagery, with newly exposed seabed rendered as a sandy tone.
- Moving the slider only updates the uniform, so recoloring is instant.

## Installation

1. Copy this folder (or a zip of it) to `wp-content/plugins/` — only `sea-level-globe.php`, `uninstall.php`, `readme.txt`, `build/`, and `assets/` are needed at runtime.
2. Activate "Sea Level Globe" in the WordPress admin.
3. Add `[sea_level_globe]` to any page or post, or insert the "Sea Level Globe" block. A "Sea Level Globe" entry in the admin menu shows a live preview and the full shortcode reference.

### Shortcode attributes

| Attribute | Default | Description |
|-----------|---------|-------------|
| `min` | `-10000` | Lowest slider value |
| `max` | `10000` | Highest slider value |
| `start` | `0` | Initial sea level |
| `step` | `10` | Slider increment |
| `unit` | `ft` | Initial unit (`ft` or `m`) |
| `height` | `600px` | Widget height (px, vh, em, rem, %) |

Example: `[sea_level_globe min="-400" max="400" step="5" unit="m" height="500px"]`

## Development

```bash
npm install                     # esbuild + three
npm run build                   # bundles src/ into build/
pip install numpy pillow requests scipy
python3 tools/build_textures.py # regenerates assets/ from NASA/NOAA sources
```

Open `test/index.html` via a local web server (e.g. `python3 -m http.server`) to try the globe without WordPress.

## Data sources

- Color: [NASA Blue Marble Next Generation](https://visibleearth.nasa.gov/collection/1484/blue-marble) (public domain)
- Elevation: NOAA ETOPO1 global relief via the CoastWatch ERDDAP service (public domain)

Elevation encoding in `assets/earth-elevation.png`: `metres = R * 256 + G - 11000`.

## License

GPL-2.0-or-later
