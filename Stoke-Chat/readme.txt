=== Stoke Chat ===
Contributors: stokemctoke
Tags: chat, messaging, rooms, community, members
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted chat rooms for logged-in users: public and private rooms, smileys, per-room roles, @mentions, and away email alerts.

== Description ==

Stoke Chat adds a fully self-hosted chat to a single WordPress site. There is no external service — messages live in your own database and only registered, logged-in users can participate.

**Features**

* Public rooms (any logged-in user can browse and join) and private, invite-only rooms.
* Room creators can rename rooms after creation.
* Built-in emoji smileys with a picker; optional custom image smiley folder under wp-content.
* Chat UI uses the Stoke McToke dark palette (cyan / orange accents on near-black).
* Per-room roles — creator, moderator, member — managed by each room's creator, completely separate from WordPress roles.
* Direct messages as private two-person rooms.
* @username mentions with highlighting.
* Email alerts (with per-user opt-out and throttling) when you are mentioned or direct-messaged while away.
* Users can delete their own messages; creators and moderators can moderate their rooms.
* Smart REST polling that slows down when the tab is hidden or the user is idle.
* Room creation restricted by role via a settings page.
* Deleting a room removes all of its messages and memberships; uninstalling removes every trace (tables, options, user meta, capabilities).

**Usage**

1. Activate the plugin.
2. Add the `[stoke_chat]` shortcode (or the "Stoke Chat" block) to any page.
3. Visit Settings → Stoke Chat to choose who may create rooms, set a custom smiley folder, and tune polling/email behaviour.

**Notes**

* Deleted messages are removed immediately for the deleter; other participants stop seeing them on their next page load (polling does not retract already-rendered messages).
* Messages from deleted WordPress accounts remain, attributed to "Former member". Rooms created by a deleted account pass to the longest-standing moderator (or member), or are removed if empty.

== Installation ==

1. Upload the `Stoke-Chat` folder to `/wp-content/plugins/`.
2. Activate through the Plugins screen.
3. Place `[stoke_chat]` on a page and set that page's URL under Settings → Stoke Chat.

== Changelog ==

= 1.1.1 =
* Dark Stoke McToke brand palette for the chat UI.

= 1.1.0 =
* Smileys: built-in emoji shortcodes and composer picker.
* Custom smiley folder setting (image files under wp-content become :filename: smileys).
* Room creators can rename rooms after creation.
* Drag to reorder rooms in the sidebar (personal order per user).
* Dark Stoke McToke palette (near-black, cyan, orange, yellow).

= 1.0.0 =
* Initial release.
