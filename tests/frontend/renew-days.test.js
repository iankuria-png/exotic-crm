import test from 'node:test';
import assert from 'node:assert/strict';
import { renewDaysForDeal } from '../../resources/js/utils/dealDuration.js';

test('explicit deal duration_days wins', () => {
    assert.equal(renewDaysForDeal({ duration: 'manual', duration_days: 8 }), '8');
    assert.equal(renewDaysForDeal({ duration: 'weekly', duration_days: 1 }), '1');
});

test('legacy duration names map to their plan length, not 30', () => {
    assert.equal(renewDaysForDeal({ duration: 'weekly', duration_days: null }), '7');
    assert.equal(renewDaysForDeal({ duration: 'biweekly' }), '14');
    assert.equal(renewDaysForDeal({ duration: 'monthly' }), '30');
    assert.equal(renewDaysForDeal({ duration: 'Weekly ' }), '7');
});

test('a weekly deal already stretched to 30 days still renews as a week', () => {
    assert.equal(renewDaysForDeal({
        duration: 'weekly',
        duration_days: null,
        activated_at: '2026-10-06T17:41:40Z',
        expires_at: '2026-11-05T17:41:40Z',
    }), '7');
});

test('unknown duration falls back to the previous period length', () => {
    assert.equal(renewDaysForDeal({
        duration: 'manual',
        activated_at: '2026-09-28T15:11:51Z',
        expires_at: '2026-10-05T20:59:59Z',
    }), '7');
});

test('nothing usable keeps the historical 30-day default', () => {
    assert.equal(renewDaysForDeal({ duration: 'manual' }), '30');
    assert.equal(renewDaysForDeal(null), '30');
    assert.equal(renewDaysForDeal({ duration: 'manual', activated_at: '2026-10-06', expires_at: '2026-10-01' }), '30');
});
