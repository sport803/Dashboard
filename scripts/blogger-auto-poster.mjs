#!/usr/bin/env node

const BLOGGER_API = 'https://www.googleapis.com/blogger/v3';
const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const DEFAULT_EVENTS_URL = 'https://sports-803-1b806-default-rtdb.firebaseio.com/s803config/todaysMatches.json';
const PLAYER_BASE = process.env.PLAYER_BASE_URL || 'https://www.sport803.online/p/player.html';
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
function leagueName(event) { return text(event.leagueName || event.league || event.category || event.series || event.competition || 'Live Sports'); }
function leagueId(event) { return text(event.leagueId || event.league?.id || event.sportId || '').toLowerCase(); }
function canonicalLeague(event) {
  const haystack = `${leagueId(event)} ${leagueName(event)} ${eventName(event)}`;
  return LEAGUE_ALIASES.find(([, regex]) => regex.test(haystack))?.[0] || leagueId(event) || 'sports';
}
function matchesConfiguredLeague(event) {
  const configured = text(process.env.LEAGUES).toLowerCase();
  if (!configured) return true;
  const wanted = new Set(configured.split(',').map(x => x.trim()).filter(Boolean));
  const id = canonicalLeague(event);
  return wanted.has(id) || wanted.has(leagueId(event)) || wanted.has(leagueName(event).toLowerCase());
}
function startTime(event) { return event.startTime || event.kickoff || event.date || event.start || event.scheduledAt || null; }
function localDate(value, timeZone) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}
function isTodayOrFuture(event) {
  const scheduled = startTime(event);
  if (!scheduled) return false;
  const timeZone = process.env.EVENT_TIMEZONE || 'UTC';
  const eventDate = localDate(scheduled, timeZone);
  const today = localDate(Date.now(), timeZone);
  return Boolean(eventDate && today && eventDate >= today);
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
  return [...new Set([
    'Sports 803', 'Live Sports', leagueName(event), canonicalLeague(event), markerFor(key),
    final ? 'Highlights' : 'Event Stream'
  ])];
}
function playerUrlFor(event, streams) {
  const existing = streams.find(url => /player|embed/i.test(url));
  if (existing) return existing;
  const first = streams[0];
  return first ? `${PLAYER_BASE}?mora=${encodeURIComponent(first)}` : '';
}
function previewHtml(event, streams) {
  const name = eventName(event), league = leagueName(event), kickoff = startTime(event) ? new Date(startTime(event)).toISOString() : 'TBD';
  const player = playerUrlFor(event, streams);
  return `<h2>${htmlEscape(name)} Live Stream</h2><p>Watch ${htmlEscape(name)} live on Sports 803. This ${htmlEscape(league)} event page will be updated with the final result and highlights after the event ends.</p><h2>Event Details</h2><p><strong>Competition:</strong> ${htmlEscape(league)}<br><strong>Scheduled:</strong> ${htmlEscape(kickoff)}</p><h2>How to Watch</h2><p>Use the player below when coverage is available.</p>${player ? `<div id="s803-event-player" style="margin:18px 0;position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:10px"><iframe loading="lazy" src="${htmlEscape(player)}" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen allow="autoplay; encrypted-media"></iframe></div>` : ''}`;
}
function highlightsHtml(event, streams) {
  const name = eventName(event), league = leagueName(event), score = event.score || event.result || [event.homeScore, event.awayScore].filter(x => x !== undefined && x !== '').join(' - ');
  const links = replayLinks(event);
  const player = links[0] || playerUrlFor(event, streams);
  return `<h2>${htmlEscape(name)} Highlights &amp; Replay</h2><p>${htmlEscape(name)} has ended. This ${htmlEscape(league)} recap includes the latest result and replay information for Sports 803 readers.</p><h2>Final Result</h2><p><strong>${htmlEscape(score || 'Final result available from the event feed')}</strong></p><h2>Key Moments</h2><p>The event has concluded. Return to this page for replay coverage and the latest event information.</p>${player ? `<h2>Watch Highlights &amp; Replay</h2><div id="s803-event-player" style="margin:18px 0;position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:10px"><iframe loading="lazy" src="${htmlEscape(player)}" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen allow="autoplay; encrypted-media"></iframe></div>` : ''}`;
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
async function main() {
  const blogId = required('BLOG_ID');
  const eventsUrl = process.env.EVENTS_URL || DEFAULT_EVENTS_URL;
  const raw = await getJson(eventsUrl, { headers: { accept: 'application/json' } });
  const allEvents = normalizeEvents(raw);
  const events = allEvents.filter(event => !isDead(event) && isTodayOrFuture(event) && matchesConfiguredLeague(event) && streamLinks(event).length);
  if (process.env.DRY_RUN === '1') {
    console.log(JSON.stringify({ scanned: allEvents.length, eligible: events.length, timezone: process.env.EVENT_TIMEZONE || 'UTC', events: events.map((event, index) => ({ key: eventKey(event, index), title: eventName(event), league: leagueName(event), scheduled: startTime(event), final: isFinal(event), streams: streamLinks(event) })) }));
    return;
  }
  const token = await accessToken();
  const posts = await listPosts(token, blogId);
  const byMarker = new Map();
  for (const post of posts) for (const label of post.labels || []) if (label.startsWith('s803:event:')) byMarker.set(label, post);

  let created = 0, updated = 0, skipped = 0;
  for (let index = 0; index < events.length; index++) {
    const event = events[index];
    const key = eventKey(event, index), marker = markerFor(key), streams = streamLinks(event), final = isFinal(event);
    const existing = byMarker.get(marker);
    const title = final ? `${eventName(event)} – Highlights & Replay | Sports 803` : `${eventName(event)} – Live Stream | Sports 803`;
    const content = final ? highlightsHtml(event, streams) : previewHtml(event, streams);
    const payload = { kind: 'blogger#post', blog: { id: blogId }, title, content, labels: labelsFor(event, key, final), searchDescription: `${eventName(event)} ${final ? 'highlights and replay' : 'live stream'} on Sports 803`.slice(0, 150) };
    if (!existing) {
      const post = await bloggerWrite(token, blogId, null, payload);
      byMarker.set(marker, post); created++;
      console.log(`created ${post.id}: ${title}`);
    } else if (final && !(existing.labels || []).includes('Highlights')) {
      payload.id = existing.id;
      if (existing.published) payload.published = existing.published;
      const post = await bloggerWrite(token, blogId, existing.id, payload);
      byMarker.set(marker, post); updated++;
      console.log(`updated ${post.id}: ${title}`);
    } else {
      skipped++;
      console.log(`skipped ${existing.id}: ${title}`);
    }
    await sleep(500);
  }
  console.log(JSON.stringify({ detected: events.length, created, updated, skipped }));
}

main().catch(error => { console.error(error.stack || error.message || error); process.exitCode = 1; });
