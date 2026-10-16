# Sports 803 Dashboard & Autopilot — WordPress plugin

A WordPress-native event publishing and match-management plugin. It creates **regular WordPress posts** and maintains their kickoff, score, match report, featured image, tags and verified replay content in place. It does not publish through a Blogger API or depend on GitHub Actions.

## Install

1. From the Dashboard repository root, run `bash scripts/build-wordpress-plugin.sh`.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, upload `dist/sports803-dashboard.zip`, and activate **Sports 803 Dashboard & Autopilot**.
3. Open the new **Sports 803** menu in the WordPress admin. Review the Autopilot configuration and run one manual check to inspect feed health.
4. Enable Autopilot when the settings are ready. It is **off by default**.
5. Add `[sports803_dashboard]` to a public page for the branded, searchable matchday dashboard. Use `[sports803_dashboard limit="30"]` to change the maximum event cards.

Requires WordPress 6.0+ and PHP 7.4+. For automatic team-graphic featured images, the host must have PHP **GD with FreeType** enabled. If it does not, event posts and text still work; the Activity log records the thumbnail error. Allow outbound HTTPS to ESPN, TheSportsDB, OneBall, Xoilac and LiveTV. No publishing credentials or API key are required by the current providers.

## What the engine manages

- **Fixture discovery and rescheduling:** scans the configured local-calendar window across the supported ESPN/competition catalog. Each competition is fetched in bounded rotating batches on the five-minute cadence. Stable event keys reuse the same WordPress post. A kickoff change reschedules that same unpublished post; a postponed/cancelled event moves to Draft and can be restored if the fixture returns.
- **Native preview posts:** creates the match/race title and structured article with competition, kickoff, verified score context, preview/report sections, reporting notes and a player block when a matched source is available. Existing published posts remain published when a kickoff changes.
- **Live update and facts:** when enabled, ESPN event summaries update the managed LIVE score/clock banner and then FULL TIME result. Available starting XIs, scorers, assists, cards and substitutions are added only when the summary provides them. Recent completed matches are rechecked at a slower interval for late-arriving facts.
- **Live source matching:** OneBall fixture IDs and Xoilac live fixture pages are matched to event teams. Generated links use the requested `https://sport803.github.io/player/` player with the `one` and/or `xoilac` parameters. Provider IDs and matched URLs are retained on the event post.
- **Highlights:** completed matches enter a retryable provider queue. The engine searches OneBall first and uses the LiveTV archive as a verified fallback. It confirms that the replay page exposes a video/player source before adding the responsive player embed and Highlights & Replay title to the **existing event post**.
- **Tags:** managed WordPress post tags follow the Dashboard publisher’s marker-first order: `s803:event:<stable-hash>`, Event Stream or Highlights, the `s803:thumbnail` tag when a featured image exists, competition/sport tags and team names, up to 20 managed tags. User-created tags are preserved.
- **Featured thumbnails:** generates a Sports 803 16:9 PNG as a native WordPress media attachment, with event state, score, kickoff, competition and team marks. It uses event logos when available and TheSportsDB as a cached logo fallback; it refreshes the plugin-owned image when relevant event facts change and leaves manually selected featured images alone. The bundled DejaVu font license is in `assets/fonts/`.
- **Retries and operations:** failed highlight jobs back off and retry, stale processing jobs are recovered, and jobs beyond the configured attempt limit enter Needs review. The **Sports 803** control center shows counts, provider status, latest-run metrics, review actions and recent activity.

## Scheduling and reliability

WordPress schedules a check every five minutes while Autopilot is enabled. Standard WP-Cron is triggered by site traffic, so it can be late on low-traffic sites. For reliable timing, configure a server/hosting cron to request `wp-cron.php` every five minutes. If `DISABLE_WP_CRON` is set, that external cron is required. A transient run lock prevents overlapping checks, and the engine bounds discovery and score/highlight jobs per request.

External sites may temporarily block requests or change their markup. Provider matching is cautious: a source URL is attached only after a team match, and a replay iframe is published only after a provider-specific verification check. Check **Sports 803 → Recent activity** and **Provider health** for the latest results. A smoke test verifies known fixture parsers and generated PNG behavior; actual feed availability still depends on the WordPress host’s network access.

## Migration note

The plugin is the WordPress publishing runtime; GitHub Actions is not needed for event autoposting. Install it on the target WordPress site, configure WP-Cron, then enable Autopilot there. If the plugin is not installed and enabled, it will not publish posts.
