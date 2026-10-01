# Sports 803 Dashboard

## GitHub Actions Blogger auto-poster

The repository includes an unattended worker at `scripts/blogger-auto-poster.mjs` and the scheduled workflow `.github/workflows/blogger-auto-poster.yml`.

Every five minutes, the workflow:

1. Queries the same ESPN scoreboard endpoints and league catalog used by the Dashboard UI (not Firebase).
2. Keeps all non-cancelled events scheduled today; an external stream/player URL is optional because Dashboard event cards are valid before stream enrichment.
3. Creates one **LIVE** Blogger post per event.
4. Uses a stable `s803:event:<hash>` label to prevent duplicate posts.
5. After the event is final, updates that same post with a highlights/replay article and result.
6. Uses the Dashboard-style article sections, titles, labels, embedded Sports 803 player, and an ImgBB-hosted thumbnail.

Only events with a valid scheduled start time dated **today** are eligible. Historical and future events are ignored, which prevents the workflow from flooding Blogger. The default date comparison uses `Africa/Nairobi`; set the optional repository variable `EVENT_TIMEZONE` under **Settings → Secrets and variables → Actions → Variables** when another timezone should define “today”.

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
