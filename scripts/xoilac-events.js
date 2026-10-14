(function attachXoilacEvents(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.XoilacEvents = api;
})(typeof window !== 'undefined' ? window : globalThis, function createXoilacEvents() {
  'use strict';

  const SITE_ORIGIN = 'https://xoilaczzz.pro';
  const CARD_START_RE = /<div\b[^>]*>/gi;

  function decodeEntities(value) {
    return String(value || '')
      .replace(/&#(\d+);/g, (_match, number) => String.fromCodePoint(Number(number)))
      .replace(/&#x([\da-f]+);/gi, (_match, number) => String.fromCodePoint(parseInt(number, 16)))
      .replace(/&nbsp;/gi, ' ')
      .replace(/&amp;/gi, '&')
      .replace(/&quot;/gi, '"')
      .replace(/&#39;|&apos;/gi, "'")
      .replace(/&lt;/gi, '<')
      .replace(/&gt;/gi, '>');
  }

  function attributes(tag) {
    const result = {};
    const attrRe = /([\w:-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g;
    let match;
    while ((match = attrRe.exec(tag))) result[match[1].toLowerCase()] = decodeEntities(match[2] ?? match[3] ?? match[4] ?? '');
    return result;
  }

  function stripMarkup(value) {
    return decodeEntities(String(value || '')
      .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
      .replace(/<\/(?:div|p|span|li|br|h\d)\s*>/gi, ' ')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')).trim();
  }

  function textByClass(fragment, className) {
    const escaped = className.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp(`<([a-z][\\w:-]*)\\b[^>]*class\\s*=\\s*["'][^"']*\\b${escaped}\\b[^"']*["'][^>]*>([\\s\\S]*?)<\\/\\1\\s*>`, 'i');
    const match = re.exec(fragment);
    return match ? stripMarkup(match[2]) : '';
  }

  function parseStartTime(containerAttrs, title) {
    const runtime = Number(containerAttrs['data-runtime']);
    if (Number.isFinite(runtime) && runtime > 0) return new Date(runtime * 1000);
    const match = String(title || '').match(/(\d{1,2}):(\d{2})\s*(?:lúc|ngày)\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i)
      || String(title || '').match(/(?:lúc\s*)?(\d{1,2}):(\d{2})[^\d]*(\d{1,2})\/(\d{1,2})\/(\d{4})/i);
    if (!match) return null;
    const [, hour, minute, day, month, year] = match.map(Number);
    // The page displays Vietnam local time (UTC+7); use an explicit offset instead of browser-local time.
    return new Date(Date.UTC(year, month - 1, day, hour - 7, minute));
  }

  function parseStatus(code) {
    const statuses = {
      '1': 'Scheduled', '2': 'In Progress', '3': 'Half Time', '4': 'In Progress',
      '5': 'Extra Time', '6': 'Extra Time', '7': 'Penalties', '8': 'Final',
      '9': 'Postponed', '10': 'Interrupted', '11': 'Interrupted', '12': 'Cancelled'
    };
    return statuses[String(code || '')] || 'Scheduled';
  }

  function chooseEventUrl(href, randomStreams, fragment) {
    if (!href || !/^\/truc-tiep\//i.test(href)) return '';
    let base;
    try { base = new URL(href, SITE_ORIGIN); } catch { return ''; }
    if (base.origin !== SITE_ORIGIN) return '';
    const streamChoices = String(randomStreams || '').match(/link\/\d+\/?/gi) || [];
    const fallbackChoices = String(fragment || '').match(/href\s*=\s*["']([^"']*\/link\/\d+\/?)["']/gi) || [];
    const fallback = fallbackChoices.map(item => (item.match(/href\s*=\s*["']([^"']+)/i) || [])[1]).find(Boolean);
    const suffix = streamChoices[0] || (fallback ? fallback.slice(fallback.lastIndexOf('/link/')).replace(/^\//, '') : '');
    const path = base.pathname.replace(/\/+$/, '');
    return `${SITE_ORIGIN}${path}${suffix ? `/${suffix.replace(/^\/+/, '').replace(/\/+$/, '')}/` : '/'}`;
  }

  function parseCard(fragment, openingTag) {
    const containerAttrs = attributes(openingTag);
    const containerClass = containerAttrs.class || '';
    const sport = String(containerAttrs['data-sport'] || '').toLowerCase();
    if (!/\bmatch-football-item\b/i.test(containerClass) && sport !== 'football') return null;
    const anchorMatch = /<a\b[^>]*>/gi;
    let anchorTag = '';
    let match;
    while ((match = anchorMatch.exec(fragment))) {
      const attrs = attributes(match[0]);
      if (/\bredirectPopup\b/i.test(attrs.class || '')) { anchorTag = match[0]; break; }
    }
    if (!anchorTag) return null;
    const anchor = attributes(anchorTag);
    const title = anchor.title || '';
    const home = textByClass(fragment, 'grid-match__team--home-name');
    const away = textByClass(fragment, 'grid-match__team--away-name');
    if (!home || !away) return null;
    const href = anchor.href || '';
    const xoilacUrl = chooseEventUrl(href, anchor['data-random-streams'], fragment);
    if (!xoilacUrl) return null;
    const competition = textByClass(fragment, 'grid-match__league') || textByClass(fragment, 'text-ellipsis') || 'Football';
    const matchId = (containerAttrs['data-fid'] || containerAttrs.id || new URL(xoilacUrl).pathname).replace(/^match-child-/, '');
    const startTime = parseStartTime(containerAttrs, title);
    return {
      id: `xoilac-${matchId}`,
      type: 'match',
      sport: 'soccer',
      league: { id: 'xoilac', sport: 'soccer', slug: 'xoilac', name: competition, icon: '⚽', type: 'match' },
      home: { name: home, logo: '', score: '' },
      away: { name: away, logo: '', score: '' },
      status: parseStatus(containerAttrs['data-status']),
      startTime,
      source: 'Xoilac',
      sourceUrl: `${SITE_ORIGIN}${href}`,
      xoilacUrl
    };
  }

  function parseXoilacEvents(html) {
    const source = String(html || '');
    const starts = [];
    let match;
    CARD_START_RE.lastIndex = 0;
    while ((match = CARD_START_RE.exec(source))) {
      const attrs = attributes(match[0]);
      if (/\bgrid-matches__item\b/i.test(attrs.class || '') && /\bgrid-matches__item-match\b/i.test(attrs.class || '')) {
        starts.push({ index: match.index, tag: match[0] });
      }
    }
    const events = [];
    for (let index = 0; index < starts.length; index++) {
      const current = starts[index];
      const end = starts[index + 1]?.index ?? source.length;
      const event = parseCard(source.slice(current.index, end), current.tag);
      if (event) events.push(event);
    }
    const seen = new Set();
    return events.filter(event => {
      const key = `${event.home.name.toLowerCase()}|${event.away.name.toLowerCase()}|${event.startTime?.toISOString() || ''}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function teamKey(value) {
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
      .replace(/\b(?:fc|cf|sc|afc|club|football club)\b/g, ' ')
      .replace(/[^a-z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
  }

  function sameTeamPair(left, right) {
    const l = [teamKey(left?.home?.name), teamKey(left?.away?.name)].filter(Boolean);
    const r = [teamKey(right?.home?.name), teamKey(right?.away?.name)].filter(Boolean);
    if (l.length !== 2 || r.length !== 2) return false;
    return (l[0] === r[0] && l[1] === r[1]) || (l[0] === r[1] && l[1] === r[0]);
  }

  function buildPlayerUrl(playerBase, sources) {
    if (!Array.isArray(sources) || !sources.length) return '';
    const one = sources.find(source => source.type === 'one' || source.type === 'm3u8');
    const mora = sources.find(source => source.type === 'mora' || source.type === 'hls');
    const embeds = sources.filter(source => source.type === 'embed');
    const xoilac = sources.find(source => source.type === 'xoilac' && source.url);
    const params = [];
    if (one?.url) params.push('one=' + one.url);
    else if (mora?.url) params.push('mora=' + encodeURIComponent(mora.url));
    embeds.forEach(source => { if (source.url) params.push('embed=' + encodeURIComponent(source.url)); });
    if (xoilac?.url) params.push('xoilac=' + xoilac.url);
    const base = String(playerBase || '').replace(/\/+$/, '');
    return base && params.length ? base + '?' + params.join('&') : '';
  }

  function mergeXoilacEvents(events) {
    const result = events.filter(event => event?.source !== 'Xoilac').map(event => ({ ...event }));
    const standalone = [];
    for (const event of events.filter(item => item?.source === 'Xoilac')) {
      const time = event.startTime ? new Date(event.startTime).getTime() : NaN;
      const target = result.find(candidate => {
        if (candidate.type !== 'match' || !sameTeamPair(candidate, event)) return false;
        const otherTime = candidate.startTime ? new Date(candidate.startTime).getTime() : NaN;
        return !Number.isFinite(time) || !Number.isFinite(otherTime) || Math.abs(time - otherTime) <= 4 * 60 * 60 * 1000;
      });
      if (target) {
        target.xoilacUrl = target.xoilacUrl || event.xoilacUrl;
        target.xoilacPageUrl = target.xoilacPageUrl || event.sourceUrl;
        target.externalSources = [...(target.externalSources || []), { url: event.xoilacUrl, type: 'xoilac', source: 'xoilac' }];
      } else standalone.push(event);
    }
    return result.concat(standalone);
  }

  return { parseXoilacEvents, mergeXoilacEvents, chooseEventUrl, teamKey, sameTeamPair, buildPlayerUrl };
});
