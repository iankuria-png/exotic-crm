import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { authentic, createGuard, publicIp, validate, truncateHtml, HTML_LIMIT } from '../src/security.js';
import { detectBlocked } from '../src/blockers.js';

test('HMAC signs timestamp, newline and exact bytes; rejects skew, tampering and malformed signatures', () => {
  const raw = Buffer.from('{"url":"https://example.com/é"}');
  const secret = 'unit-secret';
  const timestamp = '1700000000';
  const signature = createHmac('sha256', secret).update(timestamp + '\n').update(raw).digest('hex');
  const headers = { 'x-scraper-timestamp': timestamp, 'x-scraper-signature': signature };
  assert.equal(authentic(raw, headers, secret, Number(timestamp) * 1000), true);
  assert.equal(authentic(raw, headers, secret, (Number(timestamp) + 300) * 1000), true);
  for (const skew of [-301, 301]) assert.equal(authentic(raw, headers, secret, (Number(timestamp) + skew) * 1000), false);
  assert.equal(authentic(Buffer.concat([raw, Buffer.from(' ')]), headers, secret, Number(timestamp) * 1000), false);
  for (const value of [undefined, '', 'a', 'z'.repeat(64), ['a'.repeat(64)]]) {
    assert.equal(authentic(raw, { ...headers, 'x-scraper-signature': value }, secret, Number(timestamp) * 1000), false);
  }
});

for (const address of [
  '0.0.0.0', '127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1',
  '169.254.169.254', '168.63.129.16', '100.100.100.200', '100.64.0.1', '192.0.0.1', '192.0.2.1',
  '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255',
  '::', '::1', 'fc00::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1',
  '64:ff9b::a00:1', '2001:db8::1', '2002:7f00:1::', '2001::1', 'not-an-ip',
]) {
  test(`SSRF rejects ${address}`, async () => {
    assert.equal(publicIp(address), false);
    const guard = createGuard(async () => [{ address }]);
    await assert.rejects(guard('https://fixture.test'), { code: 'ssrf_blocked' });
  });
}

test('guard accepts public IPs and rejects mixed DNS, numeric aliases and non-HTTP targets', async () => {
  for (const address of ['8.8.8.8', '93.184.216.34', '2606:4700:4700::1111']) assert.equal(publicIp(address), true);
  const guard = createGuard(async () => [{ address: '8.8.8.8' }, { address: '10.0.0.1' }]);
  for (const value of ['https://fixture.test', 'http://2130706433', 'http://0x7f000001', 'http://[::1]', 'file:///etc/passwd', 'https://a:b@example.com']) {
    await assert.rejects(guard(value), { code: 'ssrf_blocked' });
  }
});

test('validation enforces ceilings and prevents header, cookie and identity overrides', () => {
  assert.equal(validate({ url: 'https://example.com' }).timeout_ms, 30000);
  for (const input of [
    { timeout_ms: 45001 }, { timeout_ms: 0 }, { timeout_ms: '1000' }, { scroll_steps: 6 },
    { scroll_steps: -1 }, { wait_until: 'commit' }, { wait_for_selector: 12 },
    { headers: [] }, { headers: { Host: 'localhost' } }, { headers: { 'User-Agent': 'FakeBrowser' } },
    { headers: { x: 'a\r\nb' } }, { cookies: [{ name: 'a', value: 'b', domain: 'other.test' }] },
    { user_agent: '' }, { screenshot: 'true' },
  ]) assert.throws(() => validate({ url: 'https://example.com', ...input }), { code: 'bad_request' });
});

test('HTML cap is 6 MiB and preserves valid UTF-8 byte accounting', () => {
  const capped = truncateHtml('a'.repeat(HTML_LIMIT - 1) + '😀');
  assert.equal(capped.truncated, true);
  assert.equal(capped.bytes, HTML_LIMIT - 1);
  assert.equal(Buffer.byteLength(capped.html), capped.bytes);
  assert.equal(capped.html.includes('�'), false);
  assert.deepEqual(truncateHtml('abc'), { html: 'abc', bytes: 3, truncated: false });
});

test('blocker types distinguish Cloudflare, CAPTCHA, login and HTTP errors', () => {
  assert.equal(detectBlocked('<title>Just a moment...</title>', 403).type, 'cloudflare_challenge');
  assert.equal(detectBlocked('<div class="g-recaptcha"></div>').type, 'captcha');
  assert.equal(detectBlocked('<h1>Sign in</h1><input type="password">').type, 'login_wall');
  assert.equal(detectBlocked('Down', 503).type, 'http_error');
  assert.equal(detectBlocked('<h1>Listings</h1>'), null);
});
