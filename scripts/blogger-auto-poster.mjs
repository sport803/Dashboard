#!/usr/bin/env node

const BLOGGER_API = 'https://www.googleapis.com/blogger/v3';
const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const ESPN_API = 'https://site.api.espn.com/apis/site/v2/sports';
const PLAYER_BASE = process.env.PLAYER_BASE_URL || 'https://www.sport803.online/p/player.html';
// Keep this catalog in sync with the Dashboard's LEAGUES list. The browser
// dashboard fetches these ESPN scoreboard feeds directly; Firebase is only
// used by the dashboard for post-log/cloud data, not for event discovery.
const DASHBOARD_LEAGUES = [
  ['ucl', 'soccer', 'uefa.champions', 'Champions League', 'match'], ['uwcl', 'soccer', 'uefa.wchampions', "UEFA Women's Champions League", 'match'], ['uel', 'soccer', 'uefa.europa', 'Europa League', 'match'],
  ['epl', 'soccer', 'eng.1', 'Premier League', 'match'], ['laliga', 'soccer', 'esp.1', 'La Liga', 'match'], ['seriea', 'soccer', 'ita.1', 'Serie A', 'match'], ['bundesliga', 'soccer', 'ger.1', 'Bundesliga', 'match'], ['ligue1', 'soccer', 'fra.1', 'Ligue 1', 'match'], ['mls', 'soccer', 'usa.1', 'MLS', 'match'],
  ['worldcup', 'soccer', 'fifa.world', 'FIFA World Cup', 'match'], ['euro', 'soccer', 'uefa.euro', 'UEFA Euro', 'match'], ['afconqual', 'soccer', 'caf.nations_qual', 'Africa Cup of Nations Qualifiers', 'match'], ['euroqual', 'soccer', 'uefa.euroq', 'UEFA Euro Qualifiers', 'match'], ['concacafnl', 'soccer', 'concacaf.nations.league', 'Concacaf Nations League', 'match'], ['nations', 'soccer', 'uefa.nations', 'UEFA Nations League', 'match'], ['intlfriendly', 'soccer', 'fifa.friendly', 'Intl Friendlies', 'match'],
  ['nba', 'basketball', 'nba', 'NBA', 'match'], ['wnba', 'basketball', 'wnba', "Women's National Basketball Association", 'match'], ['nfl', 'football', 'nfl', 'NFL', 'match'], ['nhl', 'hockey', 'nhl', 'NHL', 'match'],
  ['f1', 'racing', 'f1', 'Formula 1', 'race'], ['nascar', 'racing', 'nascar-cup-series', 'NASCAR', 'race'], ['wrc', 'racing', 'wrc', 'WRC', 'race'], ['imsa', 'racing', 'imsa', 'IMSA', 'race'], ['porsche-carrera-cup', 'racing', 'porsche-carrera-cup', 'Porsche Carrera Cup', 'race'], ['pga', 'golf', 'pga', 'PGA Tour', 'race'], ['ufc', 'mma', 'ufc', 'UFC / MMA', 'match'], ['atp', 'tennis', 'atp', 'ATP Tennis', 'match'], ['wta', 'tennis', 'wta', 'WTA Tennis', 'match'],
  ['efl', 'soccer', 'eng.2', 'Championship', 'match'], ['efltrophy', 'soccer', 'eng.trophy', 'English Football League Trophy', 'match'], ['spl', 'soccer', 'ksa.1', 'Saudi Pro League', 'match'], ['eredivisie', 'soccer', 'ned.1', 'Eredivisie', 'match'], ['primeira', 'soccer', 'por.1', 'Primeira Liga', 'match'], ['facup', 'soccer', 'eng.fa', 'FA Cup', 'match'], ['motogp', 'racing', 'motogp', 'MotoGP', 'race'], ['cycling', 'cycling', 'world', 'Cycling', 'race'], ['mlb', 'baseball', 'mlb', 'MLB', 'match']
].map(([id, sport, slug, name, type]) => ({ id, sport, slug, name, type }));
const ALLOWED_LEAGUE_IDS = new Set([
  'motogp', 'nascar', 'wrc', 'imsa', 'porsche-carrera-cup', 'porsche',
  'f1', 'pga', 'cycling'
]);
const LEAGUE_ALIASES = [
  ['motogp', /motogp|moto\s*gp/i],
  ['nascar', /nascar/i],
  ['wrc', /\bwrc\b|world rally/i],
  ['imsa', /\bimsa\b|weathertech sportscar/i],
  ['porsche-carrera-cup', /porsche\s*carrera\s*cup/i],
  ['f1', /formula\s*1|\bf1\b|grand prix/i],
  ['pga', /pga tour/i],
  ['cycling', /cycling|tour de france|giro d.?italia|vuelta/i]
];
const SPORT_LABELS = {
  soccer: ['Football', 'Football Live Stream'], basketball: ['Basketball', 'Basketball Live Stream'],
  football: ['American Football', 'Football Live Stream'], hockey: ['Ice Hockey', 'Hockey Live Stream'],
  tennis: ['Tennis', 'Tennis Live Stream'], baseball: ['Baseball', 'Baseball Live Stream'],
  racing: ['Motorsport', 'Live Racing'], golf: ['Golf', 'Golf Live Stream'],
  mma: ['MMA', 'Combat Sports'], cycling: ['Cycling', 'Cycling Live Stream']
};

const required = (name) => {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
};

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function text(value) { return String(value ?? '').trim(); }
function htmlEscape(value) {
  return text(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function stripHtml(value) { return text(value).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim(); }
function stableHash(value) {
  let hash = 2166136261;
  for (const char of text(value)) { hash ^= char.charCodeAt(0); hash = Math.imul(hash, 16777619); }
  return (hash >>> 0).toString(16).padStart(8, '0');
}
function isFinal(event) {
  const status = text(event.status || event.statusType || event.state || event.competitionStatus);
  if (/final|finished|completed|ended|full.?time|post.?match|cancelled|canceled|abandoned/i.test(status)) return true;
  const end = event.endTime || event.completedAt;
  return Boolean(end && new Date(end).getTime() <= Date.now());
}
function isDead(event) { return /postponed|cancelled|canceled|suspended|abandoned/i.test(text(event.status || event.statusType)); }
function eventName(event) {
  if (event.title) return text(event.title).replace(/\s*[–-]\s*(?:Live|Scheduled|Stream).*$/i, '');
  if (event.name) return text(event.name);
  const home = event.homeName || event.home?.name || event.homeTeam || '';
  const away = event.awayName || event.away?.name || event.awayTeam || '';
  return [home, away].filter(Boolean).join(' vs ') || 'Sports event';
}
function leagueName(event) {
  const league = typeof event.league === 'object' ? event.league?.name : event.league;
  return text(event.leagueName || league || event.category || event.series || event.competition || 'Live Sports');
}
function leagueId(event) { return text(event.leagueId || event.league?.id || event.sportId || '').toLowerCase(); }
function canonicalLeague(event) {
  const haystack = `${leagueId(event)} ${leagueName(event)} ${eventName(event)}`;
  return LEAGUE_ALIASES.find(([, regex]) => regex.test(haystack))?.[0] || leagueId(event) || 'sports';
}
function matchesConfiguredLeague(event) {
  const configured = text(process.env.AUTO_POST_LEAGUES || process.env.LEAGUES).toLowerCase();
  if (!configured) return true;
  const wanted = new Set(configured.split(',').map(x => x.trim()).filter(Boolean));
  const id = canonicalLeague(event);
  return wanted.has(id) || wanted.has(leagueId(event)) || wanted.has(leagueName(event).toLowerCase());
}
function homeName(event) { return text(event.homeName || event.home?.name || event.homeTeam || ''); }
function awayName(event) { return text(event.awayName || event.away?.name || event.awayTeam || ''); }
function isRace(event) { return text(event.type).toLowerCase() === 'race' || /racing|motogp|nascar|wrc|imsa|porsche|formula\s*1|\bf1\b/i.test(`${leagueId(event)} ${leagueName(event)} ${eventName(event)}`); }
function displayEventName(event) { return [homeName(event), awayName(event)].filter(Boolean).join(' vs ') || eventName(event); }
function eventScore(event) {
  if (event.score || event.result) return text(event.score || event.result);
  if (homeName(event) && (event.home?.score !== undefined || event.homeScore !== undefined)) return `${event.home?.score ?? event.homeScore}–${event.away?.score ?? event.awayScore ?? ''}`;
  return '';
}
function startTime(event) { return event.startTime || event.kickoff || event.date || event.start || event.scheduledAt || null; }
function eventDateText(event) {
  const value = startTime(event), date = value ? new Date(value) : null;
  return date && Number.isFinite(date.getTime()) ? date.toLocaleString('en-US', { timeZone: process.env.EVENT_TIMEZONE || 'UTC', weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }) : 'the scheduled time';
}
function localDate(value, timeZone) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}
function isToday(event) {
  const scheduled = startTime(event);
  if (!scheduled) return false;
  const timeZone = process.env.EVENT_TIMEZONE || 'Africa/Nairobi';
  const eventDate = localDate(scheduled, timeZone);
  const today = localDate(Date.now(), timeZone);
  return Boolean(eventDate && today && eventDate === today);
}
function streamLinks(event) {
  const values = [];
  const add = (value) => { if (typeof value === 'string' && /^https?:\/\//i.test(value)) values.push(value); };
  ['playerUrl', 'playerURL', 'streamUrl', 'streamURL', 'liveUrl', 'liveURL', 'embedUrl', 'embedURL', 'stream', 'url'].forEach(key => add(event[key]));
  for (const key of ['streams', 'sources', 'links']) {
    const list = Array.isArray(event[key]) ? event[key] : [];
    for (const item of list) add(typeof item === 'string' ? item : item?.playerUrl || item?.streamUrl || item?.url || item?.src);
  }
  return [...new Set(values)];
}
function replayLinks(event) {
  const values = [];
  const add = (value) => { if (typeof value === 'string' && /^https?:\/\//i.test(value)) values.push(value); };
  ['highlightsUrl', 'highlightUrl', 'replayUrl', 'replayURL', 'replayPageUrl', 'highlightsVideo'].forEach(key => add(event[key]));
  if (event.oneballId) add(`${PLAYER_BASE}?one=${encodeURIComponent(`https://sports803.github.io/player/replay/${event.oneballId}`)}`);
  return [...new Set(values)];
}
function eventKey(event, index) {
  const raw = text(event.id || event.matchId || event.eventId || event.oneballId || `${eventName(event)}|${startTime(event) || index}`);
  return `${canonicalLeague(event)}|${raw}`;
}
function markerFor(key) { return `s803:event:${stableHash(key)}`; }
function labelsFor(event, key, final = false) {
  const sport = text(event.sport || event.category || '').toLowerCase();
  return [...new Set([
    leagueName(event), leagueId(event), sport, isRace(event) ? 'Motorsport' : 'Sports', 'Sports 803',
    ...(SPORT_LABELS[sport] || ['Live Sports']), markerFor(key),
    ...(homeName(event) ? [homeName(event)] : []), ...(awayName(event) ? [awayName(event)] : []),
    final ? 'Highlights' : 'Event Stream'
  ].filter(Boolean))];
}
function playerUrlFor(event, streams) {
  const existing = streams.find(url => /player|embed/i.test(url));
  if (existing) return existing;
  const first = streams[0];
  return first ? `${PLAYER_BASE}?mora=${encodeURIComponent(first)}` : '';
}
function dashboardSections(event, mode) {
  const h = htmlEscape(homeName(event) || eventName(event)), a = htmlEscape(awayName(event)), league = htmlEscape(leagueName(event));
  const fixture = `${h}${a ? ` vs ${a}` : ''}`, kickoff = htmlEscape(eventDateText(event)), score = eventScore(event) ? `<br><strong>Score:</strong> ${htmlEscape(eventScore(event))}` : '';
  if (isRace(event)) return `<h2>${htmlEscape(leagueName(event))} Race Preview &amp; Live Stream</h2><p>Follow the latest ${league} event on Sports 803 with race context, start-time information, and live viewing details.</p><h2>Race Overview</h2><p>Follow the pace, decisive moves, and turning points from start to finish. The event will bring together the key battles, tactical calls, and late-race pressure that define this competition.</p><h2>Key Moments</h2><p>Review the starts, battles, overtakes, tactical calls, and late-race pressure that shape the replay. Multiple player sources are provided below when available.</p><h2>Championship Impact</h2><p>The result adds important context to the season standings and the next round. Check back on Sports 803 for more ${league} coverage.</p><h2>How To Watch</h2><p>Use the embedded Sports 803 player below to watch the event live and revisit the full replay.</p>`;
  if (mode === 'highlights') return `<h2>Match Overview</h2><p>${fixture} featured in ${league}. This highlights article brings together the match context, the decisive passages, and the replay so readers can follow the story from the opening phase to the final whistle.</p><h2>Match Details</h2><p><strong>Event:</strong> ${fixture}<br><strong>Competition:</strong> ${league}<br><strong>Scheduled:</strong> ${kickoff}${score}</p><h2>Key Moments</h2><p>The key moments came from changes in tempo, chances created in dangerous areas, defensive recoveries, and the sequences that changed the momentum. Watch the highlights to see each important passage in context.</p><h2>Standout Performers</h2><p>Players who carried the ball forward, created openings, defended dangerous situations, or delivered important set pieces shaped the contest. The replay provides the clearest way to review those contributions.</p><h2>Tactical Review</h2><p>The tactical picture was shaped by pressing intensity, spacing between the lines, transitions, and the way each side responded after losing possession. Reviewing the full sequence helps explain more than the final score alone.</p><h2>What the Result Means</h2><p>This result will influence confidence and preparation for the next round of fixtures. Both sides can use the performance to identify the spells that worked best and the moments that need improvement.</p><h2>How to Watch</h2><p>Use the embedded Sports 803 player and replay link in this article to revisit the main action.</p><h2>Frequently Asked Questions</h2><p><strong>Where can I watch ${fixture}?</strong><br>Return to this Sports 803 article for the latest replay and event information.</p>`;
  return `<h2>Match Overview</h2><p>${fixture} take on each other in ${league}. This article covers the event context, the main storylines, and the viewing information readers need before the action begins.</p><h2>Match Details</h2><p><strong>Event:</strong> ${fixture}<br><strong>Competition:</strong> ${league}<br><strong>Kickoff:</strong> ${kickoff}</p><h2>Team Form</h2><p>${h} will aim to turn preparation and recent performances into a strong start, while ${a || 'the opposition'} will look for the same. Lineups, availability, confidence, and recent chances will shape the form picture.</p><h2>Head-to-Head</h2><p>The history between these sides adds context to the occasion, but current form and match-day execution remain decisive. Previous meetings can reveal recurring tactical patterns and areas where either side may gain an advantage.</p><h2>Key Players to Watch</h2><p>Watch for the players who can create separation, progress possession, win duels, and make the final pass. Both sides will look for individual moments to change the game.</p><h2>Match Analysis</h2><p>Expect a tactical contest built around possession, pressing, defensive shape, and transitions. The team that manages space between the lines and reacts best after turnovers should create the clearest openings.</p><h2>How to Watch</h2><p>Follow this Sports 803 article for the latest event details and use the embedded player when the stream is available.</p><h2>Frequently Asked Questions</h2><p><strong>When is the event?</strong><br>The latest scheduled time is listed in the Match Details section. <strong>Where can I watch?</strong><br>Use the Sports 803 player and stream information attached to this article.</p>`;
}
function playerIframe(streams) { const player = playerUrlFor(null, streams); return player ? `<div style="margin:18px 0;position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" src="${htmlEscape(player)}" frameborder="0" scrolling="no" allowfullscreen></iframe></div>` : ''; }
function previewHtml(event, streams) { return dashboardSections(event, 'preview') + playerIframe(streams); }
function highlightsHtml(event, streams) { const links = replayLinks(event); return dashboardSections(event, 'highlights') + playerIframe(links.length ? links : streams); }
function thumbnailSvg(event, title, final) {
  const name = htmlEscape(displayEventName(event)), league = htmlEscape(leagueName(event)), date = htmlEscape(eventDateText(event));
  const bg = final ? '#1a0020' : '#0d1b2a';
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop stop-color="${bg}"/><stop offset="1" stop-color="#0a0e1a"/></linearGradient></defs><rect width="1920" height="1080" fill="url(#g)"/><rect x="80" y="80" width="1760" height="920" rx="34" fill="none" stroke="#e63946" stroke-width="8"/><text x="140" y="180" fill="#e63946" font-family="Arial,sans-serif" font-size="42" font-weight="700">SPORTS 803</text><text x="140" y="430" fill="white" font-family="Arial,sans-serif" font-size="76" font-weight="700">${name}</text><text x="140" y="535" fill="#4cc9f0" font-family="Arial,sans-serif" font-size="48">${league}</text><text x="140" y="650" fill="#d7dde8" font-family="Arial,sans-serif" font-size="38">${final ? 'HIGHLIGHTS &amp; REPLAY' : 'LIVE STREAM'}</text><text x="140" y="800" fill="#aab4c4" font-family="Arial,sans-serif" font-size="34">${date}</text></svg>`;
}
async function uploadImgBb(svg) {
  const key = text(process.env.IMGBB_API_KEY);
  if (!key) return '';
  const fd = new FormData(); fd.append('key', key); fd.append('image', Buffer.from(svg).toString('base64'));
  const response = await fetch('https://api.imgbb.com/1/upload', { method: 'POST', body: fd });
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(`ImgBB upload failed: ${data.error?.message || response.status}`);
  return data.data.url;
}
async function getJson(url, options = {}) {
  const response = await fetch(url, options);
  if (!response.ok) throw new Error(`${response.status} ${response.statusText} from ${url}`);
  return response.json();
}
async function accessToken() {
  const clientId = required('GOOGLE_CLIENT_ID').replace(/^\s+|\s+$/g, '');
  const clientSecret = required('GOOGLE_CLIENT_SECRET').replace(/^\s+|\s+$/g, '');
  const refreshToken = required('BLOGGER_REFRESH_TOKEN').replace(/^\s+|\s+$/g, '');
  const body = new URLSearchParams({ client_id: clientId, client_secret: clientSecret, refresh_token: refreshToken, grant_type: 'refresh_token' });
  const response = await fetch(TOKEN_URL, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body });
  const data = await response.json();
  if (!response.ok || !data.access_token) {
    const detail = data.error_description || data.error || `HTTP ${response.status}`;
    const hint = data.error === 'invalid_grant'
      ? 'The refresh token was revoked, expired, or created for a different OAuth client. Generate a new token with the exact client ID and secret stored in GitHub.'
      : data.error === 'unauthorized_client'
        ? 'The OAuth client is not allowed to use this grant. Check that the client ID and secret are from the same Web application OAuth client.'
        : 'Check that the three OAuth GitHub Secrets belong to the same client and contain no quotes or extra whitespace.';
    throw new Error(`Google token refresh failed: ${detail}. ${hint}`);
  }
  return data.access_token;
}
async function listPosts(token, blogId) {
  const posts = [];
  let pageToken = '';
  do {
    const query = new URLSearchParams({ maxResults: '500', fetchBodies: 'true' });
    if (pageToken) query.set('pageToken', pageToken);
    const data = await getJson(`${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts?${query}`, { headers: { authorization: `Bearer ${token}` } });
    posts.push(...(data.items || []));
    pageToken = data.nextPageToken || '';
  } while (pageToken);
  return posts;
}
async function bloggerWrite(token, blogId, postId, payload) {
  const url = postId ? `${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/${encodeURIComponent(postId)}` : `${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/`;
  const response = await fetch(url, { method: postId ? 'PUT' : 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: JSON.stringify(payload) });
  const data = await response.json();
  if (!response.ok) throw new Error(`Blogger write failed (${response.status}): ${JSON.stringify(data).slice(0, 500)}`);
  return data;
}
function normalizeEvents(raw) {
  if (Array.isArray(raw)) return raw;
  if (raw && typeof raw === 'object') return Object.entries(raw).map(([key, value]) => ({ ...(value || {}), id: value?.id || key }));
  return [];
}
function dashboardDate(timeZone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const values = Object.fromEntries(parts.filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
  return `${values.year}${values.month}${values.day}`;
}
function parseDashboardEvent(event, league) {
  const competition = event.competitions?.[0] || {}, competitors = competition.competitors || [];
  const home = competitors.find(item => item.homeAway === 'home') || competitors[0] || {};
  const away = competitors.find(item => item.homeAway === 'away') || competitors[1] || {};
  const teamName = item => item.team?.displayName || item.team?.shortDisplayName || 'TBD';
  const logo = item => String(item.team?.logo || item.team?.logos?.[0]?.href || '').replace(/^http:\/\//i, 'https://');
  const status = event.status?.type?.description || 'Scheduled';
  if (league.type === 'race') return { id: event.id, league, type: 'race', name: event.name || event.shortName || `${league.name} event`, status, startTime: event.date };
  return { id: event.id, league, type: 'match', home: { name: teamName(home), logo: logo(home), score: home.score ?? '' }, away: { name: teamName(away), logo: logo(away), score: away.score ?? '' }, status, startTime: event.date };
}
async function fetchDashboardEvents() {
  const timeZone = process.env.EVENT_TIMEZONE || 'Africa/Nairobi';
  const date = process.env.EVENT_DATE || dashboardDate(timeZone);
  const results = await Promise.allSettled(DASHBOARD_LEAGUES.map(async league => {
    const slugs = [league.slug];
    if (league.id === 'nations') slugs.push('uefa.nations_league');
    for (const slug of slugs) {
      try {
        const data = await getJson(`${ESPN_API}/${league.sport}/${slug}/scoreboard?dates=${encodeURIComponent(date)}`, { headers: { accept: 'application/json' } });
        if (Array.isArray(data.events) && data.events.length) return data.events.map(event => parseDashboardEvent(event, league));
      } catch (error) { console.warn(`[ESPN] ${league.name} (${slug}): ${error.message}`); }
    }
    return [];
  }));
  return results.flatMap(result => result.status === 'fulfilled' ? result.value : []);
}
async function main() {
  const blogId = required('BLOG_ID');
  // EVENTS_URL is an explicit escape hatch; the normal path must match the UI.
  const allEvents = process.env.EVENTS_URL ? normalizeEvents(await getJson(process.env.EVENTS_URL, { headers: { accept: 'application/json' } })) : await fetchDashboardEvents();
  // Dashboard cards are valid events before an external stream is resolved.
  const events = allEvents.filter(event => !isDead(event) && isToday(event) && matchesConfiguredLeague(event));
  if (process.env.DRY_RUN === '1') {
    console.log(JSON.stringify({ source: process.env.EVENTS_URL || 'dashboard-espn', scanned: allEvents.length, eligible: events.length, timezone: process.env.EVENT_TIMEZONE || 'Africa/Nairobi', events: events.map((event, index) => ({ key: eventKey(event, index), title: eventName(event), league: leagueName(event), scheduled: startTime(event), final: isFinal(event), streams: streamLinks(event) })) }));
    return;
  }
  required('IMGBB_API_KEY');
  const token = await accessToken();
  const posts = await listPosts(token, blogId);
  const byMarker = new Map();
  for (const post of posts) for (const label of post.labels || []) if (label.startsWith('s803:event:')) byMarker.set(label, post);

  let created = 0, updated = 0, skipped = 0;
  for (let index = 0; index < events.length; index++) {
    const event = events[index];
    const key = eventKey(event, index), marker = markerFor(key), streams = streamLinks(event), final = isFinal(event);
    const existing = byMarker.get(marker);
    const label = isRace(event) ? eventName(event) : displayEventName(event);
    const title = isRace(event) ? (final ? `${label} – Full Race Replay | Sports 803` : `${label} – Race Preview & Live Stream | Sports 803`) : (final ? `${label} – Highlights & Replay | Sports 803` : `${label} – ${leagueName(event)} Live Stream | Sports 803`);
    const shouldUpdate = Boolean(existing && final && !(existing.labels || []).includes('Highlights'));
    if (existing && !shouldUpdate) {
      skipped++;
      console.log(`skipped ${existing.id}: ${title}`);
      continue;
    }
    const article = final ? highlightsHtml(event, streams) : previewHtml(event, streams);
    let thumbUrl = '';
    try { thumbUrl = await uploadImgBb(thumbnailSvg(event, title, final)); } catch (error) { throw new Error(`[ImgBB] ${title}: ${error.message}`); }
    const thumbHtml = thumbUrl ? `<div><img src="${htmlEscape(thumbUrl)}" style="max-width:100%;height:auto;border-radius:10px;margin-bottom:18px" alt="${htmlEscape(title)}"></div>\n` : '';
    const content = thumbHtml + article;
    const payload = { kind: 'blogger#post', blog: { id: blogId }, title, content, labels: labelsFor(event, key, final), searchDescription: `${eventName(event)} ${final ? 'highlights and replay' : 'live stream'} on Sports 803`.slice(0, 150) };
    if (!existing) {
      const post = await bloggerWrite(token, blogId, null, payload);
      byMarker.set(marker, post); created++;
      console.log(`created ${post.id}: ${title}`);
    } else if (shouldUpdate) {
      payload.id = existing.id;
      if (existing.published) payload.published = existing.published;
      const post = await bloggerWrite(token, blogId, existing.id, payload);
      byMarker.set(marker, post); updated++;
      console.log(`updated ${post.id}: ${title}`);
    }
    await sleep(500);
  }
  console.log(JSON.stringify({ detected: events.length, created, updated, skipped }));
}

main().catch(error => { console.error(error.stack || error.message || error); process.exitCode = 1; });
