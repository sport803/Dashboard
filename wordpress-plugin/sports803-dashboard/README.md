# Sports 803 Dashboard & Blogger Autopilot (WordPress plugin)

This plugin embeds the existing Dashboard and can run the multi-blog Blogger autoposter from WordPress Cron. **The publishing destination remains Blogger**; it does not create WordPress posts.

## Install

1. Zip the `sports803-dashboard` directory and upload it through **Plugins → Add New → Upload Plugin**, or install the ZIP artifact produced by the repository build.
2. Activate **Sports 803 Dashboard & Blogger Autopilot**.
3. Open **Settings → Sports 803 Autopilot** and enter the Blogger blog IDs and OAuth credentials. The plugin cannot read GitHub Actions secrets; re-enter the existing credentials in these protected WordPress settings. Secret fields are write-only in the UI: leave them blank to retain saved values.
4. Keep **Autopilot** disabled while the GitHub schedule is still active. For cutover, disable the GitHub Actions schedule first, save the Blogger settings, and then enable WordPress Autopilot.
5. Add `[sports803_dashboard]` to a WordPress page to embed the Dashboard. Use `[sports803_dashboard height="1100"]` to set a different iframe height.

The hosting account must allow installation of custom plugins and outbound HTTPS requests to ESPN, OneBall, Xoilac, Google OAuth/Blogger, and optionally ImgBB. The Google account behind the refresh token must be allowed to publish to every configured Blogger blog.

## Autopilot behavior

- A custom WP-Cron event is scheduled every five minutes while Autopilot is enabled and credentials are configured.
- WordPress Cron is traffic-triggered. For dependable five-minute timing on a quiet site, configure a hosting/server cron to request `wp-cron.php` every five minutes. If `DISABLE_WP_CRON` is enabled, this external cron is required.
- The plugin discovers today/tomorrow ESPN events across its supported league catalog, with a TheSportsDB fallback for racing events; it matches live/replay sources from OneBall and Xoilac.
- It posts immediately to Blogger, uses the same `s803:event:<FNV-1a marker>` labels as the repository worker to avoid duplicate posts, and updates the matching post when an event becomes final.
- Each run writes at most 10 posts per Blogger blog by default. The limit is configurable from 1–25 in the plugin settings. Already-posted event markers are skipped, and remaining events are rechecked on the next run. Blogger 429 responses defer remaining events instead of retrying every event until timeout.
- A transient lock prevents overlapping runs. The settings page shows the last per-blog created/updated/skipped/failed/deferred counts and provides a manual **Run autopilot now** action.
- If the PHP GD extension and an ImgBB key are available, the plugin generates and uploads a simple Sports 803 thumbnail. Without them, posts can still be published without a thumbnail.

## Current parity boundary

This initial WordPress port covers ESPN event discovery, multi-blog Blogger OAuth/posting, markers, final-status updates, batching, OneBall/Xoilac stream matching, and optional thumbnails. The richer Node worker's player-level lineup enrichment, SuperSport/LiveTV replay fallback, and its detailed highlight/article enrichment have not yet been ported. The embedded dashboard UI is the current `index.html` copied into the plugin asset; rebuild/copy it when the repository dashboard changes.

## Security and operations

- Do not commit OAuth client secrets, Blogger refresh tokens, or ImgBB keys.
- Only WordPress administrators can view/edit settings or invoke a manual run.
- Autopilot is off by default. Disable the GitHub schedule before turning on WordPress autopilot; both publishers use the same marker for idempotency, but simultaneous runs can still race.
- Requires WordPress 6.0+ and PHP 7.4+. Image uploads additionally require PHP GD and the optional ImgBB key.
