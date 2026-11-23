import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { once } from 'node:events';
import { readFile } from 'node:fs/promises';
import { createGuard, validate, HTML_LIMIT, BOT } from '../src/security.js';
import { launchBrowser, createRenderer } from '../src/renderer.js';

let browser;
let server;
let render;
const seen = [];
const dials = [];
const guard = createGuard(async () => [{ address: '93.184.216.34' }]);

before(async () => {
  browser = await launchBrowser();
  const js = await readFile(new URL('./fixtures/js-only.html', import.meta.url), 'utf8');
  const challenge = await readFile(new URL('./fixtures/cloudflare.html', import.meta.url), 'utf8');
  server = http.createServer((request, response) => {
    seen.push({ url: request.url, headers: request.headers });
    response.setHeader('Content-Type', 'text/html; charset=utf-8');
    if (request.url === '/js') response.end(js);
    else if (request.url === '/challenge') response.writeHead(403, { 'cf-mitigated': 'challenge' }).end(challenge);
    else if (request.url === '/private') response.writeHead(302, { Location: 'http://169.254.169.254/latest/meta-data' }).end();
    else if (request.url === '/public-redirect') response.writeHead(302, { Location: 'http://other.test/echo' }).end();
    else if (request.url === '/large') response.end('a'.repeat(HTML_LIMIT + 1024));
    else if (request.url === '/subresource') response.end('<script src="http://10.0.0.1/private.js"></script><p>Page</p>');
    else if (request.url === '/heavy') response.end('<img src="/image"><style>@font-face{font-family:x;src:url(/font)}body{font-family:x}</style>Page');
    else if (request.url === '/loop') response.writeHead(302, { Location: '/loop' }).end();
    else if (request.url === '/failure') response.writeHead(503).end('<h1>Unavailable</h1>');
    else response.end('<title>Echo</title><p>Echo page</p>');
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  render = createRenderer(browser, {
    guard,
    // Test-only dial seam maps validated public IP to our loopback fixture. The
    // production resolver and private-IP policy remain unchanged (no env bypass).
    connect: (options) => {
      dials.push(options);
      return net.connect({ host: '127.0.0.1', port: server.address().port });
    },
  });
});

after(async () => {
  await browser?.close();
  if (server) await new Promise((resolve) => { server.close(resolve); server.closeAllConnections(); });
});

const run = (path, overrides = {}) => render(validate({ url: 'http://fixture.test' + path, timeout_ms: 8000, ...overrides }));

test('real Chromium renders cards after 500ms, uses declared UA, cookies and headers; cleans context', async () => {
  const result = await run('/js', {
    wait_for_selector: '.card', scroll_steps: 1,
    headers: { 'X-Fixture': 'yes' }, cookies: [{ name: 'consent', value: 'yes', domain: 'fixture.test' }],
  });
  assert.equal(result.ok, true);
  assert.match(result.html, /class="card"/);
  assert.equal(result.title, 'JS directory fixture');
  assert.equal(result.status, 200);
  assert.equal(result.blocked, null);
  assert.equal(result.truncated, false);
  assert.equal(result.screenshot_jpeg_base64, null);
  assert.ok(result.elapsed_ms >= 500);
  const request = seen.find((entry) => entry.url === '/js');
  assert.equal(request.headers['user-agent'], BOT);
  assert.equal(request.headers['x-fixture'], 'yes');
  assert.match(request.headers.cookie, /consent=yes/);
  assert.ok(dials.every(({ host }) => host === '93.184.216.34'));
  assert.equal(browser.contexts().length, 0);
});

test('Cloudflare fixture is returned as blocked without challenge retries or selector timeout', async () => {
  const before = seen.length;
  const result = await run('/challenge', { wait_for_selector: '.never-exists', wait_until: 'networkidle' });
  assert.equal(result.status, 403);
  assert.equal(result.blocked.type, 'cloudflare_challenge');
  assert.match(result.html, /challenge-running/);
  assert.equal(seen.slice(before).filter(({ url }) => url === '/challenge').length, 1);
  assert.equal(seen.slice(before).some(({ url }) => url === '/retry-challenge'), false);
  assert.equal(browser.contexts().length, 0);
});

test('rejects private literal, IPv6 loopback and redirect-to-metadata before dialing', async () => {
  for (const url of ['http://127.0.0.1', 'http://10.1.2.3', 'http://169.254.169.254', 'http://[::1]']) {
    const count = dials.length;
    await assert.rejects(run('', { url }), { code: 'ssrf_blocked' });
    assert.equal(dials.length, count);
  }
  const before = seen.length;
  await assert.rejects(run('/private'), { code: 'ssrf_blocked' });
  assert.equal(seen.slice(before).some(({ url }) => url.includes('meta-data')), false);
  assert.equal(browser.contexts().length, 0);
});

test('private subresources are rejected and contexts close', async () => {
  await assert.rejects(run('/subresource'), { code: 'ssrf_blocked' });
  assert.equal(browser.contexts().length, 0);
});

test('public redirect is guarded and does not forward custom credentials', async () => {
  const result = await run('/public-redirect', { headers: { Authorization: 'Bearer fixture-secret', 'X-Secret': 'keep-local' } });
  assert.equal(result.final_url, 'http://other.test/echo');
  const request = seen.findLast(({ headers }) => headers.host === 'other.test');
  assert.equal(request.headers.authorization, undefined);
  assert.equal(request.headers['x-secret'], undefined);
});

test('HTML is capped in the real render response and HTTP error body is preserved', async () => {
  const result = await run('/large');
  assert.equal(result.truncated, true);
  assert.equal(result.bytes, HTML_LIMIT);
  assert.equal(Buffer.byteLength(result.html), HTML_LIMIT);
  const failure = await run('/failure');
  assert.equal(failure.blocked.type, 'http_error');
  assert.equal(failure.status, 503);
  assert.match(failure.html, /Unavailable/);
});

test('timeout, redirect loops and failed DNS do not leak contexts', async () => {
  await assert.rejects(run('/js', { wait_for_selector: '.never', timeout_ms: 600 }), { code: 'timeout' });
  await assert.rejects(run('/loop'), { code: 'navigation_failed' });
  const unavailable = createRenderer(browser, { guard: createGuard(async () => { throw new Error('NXDOMAIN'); }) });
  await assert.rejects(unavailable(validate({ url: 'http://missing.test' })), { code: 'navigation_failed' });
  assert.equal(browser.contexts().length, 0);
});

test('contexts isolate cookies; optional screenshot is JPEG; heavy resources blocked', async () => {
  const result = await run('/heavy', { screenshot: true, wait_until: 'load' });
  assert.equal(Buffer.from(result.screenshot_jpeg_base64, 'base64').subarray(0, 2).toString('hex'), 'ffd8');
  assert.equal(seen.some(({ url }) => url === '/image' || url === '/font'), false);
  await run('/echo');
  assert.equal(seen.findLast(({ url }) => url === '/echo').headers.cookie, undefined);
  assert.equal(browser.contexts().length, 0);
});
