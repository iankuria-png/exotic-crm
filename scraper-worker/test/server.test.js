import test from 'node:test';
import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createHmac } from 'node:crypto';
import { createWorker } from '../src/server.js';
import { WorkerError } from '../src/security.js';

const secret = 'fixture-secret-of-at-least-32-characters';
const browser = { isConnected: () => true, version: () => 'fixture-browser' };

async function fixture(t, render, concurrency = 2) {
  const logs = [];
  const server = createWorker({ secret, browser, render, concurrency, logger: (line) => logs.push(JSON.parse(line)) });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(() => new Promise((resolve) => { server.close(resolve); server.closeAllConnections(); }));
  const url = `http://127.0.0.1:${server.address().port}`;
  const post = (raw, overrides = {}) => {
    const timestamp = String(Math.floor(Date.now() / 1000));
    return fetch(url + '/v1/render', {
      method: 'POST', body: raw,
      headers: {
        'Content-Type': 'application/json', 'X-Scraper-Timestamp': timestamp,
        'X-Scraper-Signature': createHmac('sha256', secret).update(timestamp + '\n').update(raw).digest('hex'),
        ...overrides,
      },
    });
  };
  return { url, post, logs };
}

test('unsigned health; signed render accepted; invalid HMAC/skew rejected before render; redacted logs', async (t) => {
  let calls = 0;
  const { url, post, logs } = await fixture(t, async () => { calls++; return { ok: true }; });
  const health = await (await fetch(url + '/healthz')).json();
  assert.deepEqual(health, { ok: true, version: '1.0.0', browser_version: 'fixture-browser', inflight: 0 });
  const raw = JSON.stringify({ url: 'https://example.com/private?token=secret', cookies: [{ name: 'session', value: 'do-not-log' }] });
  assert.equal((await post(raw)).status, 200);
  assert.equal((await post(raw, { 'X-Scraper-Signature': '0'.repeat(64) })).status, 401);
  assert.equal((await post(raw, { 'X-Scraper-Timestamp': '1' })).status, 401);
  assert.equal(calls, 1);
  assert.equal(JSON.stringify(logs).includes('do-not-log'), false);
  assert.equal(JSON.stringify(logs).includes('token'), false);
  assert.equal(logs[0].host, 'example.com');
});

test('rejects malformed JSON, input and content type', async (t) => {
  const { post } = await fixture(t, async () => assert.fail('Invalid request reached browser'));
  assert.equal((await post('{')).status, 400);
  assert.equal((await post('{"url":"https://example.com","timeout_ms":45001}')).status, 400);
  assert.equal((await post('{"url":"https://example.com"}', { 'Content-Type': 'text/plain' })).status, 400);
  assert.equal((await post('x'.repeat(256 * 1024 + 1))).status, 413);
});

test('render errors map to JSON and release inflight capacity', async (t) => {
  const { post, url } = await fixture(t, async () => { throw new WorkerError('timeout', 504); });
  const response = await post('{"url":"https://example.com"}');
  assert.equal(response.status, 504);
  assert.deepEqual(await response.json(), { ok: false, error: 'timeout' });
  assert.equal((await (await fetch(url + '/healthz')).json()).inflight, 0);
});

test('concurrency cap rejects overflow, reports inflight and releases slots', async (t) => {
  let release;
  let started;
  const entered = new Promise((resolve) => { started = resolve; });
  const pending = new Promise((resolve) => { release = resolve; });
  const { url, post } = await fixture(t, async () => { started(); await pending; return { ok: true }; }, 1);
  const raw = '{"url":"https://example.com"}';
  const first = post(raw);
  await entered;
  assert.equal((await (await fetch(url + '/healthz')).json()).inflight, 1);
  assert.equal((await post(raw)).status, 429);
  release();
  assert.equal((await first).status, 200);
  assert.equal((await (await fetch(url + '/healthz')).json()).inflight, 0);
  assert.equal((await post(raw)).status, 200);
});
