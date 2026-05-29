import assert from 'node:assert/strict';
import test from 'node:test';
import { signBody, verifySignature } from '../src/hmac.js';

test('verifies current and previous HMAC secrets', () => {
  const body = JSON.stringify({ ok: true });
  const current = signBody(body, 'current-secret', 1000);
  const previous = signBody(body, 'previous-secret', 1000);

  assert.equal(verifySignature(body, current, ['current-secret', 'previous-secret'], 300, 1005), true);
  assert.equal(verifySignature(body, previous, ['current-secret', 'previous-secret'], 300, 1005), true);
});

test('rejects expired or malformed HMAC signatures', () => {
  const body = JSON.stringify({ ok: true });
  const header = signBody(body, 'secret', 1000);

  assert.equal(verifySignature(body, header, ['secret'], 300, 1401), false);
  assert.equal(verifySignature(body, 't=1000,v1=short', ['secret'], 300, 1000), false);
});
