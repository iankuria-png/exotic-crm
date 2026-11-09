import { test, expect } from '@playwright/test';

const origin = 'http://127.0.0.1:5186';
const entry = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"/><script type="module">import RefreshRuntime from '/@react-refresh';RefreshRuntime.injectIntoGlobalHook(window);window.$RefreshReg$=()=>{};window.$RefreshSig$=()=>type=>type;window.__vite_plugin_react_preamble_installed__=true;</script></head><body><div id="app"></div><script type="module" src="/resources/js/app.jsx"></script></body></html>`;

async function boot(page, { kind = 'existing', role = 'admin', width = 1440, trialStatus = 'active', pair = true, payment = 'manual', copy = null, duration = null, copyProgress = null } = {}) {
    await page.setViewportSize({ width, height: 1000 });
    const user = { id: 10, name: 'Sales QA', role, status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(user => { localStorage.setItem('crm_token', 'synthetic'); localStorage.setItem('crm_user', JSON.stringify(user)); window.__copied = ''; Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__copied = text; } } }); }, user);
    const deal = { id: 42, platform_id: 6, client_id: 77, product_id: 4, product: { id: 4, name: 'PREMIUM', tier: 'premium' }, plan_type: 'premium', status: 'pending', amount: 35000, currency: 'TZS', duration: 'weekly', duration_days: 7, activated_at: null, expires_at: null, is_free_trial: false };
    const client = { id: 77, platform_id: 6, wp_post_id: 701, wp_user_id: 801, client_type: 'escort', name: 'ARYAN', phone_normalized: '255712345678', city: 'Dar es Salaam', email: 'onboard@example.test', profile_status: 'private', lifecycle_state: 'expired', needs_payment: false, notactive: false, deals: [deal], notes: [], payments: [], active_deal: null, can_deactivate_without_deal: false, platform: { id: 6, name: 'TZ', phone_prefix: '255', timezone: 'Africa/Nairobi', currency_code: 'TZS', supported_currencies: ['TZS'], payment_link_providers: { active_provider: 'fixture', providers: { fixture: { label: 'Fixture provider', enabled: true, mode: 'static_url' } } }, billing_method_policy: { activation: { crm_methods: [payment] }, renewal: { crm_methods: ['manual'] } } } };
    const state = { posts: [], previews: [], resumes: [], errors: [], summary: { deal_id: 42, primary: { status: 'active', name: 'ARYAN', market: 'TZ', public_url: 'https://source.test/escort/aryan/', expires_at: '2026-11-13T09:00:00Z', timezone: 'Africa/Nairobi' }, subsidiary: { kind: 'trial', status: trialStatus, name: 'ARYAN', market: 'Tanzania', public_url: ['active', 'already_active'].includes(trialStatus) ? 'https://target.test/escort/aryan/' : null, expires_at: '2026-11-13T09:00:00Z', timezone: 'Africa/Nairobi', can_recover: false, request_id: 5, copied_from: 'TZ', copy: copyProgress } } };
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
            state.previews.push(Object.fromEntries(url.searchParams));
            const custom = url.searchParams.get('duration_mode') === 'custom';
            const period = duration ? { ...duration, mode: custom ? 'custom' : duration.mode, days: custom ? Number(url.searchParams.get('duration_days')) : duration.days } : null;
            const copyPreview = copy ? (typeof copy === 'function' ? copy(url.searchParams) : copy) : null;
            const needsChoice = copyPreview && copyPreview.status !== 'ready';
            return json({ duration: period, copy_preview: copyPreview, source_market: 'TZ', target_platform: { id: 2, name: 'Tanzania', trial_product_id: 8 }, config_errors: [], resolution: { kind: ambiguous ? 'ambiguous' : kind === 'ambiguous' ? 'existing' : kind, name: 'ARYAN', client_id: ambiguous || kind === 'create_if_needed' ? null : 88, candidates: [{ id: 88, name: 'ARYAN', phone_normalized: '255712345678', city: 'Dar es Salaam' }, { id: 89, name: 'ARYAN 2', phone_normalized: '255712345678', city: 'Arusha' }] }, preview_token: ambiguous || needsChoice ? null : 'opaque-fixture-token', default_duration_days: period?.days || 7, match: ambiguous || kind === 'create_if_needed' ? null : { id: 88, name: 'ARYAN', wp_post_id: 99 } });
        }
        if (path === '/crm/markets/2/trial-approval-policy') return json({ platform_id: 2, approval_mode: 'admin_or_pin', pin_configured: true, version: 2 });
        if (path === '/crm/deals/42/activate') {
            state.posts.push(req.postDataJSON()); deal.status = 'active';
            if (payment === 'link') return json({ payment: { transaction_uuid: 'fixture-payment', status: 'pending' }, payment_url: 'https://provider.test/pay/fixture', message: 'Payment link created' });
            return json({ id: 42, status: 'active', subsidiary_trial: { status: 'linked' } });
        }
        if (path === '/payment-status/fixture-payment') return json({ data: { status: 'completed', deal_status: 'active' } });
        if (path === '/crm/deals/42/activation-summary') return json(state.summary);
        if (path.startsWith('/crm/subsidiary-profile-copies/') && path.endsWith('/resume')) { state.resumes.push(req.postDataJSON()); state.summary.subsidiary = { ...state.summary.subsidiary, status: 'active', public_url: 'https://target.test/escort/aryan/', copy: { ...state.summary.subsidiary.copy, state: 'ready' } }; return json({ needs_reauthorization: false }, 202); }
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
    await page.getByRole('button', { name: /^Activate subscription( \+ request approval)?$/ }).click();
    await expect(page.getByRole('heading', { name: 'Activation summary', exact: true })).toBeVisible();
}

test('paired market is selected and matched automatically, then both links copy without changing clients', async ({ page }) => {
    const state = await boot(page);
    await expect(page.getByLabel(/^Include Tanzania/)).toBeChecked();
    await expect(page.getByText('Existing profile: ARYAN', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Paired market', { exact: true })).toHaveCount(0);
    await activate(page);
    expect(state.posts[0].subsidiary_trial).toMatchObject({ enabled: true, platform_id: 2, client_id: 88, preview_token: 'opaque-fixture-token', duration_days: 7 });
    expect(state.posts[0].subsidiary_trial.request_key).toMatch(/^[a-f0-9-]{36}$/);
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    expect(await page.evaluate(() => window.__copied)).toContain('https://target.test/escort/aryan/');
    expect(await page.evaluate(() => window.__copied)).toContain('https://source.test/escort/aryan/');
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/result-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await page.getByRole('button', { name: 'Activation summary & profile links' }).click();
    await expect(page.getByRole('button', { name: 'Copy Tanzania link' })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('creation consent is part of the included market at 360px', async ({ page }) => {
    const state = await boot(page, { kind: 'create_if_needed', width: 360 });
    await expect(page.getByText(/We’ll set up ARYAN’s Tanzania profile/)).toBeVisible();
    await expect(page.getByLabel('Create this subsidiary client')).toHaveCount(0);
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/create-mobile.png', fullPage: true });
    await activate(page);
    expect(state.posts[0].subsidiary_trial.create_confirmed).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.errors).toEqual([]);
});

test('ambiguous match holds activation until an explicit profile selection', async ({ page }) => {
    const state = await boot(page, { kind: 'ambiguous' });
    await expect(page.getByText('Choose the correct profile', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'ARYAN 255712345678 · Dar es Salaam' }).click();
    await expect(page.getByText('Existing profile: ARYAN', { exact: true })).toBeVisible();
    await activate(page);
    expect(state.posts[0].subsidiary_trial.client_id).toBe(88);
    expect(state.errors).toEqual([]);
});

test('sales request without PIN copies only the active primary profile', async ({ page }) => {
    const state = await boot(page, { role: 'sales', trialStatus: 'pending_approval', width: 390 });
    await expect(page.getByLabel('Approval PIN (optional)')).toBeVisible();
    await activate(page);
    await expect(page.getByText('Awaiting admin approval', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    const message = await page.evaluate(() => window.__copied);
    expect(message).toContain('source.test'); expect(message).not.toContain('target.test');
    expect(state.errors).toEqual([]);
});

test('unavailable pair lets sales activate primary only', async ({ page }) => {
    const state = await boot(page, { pair: false });
    await expect(page.getByLabel('Include another market')).not.toBeChecked();
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
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/result-zoom.png', fullPage: true });
    expect(state.errors).toEqual([]);
});

const readyCopy = { status: 'ready', blockers: [], categories: ['Profile details', 'Bio', 'Contacts and social links', 'Rates'], photos: 8, videos: 1, main_included: true, source: { name: 'ARYAN', market: 'TZ', status: 'private' },
    location: { status: 'mapped', source: 'Dar es Salaam → Airport', label: 'Dar es Salaam → Airport', region_id: 40, city_id: 41 }, currency: { status: 'mapped', id: 7, code: 'TZS' }, snapshot_digest: 'a'.repeat(64) };
const matched = { mode: 'match_primary', days: 14, purchased_days: 14, policy_version: 0, allow_override: true, max: 365, error: null, paired: true, message: null };

test('new paired profile shows its copy summary and purchased period, and a changed day count is explicit', async ({ page }) => {
    const state = await boot(page, { kind: 'create_if_needed', copy: readyCopy, duration: matched });
    await expect(page.getByText('Create Tanzania profile from TZ', { exact: true })).toBeVisible();
    await expect(page.getByText('8 photos · 1 video · Main photo included', { exact: true })).toBeVisible();
    await expect(page.getByText('14 days · Same as TZ').first()).toBeVisible();
    await expect(page.getByText('Starts when the Tanzania profile is ready.')).toBeVisible();
    await expect(page.getByText(/Photos and bio are not copied/)).toHaveCount(0);
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/copy-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Change days' }).click();
    await page.getByLabel('Choose number of days').check();
    await page.getByLabel('Number of days', { exact: true }).fill('21');
    await expect.poll(() => state.previews.some(p => p.duration_mode === 'custom' && p.duration_days === '21')).toBe(true);
    await activate(page);
    expect(state.posts[0].subsidiary_trial).toMatchObject({ enabled: true, platform_id: 2, duration_mode: 'custom', create_confirmed: true, preview_token: 'opaque-fixture-token' });
    expect(Number(state.posts[0].subsidiary_trial.duration_days)).toBe(21);
    expect(state.errors).toEqual([]);
});

test('unmatched location is a field-level choice before activation at 390px', async ({ page }) => {
    const choice = params => params.get('copy_region_id') ? { ...readyCopy, location: { status: 'selected', source: 'Dar es Salaam → Mbezi', label: 'Dar es Salaam → Kariakoo', region_id: 40, city_id: 42 } }
        : { ...readyCopy, status: 'needs_selection', location: { status: 'needs_selection', source: 'Dar es Salaam → Mbezi', suggested_region_id: 40, options: [{ id: 40, name: 'Dar es Salaam', cities: [{ id: 41, name: 'Airport' }, { id: 42, name: 'Kariakoo' }] }] } };
    const state = await boot(page, { kind: 'create_if_needed', copy: choice, duration: matched, width: 390 });
    await expect(page.getByText('Choose the matching Tanzania location')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/location-mobile.png', fullPage: true });
    await page.getByLabel('Tanzania city').selectOption('42');
    await expect(page.getByText('Dar es Salaam → Mbezi → Dar es Salaam → Kariakoo')).toBeVisible();
    await activate(page);
    expect(state.posts[0].subsidiary_trial).toMatchObject({ copy_region_id: 40, copy_city_id: 42 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(state.errors).toEqual([]);
});

test('paused setup shows saved progress, resumes the same run and ends with a normal subscription message', async ({ page }) => {
    const progress = { run_id: '11111111-2222-4333-8444-555555555555', run_version: 4, state: 'needs_review', held_reason: 'item_unconfirmed', can_resume: true, needs_reauthorization: false, duration_days: 14, request_version: 6,
        progress: { fields: 'completed', photos_total: 8, photos_done: 7, videos_total: 1, videos_done: 0, location: true } };
    const state = await boot(page, { kind: 'create_if_needed', copy: readyCopy, duration: matched, trialStatus: 'setup_paused', copyProgress: progress });
    await activate(page);
    await expect(page.getByText('Setup paused', { exact: true })).toBeVisible();
    await expect(page.getByText('Photos · 7 of 8 copied')).toBeVisible();
    await expect(page.getByText('We could not confirm the last upload.')).toBeVisible();
    await page.screenshot({ path: 'output/subsidiary-profile-copy-2026-11-09/paused-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Check and resume setup' }).click();
    await expect(page.getByText('Profile copied from TZ')).toBeVisible();
    expect(state.resumes[0]).toMatchObject({ version: 4 });
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    const message = await page.evaluate(() => window.__copied);
    expect(message).toContain('Your TZ subscription is active until');
    expect(message).toContain('Your Tanzania subscription is active until');
    expect(message).not.toMatch(/free trial/i);
    expect(state.errors).toEqual([]);
});
