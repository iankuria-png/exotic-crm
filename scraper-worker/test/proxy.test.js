import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import { once } from 'node:events';
import { createGuard } from '../src/security.js';
import { createProxy } from '../src/proxy.js';

async function fixture(t, guard) {
  const seen = [];
  const dials = [];
  const target = http.createServer((request, response) => {
    seen.push({ url: request.url, headers: request.headers });
    if (request.url === '/redirect') response.writeHead(302, { Location: 'http://127.0.0.1/private' });
    response.end('fixture');
  });
  target.listen(0, '127.0.0.1');
  await once(target, 'listening');
  const proxy = await createProxy(guard, {
    connect: (options) => {
      dials.push(options);
      return net.connect({ host: '127.0.0.1', port: target.address().port });
    },
  });
  t.after(async () => {
    await proxy.close();
    await new Promise((resolve) => { target.close(resolve); target.closeAllConnections(); });
  });
  const get = (url) => new Promise((resolve, reject) => {
    const request = http.get(proxy.url, { path: url, headers: { 'Proxy-Authorization': 'must-not-leak' }, agent: false }, (response) => {
      let body = '';
      response.on('data', (chunk) => { body += chunk; });
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body }));
    });
    request.on('error', reject);
  });
  return { proxy, seen, dials, get };
}

test('HTTP proxy dials the exact checked numeric address and preserves target host/path', async (t) => {
  const { get, seen, dials } = await fixture(t, createGuard(async () => [{ address: '93.184.216.34' }]));
  const reply = await get('http://fixture.test/list?q=1');
  assert.equal(reply.status, 200);
  assert.equal(reply.body, 'fixture');
  assert.deepEqual(dials, [{ host: '93.184.216.34', port: 80 }]);
  assert.equal(seen[0].url, '/list?q=1');
  assert.equal(seen[0].headers.host, 'fixture.test');
  assert.equal(seen[0].headers['proxy-authorization'], undefined);
});

test('HTTP proxy rejects private addresses and revalidates DNS for each connection', async (t) => {
  let calls = 0;
  const { get, dials } = await fixture(t, createGuard(async () => [{ address: ++calls === 1 ? '8.8.8.8' : '10.0.0.1' }]));
  assert.equal((await get('http://rebind.test/')).status, 200);
  assert.equal((await get('http://rebind.test/again')).status, 403);
  assert.equal((await get('http://169.254.169.254/latest')).status, 403);
  assert.equal(dials.length, 1);
});

test('HTTP proxy returns redirect untouched; private redirect cannot connect', async (t) => {
  const { get, dials } = await fixture(t, createGuard(async () => [{ address: '8.8.8.8' }]));
  const first = await get('http://fixture.test/redirect');
  assert.equal(first.status, 302);
  assert.equal((await get(first.headers.location)).status, 403);
  assert.equal(dials.length, 1);
});

test('CONNECT tunnel pins public addresses and rejects private IPv6 before dialing', async (t) => {
  const { proxy, dials } = await fixture(t, createGuard(async () => [{ address: '8.8.8.8' }]));
  const tunnel = (path) => new Promise((resolve, reject) => {
    const request = http.request(proxy.url, { method: 'CONNECT', path, agent: false });
    request.on('connect', (response, socket) => { socket.destroy(); resolve(response.statusCode); });
    request.on('error', reject);
    request.end();
  });
  assert.equal(await tunnel('fixture.test:443'), 200);
  assert.deepEqual(dials, [{ host: '8.8.8.8', port: 443 }]);
  assert.equal(await tunnel('[::1]:443'), 403);
  assert.equal(dials.length, 1);
});
