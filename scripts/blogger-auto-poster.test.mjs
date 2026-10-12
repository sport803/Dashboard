import test from 'node:test';
import assert from 'node:assert/strict';
import {
  attachExternalSources,
  labelsFor,
  markerFor,
  needsUpdate,
  playerUrlFor,
  replayLinks,
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
