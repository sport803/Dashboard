import test from 'node:test';
import assert from 'node:assert/strict';
import XoilacEvents from './xoilac-events.js';

const sampleHtml = `
<div class="grid-matches__item grid-matches__item-match match-football-item"
     id="match-child-n54qllhnlgpkqvy" data-runtime="1791996300" data-fid=n54qllhnlgpkqvy data-status="1" data-sport="football">
  <a href="/truc-tiep/feyenoord-vs-como-luc-2345-ngay-14-10-2026/"
     title="Feyenoord vs Como lúc 23:45 ngày 14/10/2026" rel="nofollow"
     class="redirectPopup" data-random-streams=",link/3/"></a>
  <div class="grid-match__league"><span class="text-ellipsis">UEFA Champions League</span></div>
  <div class="grid-match__team--name grid-match__team--home-name">Feyenoord</div>
  <div class="grid-match__team--name grid-match__team--away-name">Como</div>
</div>
<div class="grid-matches__item grid-matches__item-match match-basketball-item"
     id="match-child-basket" data-runtime="1791996300" data-fid=basket data-status="1" data-sport="basketball">
  <a href="/truc-tiep/home-vs-away/" title="Home vs Away" class="redirectPopup"></a>
  <div class="grid-match__team--home-name">Home</div><div class="grid-match__team--away-name">Away</div>
</div>`;

test('parses football fixtures and follows the selected data-random-streams link', () => {
  const [event] = XoilacEvents.parseXoilacEvents(sampleHtml);
  assert.equal(XoilacEvents.parseXoilacEvents(sampleHtml).length, 1);
  assert.equal(event.id, 'xoilac-n54qllhnlgpkqvy');
  assert.equal(event.home.name, 'Feyenoord');
  assert.equal(event.away.name, 'Como');
  assert.equal(event.league.name, 'UEFA Champions League');
  assert.equal(event.startTime.toISOString(), '2026-10-14T16:45:00.000Z');
  assert.equal(event.status, 'Scheduled');
  assert.equal(event.xoilacUrl, 'https://xoilaczzz.pro/truc-tiep/feyenoord-vs-como-luc-2345-ngay-14-10-2026/link/3/');
});

test('rejects non-Xoilac or non-match URLs', () => {
  assert.equal(XoilacEvents.chooseEventUrl('https://evil.example/truc-tiep/a/', 'link/0/'), '');
  assert.equal(XoilacEvents.chooseEventUrl('/other/path/', 'link/0/'), '');
});

test('merges Xoilac stream details onto a matching dashboard fixture', () => {
  const xoilac = XoilacEvents.parseXoilacEvents(sampleHtml)[0];
  const espn = {
    id: 'espn-123', type: 'match', home: { name: 'Feyenoord FC' }, away: { name: 'Como' },
    league: { id: 'friendly', name: 'Friendly' }, startTime: new Date('2026-10-14T17:30:00.000Z')
  };
  const merged = XoilacEvents.mergeXoilacEvents([espn, xoilac]);
  assert.equal(merged.length, 1);
  assert.equal(merged[0].id, 'espn-123');
  assert.equal(merged[0].xoilacUrl, xoilac.xoilacUrl);
  assert.deepEqual(merged[0].externalSources.map(source => source.source), ['xoilac']);
});

test('keeps unmatched Xoilac fixtures as separate events', () => {
  const xoilac = XoilacEvents.parseXoilacEvents(sampleHtml)[0];
  const merged = XoilacEvents.mergeXoilacEvents([{ id: 'espn-other', type: 'match', home: { name: 'Ajax' }, away: { name: 'PSV' } }, xoilac]);
  assert.equal(merged.length, 2);
  assert.equal(merged[1].source, 'Xoilac');
});

test('builds the combined OneBall and Xoilac player URL without a double slash', () => {
  const xoilac = XoilacEvents.parseXoilacEvents(sampleHtml)[0];
  const playerUrl = XoilacEvents.buildPlayerUrl('https://www.sport803.online/p/player.html/', [
    { type: 'one', url: 'https://oneball.live/live/4628431.html' },
    { type: 'xoilac', url: xoilac.xoilacUrl }
  ]);
  assert.equal(playerUrl, 'https://www.sport803.online/p/player.html?one=https://oneball.live/live/4628431.html&xoilac=https://xoilaczzz.pro/truc-tiep/feyenoord-vs-como-luc-2345-ngay-14-10-2026/link/3/');
});
