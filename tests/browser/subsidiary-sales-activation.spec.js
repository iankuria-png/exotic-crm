import { test, expect } from '@playwright/test';

const origin = 'http://127.0.0.1:5186';
const entry = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"/><script type="module">import RefreshRuntime from '/@react-refresh';RefreshRuntime.injectIntoGlobalHook(window);window.$RefreshReg$=()=>{};window.$RefreshSig$=()=>type=>type;window.__vite_plugin_react_preamble_installed__=true;</script></head><body><div id="app"></div><script type="module" src="/resources/js/app.jsx"></script></body></html>`;

async function boot(page, { kind = 'existing', role = 'admin', width = 1440, trialStatus = 'active', pair = true, payment = 'manual' } = {}) {
    await page.setViewportSize({ width, height: 1000 });
    const user = { id: 10, name: 'Sales QA', role, status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(user => { localStorage.setItem('crm_token', 'synthetic'); localStorage.setItem('crm_user', JSON.stringify(user)); window.__copied = ''; Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__copied = text; } } }); }, user);
    const deal = { id: 42, platform_id: 6, client_id: 77, product_id: 4, product: { id: 4, name: 'PREMIUM', tier: 'premium' }, plan_type: 'premium', status: 'pending', amount: 35000, currency: 'TZS', duration: 'weekly', duration_days: 7, activated_at: null, expires_at: null, is_free_trial: false };
    const client = { id: 77, platform_id: 6, wp_post_id: 701, wp_user_id: 801, client_type: 'escort', name: 'ARYAN', phone_normalized: '255712345678', city: 'Dar es Salaam', email: 'onboard@example.test', profile_status: 'private', lifecycle_state: 'expired', needs_payment: false, notactive: false, deals: [deal], notes: [], payments: [], active_deal: null, can_deactivate_without_deal: false, platform: { id: 6, name: 'TZ', phone_prefix: '255', timezone: 'Africa/Nairobi', currency_code: 'TZS', supported_currencies: ['TZS'], payment_link_providers: { active_provider: 'fixture', providers: { fixture: { label: 'Fixture provider', enabled: true, mode: 'static_url' } } }, billing_method_policy: { activation: { crm_methods: [payment] }, renewal: { crm_methods: ['manual'] } } } };
    const state = { posts: [], errors: [], summary: { deal_id: 42, primary: { status: 'active', name: 'ARYAN', market: 'TZ', public_url: 'https://source.test/escort/aryan/', expires_at: '2026-11-13T09:00:00Z', timezone: 'Africa/Nairobi' }, subsidiary: { kind: 'trial', status: trialStatus, name: 'ARYAN', market: 'Tanzania', public_url: ['active', 'already_active'].includes(trialStatus) ? 'https://target.test/escort/aryan/' : null, expires_at: '2026-11-13T09:00:00Z', timezone: 'Africa/Nairobi', can_recover: false } } };
    page.on('pageerror', e => state.errors.push(e.message));
    await page.route('**/*', async route => {
        const req = route.request(); const url = new URL(req.url());
        if (url.origin !== origin) return route.abort('blockedbyclient');
        if (req.isNavigationRequest() && !url.pathname.startsWith('/api/')) return route.fulfill({ contentType: 'text/html', body: entry });
        if (!url.pathname.startsWith('/api/')) return route.continue();
        const path = url.pathname.slice(4); const json = (v, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(v) });
        if (path === '/crm/me') return json({ user });
        if (path === '/crm/clients/77') return json(client);
        if (path === '/crm/deals/42/subsidiary-trial-targets') return json({ recommended_platform_id: pair ? 2 : null, paired_platform_id: 2, pair_unavailable: !pair, targets: [{ id: 2, name: 'Tanzania', config_errors: [], free_trial_duration_days: 7 }] });
        if (path === '/crm/deals/42/subsidiary-trial-preview') {
            const chosen = url.searchParams.get('client_id'); const ambiguous = kind === 'ambiguous' && !chosen;
            return json({ target_platform: { id: 2, name: 'Tanzania', trial_product_id: 8 }, config_errors: [], resolution: { kind: ambiguous ? 'ambiguous' : kind === 'ambiguous' ? 'existing' : kind, name: 'ARYAN', client_id: ambiguous || kind === 'create_if_needed' ? null : 88, candidates: [{ id: 88, name: 'ARYAN', phone_normalized: '255712345678', city: 'Dar es Salaam' }, { id: 89, name: 'ARYAN 2', phone_normalized: '255712345678', city: 'Arusha' }] }, preview_token: ambiguous ? null : 'opaque-fixture-token', default_duration_days: 7, match: ambiguous || kind === 'create_if_needed' ? null : { id: 88, name: 'ARYAN', wp_post_id: 99 } });
        }
        if (path === '/crm/markets/2/trial-approval-policy') return json({ platform_id: 2, approval_mode: 'admin_or_pin', pin_configured: true, version: 2 });
        if (path === '/crm/deals/42/activate') {
            state.posts.push(req.postDataJSON()); deal.status = 'active';
            if (payment === 'link') return json({ payment: { transaction_uuid: 'fixture-payment', status: 'pending' }, payment_url: 'https://provider.test/pay/fixture', message: 'Payment link created' });
            return json({ id: 42, status: 'active', subsidiary_trial: { status: 'linked' } });
        }
        if (path === '/payment-status/fixture-payment') return json({ data: { status: 'completed', deal_status: 'active' } });
        if (path === '/crm/deals/42/activation-summary') return json(state.summary);
        if (path === '/crm/clients/77/billing-context') return json({ payment_methods: [payment], platform: client.platform });
        return json({ data: [], meta: {}, total: 0, enabled: false, providers: [], score: 80, checks: [], components: [] });
    });
    await page.goto(`${origin}/clients/77?tab=deals`);
    await expect(page.getByRole('button', { name: 'Activate', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Activate', exact: true }).click();
    return state;
}
async function activate(page) {
    const reference = page.locator('#client-detail-payment-reference');
    if (await reference.count()) await reference.fill('QA-RECEIPT-123');
    // Reference field ID is kept in the existing activation form.
    const fallback = page.getByPlaceholder(/reference|transaction code/i);
    if (!await reference.count() && await fallback.count()) await fallback.first().fill('QA-RECEIPT-123');
    await page.getByRole('button', { name: /^Activate subscription( \+ (request )?trial)?$/ }).click();
    await expect(page.getByRole('heading', { name: 'Activation summary', exact: true })).toBeVisible();
}

test('paired market is selected and matched automatically, then both links copy without changing clients', async ({ page }) => {
    const state = await boot(page);
    await expect(page.getByLabel('Include Tanzania free trial')).toBeChecked();
    await expect(page.getByText('Existing profile: ARYAN', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Trial market')).toHaveCount(0);
    await activate(page);
    expect(state.posts[0].subsidiary_trial).toMatchObject({ enabled: true, platform_id: 2, client_id: 88, preview_token: 'opaque-fixture-token', duration_days: 7 });
    expect(state.posts[0].subsidiary_trial.request_key).toMatch(/^[a-f0-9-]{36}$/);
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    expect(await page.evaluate(() => window.__copied)).toContain('https://target.test/escort/aryan/');
    expect(await page.evaluate(() => window.__copied)).toContain('https://source.test/escort/aryan/');
    await page.screenshot({ path: 'output/subsidiary-sales-activation-2026-11-06/result-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await page.getByRole('button', { name: 'Activation summary & profile links' }).click();
    await expect(page.getByRole('button', { name: 'Copy Tanzania link' })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('creation consent is part of the included trial at 360px', async ({ page }) => {
    const state = await boot(page, { kind: 'create_if_needed', width: 360 });
    await expect(page.getByText(/We’ll set up ARYAN’s Tanzania profile/)).toBeVisible();
    await expect(page.getByLabel('Create this subsidiary client')).toHaveCount(0);
    await page.screenshot({ path: 'output/subsidiary-sales-activation-2026-11-06/create-mobile.png', fullPage: true });
    await activate(page);
    expect(state.posts[0].subsidiary_trial.create_confirmed).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.errors).toEqual([]);
});

test('ambiguous match holds activation until an explicit profile selection', async ({ page }) => {
    const state = await boot(page, { kind: 'ambiguous' });
    await expect(page.getByText('Choose the correct profile', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription + trial', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'ARYAN 255712345678 · Dar es Salaam' }).click();
    await expect(page.getByText('Existing profile: ARYAN', { exact: true })).toBeVisible();
    await activate(page);
    expect(state.posts[0].subsidiary_trial.client_id).toBe(88);
    expect(state.errors).toEqual([]);
});

test('sales request without PIN copies only the active primary profile', async ({ page }) => {
    const state = await boot(page, { role: 'sales', trialStatus: 'pending_approval', width: 390 });
    await expect(page.getByLabel('Free-trial PIN (optional)')).toBeVisible();
    await activate(page);
    await expect(page.getByText('Awaiting admin approval', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    const message = await page.evaluate(() => window.__copied);
    expect(message).toContain('source.test'); expect(message).not.toContain('target.test');
    expect(state.errors).toEqual([]);
});

test('unavailable pair lets sales activate primary only', async ({ page }) => {
    const state = await boot(page, { pair: false });
    await expect(page.getByLabel('Include a free trial on another market')).not.toBeChecked();
    await activate(page);
    expect(state.posts[0].subsidiary_trial).toEqual({ enabled: false });
    expect(state.errors).toEqual([]);
});

test('uncertain outcomes remain explicit and clipboard failure gives selectable text', async ({ page }) => {
    const state = await boot(page, { trialStatus: 'uncertain' });
    await activate(page);
    await expect(page.getByText('Activation is being checked', { exact: true })).toBeVisible();
    await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('Denied'); } } }));
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    await expect(page.getByLabel('Text to copy')).toHaveValue(/source.test/);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('heading', { name: 'Activation summary', exact: true })).toHaveCount(0);
    expect(state.posts).toHaveLength(1);
    expect(state.errors).toEqual([]);
});

test('payment-link completion opens the same profile handoff after the existing status read', async ({ page }) => {
    const state = await boot(page, { payment: 'link' });
    await expect(page.getByText('Existing profile: ARYAN', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Send payment link', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Activation summary', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Copy Tanzania link' })).toBeVisible();
    expect(state.posts[0].payment_method).toBe('link');
    expect(state.posts[0].subsidiary_trial.platform_id).toBe(2);
    expect(state.errors).toEqual([]);
});

test('keyboard focus stays in the result panel at 200 percent zoom and reduced motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const state = await boot(page);
    await activate(page);
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    const dialog = page.getByRole('dialog');
    for (let i = 0; i < 18; i++) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
    }
    await page.screenshot({ path: 'output/subsidiary-sales-activation-2026-11-06/result-zoom.png', fullPage: true });
    expect(state.errors).toEqual([]);
});
