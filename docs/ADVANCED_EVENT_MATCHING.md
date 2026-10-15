# Advanced Event Matching and Firebase Synchronization

## Audit summary

The Dashboard already had ESPN and TheSportsDB ingestion, OneBall/Xoilac/LiveTV/SuperSport enrichment, Blogger idempotency labels, and browser-side Firebase post-log helpers. The highest-impact gaps were:

- provider matching was split across ad-hoc `pairKey`, substring checks, and feed-specific scans;
- source links did not carry confidence, evidence, or ambiguity information;
- there was no canonical event/provider-record model for preserving multiple sources;
- the browser could read/write post logs but did not publish a verified canonical event record to the existing Firebase event path;
- Firebase writes were fire-and-forget with no read-back verification;
- the existing consumer schema at `s803config/todaysMatches` needed to remain compatible.

## Implemented changes

### Canonical matching engine

`[scripts/event-matching.mjs](../scripts/event-matching.mjs)` now provides:

- Unicode/punctuation folding and configurable team aliases;
- competition aliases, including UEFA Conference League variants;
- normalized sport and kickoff fields;
- deterministic `s803_<hash>` canonical IDs;
- confidence scoring with explicit reasons and conflicts;
- strict default behavior: **90+ auto**, **70–89 review**, below 70 unmatched;
- competition, sport, team-order, reversal, and kickoff-tolerance checks;
- provider matching with tied-candidate rejection;
- canonical merge that retains multiple provider records;
- duplicate collapse only for the same provider and provider event ID.

The autoposter now uses the engine when attaching OneBall and Xoilac links. Provider links include `matchConfidence` and `matchReason`; ambiguous/tied candidates are not attached.

Provider feeds often omit kickoff and competition metadata. For those feeds, a unique team-pair match is retained as review-level evidence. Reversed order is permitted only in this narrow provider-feed path and only when no candidate tie or hard conflict exists. The general engine remains conservative by default.

### Firebase synchronization

The dashboard now has a manual **Sync canonical events** control and an opt-in **Auto-sync canonical events** setting. Each event is written below the configured existing event path, normally:

- `s803config/todaysMatches/auto_<provider-event-id>` — legacy-compatible event record;
- `s803config/syncLogs/<timestamp>` — sync summary;
- `s803config/postLog/<event-key>` — existing Blogger post log;
- `s803config/oneball/<match-id>` — existing OneBall lookup cache;
- `livetv/channels` — existing channel library, not rewritten by event sync.

Canonical records preserve existing fields (`id`, `title`, `homeName`, `awayName`, `league`, `kickoff`, `startTime`, `statusType`, `playerUrl`, `oneballId`, `source`) and add:

```json
{
  "event_id": "s803_<deterministic-hash>",
  "schema_version": 2,
  "leagueId": "epl",
  "providers": [
    {
      "provider_name": "onetv",
      "provider_event_id": "123",
      "provider_event_url": "https://…",
      "link_status": "unverified",
      "match_confidence": 50,
      "match_reason": "…",
      "last_seen": "2026-10-15T00:00:00.000Z"
    }
  ],
  "updatedAt": "2026-10-15T00:00:00.000Z"
}
```

Writes are followed by a read-back comparison. A failed write or mismatch is counted and shown in the UI; existing records are not deleted during sync. This is additive and compatible with consumers that read the legacy fields.

### Live Firebase contract check

The configured RTDB was read during the upgrade. The observed paths were:

| Path | Observed shape |
|---|---|
| `s803config/todaysMatches` | object with 825 existing records; legacy fields such as `id`, `homeName`, `awayName`, `kickoff`, `playerUrl`, and `statusType` |
| `s803config/postLog` | currently empty/null |
| `s803config/oneball` | currently empty/null |
| `livetv/channels` | object with 89 channel records |

The new code does not claim that writes are authorized in every deployment. The UI’s contract verification reports read availability, and the sync operation reports actual write/read-back results.

## Matching rules

1. An exact same-provider/provider-event-ID match is 100 and auto-resolves.
2. Normalized home/away teams are scored before fuzzy token similarity.
3. Competition and sport conflicts are hard conflicts and prevent automatic linking.
4. Kickoff differences of five minutes or less are strong evidence; differences within the configured tolerance are weaker evidence.
5. Reversed home/away order is not accepted by default. It requires explicit permission and sufficient evidence.
6. Tied candidates are not automatically selected.
7. Existing provider links are retained; a new provider is appended rather than replacing the previous source.
8. Verified/manual associations should be stored with the provider ID and must not be overwritten by a lower-confidence refresh.

## Testing report

Command run:

```bash
node --check scripts/event-matching.mjs
node --check scripts/blogger-auto-poster.mjs
npm test
```

Result: **22 tests passed, 0 failed**.

Coverage includes:

- date-window and East Africa Time behavior;
- exact and reversed provider fixtures;
- OneBall/Xoilac matching and U19 rejection;
- LiveTV Russian-name translation and iframe filtering;
- team and competition aliases;
- sport/competition conflicts;
- provider candidate selection and ambiguity evidence;
- canonical multi-provider merge;
- duplicate provider IDs;
- player URL repair, thumbnail PNG conversion, and Blogger update behavior.

Tests are deterministic and use mocked fetch responses where network behavior is exercised. A live write to Firebase was not performed automatically by the test suite.

## Security and reliability notes

- No secrets were added to source control.
- Existing browser Firebase REST writes remain subject to the RTDB rules configured by the project; read-back verification is not authentication.
- Canonical sync is manual by default to avoid unexpected bulk writes. Auto-sync must be explicitly enabled in Dashboard settings.
- Existing event records are updated per child path; the sync does not replace the entire event collection or delete stale records.
- Provider outages remain isolated: an unavailable enrichment feed does not erase the canonical event or other provider links.

## Deployment and rollback

1. Deploy the normal GitHub Pages artifact as usual; `index.html` and the new `scripts/` files are static assets.
2. Keep the existing Firebase URL and paths unless the website migration has been verified separately.
3. Run the repository test command before merging.
4. For a safe first rollout, use **Verify paths**, then manually sync a small event set.
5. To roll back, disable Auto-sync canonical events and revert the feature branch/commit. Existing legacy fields remain readable because the new fields are additive.
6. If a migration is later required, migrate one event child at a time and keep a backup of the affected Firebase subtree. No destructive migration is required for this change.

## Remaining limitations

- Firebase security rules and authenticated write permissions were not changed; the repository does not contain rules or a service-account deployment configuration.
- The browser and Node matching implementations share the same documented rules but are separate runtimes; the browser’s existing `pairKey` remains for legacy UI mappings.
- A provider that supplies only team names and no kickoff/competition remains review-level evidence rather than a fully verified canonical association.
- Administrative approval queues, full historical audit UI, and provider health dashboards require a backend or authenticated Firebase rules model not present in this static repository. The new confidence/reason metadata is the foundation for that follow-up work.
