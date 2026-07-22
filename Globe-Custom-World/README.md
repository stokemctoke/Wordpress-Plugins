# World Builder Globe

WordPress plugin that embeds an interactive 3D planet builder. Based on the [Sea Level Globe](../globe-sea-level-plugin) plugin, extended with a full set of world-shaping sliders and a procedural planet generator.

## Variables

Every variable applies instantly (GPU shader uniforms), with a slider plus an editable number field:

| Variable | Range | Effect |
|----------|-------|--------|
| Sea level | -10,000 to +10,000 ft | Floods or drains the oceans; new flooding tinted, exposed seabed rendered as sand |
| Ice caps | 0-100% | Snow/ice spreads from poles and peaks; sea ice freezes shortly after land |
| Vegetation | 0-100% | 50 = natural Earth; lower turns desert, higher turns lush |
| Cloud cover | 0-100% | Procedural cloud layer on a separate sphere, lit by the sun |
| Atmosphere | 0-100% | Fresnel rim glow, thickness and intensity |
| Ocean hue | 0-360° | Water color, deep blue through alien purples and greens |
| Sun angle | 0-360° | Moves the day-night terminator around the planet |
| Spin | -10 to +10 °/s | Auto-rotation; clouds drift slightly faster than the surface |

Two modes, switchable live:

- **Earth** - real NASA Blue Marble imagery + NOAA ETOPO elevation
- **Custom world** - continents generated from seeded 3D value noise (seam-free on the sphere), land colored by elevation and the vegetation slider, plus a Randomize button

## Installation

1. Copy this folder to `wp-content/plugins/` — only `world-builder-globe.php`, `uninstall.php`, `readme.txt`, `build/`, and `assets/` are needed at runtime.
2. Activate "World Builder Globe" in the WordPress admin.
3. Add `[world_builder_globe]` to any page, or insert the "World Builder Globe" block. A "World Builder" entry in the admin menu shows a live preview and the full shortcode reference.

See `readme.txt` for all shortcode attributes.

## Development

```bash
npm install                     # esbuild + three
npm run build                   # bundles src/ into build/
python3 tools/build_textures.py # regenerates assets/ (needs numpy pillow requests scipy)
```

Open `test/index.html` via a local server (`python3 -m http.server`); any data attribute can be overridden by query string, e.g. `?mode=custom&ice=60&ocean=120`.

## License

GPL-2.0-or-later. Earth textures: NASA Blue Marble and NOAA ETOPO1, both public domain.
