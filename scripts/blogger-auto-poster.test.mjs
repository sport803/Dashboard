import test from 'node:test';
import assert from 'node:assert/strict';
import {
  attachExternalSources,
  eventDateWindow,
  fetchLeague,
  isEligibleForPosting,
  isTodayOrTomorrow,
  labelsFor,
  markerFor,
  needsUpdate,
  playerUrlFor,
  replayLinks,
  rewriteOneBallReplayIframes,
  rasterizeThumbnail,
  uploadImgBb
} from './blogger-auto-poster.mjs';

const PNG_SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
const matchEvent = {
  id: 'espn-123',
  type: 'match',
  sport: 'soccer',
  league: { id: 'epl', name: 'Premier League' },
  homeName: 'Arsenal',
  awayName: 'Chelsea',
  startTime: '2026-10-12T17:00:00Z'
};

test('the standard posting window includes local today and tomorrow across a year boundary', () => {
  const oldTimezone = process.env.EVENT_TIMEZONE;
  const oldEventDate = process.env.EVENT_DATE;
  const oldLeagues = process.env.AUTO_POST_LEAGUES;
  const oldLegacyLeagues = process.env.LEAGUES;
  process.env.EVENT_TIMEZONE = 'Africa/Nairobi';
  delete process.env.EVENT_DATE;
  delete process.env.AUTO_POST_LEAGUES;
  delete process.env.LEAGUES;
  const now = Date.parse('2026-12-31T21:30:00Z'); // 2027-01-01 00:30 in Nairobi
  try {
    assert.deepEqual(eventDateWindow(now), ['2026-12-31', '2027-01-01', '2027-01-02']);
    assert.equal(isTodayOrTomorrow({ type: 'match', startTime: '2026-12-31T20:59:00Z' }, now), false);
    assert.equal(isTodayOrTomorrow({ type: 'match', startTime: '2026-12-31T21:30:00Z' }, now), true);
    assert.equal(isTodayOrTomorrow({ type: 'match', startTime: '2027-01-01T21:00:00Z' }, now), true);
    assert.equal(isTodayOrTomorrow({ type: 'match', startTime: '2027-01-02T21:00:00Z' }, now), false);
    assert.equal(isEligibleForPosting({ type: 'match', startTime: '2027-01-01T21:00:00Z' }, now), true);
    assert.equal(isEligibleForPosting({ type: 'match', startTime: '2027-01-02T21:00:00Z' }, now), false);
    assert.equal(isEligibleForPosting({ type: 'race', source: 'ESPN', startTime: '2026-12-31T20:59:00Z', league: { id: 'f1', name: 'Formula 1' } }, now), true);
    assert.equal(isEligibleForPosting({ type: 'race', source: 'other', startTime: '2026-12-31T20:59:00Z', league: { id: 'f1', name: 'Formula 1' } }, now), false);
  } finally {
    if (oldTimezone === undefined) delete process.env.EVENT_TIMEZONE; else process.env.EVENT_TIMEZONE = oldTimezone;
    if (oldEventDate === undefined) delete process.env.EVENT_DATE; else process.env.EVENT_DATE = oldEventDate;
    if (oldLeagues === undefined) delete process.env.AUTO_POST_LEAGUES; else process.env.AUTO_POST_LEAGUES = oldLeagues;
    if (oldLegacyLeagues === undefined) delete process.env.LEAGUES; else process.env.LEAGUES = oldLegacyLeagues;
  }
});

test('normal league feed results include the target day and tomorrow, not yesterday', async () => {
  const originalFetch = globalThis.fetch;
  const requestedDates = [];
  globalThis.fetch = async input => {
    const date = new URL(input).searchParams.get('dates');
    requestedDates.push(date);
    const isoDate = `${date.slice(0, 4)}-${date.slice(4, 6)}-${date.slice(6, 8)}`;
    return Response.json({ events: [{
      id: `event-${date}`,
      date: `${isoDate}T12:00:00Z`,
      status: { type: { description: 'Scheduled' } },
      competitions: [{ competitors: [
        { homeAway: 'home', team: { displayName: 'Home' } },
        { homeAway: 'away', team: { displayName: 'Away' } }
      ] }]
    }] });
  };
  try {
    const dates = ['2026-10-11', '2026-10-12', '2026-10-13'];
    const events = await fetchLeague({ id: 'fixture', sport: 'soccer', slug: 'fixture-league', name: 'Fixture League', type: 'match' }, dates);
    assert.deepEqual(requestedDates.sort(), ['20261011', '20261012', '20261013']);
    assert.deepEqual(events.map(event => event.id), ['event-20261012', 'event-20261013']);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('OneBall is the only scraped external event link, even when legacy PPV feeds are passed in', async () => {
  const [event] = await attachExternalSources([matchEvent], {
    oneball: [{ id: 'ob-77', home: 'Chelsea', away: 'Arsenal' }],
    ppv: [{ home: 'Arsenal', away: 'Chelsea', urls: ['https://ppvtv.example/live/123'] }]
  });

  assert.deepEqual(event.externalSources.map(source => source.source), ['onetv']);
  const player = playerUrlFor(event, []);
  assert.match(player, /oneball\.live\/live\/ob-77\.html/);
  assert.doesNotMatch(player, /ppvtv|embed=/i);
});

test('OneBall replays use the replay path while live posts keep the live path', async () => {
  const feeds = { oneball: [{ id: '4578214', home: 'Arsenal', away: 'Chelsea' }] };
  const [liveEvent] = await attachExternalSources([matchEvent], feeds);
  const [finalEvent] = await attachExternalSources([{ ...matchEvent, status: 'Final', replayUrl: 'https://example.test/old-replay' }], feeds);

  assert.equal(playerUrlFor(liveEvent, []), 'https://www.sport803.online/p/player.html?one=https://oneball.live/live/4578214.html');
  assert.equal(playerUrlFor(finalEvent, []), 'https://www.sport803.online/p/player.html?one=https://oneball.live/replay/4578214.html');
  assert.deepEqual(replayLinks(finalEvent), ['https://www.sport803.online/p/player.html?one=https://oneball.live/replay/4578214.html']);
});

test('history repair changes only OneBall sources inside player iframe src attributes', () => {
  const source = '<p>https://oneball.live/live/ignore-this.html</p><iframe src="https://www.sport803.online/p/player.html?one=https://oneball.live/live/4578214.html"></iframe><iframe src="https://www.sport803.online/p/player.html?one=https%3A%2F%2Foneball.live%2Flive%2F4578215.html"></iframe><iframe src="https://other.example/player.html?one=https://oneball.live/live/123.html"></iframe>';
  const result = rewriteOneBallReplayIframes(source);

  assert.equal(result.changed, true);
  assert.match(result.content, /oneball\.live\/replay\/4578214\.html/);
  assert.match(result.content, /oneball\.live%2Freplay%2F4578215\.html/);
  assert.match(result.content, /<p>https:\/\/oneball\.live\/live\/ignore-this\.html<\/p>/);
  assert.match(result.content, /other\.example\/player\.html\?one=https:\/\/oneball\.live\/live\/123\.html/);
  assert.deepEqual(rewriteOneBallReplayIframes(result.content), { content: result.content, changed: false });
});

test('successful thumbnail uploads are labelled within Blogger limits', () => {
  const normalLabels = labelsFor(matchEvent, 'epl|espn-123', false);
  const imageLabels = labelsFor(matchEvent, 'epl|espn-123', false, 20, true);

  assert.ok(imageLabels.includes('s803:thumbnail'));
  assert.ok(!normalLabels.includes('s803:thumbnail'));
  assert.ok(imageLabels.includes(markerFor('epl|espn-123')));
  assert.ok(imageLabels.length <= 20);
});

test('posts without the successful-thumbnail marker are automatically selected for repair', async () => {
  const context = { event: matchEvent, final: false, desiredPlayer: '' };
  const existingWithoutImage = { id: 'post-1', labels: [markerFor('epl|espn-123'), 'Event Stream'] };
  const existingWithImage = { id: 'post-1', labels: [markerFor('epl|espn-123'), 'Event Stream', 's803:thumbnail'] };

  assert.equal(await needsUpdate(existingWithoutImage, context, {}), true);
  assert.equal(await needsUpdate(existingWithImage, context, {}), false);
});

test('SVG thumbnails rasterize to a valid PNG byte stream', async () => {
  const png = await rasterizeThumbnail('<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8" fill="#e63946"/></svg>');
  assert.ok(png.subarray(0, PNG_SIGNATURE.length).equals(PNG_SIGNATURE));
  assert.ok(png.length > PNG_SIGNATURE.length);
});

test('ImgBB receives PNG data rather than the source SVG text', async () => {
  const originalFetch = globalThis.fetch;
  let submittedImage = '';
  globalThis.fetch = async (_url, options) => {
    submittedImage = options.body.get('image');
    return Response.json({ success: true, data: { url: 'https://images.example/thumb.png' } });
  };
  try {
    const url = await uploadImgBb('<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"><rect width="8" height="8" fill="#e63946"/></svg>', 'test-key');
    assert.equal(url, 'https://images.example/thumb.png');
    assert.ok(Buffer.from(submittedImage, 'base64').subarray(0, PNG_SIGNATURE.length).equals(PNG_SIGNATURE));
  } finally {
    globalThis.fetch = originalFetch;
  }
});
