import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { once } from 'node:events';
import { request } from 'playwright';
import { createGuard, BOT } from '../src/security.js';
import { createProxy } from '../src/proxy.js';

// Exercise the real Playwright HTTP transport used by route.fetch without a
// browser process. This supplements, and does not replace, renderer.test.js.
test('Playwright transport uses the pinned proxy, retains cookies and leaves redirects for interception', async (t) => {
  const seen = [];
  const dials = [];
  let blocked = false;
  const fixture = http.createServer((req, res) => {
    seen.push(req.headers);
    if (req.url === '/redirect') res.writeHead(302, { Location: 'http://127.0.0.1/private' });
    res.end('<p>fixture</p>');
  });
  fixture.listen(0, '127.0.0.1');
  await once(fixture, 'listening');
  const proxy = await createProxy(createGuard(async () => [{ address: '93.184.216.34' }]), {
    connect: (options) => {
      dials.push(options);
      return net.connect({ host: '127.0.0.1', port: fixture.address().port });
    },
    onBlocked: () => { blocked = true; },
  });
  const client = await request.newContext({
    proxy: { server: proxy.url }, userAgent: BOT,
    storageState: { cookies: [{ name: 'consent', value: 'yes', domain: 'fixture.test', path: '/', expires: -1, httpOnly: false, secure: false, sameSite: 'Lax' }], origins: [] },
  });
  t.after(async () => {
    await client.dispose();
    await proxy.close();
    await new Promise((resolve) => { fixture.close(resolve); fixture.closeAllConnections(); });
  });
  const result = await client.get('http://fixture.test/redirect', { maxRedirects: 0, maxRetries: 0 });
  assert.equal(result.status(), 302);
  assert.equal(result.headers().location, 'http://127.0.0.1/private');
  assert.match(seen[0].cookie, /consent=yes/);
  assert.equal(seen[0]['user-agent'], BOT);
  assert.equal(seen.length, 1);
  assert.ok(dials.every(({ host }) => host === '93.184.216.34'));
  await client.get('http://other.test/echo', { maxRedirects: 0, maxRetries: 0 });
  assert.equal(seen[1].cookie, undefined);
  const before = dials.length;
  const denied = await client.get('http://169.254.169.254/', { maxRedirects: 0, maxRetries: 0 });
  assert.equal(denied.status(), 403);
  assert.equal(blocked, true);
  assert.equal(dials.length, before);
});
