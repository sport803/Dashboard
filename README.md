# Sports 803 Dashboard

## GitHub Actions Blogger auto-poster

The repository includes an unattended worker at `scripts/blogger-auto-poster.mjs` and the scheduled workflow `.github/workflows/blogger-auto-poster.yml`.

Every five minutes, the workflow:

1. Queries the same ESPN scoreboard endpoints and league catalog used by the Dashboard UI (not Firebase).
2. Keeps all non-cancelled events scheduled today; an external stream/player URL is optional because Dashboard event cards are valid before stream enrichment.
3. Creates one **LIVE** Blogger post per event.
4. Uses a stable `s803:event:<hash>` label to prevent duplicate posts.
5. After the event is final, updates that same post with a highlights/replay article and result.
6. Resolves the matching OneBall live page and August PPV embed feed, then adds them to the Sports 803 player URL using the same `one=` and `embed=` parameters as the Dashboard.
7. Generates a Dashboard-style logo-based thumbnail and uploads it to ImgBB.

Normal events with a valid scheduled start time dated **today** are eligible. Racing events use the selected date plus or minus one day so multi-day weekends are not missed. Older unrelated events are still ignored. The default date comparison uses `Africa/Nairobi`; set the optional repository variable `EVENT_TIMEZONE` under **Settings → Secrets and variables → Actions → Variables** when another timezone should define “today”.

For a match with a OneBall listing, the generated article contains a player iframe such as `https://www.sport803.online/p/player.html?one=https://oneball.live/live/<match-id>.html`. If August PPV has the same fixture, its `embed=` source is added to that same player URL as a backup/source. The Action uses the stable OneBall match page rather than expiring raw signal URLs.

The publisher uses bounded HTTP timeouts and retries for transient feed, logo, ImgBB, OAuth, and Blogger failures. It caches successful team-logo lookups between runs, validates its environment before making network calls, limits Blogger labels to 20, fetches existing post bodies only when a player-link comparison is needed, and reports per-event failures without preventing other events from being processed.

### Racing event discovery

The dashboard and Action now use a three-day window (target date ±1 day) and deduplicate events by ID. ESPN remains the primary source for normal leagues and supported Formula 1/IndyCar feeds. MotoGP, WRC, IMSA, NASCAR, and other unsupported racing slugs use TheSportsDB's Motorsport day feed, cached once per date per run. Race events from adjacent days are retained so multi-day weekends are visible on the selected date. Broken sources are reported in the dashboard source legend and in the Action log instead of silently becoming zero events.

The workflow supports event data for all Dashboard leagues. The racing detection aliases include **MotoGP, NASCAR, WRC, IMSA, and Porsche Carrera Cup**.

### Required GitHub repository secrets

Add these under **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `BLOGGER_BLOG_ID` | Numeric Blogger blog ID |
| `BLOGGER_GOOGLE_CLIENT_ID` | OAuth client ID for the Blogger API |
| `BLOGGER_GOOGLE_CLIENT_SECRET` | OAuth client secret |
| `BLOGGER_REFRESH_TOKEN` | OAuth refresh token with Blogger scope |
| `IMGBB_API_KEY` | ImgBB API key used to upload each generated event thumbnail |

Optional Actions variable:

| Variable | Purpose |
|---|---|
| `EVENT_TIMEZONE` | IANA timezone used for the ESPN date query and today filter; defaults to `Africa/Nairobi` |
| `AUTO_POST_LEAGUES` | Comma-separated league IDs/names to publish; blank means all eligible leagues |

### Choosing leagues

Create or edit the repository variable `AUTO_POST_LEAGUES` under **Settings → Secrets and variables → Actions → Variables**. For example:

```text
epl,f1,motogp,nascar,wrc,imsa,porsche-carrera-cup
```

Use the Dashboard league IDs where possible. The worker also recognizes league names and aliases such as Formula 1, MotoGP, NASCAR, WRC, IMSA, and Porsche Carrera Cup. This setting is independent of the Dashboard browser's local `activeLeagues` setting, because browser localStorage is not available inside GitHub Actions.

The refresh token is required because GitHub Actions does not have a browser session. Do not commit it to the repository or place it in `index.html`.

### Running it

- The schedule runs every five minutes after the secrets are present.
- Use **Actions → Blogger event auto-poster → Run workflow** for a manual run.
- A run with no matching stream events safely makes no Blogger changes.

The existing dashboard UI still supports interactive Google sign-in, manual posting, bulk posting, and its browser-tab scheduler. The GitHub Action is the unattended path.
