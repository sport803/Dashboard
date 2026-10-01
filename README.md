# Sports 803 Dashboard

## GitHub Actions Blogger auto-poster

The repository includes an unattended worker at `scripts/blogger-auto-poster.mjs` and the scheduled workflow `.github/workflows/blogger-auto-poster.yml`.

Every five minutes, the workflow:

1. Reads the Dashboard event feed from Firebase (or `BLOGGER_EVENTS_URL` when provided).
2. Keeps only events that have a stream/player URL.
3. Creates one **LIVE** Blogger post per event.
4. Uses a stable `s803:event:<hash>` label to prevent duplicate posts.
5. After the event is final, updates that same post with a highlights/replay article and result.

The workflow supports event data for all Dashboard leagues. The racing detection aliases include **MotoGP, NASCAR, WRC, IMSA, and Porsche Carrera Cup**.

### Required GitHub repository secrets

Add these under **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `BLOGGER_BLOG_ID` | Numeric Blogger blog ID |
| `BLOGGER_GOOGLE_CLIENT_ID` | OAuth client ID for the Blogger API |
| `BLOGGER_GOOGLE_CLIENT_SECRET` | OAuth client secret |
| `BLOGGER_REFRESH_TOKEN` | OAuth refresh token with Blogger scope |

Optional:

| Secret | Purpose |
|---|---|
| `BLOGGER_EVENTS_URL` | Override the default Firebase `todaysMatches` JSON endpoint |

The refresh token is required because GitHub Actions does not have a browser session. Do not commit it to the repository or place it in `index.html`.

### Running it

- The schedule runs every five minutes after the secrets are present.
- Use **Actions → Blogger event auto-poster → Run workflow** for a manual run.
- A run with no matching stream events safely makes no Blogger changes.

The existing dashboard UI still supports interactive Google sign-in, manual posting, bulk posting, and its browser-tab scheduler. The GitHub Action is the unattended path.
