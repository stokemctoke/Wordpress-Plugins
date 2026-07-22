=== World Builder Globe ===
Contributors: stoke
Tags: globe, 3d, planet, world builder, map
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Interactive 3D planet builder: start from Earth or a random world, then shape it with sliders for sea level, ice, vegetation, clouds, atmosphere, ocean color, sunlight, and rotation.

== Description ==

World Builder Globe embeds an interactive 3D planet on any page or post. Visitors can rotate and zoom the globe, switch between real Earth and procedurally generated custom worlds, and adjust:

* Sea level (-10,000 ft to +10,000 ft, slider or exact text entry)
* Ice caps - extent grows from the poles and mountain tops, including sea ice
* Vegetation - lush green through arid desert
* Cloud cover - procedural cloud layer density
* Atmosphere - rim glow thickness and intensity
* Ocean hue - deep blue through alien tints (0-360 degrees)
* Sun angle - moves the day-night terminator around the planet
* Spin - auto-rotation speed and direction

In Custom world mode, a Randomize button generates new continents from seeded 3D noise. All rendering happens on the visitor's GPU using bundled NASA/NOAA data. No API keys, no CDNs, no external requests.

== Usage ==

Add the shortcode to any page or post:

`[world_builder_globe]`

Optional attributes (initial values; visitors can change everything live):

* `mode` - `earth` or `custom` (default earth)
* `sea` - initial sea level in ft (default 0)
* `ice` - ice caps percent (default 15)
* `vegetation` - vegetation percent, 50 = natural Earth (default 50)
* `clouds` - cloud cover percent (default 30)
* `atmosphere` - atmosphere percent (default 40)
* `ocean` - ocean hue in degrees (default 210)
* `sun` - sun angle in degrees (default 35)
* `spin` - rotation in degrees per second, -10 to 10 (default 0)
* `height` - widget height, e.g. `640px` or `80vh` (default 640px)

Example: `[world_builder_globe mode="custom" ocean="140" spin="2" height="500px"]`

Or insert the "World Builder Globe" block and adjust the same settings in the sidebar.

== Data sources ==

* Earth imagery: NASA Blue Marble Next Generation (public domain)
* Elevation/bathymetry: NOAA ETOPO1 Global Relief Model (public domain)

== Changelog ==

= 1.1.0 =
* Doubled the maximum zoom level.
* Added an admin menu entry with a live preview and shortcode reference.

= 1.0.0 =
* Initial release.
