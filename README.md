# Sports 803 Dashboard

This repository contains the Sports 803 event dashboard and an installable WordPress plugin. The plugin creates and manages **native WordPress posts** for fixtures; WordPress is the publishing destination.

## WordPress plugin

The plugin package lives at `wordpress-plugin/sports803-dashboard/`. It provides:

- ESPN fixture discovery within a configurable date window and supported-league catalog;
- one WordPress post per fixture, with kickoff rescheduling and Draft handling for postponed/cancelled events;
- optional ESPN summary polling for LIVE score/clock and FULL TIME updates;
- an extensible replay-provider interface with OneBall verification and same-post player embeds;
- retry, stale-job recovery, Needs review state, activity logging, and a public native WordPress event listing shortcode.

Build the installable archive and run the WordPress-specific checks:

```bash
bash scripts/test-wordpress-plugin.sh
```

The output archive is `dist/sports803-dashboard.zip` (generated locally and ignored by Git). For installation and configuration, see [`wordpress-plugin/sports803-dashboard/README.md`](wordpress-plugin/sports803-dashboard/README.md).

## Autopilot runtime

Autopilot is disabled by default. After installing the plugin, enable it at **WordPress → Settings → Sports 803 Autopilot**. WordPress schedules checks every five minutes. WP-Cron is traffic-triggered, so use a hosting/server cron to request `wp-cron.php` every five minutes when dependable timing is required. The plugin requires no external publishing credentials or GitHub Actions secrets.

The repository still contains the original static dashboard and legacy publisher source for reference; the WordPress plugin does not embed or invoke those Blogger publishing paths. The plugin's public shortcode is rendered from WordPress-managed event posts.
