import test, { before, after } from 'node:test';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createServer } from 'vite';

let server, Findings;
before(async () => {
    server = await createServer({ server: { middlewareMode: true }, appType: 'custom' });
    Findings = (await server.ssrLoadModule('/resources/js/components/kyc/KycAiFindings.jsx')).default;
});
after(async () => { await server?.close(); });
const render = (props) => renderToStaticMarkup(React.createElement(QueryClientProvider, { client: new QueryClient() },
    React.createElement(Findings, { subjectId: 1, canReview: true, onRefresh() {}, ...props })));

test('waiting case displays its next step and expandable explanation without a duplicate run button', () => {
    const html = render({ review: { status: 'queued' }, automation: { can_run: false, mode: 'auto_approve' } });
    assert.match(html, /Waiting to start/);
    assert.match(html, /has not examined them yet/);
    assert.match(html, /Next step/);
    assert.match(html, /<summary[^>]*>How AI reviews this KYC/);
    assert.doesNotMatch(html, /Retry AI check/);
});
test('processing failure offers a retry only when the server permits it', () => {
    const props = { review: { status: 'stalled' }, automation: { can_run: true }, subject: { status: 'in_review' } };
    assert.match(render(props), /Retry AI check/);
    assert.doesNotMatch(render({ ...props, automation: { can_run: false, blocked_reason: 'consent_required' } }), /Retry AI check/);
});
test('calibration observations and superseded findings are never rendered as current evidence', () => {
    const review = { status: 'completed', observations: { dob: 'SYNTHETIC-PRIVATE-OBSERVATION' } };
    assert.doesNotMatch(render({ review: { ...review, mode: 'shadow' } }), /SYNTHETIC-PRIVATE-OBSERVATION/);
    assert.doesNotMatch(render({ review: { ...review, stale: true } }), /SYNTHETIC-PRIVATE-OBSERVATION/);
});
test('applied approval explains the outcome and keeps technical metadata expandable', () => {
    const html = render({ subject: { status: 'approved' }, review: { status: 'completed', action_taken: 'approved', recommendation: 'approve', observations: {} }, automation: { mode: 'auto_approve' } });
    assert.match(html, /AI approval was applied/);
    assert.match(html, /No routine reviewer decision is required/);
    assert.match(html, /<summary[^>]*>AI attempt details/);
});
