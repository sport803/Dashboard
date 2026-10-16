# Sports 803 Dashboard

The repository contains the original Sports 803 dashboard sources and an installable **WordPress-native publishing plugin**. WordPress is the post destination; the plugin owns fixture discovery, post lifecycle, scores and verified highlights without Blogger credentials or scheduled GitHub Actions.

## WordPress plugin

Package source: `wordpress-plugin/sports803-dashboard/`.

The plugin creates native WordPress event posts with the Dashboard-style preview/highlight templates, stable event markers and sport/league/team tags, featured match graphics, ESPN score summaries, event rescheduling, draft handling for cancelled/postponed fixtures, and retryable verified-replay jobs. It matches ESPN fixtures with OneBall and Xoilac live sources, uses LiveTV as a replay fallback, and builds player links on `https://sport803.github.io/player/`.

It includes two dashboard surfaces:

- a professional **Sports 803** WordPress admin control center for settings, run status, provider health, job counters, Needs review actions and activity logs;
- a public, searchable matchday board through `[sports803_dashboard]`.

Build the uploadable archive and run PHP, source-provider, thumbnail and ZIP checks:

```bash
bash scripts/test-wordpress-plugin.sh
```

The generated `dist/sports803-dashboard.zip` is ignored by Git. For installation, cron requirements and provider behavior, see [`wordpress-plugin/sports803-dashboard/README.md`](wordpress-plugin/sports803-dashboard/README.md).

## Runtime notes

Autopilot is **off by default**. Install the ZIP on the target WordPress site, configure **Sports 803** and enable it there. WordPress checks every five minutes; standard WP-Cron is traffic-triggered, so a hosting/server cron requesting `wp-cron.php` every five minutes is recommended. Automatic featured thumbnails require PHP GD with FreeType.

The plugin embeds the current Player app at `https://sport803.github.io/player/` and sends OneBall replay URLs using the `.htm` format. Player-side parsing supports both `one` and `xoilac` sources.
