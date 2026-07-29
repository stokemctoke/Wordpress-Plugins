# WordPress Plugins

Custom self-hosted WordPress plugins by [@stokemctoke](https://github.com/stokemctoke).

| Plugin | Description |
| --- | --- |
| [Gallus QR](Gallus-QR/) | Free, self-hosted custom QR code generator — centre logo, custom shapes, adjustable export size, PNG/SVG export, editable dynamic codes, scan analytics, and faithful re-download of saved designs. |
| [Globe: World Builder](Globe-Custom-World/) | Interactive 3D planet builder. Start from Earth or generate a random world, then shape it with sliders: sea level, ice caps, vegetation, clouds, atmosphere, ocean color, sunlight, and rotation. |
| [Globe: Sea Level](Globe-Sea-Level/) | Interactive 3D globe of Earth with an adjustable sea level. Zoom, rotate, and raise or lower the oceans by up to 10,000 ft via a slider or exact text entry. |
| [Stoke Chat](Stoke-Chat/) | Self-hosted chat rooms for logged-in users — public/private rooms, per-room roles, @mentions, and away email alerts. No external services. |

Each plugin folder is a complete, standalone WordPress plugin — see its own README for details.

## Releases

Each plugin is versioned and released independently — they share this repo but no code, and you install them separately. Installable zips are attached to [Releases](https://github.com/stokemctoke/Wordpress-Plugins/releases); download one and use **Plugins → Add New → Upload Plugin**.

Tags are prefixed with the plugin's install slug:

```
gallus-qr-v2.1.2
stoke-chat-v1.1.5
sea-level-globe-v1.0.0
```

Note the slug comes from the plugin's main PHP file, not its folder — `Globe-Custom-World/` installs as `world-builder-globe`.

**Historical exception:** bare `vX.Y.Z` tags up to and including `v2.1.1` are Gallus QR, from when it was the only plugin here. They are left as they are; everything from now on is prefixed. (`v2.1.0` was deleted — it pointed at pre-2.1.0 code and was never released. 2.1.1 supersedes it.)

### Cutting a release

```
tools/release.sh <plugin-dir> <version>       # e.g. tools/release.sh Gallus-QR 2.1.2
tools/release.sh Gallus-QR 2.1.2 --dry-run    # preview the version bump, change nothing
```

Write the `= <version> =` changelog section in the plugin's `readme.txt` first — the script uses it as the release notes and refuses to run without it. It then bumps every place the version is written, commits, builds the zip from the committed tree, verifies the payload (single correct top-level folder, no dev files, header version matches), and prompts once before anything public happens.

Pass `--latest` to also move GitHub's "Latest release" badge to it. In a multi-plugin repo that badge just tracks whichever release was cut most recently, so pin it deliberately.
