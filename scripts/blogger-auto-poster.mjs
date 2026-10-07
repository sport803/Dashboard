#!/usr/bin/env node
// Sports 803 Blogger auto-poster (hardened). Lives at scripts/blogger-auto-poster.mjs.
// ESM so it runs as `node scripts/blogger-auto-poster.mjs` regardless of package.json "type". No npm dependencies.

import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

// ============================================================================
// CONFIG
// ============================================================================
// DASHBOARD_LEAGUES, LEAGUE_ALIASES and SPORT_LABELS MUST stay in sync with the
// Dashboard's LEAGUES list. The browser dashboard fetches these ESPN scoreboard
// feeds directly; cloud post-log data is not used for event discovery.

const BLOGGER_API = 'https://www.googleapis.com/blogger/v3';
const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const ESPN_API = 'https://site.api.espn.com/apis/site/v2/sports';
function normalizePlayerBase(value) { return text(value).replace(/\/+$/, ''); }
const PLAYER_BASE = normalizePlayerBase(process.env.PLAYER_BASE_URL || 'https://www.sport803.online/p/player.html');
const PPVTV_MATCHES_API = 'https://august.ppvtv.icu/api/matches.json';
const ONEBALL_LIST_URL = 'https://oneball.live/list.json';
const TSDB_API = 'https://www.thesportsdb.com/api/v1/json/3';
const IMGBB_API = 'https://api.imgbb.com/1/upload';
const USER_AGENT = 'Sports803-Blogger-AutoPoster/1.0';
const SUPERSPORT_BASE = 'https://supersport.com';
const SUPERSPORT_SPORT_MAP = { soccer: 'football', football: 'football', mma: 'mma', basketball: 'basketball', rugby: 'rugby', cricket: 'cricket', golf: 'golf', tennis: 'tennis', motorsport: 'motorsport', racing: 'motorsport', cycling: 'cycling' };
const superSportCache = new Map();
const PLAYER_DATA_MAX_EVENTS = 80;
const PLAYER_DATA_CONCURRENCY = 4;
const PLAYER_DATA_TSDB_MAX_CALLS = 20;
const playerDataCache = new Map(), tsdbPlayerCache = new Map();
let tsdbPlayerCalls = 0;

const FETCH_TIMEOUT_MS = 15_000;
const MAX_LABELS = 20; // Blogger's per-post label limit
const LOGO_TTL_MS = 30 * 24 * 60 * 60 * 1000; // found logos: 30 days
const LOGO_MISS_TTL_MS = 24 * 60 * 60 * 1000; // lookup succeeded, no badge: 1 day
const LOGO_ERROR_TTL_MS = 5 * 60 * 1000; // lookup failed: 5 minutes

const DASHBOARD_LEAGUES = [
  ['ucl', 'soccer', 'uefa.champions', 'Champions League', 'match'], ['uwcl', 'soccer', 'uefa.wchampions', "UEFA Women's Champions League", 'match'], ['uel', 'soccer', 'uefa.europa', 'Europa League', 'match'],
  ['epl', 'soccer', 'eng.1', 'Premier League', 'match'], ['laliga', 'soccer', 'esp.1', 'La Liga', 'match'], ['seriea', 'soccer', 'ita.1', 'Serie A', 'match'], ['bundesliga', 'soccer', 'ger.1', 'Bundesliga', 'match'], ['ligue1', 'soccer', 'fra.1', 'Ligue 1', 'match'], ['mls', 'soccer', 'usa.1', 'MLS', 'match'],
  ['worldcup', 'soccer', 'fifa.world', 'FIFA World Cup', 'match'], ['euro', 'soccer', 'uefa.euro', 'UEFA Euro', 'match'], ['afconqual', 'soccer', 'caf.nations_qual', 'Africa Cup of Nations Qualifiers', 'match'], ['euroqual', 'soccer', 'uefa.euroq', 'UEFA Euro Qualifiers', 'match'], ['concacafnl', 'soccer', 'concacaf.nations.league', 'Concacaf Nations League', 'match'], ['nations', 'soccer', 'uefa.nations', 'UEFA Nations League', 'match'], ['intlfriendly', 'soccer', 'fifa.friendly', 'Intl Friendlies', 'match'],
  ['nba', 'basketball', 'nba', 'NBA', 'match'], ['wnba', 'basketball', 'wnba', "Women's National Basketball Association", 'match'], ['nfl', 'football', 'nfl', 'NFL', 'match'], ['nhl', 'hockey', 'nhl', 'NHL', 'match'],
  ['f1', 'racing', 'f1', 'Formula 1', 'race'], ['nascar', 'racing', 'nascar-cup-series', 'NASCAR', 'race'], ['wrc', 'racing', 'wrc', 'WRC', 'race'], ['imsa', 'racing', 'imsa', 'IMSA', 'race'], ['porsche-carrera-cup', 'racing', 'porsche-carrera-cup', 'Porsche Carrera Cup', 'race'], ['pga', 'golf', 'pga', 'PGA Tour', 'race'], ['ufc', 'mma', 'ufc', 'UFC / MMA', 'match'], ['atp', 'tennis', 'atp', 'ATP Tennis', 'match'], ['wta', 'tennis', 'wta', 'WTA Tennis', 'match'],
  ['efl', 'soccer', 'eng.2', 'Championship', 'match'], ['efltrophy', 'soccer', 'eng.trophy', 'English Football League Trophy', 'match'], ['spl', 'soccer', 'ksa.1', 'Saudi Pro League', 'match'], ['eredivisie', 'soccer', 'ned.1', 'Eredivisie', 'match'], ['primeira', 'soccer', 'por.1', 'Primeira Liga', 'match'], ['facup', 'soccer', 'eng.fa', 'FA Cup', 'match'], ['motogp', 'racing', 'motogp', 'MotoGP', 'race'], ['cycling', 'cycling', 'world', 'Cycling', 'race'], ['mlb', 'baseball', 'mlb', 'MLB', 'match']
].map(([id, sport, slug, name, type]) => ({ id, sport, slug, name, type }));

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

// v11: racing leagues now use TheSportsDB; ESPN is used only for f1/indycar and non-racing sports.
// Multi-day events use a ±1 day window and dedupe by event id.
const ESPN_RACING_SUPPORTED = new Set(['f1', 'indycar']);
const TSDB_RACING_LEAGUE = { motogp: [4407], wrc: [4445, 4409], imsa: [4455], nascar: [4393, 4573], indycar: [4471], formulae: [4480], wsbk: [4484] };
const tsdbRacingDayCache = new Map();
const leagueFetchStatus = {}, leagueFetchSource = {};

function eventTimeZone() { return process.env.EVENT_TIMEZONE || 'Africa/Nairobi'; }

/**
 * Validate all env vars up front and report every problem at once.
 * Secret names match the repo README (BLOGGER_BLOG_ID, BLOGGER_GOOGLE_CLIENT_ID, ...); the older
 * short names (BLOG_ID, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET) still work as fallbacks.
 * DRY_RUN=1 only needs the blog ID (no writes happen, so no Google/ImgBB credentials).
 */
const ENV_NAMES = {
  blogId: ['BLOGGER_BLOG_ID', 'BLOG_ID'],
  clientId: ['BLOGGER_GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_ID'],
  clientSecret: ['BLOGGER_GOOGLE_CLIENT_SECRET', 'GOOGLE_CLIENT_SECRET'],
  refreshToken: ['BLOGGER_REFRESH_TOKEN'],
  imgbbKey: ['IMGBB_API_KEY']
};
function loadConfig(env = process.env) {
  const dryRun = ['1', 'true'].includes(text(env.DRY_RUN).toLowerCase());
  const read = (field) => ENV_NAMES[field].map(name => text(env[name])).find(Boolean) || '';
  const needed = dryRun ? ['blogId'] : Object.keys(ENV_NAMES);
  const missing = needed.filter(field => !read(field)).map(field => ENV_NAMES[field][0]);
  if (missing.length) throw new Error(`Missing required environment variable(s): ${missing.join(', ')}`);
  const timeZone = text(env.EVENT_TIMEZONE) || 'Africa/Nairobi';
  try { new Intl.DateTimeFormat('en-US', { timeZone }); } catch { throw new Error(`Invalid EVENT_TIMEZONE: "${timeZone}"`); }
  return { dryRun, timeZone, blogId: read('blogId'), clientId: read('clientId'), clientSecret: read('clientSecret'), refreshToken: read('refreshToken'), imgbbKey: read('imgbbKey') };
}

// ============================================================================
// GENERIC HELPERS
// ============================================================================

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
// null/undefined and booleans become ''. Numbers (incl. 0) are kept as strings.
// (The suggested `value == null ? '' : ... String(value)` alone would still turn false into "false".)
function text(value) {
  if (value == null || typeof value === 'boolean') return '';
  return typeof value === 'string' ? value.trim() : String(value).trim();
}
// First value that is non-empty after text(); keeps score 0 / id 0 from being dropped by `||`.
function pick(...values) {
  for (const value of values) { const t = text(value); if (t) return t; }
  return '';
}
function htmlEscape(value) {
  return text(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function stableHash(value) {
  let hash = 2166136261;
  for (const char of text(value)) { hash ^= char.charCodeAt(0); hash = Math.imul(hash, 16777619); }
  return (hash >>> 0).toString(16).padStart(8, '0');
}

// ============================================================================
// HTTP: errors, retry, timeouts
// ============================================================================

class HttpError extends Error {
  constructor(message, { status, retryable } = {}) {
    super(message);
    this.name = 'HttpError';
    this.status = status;
    if (retryable !== undefined) this.retryable = retryable;
  }
}
function timeoutSignal() { return AbortSignal.timeout(FETCH_TIMEOUT_MS); }

function isRetryable(error) {
  if (error?.retryable === false) return false;
  if (error?.retryable === true) return true;
  if (typeof error?.status === 'number') return error.status === 429 || error.status >= 500;
  // Network-level failures from fetch (timeouts, resets, DNS).
  return error?.name === 'TimeoutError' || error?.name === 'AbortError' || (error instanceof TypeError && /fetch failed/i.test(error.message));
}
function defaultRetryBase() {
  const fromEnv = process.env.RETRY_BASE_MS; // lets tests run fast
  return fromEnv !== undefined && Number.isFinite(Number(fromEnv)) ? Number(fromEnv) : 500;
}
/** Retries 429 / 5xx / network errors with exponential backoff + jitter. Everything else fails immediately. */
async function withRetry(fn, { tries = 4, base = defaultRetryBase() } = {}) {
  for (let attempt = 1; ; attempt++) {
    try { return await fn(attempt); }
    catch (error) {
      if (attempt >= tries || !isRetryable(error)) throw error;
      const delay = base * 2 ** (attempt - 1) + Math.random() * base;
      console.warn(`[retry] attempt ${attempt}/${tries} failed (${error.message}); retrying in ${Math.round(delay)}ms`);
      await sleep(delay);
    }
  }
}
async function getJson(url, options = {}) {
  return withRetry(async () => {
    const response = await fetch(url, { ...options, signal: timeoutSignal() });
    if (!response.ok) throw new HttpError(`${response.status} ${response.statusText} from ${url}`, { status: response.status });
    return response.json();
  });
}

// ============================================================================
// EVENT MODEL HELPERS
// ============================================================================

const FINAL_STATUS_RE = /final|finished|completed|ended|full.?time|\bft\b|post.?match|cancelled|canceled|abandoned/i;
function isFinal(event, now = Date.now()) {
  const status = text(event.status || event.statusType || event.state || event.competitionStatus);
  if (FINAL_STATUS_RE.test(status)) return true;
  const end = event.endTime || event.completedAt;
  if (!end) return false;
  const endMs = new Date(end).getTime();
  if (!Number.isFinite(endMs)) { console.warn(`[isFinal] unparseable end time "${end}"; treating event as not final`); return false; }
  return endMs <= now;
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
// Race detection uses league id/name and sport only; an event *title* containing
// "Grand Prix" etc. must not force race mode.
const RACE_RE = /racing|motogp|nascar|wrc|imsa|porsche|formula\s*1|\bf1\b/i;
function isRace(event) {
  return text(event.type).toLowerCase() === 'race' || RACE_RE.test(`${leagueId(event)} ${leagueName(event)} ${text(event.sport)}`);
}
function displayEventName(event) { return [homeName(event), awayName(event)].filter(Boolean).join(' vs ') || eventName(event); }
function eventScore(event) {
  const direct = pick(event.score, event.result);
  if (direct) return direct;
  const home = pick(event.home?.score, event.homeScore), away = pick(event.away?.score, event.awayScore);
  if (home && away) return `${home}–${away}`;
  return home || away || '';
}
function startTime(event) { return event.startTime || event.kickoff || event.date || event.start || event.scheduledAt || null; }
function eventDateText(event) {
  const value = startTime(event), date = value ? new Date(value) : null;
  return date && Number.isFinite(date.getTime()) ? date.toLocaleString('en-US', { timeZone: eventTimeZone(), weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }) : 'the scheduled time';
}
/** YYYY-MM-DD in the given zone. Returns null for an unparseable date; throws RangeError for an invalid zone. */
function localDate(value, timeZone) {
  const date = new Date(value);
  if (!Number.isFinite(date.getTime())) return null;
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}
function isToday(event, now = Date.now()) {
  const scheduled = startTime(event);
  if (!scheduled) return false;
  const timeZone = eventTimeZone();
  const eventDate = localDate(scheduled, timeZone);
  const today = localDate(now, timeZone);
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
// v13: SuperSport highlights source. Fetches supersport.com/{sport}/videos,
// parses the Next.js __next_f payload, extracts the YouTube embed or HLS URL,
// and always wraps it in the Sports 803 player with ?embed= or ?mora=.
function supersportSportFor(event) { return SUPERSPORT_SPORT_MAP[text(event?.league?.sport || event?.sport).toLowerCase()] || null; }
// v13: Proxy fetch with a bounded four-proxy order; short shell responses are rejected.
async function fetchSuperSportPage(url, timeoutMs = 15000) {
  const proxies = [u => `https://r.jina.ai/http://${u.replace(/^https?:\/\//i, '')}`, u => `https://api.codetabs.com/v1/proxy?quest=${encodeURIComponent(u)}`, u => `https://api.allorigins.win/raw?url=${encodeURIComponent(u)}`, u => `https://corsproxy.io/?url=${encodeURIComponent(u)}`];
  for (const proxy of proxies) { try { const response = await fetch(proxy(url), { headers: { 'X-Return-Format': 'html', 'user-agent': USER_AGENT }, signal: AbortSignal.timeout(timeoutMs) }); if (response.ok) { const html = await response.text(); if (html.length > 500) return html; } } catch (error) { console.warn(`[SuperSport] proxy failed: ${error.message}`); } }
  return '';
}
// v13: Decode Next.js flight chunks and return only secure, verified media URLs.
function extractSuperSportVideo(html, pageUrl) {
  const chunks = [], source = String(html || ''); let match; const chunkRe = /self\.__next_f\.push\(\[1,\s*("(?:\\.|[^"\\])*")\s*\]\)/g;
  while ((match = chunkRe.exec(source))) { try { chunks.push(JSON.parse(match[1])); } catch {} }
  const blob = chunks.join('\n') + '\n' + source;
  const hls = blob.match(/https:\\?\/\\?\/vod\.supersport\.com[\s\S]*?\.m3u8(?:\?[^\s"'\\]+)?/i), yt = blob.match(/https:\\?\/\\?\/www\.youtube\.com\\?\/embed\\?\/([A-Za-z0-9_-]+)/i);
  const sourceUrl = hls ? hls[0].replace(/\\\//g, '/') : yt ? `https://www.youtube.com/embed/${yt[1]}` : '';
  if (!/^https:\/\//i.test(sourceUrl)) return null;
  const title = ((blob.match(/"title"\s*:\s*"((?:\\.|[^"\\])+)"/i) || [])[1] || (source.match(/<title[^>]*>([^<]+)/i) || [])[1] || '').replace(/\\"/g, '"').trim();
  const thumbnail = ((source.match(/<meta[^>]+(?:property|name)=["']og:image["'][^>]+content=["']([^"']+)/i) || blob.match(/"image"\s*:\s*"(https:\\?\/\\?\/images\.supersport\.com[^"\\]+)/i) || [])[1] || '').replace(/\\\//g, '/');
  return { title: title || 'SuperSport Highlights', source: hls ? 'm3u8' : 'youtube', sourceUrl, thumbnail, pageUrl };
}
// v13: Match one recent SuperSport listing item and fetch only its video page.
async function findSuperSportHighlight(event) {
  if (process.env.SUPERSPORT_HIGHLIGHTS === '0' || isRace(event) || event?.type === 'race') return null;
  const sport = supersportSportFor(event); if (!sport) return null;
  const key = eventKey(event, 0), cached = superSportCache.get(key); if (cached && Date.now() - cached.ts < 6 * 3600000) return cached.value;
  try {
    const listing = await fetchSuperSportPage(`${SUPERSPORT_BASE}/${sport}/videos`); if (!listing) return null;
    const links = new Set(), re = new RegExp(`(?:"url"\\s*:\\s*|href=["'])(/${sport}/video/[^"']+)`, 'gi'); let match;
    while ((match = re.exec(listing)) && links.size < 25) links.add(match[1].replace(/\\u0026/g, '&'));
    const candidates = [...links].filter(url => oneBallMatchFound(url, homeName(event), awayName(event))).sort((a, b) => Number(/highlight/i.test(b)) - Number(/highlight/i.test(a)));
    if (!candidates.length) { superSportCache.set(key, { ts: Date.now(), value: null }); return null; }
    const pageUrl = SUPERSPORT_BASE + candidates[0], video = extractSuperSportVideo(await fetchSuperSportPage(pageUrl), pageUrl); if (!video) return null;
    const playerUrl = `${PLAYER_BASE}?${video.source === 'm3u8' ? 'mora' : 'embed'}=${encodeURIComponent(video.sourceUrl)}`;
    const result = { url: playerUrl, playerUrl, source: 'supersport', sourceKind: video.source, rawSource: video.sourceUrl, pageUrl, matchId: null, title: `${homeName(event)} vs ${awayName(event)} Highlights`, thumbnail: video.thumbnail || '' };
    superSportCache.set(key, { ts: Date.now(), value: result }); return result;
  } catch (error) { console.warn(`[SuperSport] ${error.message}`); return null; }
}

// ============================================================================
// TEAM MATCHING
// ============================================================================

function teamKey(value) {
  const aliases = { 'french saint martin': 'st martin', 'saint martin': 'st martin', 'st martin': 'st martin', 'united states': 'usa', 'us': 'usa' };
  const normalized = text(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
    .replace(/\b(fc|afc|rfc|sc|cf|ac|as|bsc|rcd|rc|vfb|vfl|real|deportivo|athletic|clube|club|city|united|town|rovers|wanderers|albion|hotspur|villa|forest|palace|wolves|celtic|rangers)\b/g, '')
    .replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
  return aliases[normalized] || normalized;
}
function teamsMatch(left, right) {
  const a = teamKey(left), b = teamKey(right);
  if (!a || !b) return false;
  const aTokens = a.split(' ').filter(x => x.length > 3), bTokens = b.split(' ').filter(x => x.length > 3);
  return a === b || a.includes(b) || b.includes(a) || (aTokens.length > 0 && aTokens.every(x => b.includes(x))) || (bTokens.length > 0 && bTokens.every(x => a.includes(x)));
}
function pairMatch(event, home, away) {
  return (teamsMatch(homeName(event), home) && teamsMatch(awayName(event), away)) || (teamsMatch(homeName(event), away) && teamsMatch(awayName(event), home));
}

// ============================================================================
// EXTERNAL STREAM SOURCES (OneBall / PPVTV)
// ============================================================================

function sourceUrl(value) { return /^https:\/\//i.test(text(value)) ? text(value) : ''; }
function oneBallUrl(matchId) { return `https://oneball.live/live/${encodeURIComponent(String(matchId).replace(/\.html?$/i, ''))}.html`; }
function normalizePlayerUrlsInHtml(html) {
  return String(html || '').replace(/((?:https?:\/\/)[^"'<>\s]+\/player\.html)\/+\?/gi, '$1?');
}
function sports803PlayerUrl(sources) {
  const one = sources.find(source => source.type === 'one');
  const mora = sources.find(source => source.type === 'mora' || source.type === 'hls');
  const embeds = sources.filter(source => source.type === 'embed');
  const params = [];
  if (one?.url) params.push(`one=${one.url}`);
  else if (mora?.url) params.push(`mora=${encodeURIComponent(mora.url)}`);
  embeds.forEach(source => { if (source.url) params.push(`embed=${encodeURIComponent(source.url)}`); });
  return params.length ? normalizePlayerUrlsInHtml(`${PLAYER_BASE}?${params.join('&')}`) : '';
}
async function loadExternalSources() {
  const result = { oneball: [], ppv: [] };
  try {
    const raw = await getJson(ONEBALL_LIST_URL, { headers: { accept: 'application/json', 'user-agent': USER_AGENT } });
    const entries = Array.isArray(raw) ? raw : raw?.matches || raw?.data || raw?.list || [];
    result.oneball = entries.map(entry => ({ id: entry.nami_id || entry.id, home: entry.home_team || entry.home_team_name || entry.home?.name || entry.home, away: entry.away_team || entry.away_team_name || entry.away?.name || entry.away })).filter(entry => entry.id && entry.home && entry.away);
    console.log(`[OneBall] loaded ${result.oneball.length} match links`);
  } catch (error) { console.warn(`[OneBall] ${error.message}`); }
  try {
    const payload = await getJson(`${PPVTV_MATCHES_API}?_ts=${Date.now()}`, { headers: { accept: 'application/json', 'user-agent': USER_AGENT } });
    const matches = Array.isArray(payload) ? payload : payload?.matches || payload?.data?.matches || [];
    result.ppv = matches.map(match => {
      let home = match.teams?.home?.name || match.home_team?.name || match.home_team || match.home?.name || '';
      let away = match.teams?.away?.name || match.away_team?.name || match.away_team || match.away?.name || '';
      if (!home || !away) { const parts = text(match.title).split(/\s+(?:at|vs?\.?|v|x|versus)\s+/i); if (parts.length > 1) [home, away] = [parts[0], parts.slice(1).join(' vs ')]; }
      const urls = [match.embed_url, match.embedUrl, ...(Array.isArray(match.servers) ? match.servers.flatMap(server => [server?.embed_url, server?.embedUrl, server?.url]) : [])].map(sourceUrl).filter(Boolean);
      return { home, away, urls: [...new Set(urls)] };
    }).filter(match => match.home && match.away && match.urls.length);
    console.log(`[PPVTV] loaded ${result.ppv.length} match links`);
  } catch (error) { console.warn(`[PPVTV] ${error.message}`); }
  return result;
}
/** Map keyed by "teamKey(home)|teamKey(away)" AND the reversed key; first entry wins. */
function buildFeedIndex(entries) {
  const index = new Map();
  for (const entry of entries) {
    const home = teamKey(entry.home), away = teamKey(entry.away);
    if (!home || !away) continue;
    for (const key of [`${home}|${away}`, `${away}|${home}`]) if (!index.has(key)) index.set(key, entry);
  }
  return index;
}
/**
 * O(1) exact lookup first. On a miss we fall back to the original fuzzy pairMatch scan, because exact
 * keys alone would lose matches like "Manchester United" vs "Man United" (teamsMatch uses substring/token rules).
 */
function findFeedMatch(index, entries, event) {
  const home = teamKey(homeName(event)), away = teamKey(awayName(event));
  const exact = home && away ? index.get(`${home}|${away}`) : undefined;
  return exact || entries.find(entry => pairMatch(event, entry.home, entry.away));
}
function uniqueByUrl(sources) {
  const seen = new Set();
  return sources.filter(source => { if (!source.url || seen.has(source.url)) return false; seen.add(source.url); return true; });
}
async function attachExternalSources(events, feeds) {
  feeds = feeds || await loadExternalSources();
  const oneIndex = buildFeedIndex(feeds.oneball), ppvIndex = buildFeedIndex(feeds.ppv);
  return events.map(event => {
    if (event.type !== 'match') return { ...event, externalSources: [] };
    const sources = [];
    const one = findFeedMatch(oneIndex, feeds.oneball, event);
    if (one) sources.push({ url: oneBallUrl(one.id), type: 'one', source: 'onetv', matchId: String(one.id), home: one.home, away: one.away });
    const ppv = findFeedMatch(ppvIndex, feeds.ppv, event);
    if (ppv) sources.push(...ppv.urls.map(url => ({ url, type: 'embed', source: 'ppvtv', home: ppv.home, away: ppv.away })));
    // oneballId is populated here (instead of deleting the branch in replayLinks) so the replay URL feature keeps working.
    return { ...event, externalSources: uniqueByUrl(sources), ...(one ? { oneballId: String(one.id) } : {}) };
  });
}
function replayLinks(event) {
  const values = [];
  const add = (value) => { if (typeof value === 'string' && /^https?:\/\//i.test(value)) values.push(value); };
  ['highlightsUrl', 'highlightUrl', 'replayUrl', 'replayURL', 'replayPageUrl', 'highlightsVideo'].forEach(key => add(event[key]));
  if (event.oneballId) add(`${PLAYER_BASE}?one=${encodeURIComponent(`https://sports803.github.io/player/replay/${event.oneballId}`)}`);
  return [...new Set(values)];
}

// ============================================================================
// KEYS, MARKERS, LABELS
// ============================================================================

function eventKey(event, index) {
  // Hash input stays `canonicalLeague|id` so previously posted articles keep matching.
  const raw = pick(event.id, event.matchId, event.eventId, event.oneballId) || `${eventName(event)}|${startTime(event) || index}`;
  return `${canonicalLeague(event)}|${raw}`;
}
function markerFor(key) { return `s803:event:${stableHash(key)}`; }
/** Marker first (must always survive), then the status label, then the rest, capped at `limit` total. */
function labelsFor(event, key, final = false, limit = MAX_LABELS) {
  const marker = markerFor(key);
  const sport = text(event.sport || event.category || '').toLowerCase();
  const status = final ? 'Highlights' : 'Event Stream'; // 'Highlights' drives update detection, so keep it early
  const others = [...new Set([
    status, leagueName(event), leagueId(event), sport, isRace(event) ? 'Motorsport' : 'Sports', 'Sports 803',
    ...(SPORT_LABELS[sport] || ['Live Sports']),
    ...(homeName(event) ? [homeName(event)] : []), ...(awayName(event) ? [awayName(event)] : [])
  ].filter(Boolean))].filter(label => label !== marker);
  return [marker, ...others.slice(0, Math.max(0, limit - 1))];
}
function playerUrlFor(event, streams) {
  return sports803PlayerUrl((event?.externalSources || []).concat(streams.map(url => ({ url, type: 'mora' }))));
}

// ============================================================================
// ARTICLE HTML
// ============================================================================

function sectionVars(event) {
  const h = htmlEscape(homeName(event) || eventName(event)), a = htmlEscape(awayName(event)), league = htmlEscape(leagueName(event));
  const fixture = `${h}${a ? ` vs ${a}` : ''}`, kickoff = htmlEscape(eventDateText(event));
  const score = eventScore(event) ? `<br><strong>Score:</strong> ${htmlEscape(eventScore(event))}` : '';
  return { h, a, league, fixture, kickoff, score };
}
function matchDataSourceUrl(event) {
  if (event?.sourceUrl) return event.sourceUrl;
  if (event?.league?.sport && event?.league?.slug && event?.id) return `${ESPN_API}/${event.league.sport}/${event.league.slug}/summary?event=${encodeURIComponent(event.id)}`;
  return '';
}
function editorialFactsSections(event) {
  if (isRace(event)) return [];
  const pd = event?.playerData || {}, home = pd.home || {}, away = pd.away || {}, esc = htmlEscape, sections = [];
  const goals = [home, away].flatMap(side => (side.scorers || []).map(item => ({ ...item, team: side.name || '' })));
  const cards = [home, away].flatMap(side => (side.cards || []).map(item => ({ ...item, team: side.name || '' })));
  const subs = [home, away].flatMap(side => (side.substitutions || []).map(item => ({ ...item, team: side.name || '' })));
  if (goals.length) sections.push(`<h2>Key Moments</h2><ul>${goals.map(item => `<li>${esc(item.minute || 'Match event')} — ${esc(item.player)} scored for ${esc(item.team)}${item.assist ? `, assisted by ${esc(item.assist)}` : ''}.</li>`).join('')}</ul>`);
  if (cards.length || subs.length) sections.push(`<h2>Match Timeline</h2><ul>${cards.map(item => `<li>${esc(item.minute || 'Match event')} — ${esc(item.player)} booked for ${esc(item.team)}.</li>`).join('')}${subs.map(item => `<li>${esc(item.minute || 'Match event')} — ${esc(item.player)} entered for ${esc(item.team)}.</li>`).join('')}</ul>`);
  const source = matchDataSourceUrl(event);
  sections.push(`<h2>Reporting Notes</h2><p>This report was prepared by the <strong>Sports 803 Match Desk</strong>. Lineups, scorers, cards and substitutions are included only when returned by the available match-data source. ${source ? `<a href="${htmlEscape(source)}" rel="nofollow noopener" target="_blank">Review the match-data source</a>.` : 'Additional official competition or broadcaster details should be checked before publication.'}</p>`);
  return sections;
}
function raceSections(event) {
  const { league } = sectionVars(event);
  return `<h2>${league} Race Report</h2><p>This article records the available event context and separates verified race details from editorial analysis.</p><h2>Race Overview</h2><p>Follow the pace, decisive moves, and turning points from start to finish using the verified race information available for this event.</p><h2>Key Moments</h2><p>Verified starts, battles, overtakes and results are added when the source provides them. Unsupported details are not presented as fact.</p><h2>Championship Impact</h2><p>Check the official competition information for the latest standings and table impact.</p><h2>How To Watch</h2><p>Use an authorized broadcaster or licensed replay in your region. A permitted Sports 803 player is included only when a verified source is available.</p>`;
}
function highlightsSections(event) {
  const { fixture, league, kickoff, score } = sectionVars(event);
  return `<h2>Match Report</h2><p>${fixture} finished ${score ? `<strong>${score.replace('<br><strong>Score:</strong> ', '')}</strong>` : 'with the final result recorded in the match details below'} in ${league}. This report separates verified match events from editorial context.</p><h2>Match Details</h2><p><strong>Teams:</strong> ${fixture}<br><strong>Competition:</strong> ${league}<br><strong>Scheduled:</strong> ${kickoff}${score}</p><h2>Tactical Review</h2><p>The analysis focuses on the moments supported by the available match timeline, including scoring actions, substitutions and disciplinary events. Where a data point is unavailable, it is not presented as fact.</p><h2>How to Watch</h2><p>Use an authorized broadcaster or licensed replay in your region. The Sports 803 player is included only when a valid, permitted source is available; otherwise this page remains a match report with replay information to be updated later.</p><h2>Frequently Asked Questions</h2><p><strong>What was the result?</strong><br>${fixture} — ${score ? score.replace('<br><strong>Score:</strong> ', '') : 'see the latest verified match data'}.</p>`;
}
function previewSections(event) {
  const { h, a, fixture, league, kickoff } = sectionVars(event);
  return `<h2>Match Preview</h2><p>${fixture} meet in ${league}. This preview records the scheduled context and the questions that will be answered by the match rather than presenting generic claims as analysis.</p><h2>Match Details</h2><p><strong>Teams:</strong> ${fixture}<br><strong>Competition:</strong> ${league}<br><strong>Kickoff:</strong> ${kickoff}</p><h2>What to Watch</h2><p>Pay attention to confirmed team news, the first tactical adjustments, set pieces and the quality of chances created. Starting XIs are labelled as confirmed only after an official or verified match-data release.</p><h2>How to Watch</h2><p>Check an authorized broadcaster or licensed player in your region. If a permitted embed becomes available, this article can be updated without changing its match context.</p>`;
}
function playerDataSections(event) {
  const pd = event?.playerData; if (!pd || isRace(event)) return '';
  const esc = htmlEscape, sections = [], home = pd.home || {}, away = pd.away || '';
  const hxi = (home.starters || []).map(x => x.name).filter(Boolean), axi = (away.starters || []).map(x => x.name).filter(Boolean);
  if (hxi.length || axi.length) sections.push(`<h2>${pd.complete && isFinal(event) ? 'Confirmed Starting XIs' : 'Probable XIs'}</h2><p><strong>${esc(home.name || homeName(event))}:</strong> ${esc(hxi.join(', '))}<br><strong>${esc(away.name || awayName(event))}:</strong> ${esc(axi.join(', '))}</p>`);
  const goals = [home, away].map(side => { const rows = (side.scorers || []).map(x => `${esc(x.player)}${x.minute ? ` ${esc(x.minute)}` : ''}${x.assist ? ` (assist: ${esc(x.assist)})` : ''}`).join(', '); return rows ? `<strong>${esc(side.name)}:</strong> ${rows}` : ''; }).filter(Boolean);
  if (goals.length) sections.push(`<h2>Goal Scorers</h2><p>${goals.join('<br>')}</p>${pd.source === 'tsdb' && pd.complete === false ? '<p><em>Full match timeline unavailable; the available event details may be incomplete.</em></p>' : ''}`);
  const cards = [home, away].flatMap(side => (side.cards || []).map(x => `🟨 ${esc(x.player)}${x.minute ? ` ${esc(x.minute)}` : ''} (${esc(side.name)})`).concat((side.substitutions || []).map(x => `↔ ${esc(x.player)}${x.minute ? ` ${esc(x.minute)}` : ''} (${esc(side.name)})`)));
  if (cards.length) sections.push(`<h2>Bookings &amp; Substitutions</h2><p>${cards.join('<br>')}</p>`);
  const danger = [...(pd.dangerMen?.home || []), ...(pd.dangerMen?.away || [])].filter(x => x.name && x.reason);
  if (danger.length) sections.push(`<h2>Danger Men</h2><p>${danger.map(x => `<strong>${esc(x.name)}:</strong> ${esc(x.reason)}`).join('<br>')}</p>`);
  const news = [...(pd.teamNews?.home || []), ...(pd.teamNews?.away || [])].filter(Boolean);
  if (news.length) sections.push(`<h2>Team News</h2><p>${news.map(esc).join('<br>')}</p>`);
  return sections.join('');
}
function dashboardSections(event, mode) {
  if (isRace(event)) return raceSections(event);
  const base = mode === 'highlights' ? highlightsSections(event) : previewSections(event);
  return playerDataSections(event) + editorialFactsSections(event).join('') + base;
}
function playerIframe(event, streams) {
  const direct = streams.find(url => /\/player\.html\?(?:mora|embed)=/i.test(String(url || '')));
  const player = direct || playerUrlFor(event, streams);
  return player ? `<div style="margin:18px 0;position:relative;padding-bottom:56.25%;height:0;overflow:hidden"><iframe loading="lazy" allow="encrypted-media" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" src="${htmlEscape(player)}" frameborder="0" scrolling="no" allowfullscreen></iframe></div>` : '';
}
function previewHtml(event, streams) { return dashboardSections(event, 'preview') + playerIframe(event, streams); }
function highlightsHtml(event, streams) { const links = replayLinks(event); return dashboardSections(event, 'highlights') + playerIframe(event, links.length ? links : streams); }
/** Post bodies store the player URL HTML-escaped (& -> &amp;), so compare against both forms. */
function contentHasPlayer(content, player) {
  const body = normalizePlayerUrlsInHtml(content);
  const wanted = normalizePlayerUrlsInHtml(player);
  return body.includes(htmlEscape(wanted)) || body.includes(wanted);
}

// ============================================================================
// LOGOS (persistent cache) & THUMBNAIL
// ============================================================================
// The logo cache lives in .logo-cache.json in the working directory (override: LOGO_CACHE_FILE).
// NOTE: if a workflow does `git add -A` / commits the working tree, add `.logo-cache.json` to
// .gitignore. On CI, persist it between runs with actions/cache instead of committing it.

function logoCacheFile() { return process.env.LOGO_CACHE_FILE || path.join(process.cwd(), '.logo-cache.json'); }
let logoCache = null, logoCacheDirty = false;
function loadLogoCache() {
  if (logoCache) return logoCache;
  logoCache = new Map();
  try {
    const raw = JSON.parse(fs.readFileSync(logoCacheFile(), 'utf8'));
    for (const [key, entry] of Object.entries(raw || {})) if (entry && typeof entry.url === 'string' && Number.isFinite(entry.at)) logoCache.set(key, entry);
  } catch { /* missing or corrupt: start empty */ }
  return logoCache;
}
function saveLogoCache() {
  if (!logoCache || !logoCacheDirty) return;
  const now = Date.now(), fresh = {};
  for (const [key, entry] of logoCache) if (now - entry.at < (entry.ttl ?? LOGO_TTL_MS)) fresh[key] = entry;
  try { fs.writeFileSync(logoCacheFile(), JSON.stringify(fresh)); logoCacheDirty = false; }
  catch (error) { console.warn(`[logo-cache] could not write ${logoCacheFile()}: ${error.message}`); }
}
function resetLogoCache() { logoCache = null; logoCacheDirty = false; }
function rememberLogo(cache, key, url, ttl) { cache.set(key, { url, at: Date.now(), ttl }); logoCacheDirty = true; }
async function teamLogoUrl(team, fallback = '') {
  if (fallback) return fallback;
  const key = teamKey(team);
  if (!key) return '';
  const cache = loadLogoCache();
  const hit = cache.get(key);
  if (hit && Date.now() - hit.at < (hit.ttl ?? LOGO_TTL_MS)) return hit.url;
  try {
    const data = await getJson(`${TSDB_API}/searchteams.php?t=${encodeURIComponent(team)}`);
    const url = data?.teams?.[0]?.strTeamBadge || data?.teams?.[0]?.strBadge || '';
    rememberLogo(cache, key, url, url ? LOGO_TTL_MS : LOGO_MISS_TTL_MS);
    return url;
  } catch { rememberLogo(cache, key, '', LOGO_ERROR_TTL_MS); return ''; }
}
async function imageDataUrl(url) {
  if (!url) return '';
  try {
    const response = await fetch(url, { headers: { accept: 'image/*', 'user-agent': USER_AGENT }, signal: timeoutSignal() });
    if (!response.ok) return '';
    const type = response.headers.get('content-type')?.split(';')[0] || 'image/png';
    return `data:${type};base64,${Buffer.from(await response.arrayBuffer()).toString('base64')}`;
  } catch { return ''; }
}
/** side is 'H' | 'A' (selects the clip path); name is the fallback label and is only rendered when non-empty. */
function logoMarkup(data, cx, side, name) {
  if (data) return `<image href="${data}" x="${cx - 140}" y="300" width="280" height="280" preserveAspectRatio="xMidYMid meet" clip-path="url(#clip${side})"/>`;
  if (!name) return '';
  return `<text x="${cx}" y="490" fill="rgba(255,255,255,.75)" font-family="Arial,sans-serif" font-size="150" font-weight="900" text-anchor="middle">${htmlEscape(name.charAt(0))}</text>`;
}
async function thumbnailSvg(event, title, final) {
  const home = homeName(event) || eventName(event), away = awayName(event), league = leagueName(event), date = eventDateText(event);
  const [homeLogo, awayLogo] = await Promise.all([teamLogoUrl(home, event.home?.logo), teamLogoUrl(away, event.away?.logo)]);
  const [hData, aData] = await Promise.all([imageDataUrl(homeLogo), imageDataUrl(awayLogo)]);
  const score = eventScore(event), bg1 = final ? '#1a0020' : '#0d1b2a', bg2 = final ? '#350b32' : '#07111f';
  return `<svg xmlns="http://www.w3.org/2000/svg" width="1920" height="1080" viewBox="0 0 1920 1080"><defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop stop-color="${bg1}"/><stop offset="1" stop-color="${bg2}"/></linearGradient><linearGradient id="stripe" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#e63946"/><stop offset="1" stop-color="#c1121f"/></linearGradient><clipPath id="clipH"><circle cx="480" cy="440" r="132"/></clipPath><clipPath id="clipA"><circle cx="1440" cy="440" r="132"/></clipPath></defs><rect width="1920" height="1080" fill="url(#bg)"/><rect x="0" y="0" width="960" height="1080" fill="${bg1}" opacity=".2"/><rect x="960" y="0" width="960" height="1080" fill="${bg2}" opacity=".25"/><rect x="0" width="18" height="1080" fill="#e63946"/><rect x="1902" width="18" height="1080" fill="#f4a261"/><rect x="650" y="42" width="620" height="74" rx="37" fill="#080c14" opacity=".92"/><text x="960" y="91" fill="#e2e8f0" font-family="Arial,sans-serif" font-size="36" font-weight="700" text-anchor="middle">${htmlEscape(league)}</text><circle cx="480" cy="440" r="142" fill="rgba(255,255,255,.06)" stroke="rgba(255,255,255,.18)" stroke-width="4"/><circle cx="1440" cy="440" r="142" fill="rgba(255,255,255,.06)" stroke="rgba(255,255,255,.18)" stroke-width="4"/>${logoMarkup(hData, 480, 'H', home)}${logoMarkup(aData, 1440, 'A', away)}<circle cx="960" cy="440" r="62" fill="#e63946"/><text x="960" y="453" fill="#fff" font-family="Arial,sans-serif" font-size="32" font-weight="900" text-anchor="middle">${score ? htmlEscape(score) : 'VS'}</text><text x="480" y="690" fill="#fff" font-family="Arial,sans-serif" font-size="40" font-weight="800" text-anchor="middle">${htmlEscape(home)}</text><text x="1440" y="690" fill="#fff" font-family="Arial,sans-serif" font-size="40" font-weight="800" text-anchor="middle">${htmlEscape(away)}</text><text x="960" y="820" fill="rgba(255,255,255,.72)" font-family="Arial,sans-serif" font-size="28" font-weight="600" text-anchor="middle">🕐 ${htmlEscape(date)}</text><rect y="1008" width="1920" height="72" fill="url(#stripe)" opacity=".94"/><text x="960" y="1054" fill="#fff" font-family="Arial,sans-serif" font-size="28" font-weight="800" text-anchor="middle">▶  SPORTS 803 MATCH REPORT</text><rect x="1710" y="24" width="170" height="42" rx="8" fill="rgba(0,0,0,.88)"/><circle cx="1730" cy="45" r="6" fill="#e63946"/><text x="1745" y="54" fill="#fff" font-family="Arial,sans-serif" font-size="20" font-weight="900">SPORTS</text><text x="1812" y="54" fill="#39ff14" font-family="Arial,sans-serif" font-size="20" font-weight="900">803</text></svg>`;
}
async function uploadImgBb(svg, apiKey) {
  const key = text(apiKey);
  if (!key) return '';
  return withRetry(async () => {
    const fd = new FormData(); fd.append('key', key); fd.append('image', Buffer.from(svg).toString('base64'));
    const response = await fetch(IMGBB_API, { method: 'POST', body: fd, signal: timeoutSignal() });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) throw new HttpError(`ImgBB upload failed: ${data.error?.message || response.status}`, { status: response.status });
    return data.data.url;
  });
}

// ============================================================================
// GOOGLE / BLOGGER
// ============================================================================

async function accessToken(config) {
  return withRetry(async () => {
    const body = new URLSearchParams({ client_id: config.clientId, client_secret: config.clientSecret, refresh_token: config.refreshToken, grant_type: 'refresh_token' });
    const response = await fetch(TOKEN_URL, { method: 'POST', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body, signal: timeoutSignal() });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.access_token) {
      const detail = data.error_description || data.error || `HTTP ${response.status}`;
      const hint = data.error === 'invalid_grant'
        ? 'The refresh token was revoked, expired, or created for a different OAuth client. Generate a new token with the exact client ID and secret stored in GitHub.'
        : data.error === 'unauthorized_client'
          ? 'The OAuth client is not allowed to use this grant. Check that the client ID and secret are from the same Web application OAuth client.'
          : 'Check that the three OAuth GitHub Secrets belong to the same client and contain no quotes or extra whitespace.';
      const permanent = ['invalid_grant', 'unauthorized_client', 'invalid_client'].includes(data.error);
      throw new HttpError(`Google token refresh failed: ${detail}. ${hint}`, { status: response.status, retryable: permanent ? false : undefined });
    }
    return data.access_token;
  });
}
/**
 * One list call (no bodies, ids/labels/published only). Bodies are fetched later, per post, only when needed.
 */
async function listPosts(token, blogId) {
  const posts = [];
  let pageToken = '';
  do {
    const query = new URLSearchParams({ maxResults: '500', fetchBodies: 'false', fields: 'items(id,labels,published),nextPageToken' });
    if (pageToken) query.set('pageToken', pageToken);
    const data = await getJson(`${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts?${query}`, { headers: { authorization: `Bearer ${token}` } });
    posts.push(...(data.items || []));
    pageToken = data.nextPageToken || '';
  } while (pageToken);
  return posts;
}
async function getPostBody(token, blogId, postId) {
  const query = new URLSearchParams({ fetchBodies: 'true', fields: 'id,content' });
  const data = await getJson(`${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/${encodeURIComponent(postId)}?${query}`, { headers: { authorization: `Bearer ${token}` } });
  return typeof data.content === 'string' ? data.content : '';
}
async function getPostForRepair(token, blogId, postId) {
  const query = new URLSearchParams({ fetchBodies: 'true', fields: 'id,title,content,labels,published' });
  return getJson(`${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/${encodeURIComponent(postId)}?${query}`, { headers: { authorization: `Bearer ${token}` } });
}
async function repairMalformedPlayerUrls(token, blogId, posts, sleepFn = sleep) {
  const counts = { scanned: 0, updated: 0, skipped: 0, failed: 0 };
  for (const listed of posts) {
    counts.scanned++;
    try {
      const post = await getPostForRepair(token, blogId, listed.id);
      const content = String(post.content || '');
      if (!/\/player\.html\/+\?/i.test(content)) { counts.skipped++; continue; }
      const fixedContent = normalizePlayerUrlsInHtml(content);
      const payload = { kind: 'blogger#post', blog: { id: blogId }, title: post.title || '', content: fixedContent, labels: post.labels || [] };
      if (post.published) payload.published = post.published;
      await bloggerWrite(token, blogId, post.id, payload);
      counts.updated++;
      console.log(`repaired ${post.id}: ${post.title || '(untitled)'}`);
      logEvent({ action: 'repaired-player-url', postId: post.id, title: post.title || '' });
      await sleepFn(350);
    } catch (error) {
      counts.failed++;
      console.error(`repair failed ${listed.id}: ${error.message}`);
      logEvent({ action: 'repair-failed', postId: listed.id, error: error.message });
    }
  }
  return counts;
}
async function bloggerWrite(token, blogId, postId, payload) {
  const url = postId ? `${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/${encodeURIComponent(postId)}` : `${BLOGGER_API}/blogs/${encodeURIComponent(blogId)}/posts/`;
  return withRetry(async () => {
    let response;
    try {
      response = await fetch(url, { method: postId ? 'PUT' : 'POST', headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' }, body: JSON.stringify(payload), signal: timeoutSignal() });
    } catch (error) {
      // A timed-out/dropped POST may already have created the post; retrying could duplicate it.
      // The marker label makes the next run idempotent, so fail this one instead.
      if (!postId) error.retryable = false;
      throw error;
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new HttpError(`Blogger write failed (${response.status}): ${JSON.stringify(data).slice(0, 500)}`, { status: response.status });
    return data;
  });
}

// ============================================================================
// ESPN DISCOVERY
// ============================================================================

function dashboardDate(timeZone) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const values = Object.fromEntries(parts.filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
  return `${values.year}${values.month}${values.day}`;
}
function eventDateWindow() {
  const raw = process.env.EVENT_DATE;
  const normalized = raw && /^\d{8}$/.test(raw) ? `${raw.slice(0, 4)}-${raw.slice(4, 6)}-${raw.slice(6, 8)}` : raw;
  const anchor = normalized ? new Date(`${normalized}T12:00:00`) : new Date(`${dashboardDate(eventTimeZone()).slice(0, 4)}-${dashboardDate(eventTimeZone()).slice(4, 6)}-${dashboardDate(eventTimeZone()).slice(6, 8)}T12:00:00`);
  return [-1, 0, 1].map(offset => { const date = new Date(anchor); date.setDate(date.getDate() + offset); return date.toISOString().slice(0, 10); });
}
function dedupeEvents(events) {
  const seen = new Set();
  return events.filter(event => { const key = text(event.id || event.name); if (!key || seen.has(key)) return false; seen.add(key); return true; });
}
function setLeagueFetchResult(league, status, source) { leagueFetchStatus[league.id] = status; leagueFetchSource[league.id] = source || ''; }
async function fetchTSDBRacingDay(date) {
  if (tsdbRacingDayCache.has(date)) return tsdbRacingDayCache.get(date);
  const promise = getJson(`${TSDB_API}/eventsday.php?d=${encodeURIComponent(date)}&s=Motorsport`, { headers: { accept: 'application/json', 'user-agent': USER_AGENT } }).then(data => Array.isArray(data.events) ? data.events : []);
  tsdbRacingDayCache.set(date, promise);
  try { return await promise; } catch (error) { tsdbRacingDayCache.delete(date); throw error; }
}
// v11: normalize TheSportsDB motorsport events to the dashboard race model.
async function fetchRacingFromTSDB(league, dates = eventDateWindow()) {
  const allowedIds = new Set(TSDB_RACING_LEAGUE[league.id] || []);
  if (!allowedIds.size) return [];
  const dayResults = await Promise.all(dates.map(fetchTSDBRacingDay));
  const events = dayResults.flat().filter(event => {
    const name = text(event.strLeague).toLowerCase();
    return allowedIds.has(Number(event.idLeague)) || (league.id === 'wrc' && /wrc|world rally/.test(name)) || (league.id === 'imsa' && /imsa|weathertech|sportscar/.test(name)) || (league.id === 'nascar' && /nascar/.test(name));
  }).map(event => ({ id: event.idEvent, league, type: 'race', name: event.strEvent || `${league.name} race`, venue: event.strVenue || '', location: event.strCity || event.strCountry || '', entries: [], status: event.strStatus || 'Scheduled', startTime: event.strTimestamp ? new Date(event.strTimestamp) : (event.dateEvent ? new Date(`${event.dateEvent}T${event.strTime || '12:00:00'}`) : null), source: 'TheSportsDB' }));
  return dedupeEvents(events);
}
function parseDashboardEvent(event, league) {
  const competition = event.competitions?.[0] || {}, competitors = competition.competitors || [];
  const home = competitors.find(item => item.homeAway === 'home') || competitors[0] || {};
  const away = competitors.find(item => item.homeAway === 'away') || competitors[1] || {};
  const teamName = item => item.team?.displayName || item.team?.shortDisplayName || 'TBD';
  const logo = item => String(item.team?.logo || item.team?.logos?.[0]?.href || '').replace(/^http:\/\//i, 'https://');
  const status = event.status?.type?.description || 'Scheduled';
  const sourceUrl = league.sport && league.slug && event.id ? `${ESPN_API}/${league.sport}/${league.slug}/summary?event=${encodeURIComponent(event.id)}` : '';
  if (league.type === 'race') return { id: event.id, league, type: 'race', name: event.name || event.shortName || `${league.name} event`, status, startTime: event.date, endTime: event.endDate || competition.endDate || null, source: 'ESPN', sourceUrl };
  return { id: event.id, league, type: 'match', home: { name: teamName(home), logo: logo(home), score: home.score ?? '' }, away: { name: teamName(away), logo: logo(away), score: away.score ?? '' }, status, startTime: event.date, sourceUrl };
}

// v12: player-level enrichment. ESPN summary is primary; TheSportsDB is fallback.
// TSDB free tier truncates arrays to 5 rows silently — cross-check timeline goals
// against final score and mark complete:false when they disagree.
function playerSide(name) { return { name: name || 'Team', starters: [], subs: [], scorers: [], cards: [], substitutions: [] }; }
function playerName(value) { return value?.athlete?.displayName || value?.athlete?.fullName || value?.player?.displayName || value?.displayName || value?.strPlayer || ''; }
function playerMinute(value) { return value?.displayValue || value?.text || value?.strTime || value?.intTime || ''; }
function emptyPlayerData(event, source = null) { return { source, complete: null, home: playerSide(homeName(event)), away: playerSide(awayName(event)), timeline: [], dangerMen: { home: [], away: [] }, teamNews: { home: [], away: [] } }; }
function playerDataSportSupported(event) { return ['soccer', 'basketball', 'hockey'].includes(text(event.league?.sport).toLowerCase()); }
function parseSummaryPlayerData(event, summary) {
  const data = emptyPlayerData(event, 'espn'), competitors = summary?.header?.competitions?.[0]?.competitors || [], teamIds = {};
  competitors.forEach((item, index) => { const id = String(item.id || item.team?.id || ''); if (id) teamIds[id] = index ? 'away' : 'home'; });
  (Array.isArray(summary?.rosters) ? summary.rosters : []).forEach((group, index) => {
    const side = teamIds[String(group.team?.id || group.teamId || '')] || (index ? 'away' : 'home');
    for (const row of group.roster || group.players || []) { const name = playerName(row); if (!name) continue; const item = { name, starter: row.starter === true, position: row.position?.abbreviation || row.position?.displayName || '', number: row.jersey || row.uniformNumber || '', stats: row.stats || [] }; (item.starter ? data[side].starters : data[side].subs).push(item); }
  });
  (summary?.keyEvents || []).forEach(item => {
    const type = text(item.type?.text || item.type?.name).toLowerCase(), player = playerName(item.participants?.[0]) || playerName(item); if (!player) return;
    const teamId = String(item.team?.id || item.team?.uid || item.teamId || ''), side = teamIds[teamId] || (data.timeline.length % 2 ? 'away' : 'home');
    const row = { player, minute: playerMinute(item.clock || item), assist: playerName(item.participants?.[1]) || '', type, team: data[side].name };
    if (/goal|point/.test(type)) { data[side].scorers.push(row); data.timeline.push(row); } else if (/yellow|red|card|booking/.test(type)) data[side].cards.push(row); else if (/substitution|sub/.test(type)) data[side].substitutions.push(row);
  });
  [data.home, data.away].forEach((side, index) => { const scorer = side.scorers[0]; if (scorer?.player) data.dangerMen[index ? 'away' : 'home'].push({ name: scorer.player, reason: scorer.minute ? `scored at ${scorer.minute}` : 'scored in this match' }); });
  data.complete = Boolean(data.home.starters.length || data.away.starters.length || data.timeline.length); return data;
}
async function tsdbPlayerJson(kind, eventId) {
  const key = `${kind}:${eventId}`; if (tsdbPlayerCache.has(key)) return tsdbPlayerCache.get(key);
  if (tsdbPlayerCalls >= PLAYER_DATA_TSDB_MAX_CALLS) return null;
  tsdbPlayerCalls++; const data = await getJson(`${TSDB_API}/${kind}.php?id=${encodeURIComponent(eventId)}`, { headers: { accept: 'application/json', 'user-agent': USER_AGENT } }).catch(() => null); tsdbPlayerCache.set(key, data); return data;
}
async function hydrateEventPlayerData(event, options = {}) {
  if (!event || isRace(event) || !playerDataSportSupported(event) || process.env.ENRICH_PLAYER_DATA === '0') return null;
  const key = String(event.id || eventKey(event, 0)); if (!options.force && playerDataCache.has(key)) { event.playerData = playerDataCache.get(key); return event.playerData; }
  try {
    const summary = await getJson(`${ESPN_API}/${event.league?.sport || 'soccer'}/${event.league?.slug || ''}/summary?event=${encodeURIComponent(event.id)}`, { headers: { accept: 'application/json', 'user-agent': USER_AGENT } });
    const data = parseSummaryPlayerData(event, summary); playerDataCache.set(key, data); event.playerData = data; return data;
  } catch (error) {
    const timeline = await tsdbPlayerJson('event_timeline', event.id), lineup = await tsdbPlayerJson('event_lineup', event.id);
    if (!timeline && !lineup) { console.warn(`[Player data] ${eventName(event)}: ${error.message}`); playerDataCache.set(key, null); return null; }
    const data = emptyPlayerData(event, 'tsdb'), rows = timeline?.timeline || timeline?.events || timeline?.event || [];
    for (const row of Array.isArray(rows) ? rows : []) { const name = playerName(row) || row.strPlayer || row.strPlayer2 || ''; if (!name) continue; const side = /away/i.test(row.strHome || row.strTeam || '') ? data.away : data.home; const type = text(row.strType || row.strEvent).toLowerCase(); const entry = { player: name, minute: row.intTime || row.strTime || '', assist: row.strAssist || '', type, team: side.name }; if (/goal|score/.test(type)) { side.scorers.push(entry); data.timeline.push(entry); } else if (/card|yellow|red/.test(type)) side.cards.push(entry); else if (/subst/.test(type)) side.substitutions.push(entry); }
    const starters = lineup?.lineup || lineup?.players || lineup?.event_lineup || [];
    (Array.isArray(starters) ? starters : []).forEach((row, index) => { const name = playerName(row); if (name) (index % 2 ? data.away : data.home).starters.push({ name, starter: true, position: row.strPosition || row.position || '' }); });
    const score = [event.home?.score, event.away?.score].map(Number), goals = data.timeline.filter(item => /goal|score/.test(item.type)).length;
    data.complete = score.every(Number.isFinite) ? goals === score.reduce((sum, value) => sum + value, 0) : false;
    playerDataCache.set(key, data); event.playerData = data; return data;
  }
}
async function enrichEventsWithPlayerData(events) {
  if (process.env.ENRICH_PLAYER_DATA === '0') return events;
  const matches = events.filter(event => event.type === 'match' && !isRace(event)).slice(0, PLAYER_DATA_MAX_EVENTS);
  if (events.filter(event => event.type === 'match' && !isRace(event)).length > PLAYER_DATA_MAX_EVENTS) console.warn(`[Player data] cap reached at ${PLAYER_DATA_MAX_EVENTS} events`);
  for (let i = 0; i < matches.length; i += PLAYER_DATA_CONCURRENCY) { await Promise.allSettled(matches.slice(i, i + PLAYER_DATA_CONCURRENCY).map(event => hydrateEventPlayerData(event))); await sleep(120); }
  return events;
}
// v11: every broken source is logged; empty 200 responses remain valid empty leagues.
async function fetchLeague(league, dates = eventDateWindow()) {
  const anchorDate = dates[1];
  if (league.type === 'race' && !ESPN_RACING_SUPPORTED.has(league.id)) {
    try { const events = await fetchRacingFromTSDB(league, dates); setLeagueFetchResult(league, events.length ? 'ok' : 'empty', 'TheSportsDB'); return events; }
    catch (error) { setLeagueFetchResult(league, 'error', 'TheSportsDB'); throw new Error(`${league.name} TheSportsDB failed: ${error.message}`); }
  }
  const slugs = [league.slug];
  if (league.id === 'nations') slugs.push('uefa.nations_league');
  let hadEmpty = false, lastError = null;
  for (const slug of slugs) {
    const collected = [];
    for (const date of dates) {
      try {
        const data = await getJson(`${ESPN_API}/${league.sport}/${slug}/scoreboard?dates=${encodeURIComponent(date.replace(/-/g, ''))}`, { headers: { accept: 'application/json' } });
        if (Array.isArray(data.events) && data.events.length) collected.push(...data.events.map(event => ({ event, date })));
        else hadEmpty = true;
      } catch (error) { lastError = error; console.warn(`[ESPN] ${league.name} (${slug}, ${date}): ${error.message}`); }
    }
    const parsed = dedupeEvents(collected.filter(item => league.type === 'race' || item.date === anchorDate).map(item => parseDashboardEvent(item.event, league)));
    if (parsed.length) { setLeagueFetchResult(league, 'ok', 'ESPN'); return parsed; }
  }
  if (league.type === 'race') {
    try { const events = await fetchRacingFromTSDB(league, dates); setLeagueFetchResult(league, events.length ? 'ok' : 'empty', 'TheSportsDB'); return events; }
    catch (error) { lastError = error; console.warn(`[TSDB] ${league.name}: ${error.message}`); }
  }
  if (lastError && !hadEmpty) { setLeagueFetchResult(league, 'error', 'ESPN'); throw new Error(`${league.name} ESPN failed: ${lastError.message}`); }
  setLeagueFetchResult(league, 'empty', 'ESPN');
  return [];
}
async function fetchDashboardEvents() {
  const dates = eventDateWindow();
  const results = await Promise.allSettled(DASHBOARD_LEAGUES.map(league => fetchLeague(league, dates)));
  results.forEach((result, index) => { if (result.status === 'rejected') console.warn(`[Fetch] ${DASHBOARD_LEAGUES[index].name} failed: ${result.reason?.message || result.reason}`); });
  return dedupeEvents(results.flatMap(result => result.status === 'fulfilled' ? result.value : []));
}

// ============================================================================
// POSTING PIPELINE
// ============================================================================

/** One JSON line per event; single line, no indent, so it can be grepped/parsed out of the human logs. */
function logEvent(entry) { console.log(JSON.stringify(entry)); }

/**
 * Decide whether an existing post needs rewriting. Labels alone settle the "now final" case; the
 * "player URL changed/missing" case needs the body, which we fetch lazily (one GET) and only for
 * posts of today's events that have a player URL to compare against.
 */
function contentHasPlayerData(content) {
  const body = String(content || '').toLowerCase();
  return /confirmed starting xis|probable xis|goal scorers|scorers|disciplinary|substitutions|danger men|team news/.test(body);
}
async function needsUpdate(existing, { event, final, desiredPlayer }, ctx) {
  if (final && !(existing.labels || []).includes('Highlights')) return true;
  // A post is valid without a highlight/stream iframe. Revisit it when verified
  // player data becomes available so lineups and match details can be enriched later.
  if (event?.playerData && !contentHasPlayerData(existing.content)) {
    if (typeof existing.content !== 'string') existing.content = await getPostBody(ctx.token, ctx.blogId, existing.id);
    if (!contentHasPlayerData(existing.content)) return true;
  }
  if (!desiredPlayer) return false;
  if (typeof existing.content !== 'string') existing.content = await getPostBody(ctx.token, ctx.blogId, existing.id);
  if (/\/player\.html\/+\?/i.test(existing.content)) return true;
  return !contentHasPlayer(existing.content, desiredPlayer);
}

async function processEvents(events, ctx) {
  const { token, blogId, byMarker, imgbbKey, sleepFn = sleep } = ctx;
  const counts = { created: 0, updated: 0, skipped: 0, failed: 0 };
  for (let index = 0; index < events.length; index++) {
    const event = events[index];
    let key = '', title = '', existing = null;
    try {
      key = eventKey(event, index);
      const marker = markerFor(key), streams = streamLinks(event), final = isFinal(event);
      let superSport = null;
      if (final && event.type === 'match' && !event.externalSources?.some(source => source.source === 'onetv')) superSport = await findSuperSportHighlight(event);
      if (superSport) { event.highlightsUrl = superSport.playerUrl; event.replayUrl = superSport.pageUrl; event.externalSources = [...(event.externalSources || []), { url: superSport.rawSource, type: superSport.sourceKind === 'm3u8' ? 'mora' : 'embed', source: 'supersport' }]; }
      existing = byMarker.get(marker) || null;
      const label = isRace(event) ? eventName(event) : displayEventName(event);
      title = isRace(event) ? (final ? `${label} – Full Race Replay | Sports 803` : `${label} – Race Preview & Live Stream | Sports 803`) : (final ? `${label} – Highlights & Replay | Sports 803` : `${label} – ${leagueName(event)} Live Stream | Sports 803`);
      const desiredPlayer = superSport?.playerUrl || playerUrlFor(event, streams);
      const shouldUpdate = existing ? await needsUpdate(existing, { event, final, desiredPlayer }, ctx) : false;
      if (existing && !shouldUpdate) {
        counts.skipped++;
        console.log(`skipped ${existing.id}: ${title}`);
        logEvent({ key, action: 'skipped', postId: existing.id, title });
        continue;
      }

      // Only now (we are definitely writing) do we build and upload the thumbnail; failure => post without it.
      const article = final ? highlightsHtml(event, streams) : previewHtml(event, streams);
      let thumbHtml = '';
      try {
        const thumbUrl = await uploadImgBb(await thumbnailSvg(event, title, final), imgbbKey);
        if (thumbUrl) thumbHtml = `<div><img src="${htmlEscape(thumbUrl)}" style="max-width:100%;height:auto;border-radius:10px;margin-bottom:18px" alt="${htmlEscape(title)}"></div>\n`;
      } catch (error) { console.warn(`[ImgBB] ${title}: ${error.message}; posting without thumbnail`); }

      const payload = { kind: 'blogger#post', blog: { id: blogId }, title, content: thumbHtml + article, labels: labelsFor(event, key, final), searchDescription: `${eventName(event)} ${final ? 'highlights and replay' : 'live stream'} on Sports 803`.slice(0, 150) };
      let post, action;
      if (!existing) {
        post = await bloggerWrite(token, blogId, null, payload);
        counts.created++; action = 'created';
      } else {
        payload.id = existing.id;
        if (existing.published) payload.published = existing.published;
        post = await bloggerWrite(token, blogId, existing.id, payload);
        counts.updated++; action = 'updated';
      }
      byMarker.set(marker, post);
      console.log(`${action} ${post.id}: ${title}`);
      logEvent({ key, action, postId: post.id, title, hlSource: superSport?.source || '' });
      await sleepFn(500 + Math.random() * 300);
    } catch (error) {
      counts.failed++;
      console.error(`failed ${key} "${title}": ${error.message}`);
      logEvent({ key, action: 'failed', postId: existing?.id || '', title, error: error.message });
    }
  }
  return counts;
}

async function main() {
  const config = loadConfig(); // validates env + timezone before any network call
  if (process.env.REPAIR_PLAYER_SLASHES === '1' || process.env.REPAIR_PLAYER_SLASHES === 'true') {
    const token = await accessToken(config);
    const posts = await listPosts(token, config.blogId);
    const counts = await repairMalformedPlayerUrls(token, config.blogId, posts);
    console.log(JSON.stringify({ mode: 'repair-player-slashes', ...counts }));
    if (counts.failed > 0) process.exitCode = 1;
    return;
  }
  const allEvents = await attachExternalSources(await fetchDashboardEvents());
  await enrichEventsWithPlayerData(allEvents);
  // Dashboard cards are valid events before an external stream is resolved.
  const events = allEvents.filter(event => !isDead(event) && (isToday(event) || (event.type === 'race' && (event.source === 'ESPN' || event.source === 'TheSportsDB'))) && matchesConfiguredLeague(event));

  if (config.dryRun) {
    let sampleThumbnail = '', failed = 0;
    try { sampleThumbnail = events[0] ? await thumbnailSvg(events[0], eventName(events[0]), isFinal(events[0])) : ''; }
    catch (error) { failed = 1; console.error(`[dry-run] thumbnail failed: ${error.message}`); }
    saveLogoCache();
    console.log(JSON.stringify({ source: 'dashboard-espn', scanned: allEvents.length, eligible: events.length, failed, timezone: config.timeZone, playerData: events.map(event => ({ id: event.id, title: eventName(event), source: event.playerData?.source || null, complete: event.playerData?.complete ?? null, starters: (event.playerData?.home?.starters?.length || 0) + (event.playerData?.away?.starters?.length || 0), scorers: (event.playerData?.home?.scorers?.length || 0) + (event.playerData?.away?.scorers?.length || 0) })), thumbnail: { svgBytes: Buffer.byteLength(sampleThumbnail), embedsLogo: sampleThumbnail.includes('data:image/'), events: events.map((event, index) => ({ key: eventKey(event, index), title: eventName(event), league: leagueName(event), scheduled: startTime(event), final: isFinal(event), sources: event.externalSources || [], player: playerUrlFor(event, streamLinks(event)) })) } }));
    if (failed) process.exitCode = 1;
    return;
  }

  const token = await accessToken(config);
  const posts = await listPosts(token, config.blogId);
  const byMarker = new Map();
  for (const post of posts) for (const label of post.labels || []) if (label.startsWith('s803:event:')) byMarker.set(label, post);

  const counts = await processEvents(events, { token, blogId: config.blogId, byMarker, imgbbKey: config.imgbbKey });
  saveLogoCache();
  console.log(JSON.stringify({ detected: events.length, created: counts.created, updated: counts.updated, skipped: counts.skipped, failed: counts.failed }));
  // The run finished every event; still exit non-zero so CI shows partial failures.
  if (counts.failed > 0) process.exitCode = 1;
}

export {
  DASHBOARD_LEAGUES, LEAGUE_ALIASES, SPORT_LABELS, MAX_LABELS,
  loadConfig, withRetry, isRetryable, HttpError, getJson,
  text, pick, htmlEscape, stableHash, isFinal, isDead, eventName, eventScore, leagueName, leagueId, canonicalLeague, isRace,
  localDate, isToday, streamLinks, teamKey, teamsMatch, pairMatch,
  attachExternalSources, buildFeedIndex, replayLinks, eventKey, markerFor, labelsFor, playerUrlFor,
  dashboardSections, raceSections, previewSections, highlightsSections, playerDataSections, contentHasPlayer, supersportSportFor, fetchSuperSportPage, extractSuperSportVideo, findSuperSportHighlight,
  teamLogoUrl, saveLogoCache, resetLogoCache, logoMarkup, thumbnailSvg, uploadImgBb,
  accessToken, listPosts, getPostBody, getPostForRepair, repairMalformedPlayerUrls, bloggerWrite, processEvents, parseDashboardEvent, hydrateEventPlayerData, enrichEventsWithPlayerData, fetchLeague, fetchDashboardEvents, fetchRacingFromTSDB, leagueFetchStatus, leagueFetchSource, normalizePlayerBase, normalizePlayerUrlsInHtml
};

const invokedDirectly = process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href;
if (invokedDirectly) main().catch(error => { console.error(error.stack || error.message || error); process.exitCode = 1; });
