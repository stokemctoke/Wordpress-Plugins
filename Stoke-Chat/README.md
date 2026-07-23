# Stoke Chat

Self-hosted chat rooms for a single WordPress site. No external services, no build step — messages live in your own database and only registered, logged-in users can participate.

## Features

- **Logged-in users only.** No public or unregistered access anywhere — the UI shows a login prompt and every REST route requires authentication.
- **Public and private rooms.** Any logged-in user can browse and join public rooms; private rooms are invite-only and invisible to non-members (the API returns 404, not 403, so their existence never leaks).
- **Rename rooms.** Creators (and site admins) can change a room’s name after creation.
- **Reorder rooms.** Drag rooms in the sidebar to set a personal order (saved per user).
- **Smileys.** Built-in emoji shortcodes (`:smile:`, `:wink:`, `:)`, …) plus a picker in the composer. Optionally point **Settings → Stoke Chat → Custom smiley folder** at a folder under `wp-content` (e.g. `uploads/stoke-chat-smileys`); image files there become `:filename:` smileys.
- **Brand palettes.** Settings → Stoke Chat → Color palette: **Stoke McToke** (cyan) or **Gallus Gadgets** (warm dark + orange buttons/accents).
- **Per-room roles.** Each room has a creator, optional moderators, and members — managed by the room creator, completely separate from WordPress roles. Moderators can delete any message, invite, and kick.
- **Direct messages.** A DM is simply a private two-person room; no separate messaging system to maintain.
- **@mentions with email alerts.** `@username` mentions are highlighted, and mentioned users (or DM recipients) get an email via `wp_mail` when they've been inactive past a configurable threshold — throttled per user/room, with a per-user opt-out on the profile page.
- **Smart polling.** Vanilla-JS frontend polls the REST API and automatically backs off when the tab is hidden or the user is idle.
- **Moderation.** Users delete their own messages; creators/moderators delete anyone's in their room. Rate limiting on posting (10 msgs / 30 s) and room creation (5 / hour).
- **Clean lifecycle.** Deleting a room removes its messages and memberships. Deleting a WP user hands their rooms to the longest-standing moderator (or member), keeping their messages as "Former member". Uninstalling drops the tables, options, user meta, capabilities, and cron events.

## Usage

1. Activate the plugin — it creates its three tables (`*_stokechat_rooms`, `*_stokechat_messages`, `*_stokechat_members`) and grants the `stokechat_create_rooms` capability.
2. Put the `[stoke_chat]` shortcode (or the **Stoke Chat** block) on any page.
3. Visit **Settings → Stoke Chat** to set the chat page URL (used in alert emails), choose which roles may create rooms, and tune polling/email behaviour.

## Architecture

- PHP 7.4+, namespace `StokeChat\`, tiny custom autoloader — no Composer, no npm.
- REST API at `stoke-chat/v1` (cookie auth + `X-WP-Nonce`); clients poll with `?after=<last_message_id>` so responses carry only new messages.
- Frontend is a single `assets/js/chat.js` (vanilla ES2017). All user content renders via `textContent` — no `innerHTML` anywhere.
- Custom tables managed with `dbDelta` and a schema-version option for future upgrades.

## Development

A `wp-env` config is included:

```sh
cd Stoke-Chat
npx @wordpress/env start          # WordPress at http://localhost:8888
npx @wordpress/env run cli wp user create alice alice@example.test --role=author --user_pass=pass
```

Create a page with `[stoke_chat]`, log in as two different users in two browsers, and chat.

## License

GPLv2 or later.
