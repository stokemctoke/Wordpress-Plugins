=== Sea Level Globe ===
Contributors: stoke
Tags: globe, sea level, 3d, map, climate
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Interactive 3D globe with an adjustable sea level slider (+/- 10,000 ft), rendered entirely in the browser with no external services.

== Description ==

Sea Level Globe embeds an interactive 3D Earth on any page or post. Visitors can:

* Rotate the globe by dragging and zoom with the scroll wheel or pinch gestures.
* Raise or lower the global sea level with a slider (default range -10,000 ft to +10,000 ft).
* Type an exact value into the text field for precise adjustments.
* Toggle between feet and metres, and reset to today's sea level with one click.

All rendering happens on the visitor's GPU using bundled NASA Blue Marble imagery and a NOAA ETOPO 2022 elevation heightmap. No API keys, no CDNs, no external requests.

== Usage ==

Add the shortcode to any page or post:

`[sea_level_globe]`

Optional attributes:

* `min` – lowest slider value (default -10000)
* `max` – highest slider value (default 10000)
* `start` – initial sea level (default 0)
* `step` – slider increment (default 10)
* `unit` – initial unit, `ft` or `m` (default ft)
* `height` – widget height, e.g. `600px` or `80vh` (default 600px)

Example: `[sea_level_globe min="-400" max="400" step="5" unit="m" height="500px"]`

Or insert the "Sea Level Globe" block in the block editor and adjust the same settings in the sidebar.

== Data sources ==

* Earth imagery: NASA Blue Marble Next Generation (public domain)
* Elevation/bathymetry: NOAA ETOPO 2022 Global Relief Model (public domain)

== Changelog ==

= 1.1.0 =
* Doubled the maximum zoom level.
* Added an admin menu entry with a live preview and shortcode reference.

= 1.0.0 =
* Initial release.
