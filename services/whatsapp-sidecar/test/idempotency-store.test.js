import assert from 'node:assert/strict';
import test from 'node:test';
import { IdempotencyStore } from '../src/idempotency-store.js';

test('returns cached idempotency result without reinserting', () => {
  const store = new IdempotencyStore(60_000);
  const first = store.putIfAbsent('attempt-1', { status: 'in_flight', attemptUuid: 'attempt-1' });
  const second = store.putIfAbsent('attempt-1', { status: 'new', attemptUuid: 'attempt-1' });

  assert.equal(first.inserted, true);
  assert.equal(second.inserted, false);
  assert.deepEqual(second.value, { status: 'in_flight', attemptUuid: 'attempt-1' });
});

test('updates cached result after provider send', () => {
  const store = new IdempotencyStore(60_000);
  store.putIfAbsent('attempt-1', { status: 'in_flight', attemptUuid: 'attempt-1' });
  const updated = store.update('attempt-1', { status: 'sent', messageId: 'wamid.local' });

  assert.equal(updated.status, 'sent');
  assert.equal(store.get('attempt-1').messageId, 'wamid.local');
});
