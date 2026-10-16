# Sports 803 WordPress Dashboard & Autopilot

A WordPress plugin that creates and manages **native WordPress posts** for sports events. No external publishing credentials are required.

## Install

1. Build the uploadable archive from the repository root: `bash scripts/build-wordpress-plugin.sh`.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, upload `dist/sports803-dashboard.zip`, and activate **Sports 803 Dashboard & Autopilot**.
3. Open **Settings → Sports 803 Autopilot** and configure the date window, leagues, publication timing, live scores, and highlight settings.
4. Enable **Autopilot** only when the settings are ready. It is off by default.
5. Add `[sports803_dashboard]` to a public page for a native WordPress listing of managed upcoming/recent event posts. Limit the number of cards with `[sports803_dashboard limit="30"]`.

Requires WordPress 6.0+ and PHP 7.4+. The server must allow outbound HTTPS to ESPN, TheSportsDB (racing fallback), and OneBall. No API secrets are required by the current providers.

## Autopilot engine

- WordPress schedules the engine every five minutes while Autopilot is enabled. WP-Cron runs when the site receives requests, so configure a hosting/server cron to request `wp-cron.php` every five minutes for reliable timing on low-traffic sites. If `DISABLE_WP_CRON` is set, that external cron is required.
- The event discovery window is configurable from 1–14 local calendar days. Supported leagues can be selected by comma-separated ID/name; a blank filter means all supported leagues. To bound each request, discovery rotates through the configured number of leagues on successive five-minute checks.
- A stable league/event key ensures each fixture maps to one existing WordPress post. If kickoff changes, the same scheduled post is rescheduled. If ESPN reports a fixture postponed or cancelled, an existing managed post is moved to Draft; if the fixture returns to the schedule, that same post is restored.
- New posts can be scheduled for kickoff (default) or published immediately. Existing published posts remain published when a later kickoff adjustment arrives.
- With score synchronization enabled, eligible managed match posts are checked through ESPN event-summary endpoints. The engine updates one LIVE score/clock banner and replaces it with a FULL TIME result when the event ends.
- Finished matches are queued for highlight discovery when automatic highlights are enabled. Providers implement a common interface; OneBall is the configured provider. The plugin uses the OneBall match ID retained from the fixture feed when available, verifies a replay page over HTTPS, then adds the player embed to the **same WordPress post**.
- Highlight searches use exponential retry delays. Stale `processing` jobs are recovered; jobs that reach the configured attempt limit are marked **Needs review**. Administrators can retry them from the settings page.
- A transient prevents overlapping runs. The settings page shows run counts, job counters, recent activity, and per-post highlight errors. The activity log is capped at 200 entries.

## Operational note

This package owns the autopost lifecycle in WordPress; it does not rely on GitHub Actions for event publishing. Install and enable the plugin on the target WordPress site to start its five-minute checks. WP-Cron must be configured on that site for scheduled checks and scheduled post publication.
