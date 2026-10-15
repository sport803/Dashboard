// Sports 803 canonical event matching engine.
// Provider records are intentionally kept separate from canonical fixture data.

const DEFAULT_TEAM_ALIASES = {
  'man united': 'manchester united',
  'man utd': 'manchester united',
  'manchester utd': 'manchester united',
  'psg': 'paris saint germain',
  'paris sg': 'paris saint germain',
  'inter milan': 'internazionale',
  'inter': 'internazionale',
  'fc barcelona': 'barcelona',
  'bayern munich': 'bayern munich',
  'fenerbahce': 'fenerbahce',
  'csu craiova': 'universitatea craiova',
  'afc ajax': 'ajax',
  'midtjylland': 'fc midtjylland',
  'thun': 'fc thun'
};

const DEFAULT_COMPETITION_ALIASES = {
  'uefa europa conference league': 'uefa conference league',
  'uefa europa conference': 'uefa conference league',
  'uefa.europa.conf': 'uefa conference league',
  'uecl': 'uefa conference league',
  'uefa champions league': 'champions league',
  'uefa europa league': 'europa league'
};

const STOPWORDS = new Set(['fc', 'afc', 'rfc', 'sc', 'cf', 'ac', 'as', 'bsc', 'rcd', 'rc', 'vfb', 'vfl', '1fc', 'club', 'clube']);

function asText(value) {
  return value == null || typeof value === 'boolean' ? '' : String(value).trim();
}

function fold(value) {
  return asText(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
    .replace(/[’'`]/g, '').replace(/[&+]/g, ' and ').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
}

function aliasLookup(value, aliases = DEFAULT_TEAM_ALIASES) {
  const key = fold(value);
  return fold(aliases[key] || key);
}

export function normalizeTeam(value, aliases = DEFAULT_TEAM_ALIASES) {
  const canonical = aliasLookup(value, aliases);
  return canonical.split(' ').filter(token => !STOPWORDS.has(token)).join(' ').trim();
}

export function normalizeCompetition(value, aliases = DEFAULT_COMPETITION_ALIASES) {
  const key = fold(value);
  return fold(aliases[key] || key);
}

export function normalizeSport(value) {
  const key = fold(value);
  if (['football', 'soccer', 'association football'].includes(key)) return 'soccer';
  if (['basketball'].includes(key)) return 'basketball';
  if (['ice hockey', 'hockey'].includes(key)) return 'hockey';
  if (['tennis'].includes(key)) return 'tennis';
  if (['motorsport', 'racing', 'formula 1', 'f1'].includes(key)) return 'racing';
  return key;
}

function field(record, ...names) {
  for (const name of names) {
    const value = record?.[name];
    if (value != null && asText(value)) return value;
  }
  return '';
}

export function normalizeRecord(record = {}, options = {}) {
  const home = field(record, 'home', 'homeTeam', 'homeName', 'home_team')?.name || field(record, 'home', 'homeTeam', 'homeName', 'home_team');
  const away = field(record, 'away', 'awayTeam', 'awayName', 'away_team')?.name || field(record, 'away', 'awayTeam', 'awayName', 'away_team');
  const competition = record.competition?.name || record.league?.name || field(record, 'competition', 'league', 'leagueName', 'tournament');
  const kickoff = field(record, 'kickoffUtc', 'kickoff', 'startTime', 'date', 'scheduledAt', 'sourceKickoff');
  return {
    provider: asText(record.provider || record.providerName || record.source),
    providerEventId: asText(record.providerEventId || record.providerId || record.matchId || record.id),
    home: normalizeTeam(home, options.teamAliases),
    away: normalizeTeam(away, options.teamAliases),
    competition: normalizeCompetition(competition, options.competitionAliases),
    sport: normalizeSport(field(record, 'sport', 'sportType', 'type')),
    kickoff: kickoff ? new Date(kickoff) : null,
    status: fold(field(record, 'status', 'state')),
    raw: record
  };
}

function validDate(value) { return value instanceof Date && Number.isFinite(value.getTime()); }
function minutesBetween(a, b) { return validDate(a) && validDate(b) ? Math.abs(a.getTime() - b.getTime()) / 60000 : null; }
function tokenSimilarity(a, b) {
  if (!a || !b) return 0;
  if (a === b) return 1;
  const left = new Set(a.split(' ')), right = new Set(b.split(' '));
  const intersection = [...left].filter(token => right.has(token)).length;
  return intersection / Math.max(left.size, right.size, 1);
}
function pairSame(left, right) { return left.home === right.home && left.away === right.away; }
function pairReversed(left, right) { return left.home === right.away && left.away === right.home; }

export function stableEventId(record, namespace = 's803') {
  const normalized = normalizeRecord(record);
  const sourceId = normalized.provider && normalized.providerEventId ? `${normalized.provider}:${normalized.providerEventId}` : '';
  const key = sourceId || [normalized.sport, normalized.competition, normalized.home, normalized.away, validDate(normalized.kickoff) ? normalized.kickoff.toISOString() : 'unknown'].join('|');
  let hash = 2166136261;
  for (const char of key) { hash ^= char.charCodeAt(0); hash = Math.imul(hash, 16777619); }
  return `${namespace}_${(hash >>> 0).toString(16).padStart(8, '0')}`;
}

export function compareEvents(leftRecord, rightRecord, options = {}) {
  const left = normalizeRecord(leftRecord, options), right = normalizeRecord(rightRecord, options);
  const reasons = [], conflicts = [];
  let score = 0;
  const sameProviderId = left.provider && right.provider && left.provider === right.provider && left.providerEventId && left.providerEventId === right.providerEventId;
  if (sameProviderId) return { score: 100, decision: 'auto', reasons: ['Exact provider and provider event ID agree.'], conflicts: [], reversed: false, left, right };

  const sameSport = !left.sport || !right.sport || left.sport === right.sport;
  if (!sameSport) conflicts.push(`Sport conflict: ${left.sport} vs ${right.sport}`); else if (left.sport && right.sport) { score += 10; reasons.push('Sport agrees.'); }
  const sameCompetition = !left.competition || !right.competition || left.competition === right.competition;
  if (!sameCompetition) conflicts.push(`Competition conflict: ${left.competition} vs ${right.competition}`); else if (left.competition && right.competition) { score += 15; reasons.push('Competition agrees.'); }

  const exactOrder = pairSame(left, right), reversed = pairReversed(left, right);
  const homeSimilarity = tokenSimilarity(left.home, right.home), awaySimilarity = tokenSimilarity(left.away, right.away);
  const reverseHomeSimilarity = tokenSimilarity(left.home, right.away), reverseAwaySimilarity = tokenSimilarity(left.away, right.home);
  if (exactOrder) { score += 50; reasons.push('Normalized home and away teams agree in order.'); }
  else if (reversed) { score += 36; reasons.push('Normalized teams agree only after reversing home/away order.'); }
  else {
    const direct = homeSimilarity + awaySimilarity, reverse = reverseHomeSimilarity + reverseAwaySimilarity;
    if (direct >= 1.5) { score += 38; reasons.push('Team aliases/tokens are strongly similar in home/away order.'); }
    else if (reverse >= 1.5) { score += 27; reasons.push('Team aliases/tokens are strongly similar after reversal.'); }
    else conflicts.push('Team names are not sufficiently compatible.');
  }

  const kickoffMinutes = minutesBetween(left.kickoff, right.kickoff);
  const tolerance = Number(options.kickoffToleranceMinutes ?? 180);
  if (kickoffMinutes == null) reasons.push('Kickoff time is missing from one or both records.');
  else if (kickoffMinutes <= 5) { score += 20; reasons.push(`Kickoff times agree; difference: ${Math.round(kickoffMinutes)} minutes.`); }
  else if (kickoffMinutes <= tolerance) { score += 10; reasons.push(`Kickoff times are compatible; difference: ${Math.round(kickoffMinutes)} minutes.`); }
  else conflicts.push(`Kickoff times conflict; difference: ${Math.round(kickoffMinutes)} minutes.`);

  const reversedAllowed = reversed && Boolean(options.allowReversedOrder) && score >= Number(options.reversedMinimumScore ?? 70) && conflicts.length === 0;
  if (reversed && !reversedAllowed) conflicts.push('Reversed home/away order requires explicit safe-match permission.');
  if (!sameSport || (!exactOrder && !reversed && homeSimilarity + awaySimilarity < 1.5 && reverseHomeSimilarity + reverseAwaySimilarity < 1.5)) score = Math.min(score, 39);
  if (conflicts.length) score = Math.min(score, 69);
  const threshold = Number(options.autoThreshold ?? 90), reviewThreshold = Number(options.reviewThreshold ?? 70);
  const decision = score >= threshold && conflicts.length === 0 ? 'auto' : score >= reviewThreshold && conflicts.length === 0 ? 'review' : 'unmatched';
  return { score: Math.max(0, Math.min(100, Math.round(score))), decision, reasons, conflicts, reversed: reversedAllowed, kickoffMinutes, left, right };
}

export function matchProviderRecord(canonical, candidates, options = {}) {
  const rows = (Array.isArray(candidates) ? candidates : []).map(candidate => ({ candidate, comparison: compareEvents(canonical, candidate, options) }));
  rows.sort((a, b) => b.comparison.score - a.comparison.score);
  const best = rows[0] || null;
  return best ? { ...best, alternatives: rows.slice(1, 4) } : { candidate: null, comparison: { score: 0, decision: 'unmatched', reasons: ['No provider candidates were supplied.'], conflicts: [] }, alternatives: [] };
}

export function mergeProviderRecord(canonical, providerRecord, comparison, options = {}) {
  const existing = canonical && typeof canonical === 'object' ? canonical : {};
  const normalized = normalizeRecord(providerRecord, options);
  const provider = {
    provider_name: normalized.provider || 'unknown', provider_event_id: normalized.providerEventId || null,
    provider_event_url: asText(providerRecord.providerEventUrl || providerRecord.url), provider_event_title: asText(providerRecord.providerEventTitle || providerRecord.title),
    source_kickoff: normalized.kickoff ? normalized.kickoff.toISOString() : null, last_seen: new Date().toISOString(), last_verified: new Date().toISOString(),
    link_status: 'unverified', match_confidence: comparison?.score ?? 0, match_reason: [...(comparison?.reasons || []), ...(comparison?.conflicts || []).map(item => `Conflict: ${item}`)].join(' ')
  };
  const providers = Array.isArray(existing.providers) ? existing.providers.filter(item => !(item.provider_name === provider.provider_name && item.provider_event_id === provider.provider_event_id && provider.provider_event_id)) : [];
  providers.push(provider);
  return {
    ...existing,
    event_id: existing.event_id || stableEventId(canonical || providerRecord),
    sport: existing.sport || normalized.sport,
    competition: existing.competition || normalized.competition,
    home_team: existing.home_team || normalized.home,
    away_team: existing.away_team || normalized.away,
    kickoff_utc: existing.kickoff_utc || (normalized.kickoff ? normalized.kickoff.toISOString() : null),
    match_confidence: Math.max(Number(existing.match_confidence || 0), Number(comparison?.score || 0)),
    last_updated: new Date().toISOString(), schema_version: existing.schema_version || 1,
    providers
  };
}

export function dedupeProviderRecords(records, options = {}) {
  const output = [];
  for (const record of records || []) {
    const provider = asText(record.provider || record.providerName || record.source);
    const providerEventId = asText(record.providerEventId || record.providerId || record.matchId || record.id);
    const exactDuplicate = output.find(existing => provider && providerEventId &&
      asText(existing.provider || existing.providerName || existing.source) === provider &&
      asText(existing.providerEventId || existing.providerId || existing.matchId || existing.id) === providerEventId);
    if (exactDuplicate) {
      exactDuplicate.duplicate_of = providerEventId;
      continue;
    }
    const match = matchProviderRecord(record, output, { ...options, allowReversedOrder: false });
    // Different IDs from the same provider are retained even when the teams look alike.
    // A verified provider ID is stronger evidence than name-only similarity.
    if (match.comparison.decision === 'auto' && !providerEventId) {
      const index = output.indexOf(match.candidate);
      output[index] = { ...match.candidate, ...record, duplicate_of: match.candidate.providerEventId || null };
    } else output.push(record);
  }
  return output;
}

export { DEFAULT_TEAM_ALIASES, DEFAULT_COMPETITION_ALIASES };
