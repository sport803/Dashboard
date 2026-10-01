# Sports 803 Dashboard

## GitHub Actions Blogger auto-poster

The repository includes an unattended worker at `scripts/blogger-auto-poster.mjs` and the scheduled workflow `.github/workflows/blogger-auto-poster.yml`.

Every five minutes, the workflow:

1. Reads the Dashboard event feed from Firebase (or `BLOGGER_EVENTS_URL` when provided).
2. Keeps only events that have a stream/player URL.
3. Creates one **LIVE** Blogger post per event.
4. Uses a stable `s803:event:<hash>` label to prevent duplicate posts.
5. After the event is final, updates that same post with a highlights/replay article and result.
6. Uses the Dashboard-style article sections, titles, labels, embedded Sports 803 player, and an ImgBB-hosted thumbnail.

Only events with a valid scheduled start time dated **today or later** are eligible. Historical events are ignored, which prevents the first workflow run from flooding Blogger. The default date comparison uses UTC; set the optional repository variable `EVENT_TIMEZONE` (for example, `Africa/Nairobi`) under **Settings → Secrets and variables → Actions → Variables** if “today” should follow your local timezone.

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

Optional:

| Secret | Purpose |
|---|---|
| `BLOGGER_EVENTS_URL` | Override the default Firebase `todaysMatches` JSON endpoint |

Optional Actions variable:

| Variable | Purpose |
|---|---|
| `EVENT_TIMEZONE` | IANA timezone used for the today/future filter; defaults to `UTC` |
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
