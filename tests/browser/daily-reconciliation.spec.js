import { test, expect } from '@playwright/test';

// Full CRM React shell, intercepted synthetic APIs. No production accounts or providers.
const origin = 'http://127.0.0.1:5184';
const entry = `<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"/><script type="module">import RefreshRuntime from '/@react-refresh';RefreshRuntime.injectIntoGlobalHook(window);window.$RefreshReg$=()=>{};window.$RefreshSig$=()=>type=>type;window.__vite_plugin_react_preamble_installed__=true;</script></head><body><div id="app"></div><script type="module" src="/resources/js/app.jsx"></script></body></html>`;

async function boot(page, role = 'admin', width = 1440) {
    await page.setViewportSize({ width, height: 1000 });
    const user = { id: role === 'admin' ? 1 : 2, name: role === 'admin' ? 'Admin fixture' : 'Sales fixture', role, status: 'active', assigned_market_ids: [6] };
    const state = {
        units: [1, 2, 3].map(id => ({ key: `payment:${id}`, type: 'payment', id, platform_ids: [6], amount: '35000.00', currency: 'TZS', reference: `DEMO-004${id}`, clients: [{ id: id + 40, payment_id: id, name: `Example advertiser ${id}`, package: 'VIP', duration_days: 7 }], date: '2026-11-05T08:42:00Z', business_date: '2026-11-05', date_fallback: false, channel: 'manual', financial_status: 'completed', recorder_ids: [2], recorders: [{ id: 2, name: 'Sales fixture' }], review_id: null, review_version: 0, source_digest: 'a'.repeat(64), review_status: 'pending', stale: false, flag_reason: null, blockers: [], correction: null, attachments: [], proofs: [], evidence_level: 'No proof attached', settlement_label: 'Receiving-account settlement not verified here' })),
        events: [], previews: [], failNext: false, trial: { id: 5, request_key: 'fixture', requester_id: 2, platform_id: 6, action: 'activate', version: 0, status: 'pending', reason: 'Introductory market trial requested.', terms_json: { client_ids: [41], product_id: 2, duration_days: 7 }, market: 'TZ', requester: 'Sales fixture', approver: null, approved_via: null, clients: [{ id: 41, name: 'Example advertiser 1' }], package: 'VIP', created_at: '2026-11-05T08:00:00Z' }, policy: { platform_id: 6, approval_mode: 'admin_only', version: 1, pin_configured: true, pin_exception_reason: null, review_on: null }, requests: [], unexpected: [],
    };
    await page.addInitScript(({ user }) => { localStorage.setItem('crm_token', 'synthetic-token'); localStorage.setItem('crm_user', JSON.stringify(user)); }, { user });
    await page.route('**/*', async route => {
        const req = route.request(); const url = new URL(req.url());
        if (url.origin !== origin) return route.abort('blockedbyclient');
        if (req.isNavigationRequest() && !url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'text/html', body: entry });
        if (!url.pathname.startsWith('/api/')) return route.continue();
        const path = url.pathname.slice(4); state.requests.push({ path, method: req.method() });
        const json = (v, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(v) });
        let body; try { body = req.postDataJSON(); } catch { body = {}; }
        if (path === '/crm/me') return json({ user });
        if (path === '/crm/reconciliations/options') return json({ markets: [{ id: 6, name: 'TZ', currency_code: 'TZS' }], review_market_ids: role === 'admin' ? [6] : [], export_market_ids: role === 'admin' ? [6] : [], is_admin: role === 'admin', staff: [{ id: 1, name: 'Admin fixture', role: 'admin' }, { id: 2, name: 'Sales fixture', role: 'sales' }], grants: [], timezone: 'Africa/Nairobi' });
        if (path === '/crm/reconciliations/my-corrections') return json({ data: state.units.filter(u => u.correction && u.correction.assigned_to === user.id).map(unit => ({ request: unit.correction, unit })) });
        if (path === '/crm/reconciliations') {
            const counts = { pending: 0, approved: 0, denied: 0, correction_requested: 0, resubmitted: 0, flagged: 0 }; state.units.forEach(u => { counts[u.review_status]++; if (u.flag_reason) counts.flagged++; });
            return json({ data: state.units, summary: { counts, totals: [{ currency: 'TZS', recorded: '105000.00', approved: String(counts.approved * 35000) }] }, meta: { total: state.units.length, page: 1, last_page: 1, per_page: 25, as_of: '2026-11-05T10:00:00Z', date: '2026-11-05' } });
        }
        if (path === '/crm/reconciliations/decisions/preview') { const p = { ...body, key: `p${state.previews.length}` }; state.previews.push(p); return json({ eligible: body.units, blocked: [], preview_token: p.key }); }
        if (path === '/crm/reconciliations/decisions') {
            if (state.failNext) { state.failNext = false; return json({ message: 'The receipt changed. Refresh and review it again.' }, 409); }
            const p = state.previews.find(p => p.key === body.preview_token);
            p.units.forEach(selected => { const u = state.units.find(u => u.key === selected.key); u.review_version++; u.review_id = u.id; if (p.action === 'flag') u.flag_reason = p.reason; else if (p.action === 'clear_flag') u.flag_reason = null; else if (p.action === 'request_correction') { u.review_status = 'correction_requested'; u.correction = { id: u.id, review_id: u.id, assigned_to: Number(p.assigned_to), message: p.reason, required_fields: p.required_fields, response_revisions_json: [], status: 'open', version: 0 }; } else u.review_status = p.action === 'approve' ? 'approved' : p.action === 'deny' ? 'denied' : 'pending'; state.events.push({ id: state.events.length + 1, action: p.action, reason: p.reason, actor: user.name, created_at: '2026-11-05T10:00:00Z' }); });
            return json({ count: p.units.length, results: p.units.map(u => ({ key: u.key, status: state.units.find(x => x.key === u.key).review_status })) });
        }
        const detail = path.match(/^\/crm\/reconciliations\/payment\/(\d+)$/); if (detail) return json({ unit: state.units.find(u => u.id === Number(detail[1])), events: state.events });
        if (path.includes('/corrections/') && path.endsWith('/resubmit')) { const u = state.units[0]; u.review_status = 'resubmitted'; u.correction.status = 'submitted'; u.correction.version++; u.correction.response_revisions_json.push({ at: '2026-11-05T10:00:00Z', response: body.response }); return json({ request: u.correction }); }
        if (path === '/crm/free-trials') return json({ data: [state.trial], meta: { total: 1, page: 1, last_page: 1 } });
        if (path === '/crm/free-trials/requests/5') return json({ request: state.trial, operations: [], events: [] });
        if (path === '/crm/free-trials/requests/5/decision') { state.trial.status = body.action === 'approve' ? 'active' : 'denied'; state.trial.version++; return json({ trial_request: state.trial, message: body.action === 'approve' ? 'Free trial activated and verified on the site.' : 'Trial request denied.' }); }
        if (path === '/crm/markets/6/trial-approval-policy') { if (req.method() === 'PUT') state.policy = { ...state.policy, ...body, version: state.policy.version + 1 }; return json(state.policy); }
        if (path === '/crm/reconciliations/export') return route.fulfill({ contentType: 'text/csv', body: 'Receipt,Amount\nDEMO,35000\n' });
        if (path.startsWith('/crm/reconciliations') || path.startsWith('/crm/free-trials')) { state.unexpected.push(path); return json({ message: `Unexpected fixture request ${path}` }, 422); }
        return json({ data: [], meta: {}, enabled: false }); // Existing shell-only badges/help/heartbeat.
    });
    await page.goto(`${origin}/payments/reconciliations`);
    await expect(page.getByRole('heading', { name: 'Reconciliations', exact: true })).toBeVisible();
    return state;
}

test('reviewer bulk approves, requests correction, denies safely and exports in the real shell', async ({ page }) => {
    const state = await boot(page);
    await page.getByLabel('Select visible receipts').check(); await page.getByRole('button', { name: 'Review & approve' }).click();
    const dialog = page.getByRole('dialog'); await expect(dialog).toContainText('3 receipts selected');
    await dialog.getByRole('button', { name: 'Review selection' }).click(); await dialog.getByRole('button', { name: 'Confirm · 3 receipts' }).click();
    await expect(page.locator('.rec-table .rec-badge-approved')).toHaveCount(3);
    await page.getByRole('button', { name: /Example advertiser 1/ }).click();
    await expect(page.getByRole('dialog')).toContainText('Receiving-account settlement not verified');
    await page.getByRole('button', { name: 'Request correction', exact: true }).click();
    const sheet = page.getByRole('dialog').last(); await sheet.getByLabel('Reason / instructions').fill('Attach a readable receipt with the complete reference.');
    await sheet.getByLabel('Assign to').selectOption('2'); await sheet.getByRole('button', { name: 'Review selection' }).click(); await sheet.getByRole('button', { name: 'Confirm · 1 receipts' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(1); await expect(page.getByRole('dialog')).toContainText('Correction request'); await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: /Example advertiser 2/ }).click(); await page.getByRole('button', { name: 'Deny reconciliation', exact: true }).click();
    const deny = page.getByRole('dialog').last(); await expect(deny).toContainText('customer’s service stays active'); await deny.getByLabel('Reason / instructions').fill('Receipt requires further investigation.');
    await deny.getByRole('button', { name: 'Review selection' }).click(); await deny.getByRole('button', { name: 'Confirm · 1 receipts' }).click(); await expect(page.getByRole('dialog')).toHaveCount(1); await expect(page.getByRole('dialog')).toContainText('Denied'); await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
    const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export records' }).click(); await download;
    expect(state.units[1].financial_status).toBe('completed'); expect(state.unexpected).toEqual([]);
    await page.screenshot({ path: 'output/daily-reconciliation-2026-11-05/reviewer-desktop.png', fullPage: true });
});

test('stale preview keeps the correction workflow and keyboard focus usable', async ({ page }) => {
    const state = await boot(page); state.failNext = true;
    await page.getByLabel('Select receipt DEMO-0041', { exact: true }).check(); await page.getByRole('button', { name: 'Review & approve' }).click();
    const dialog = page.getByRole('dialog'); await dialog.getByRole('button', { name: 'Review selection' }).click(); await dialog.getByRole('button', { name: 'Confirm · 1 receipts' }).click();
    await expect(dialog.getByRole('alert')).toContainText('receipt changed'); await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Review & approve' })).toBeFocused();
    expect(state.units[0].review_status).toBe('pending');
});

for (const width of [390, 360]) test(`phone ${width}px supports sales correction and reduced motion`, async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const state = await boot(page, 'sales', width);
    state.units[0].review_id = 1; state.units[0].review_status = 'correction_requested'; state.units[0].correction = { id: 1, assigned_to: 2, message: 'Add the complete receipt reference.', response_revisions_json: [], version: 0, status: 'open' };
    await page.reload(); await page.getByRole('button', { name: 'Review & respond' }).click();
    const dialog = page.getByRole('dialog'); await expect(dialog).toBeVisible(); await dialog.getByLabel('Your response').fill('Reference checked against the original receipt.'); await dialog.getByLabel('Corrected reference annotation (optional)').fill('DEMO0041');
    await dialog.getByRole('button', { name: 'Submit for re-review' }).click(); await expect(dialog).toContainText('Resubmitted');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({ path: `output/daily-reconciliation-2026-11-05/sales-${width}.png`, fullPage: true });
    await page.keyboard.press('Escape'); expect(state.unexpected).toEqual([]);
});

test('admin configures a PIN exception and approves a trial with separate activation state', async ({ page }) => {
    const state = await boot(page); await page.getByRole('tab', { name: 'Free trials' }).click();
    await page.getByRole('button', { name: 'Market policies' }).click(); const policy = page.getByRole('dialog'); await policy.getByLabel('Market', { exact: true }).selectOption('6');
    await policy.getByLabel('Admin or configured PIN', { exact: false }).check(); await policy.getByLabel('Reason for PIN access').fill('Market development before revenue'); await policy.getByLabel('Review this exception on').fill('2026-12-05'); await policy.getByLabel('Reason for policy change').fill('Authorize the pilot market exception.');
    await policy.getByRole('button', { name: 'Save market approval policy' }).click(); await expect(page.getByText('Market trial approval policy saved.', { exact: true })).toBeVisible(); await page.keyboard.press('Escape');
    expect(state.policy.approval_mode).toBe('admin_or_pin');
    await page.getByRole('button', { name: /Example advertiser 1/ }).click(); const trial = page.getByRole('dialog'); await trial.getByLabel('Decision / follow-up reason').fill('Approved introductory trial.'); await trial.getByRole('button', { name: 'Approve & activate' }).click();
    await expect(page.getByRole('dialog')).toContainText('Active · verified');
    expect(state.unexpected).toEqual([]); await page.screenshot({ path: 'output/daily-reconciliation-2026-11-05/trial-approved.png', fullPage: true });
});
