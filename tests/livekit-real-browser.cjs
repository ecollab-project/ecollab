/* Actual Chromium + LiveKit SDK. Synthetic devices, real encoded WebRTC media. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE);
const base = process.env.TEST_MEDIA_URL.replace(/^ws/, 'http');
const result = { tests: [], timings: [], media: [] };
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function command(action, data = {}) {
  const r = await fetch(process.env.TEST_MEDIA_CONTROL, { method: 'POST', headers: {
    'Content-Type': 'application/json', 'X-Fixture-Key': 'local-disposable-only'
  }, body: JSON.stringify({ action, ...data }) });
  const v = await r.json(); assert.equal(r.status, 200, JSON.stringify(v)); return v;
}
function pass(name, evidence) { result.tests.push({ name, status: 'PASS', evidence }); console.log('PASS', name, evidence || ''); }
async function until(fn, timeout = 10000) {
  const end = performance.now() + timeout;
  while (performance.now() < end) { if (await fn()) return; await sleep(100); }
  throw Error('Timed out waiting for real media/connection state');
}
let browser;
const pages = [];
async function page(uid, room, { ttl = 600, url = process.env.TEST_MEDIA_URL, context, existingToken } = {}) {
  context ||= await browser.newContext({ permissions: ['camera', 'microphone'] });
  const p = await context.newPage(); pages.push(p);
  await p.goto(base + '/fixture'); await p.addScriptTag({ url: base + '/sdk.js' });
  await p.evaluate(() => {
    window.events = []; window.room = new LivekitClient.Room({ adaptiveStream: false, dynacast: false });
    for (const event of ['connected', 'disconnected', 'reconnecting', 'reconnected', 'signalReconnecting', 'signalConnected', 'trackSubscribed']) {
      room.on(event, () => events.push({ event, time: performance.now(), unix_ms: Date.now() }));
    }
    room.on('trackSubscribed', track => { const e = track.attach(); e.autoplay = true; e.muted = true; document.body.appendChild(e); });
  });
  const token = existingToken || (await command('token', { room, uid, ttl })).token;
  p.fixtureToken = token; p.fixtureURL = url;
  const started = performance.now();
  await p.evaluate(async ({ url, token }) => { await room.connect(url, token, { autoSubscribe: true }); }, { url, token });
  result.timings.push({ operation: 'browser_join', milliseconds: performance.now() - started });
  return p;
}
async function disconnect(p) { await p.evaluate(() => room.disconnect()); }
async function state(p) { return p.evaluate(() => room.state); }
async function publish(p, video) { await p.evaluate(async video => {
  await room.localParticipant.setMicrophoneEnabled(true);
  if (video) await room.localParticipant.setCameraEnabled(true);
}, video); }
async function stats(p) {
  return p.evaluate(async () => {
    const out = [];
    for (const participant of room.remoteParticipants.values()) {
      for (const pub of participant.trackPublications.values()) {
        if (!pub.track) continue;
        const rows = await pub.track.getRTCStatsReport();
        rows?.forEach(s => { if (s.type === 'inbound-rtp') out.push({ kind: s.kind, packetsReceived: s.packetsReceived,
          bytesReceived: s.bytesReceived, framesDecoded: s.framesDecoded, packetsLost: s.packetsLost, jitter: s.jitter }); });
      }
    }
    return out;
  });
}
async function rejected(uid, room, options = {}) {
  let accepted;
  try { accepted = await page(uid, room, options); }
  catch (e) { assert.match(String(e), /403|permission|denied|could not|authorization|failed/i); return; }
  await disconnect(accepted); throw Error('Unauthorized LiveKit admission succeeded');
}
async function replayRejected(p) {
  const r = await p.evaluate(async ({ url, token }) => {
    const r = new LivekitClient.Room();
    try { await r.connect(url, token); await r.disconnect(); return 'accepted'; }
    catch (e) { return 'rejected:' + e.message; }
  }, { url: p.fixtureURL, token: p.fixtureToken });
  assert.match(r, /^rejected:/); return r;
}
(async () => {
  browser = await chromium.launch({ executablePath: process.env.CHROMIUM_BIN, headless: true,
    args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--autoplay-policy=no-user-gesture-required'] });
  result.browser = await browser.version();
  const roomName = 'ecollab-channel-10';
  await command('restore');
  let a = await page(1, roomName), b = await page(2, roomName);
  await publish(a, false);
  await until(async () => (await stats(b)).some(s => s.kind === 'audio' && s.packetsReceived > 5));
  pass('authorized private voice join and actual audio subscription');
  await a.evaluate(() => room.localParticipant.setCameraEnabled(true));
  await publish(b, true);
  await until(async () => (await stats(b)).some(s => s.kind === 'video' && s.framesDecoded > 3));
  await until(async () => (await stats(a)).some(s => s.kind === 'video' && s.framesDecoded > 3));
  const first = await stats(b); await sleep(1000); const second = await stats(b);
  assert(second.every((s, i) => s.bytesReceived > first[i].bytesReceived));
  result.media.push({ sample_interval_ms: 1000, first, second });
  pass('bidirectional real audio/video publication, subscription and increasing received bytes');
  console.log('ROUTES', JSON.stringify(await command('routes')));
  result.room_api_body_types = await command('room-api-body-types');
  console.log('ROOM_API_BODY_TYPES', JSON.stringify(result.room_api_body_types));
  assert.equal(result.room_api_body_types.array.status, 400); assert.equal(result.room_api_body_types.object.status, 200);
  const privateCommit = await command('remove-private');
  const bypass = await command('bypass'); result.origin = bypass;
  assert.equal(bypass.gated, 403); assert.equal(bypass.direct_origin, 200);
  assert.equal(bypass.alternate_host, 403); assert.equal(bypass.unknown_route, 404);
  pass('gated stale token denied; direct origin bypass reproduced on fixture loopback', bypass);
  await replayRejected(a);
  let t = performance.now(); const cycle = await command('cycle');
  console.log('REAL_WORKER_CYCLE', JSON.stringify(cycle));
  await until(async () => (await state(a)) !== 'connected');
  result.timings.push({ operation: 'private_revocation_to_observed_disconnect_after_manual_cycle', dispatch_ms: performance.now()-t,
    commit_ack_to_observed_disconnect_ms: await a.evaluate(() => events.filter(x => x.event === 'disconnected').at(-1).unix_ms) - privateCommit.commit_ack_unix_ms, cycle });
  await replayRejected(a); pass('real reconciliation worker removes private-revoked participant and gated replay fails');
  const deniedRoutes = await command('routes', { token: a.fixtureToken });
  assert(Object.values(deniedRoutes).every(status => status === 403)); pass('all four signaling/validation paths reject retained revoked token', deniedRoutes);
  const origin = await command('origin'); result.origin_binding = { ...origin }; delete result.origin_binding.url;
  const direct = await page(1, roomName, { url: origin.url, existingToken: a.fixtureToken });
  assert.equal(await state(direct), 'connected'); pass('actual direct-origin SDK stale-token join bypasses online gate when origin is reachable');
  await command('cycle'); await until(async () => (await state(direct)) !== 'connected');
  await disconnect(b);
  for (const action of ['remove-member', 'suspend', 'ban', 'server-suspend', 'deactivate']) {
    await command('restore'); a = await page(1, roomName);
    const committed = await command(action); await replayRejected(a);
    const started = performance.now(); const c = await command('cycle');
    await until(async () => (await state(a)) !== 'connected');
    result.timings.push({ operation: action+'_manual_cycle', dispatch_ms: performance.now()-started,
      commit_ack_to_observed_disconnect_ms: await a.evaluate(() => events.filter(x => x.event === 'disconnected').at(-1).unix_ms) - committed.commit_ack_unix_ms, cycle: c });
    pass(action+' stale-token rejection and real eviction');
  }
  for (const [room, action] of [['ecollab-dm-group-v2-1', 'remove-group'], ['ecollab-dm-call-1', 'end-call']]) {
    await command('restore'); a = await page(1, room); b = await page(2, room);
    await publish(a, true); await until(async () => (await stats(b)).some(s => s.kind === 'video' && s.framesDecoded > 2));
    await command(action); await replayRejected(a); await command('cycle');
    await until(async () => (await state(a)) !== 'connected'); await disconnect(b);
    pass(room+' actual media and revocation');
  }
  await command('restore'); a = await page(1, roomName, { ttl: 3 });
  await sleep(4100); assert.equal(await state(a), 'connected');
  await replayRejected(a); // Original expired JWT must fail even if the SDK received a refreshed JWT.
  const signals = await a.evaluate(() => events.filter(x => x.event === 'signalConnected').length);
  await a.evaluate(() => room.simulateScenario('signal-reconnect'));
  await until(async () => await a.evaluate(before => events.filter(x => x.event === 'signalConnected').length > before || events.some(x => x.event === 'reconnected'), signals));
  assert.equal(await state(a), 'connected');
  result.expiry = await a.evaluate(() => ({ state: room.state, events }));
  // A refreshed signaling token may be used by the SDK; admission must validate it too.
  console.log('EXPIRY_RECONNECT', JSON.stringify(result.expiry));
  pass('original JWT expires; server-refreshed SDK credential resumes through gated signaling');
  await disconnect(a);
  await command('restore'); a = await page(1, roomName);
  await a.context().setOffline(true); await sleep(700); await a.context().setOffline(false);
  await a.evaluate(() => room.simulateScenario('full-reconnect'));
  await until(async () => await a.evaluate(() => events.some(x => x.event === 'reconnected')));
  await until(async () => (await state(a)) === 'connected');
  pass('authorized browser network interruption recovery', await a.evaluate(() => events));
  // Same user in same room: LiveKit identity is shared across browser tabs.
  b = await page(1, roomName, { context: a.context() }); await until(async () => (await state(a)) !== 'connected');
  assert.equal(await state(b), 'connected'); pass('same-user second tab replaces first LiveKit identity');
  await disconnect(b);
  await command('restore'); a = await page(1, roomName); b = await page(2, roomName);
  await command('start-worker'); await sleep(500);
  t = performance.now(); const bothCommit = await command('both-deactivate');
  await until(async () => (await state(a)) !== 'connected' && (await state(b)) !== 'connected', 15000);
  result.timings.push({ operation: 'two_accounts_running_worker', request_to_both_disconnects_ms: performance.now()-t,
    commit_ack_to_last_disconnect_ms: Math.max(await a.evaluate(() => events.filter(x => x.event === 'disconnected').at(-1).unix_ms), await b.evaluate(() => events.filter(x => x.event === 'disconnected').at(-1).unix_ms)) - bothCommit.commit_ack_unix_ms });
  await command('stop-worker'); pass('periodic real worker removes multiple revoked participants');
  await command('restore'); a = await page(1, roomName); b = await page(2, roomName); await publish(b, true);
  await until(async () => (await stats(a)).some(s => s.kind === 'video' && s.framesDecoded > 2));
  await command('deactivate');
  await sleep(10500); assert.equal(await state(a), 'connected');
  await command('db-outage'); const failed = await command('cycle');
  assert.equal(await state(a), 'connected'); assert.match(failed.stderr, /incomplete/);
  const blockedFirst = await stats(a); await sleep(1000); const blockedSecond = await stats(a);
  assert(blockedSecond.some(s => s.kind === 'video' && s.bytesReceived > blockedFirst.find(x => x.kind === 'video').bytesReceived));
  result.media.push({ scenario: 'revoked participant still receives private media during DB/schema outage', first: blockedFirst, second: blockedSecond });
  await command('db-restore'); await command('start-worker');
  await until(async () => (await state(a)) !== 'connected'); await command('stop-worker');
  await disconnect(b);
  pass('stopped-worker and DB/schema failure leave existing stream; restart after recovery removes it', failed);
  await command('restore'); a = await page(1, roomName); b = await page(2, roomName); await publish(b, false);
  await until(async () => (await stats(a)).some(s => s.kind === 'audio' && s.packetsReceived > 3)); await command('deactivate');
  await command('api-outage'); const apiFailed = await command('cycle');
  assert.equal(await state(a), 'connected'); assert.match(apiFailed.stderr, /incomplete/); await replayRejected(a);
  const apiFirst = await stats(a); await sleep(1000); const apiSecond = await stats(a);
  assert(apiSecond.some(s => s.kind === 'audio' && s.bytesReceived > apiFirst.find(x => x.kind === 'audio').bytesReceived));
  result.media.push({ scenario: 'revoked participant still receives private audio during RoomService HTTP-503', first: apiFirst, second: apiSecond });
  await command('api-restore'); await command('cycle'); await until(async () => (await state(a)) !== 'connected');
  await disconnect(b);
  pass('RoomService HTTP-503 leaves stream active; gated rejoin denied; recovery cycle removes it', apiFailed);
  await command('restore'); a = await page(1, roomName); b = await page(2, roomName); await publish(b, true);
  await until(async () => (await stats(a)).some(s => s.kind === 'video' && s.framesDecoded > 2));
  await command('start-worker'); await sleep(500); const networkCommit = await command('deactivate');
  await command('db-stop'); await replayRejected(a); await sleep(5500);
  assert.equal(await state(a), 'connected');
  const deadWorker = await command('worker-state'); assert.notEqual(deadWorker.exit, null); assert.notEqual(deadWorker.exit, 0);
  const networkFirst = await stats(a); await sleep(1000); const networkSecond = await stats(a);
  assert(networkSecond.some(s => s.kind === 'video' && s.bytesReceived > networkFirst.find(x => x.kind === 'video').bytesReceived));
  result.media.push({ scenario: 'actual MariaDB daemon stopped; revoked participant still receives private video', first: networkFirst, second: networkSecond });
  await command('db-start'); await command('start-worker'); await until(async () => (await state(a)) !== 'connected'); await command('stop-worker');
  result.timings.push({ operation: 'actual_DB_daemon_outage_and_worker_restart', commit_ack_to_disconnect_ms:
    await a.evaluate(() => events.filter(x => x.event === 'disconnected').at(-1).unix_ms) - networkCommit.commit_ack_unix_ms, worker_exit_during_outage: deadWorker.exit });
  await disconnect(b); pass('actual DB daemon failure preserves old media, denies reconnect; worker exits and recovers after explicit fixture restart');
  await browser.close(); browser = null;
  fs.writeFileSync(process.env.TEST_MEDIA_RESULT, JSON.stringify(result, null, 2));
})().catch(e => { console.error(e); process.exitCode = 1; }).finally(async () => { if (browser) await browser.close(); });
