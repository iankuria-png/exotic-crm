import { test, expect } from '@playwright/test';

async function setup(page, { shared = false, held = false, partial = false, denied = false, trial = false } = {}) {
    const staff = { id: 7, name: 'Office fixture', role: 'admin', status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(({ staff, denied }) => {
        localStorage.setItem('crm_token', 'isolated-sales-fixture'); localStorage.setItem('crm_user', JSON.stringify(staff));
        window.fixtureCopies = [];
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async text => { if (denied) throw new Error('Clipboard denied'); window.fixtureCopies.push(text); } } });
    }, { staff, denied });
    const clients = [{ id: 42, name: 'Example Profile A', wp_post_id: 10001, platform_id: 6 }, { id: 43, name: 'Example Profile B', wp_post_id: 10002, platform_id: 6 }];
    const price = { id: 8, price: '35000.00', currency: 'TZS', duration_days: 7, product: { id: 4, name: 'VIP', tier: 'vip', platform_id: 6 } };
    const purchase = id => ({ client_id: id, platform_id: 6, product_price_id: 8, tier: 'vip', duration_days: 7, amount_minor: 3500000, currency: 'TZS', subsidiary: null });
    const state = { session: null, mutations: [], receiptVersion: 2, failSave: false, partial };
    const receipt = () => ({ id: 1, version: state.receiptVersion, account_id: 'fixture', account_label: 'TZ office wallet', account_mode: 'shadow', reference: 'FIXTURE0001', principal_minor: shared ? 7000000 : 3500000, currency: 'TZS', decision_state: state.session ? `sales_${state.session.state}` : 'needs_review', evidence_level: 'phone', historical_only: false, payer: { name: 'EXAMPLE PAYER', phone: '+255700000001', complete: true }, captured_payer_receipts: 1, clients: state.session?.items.map(i => i.client) || [], reasons: [], corrections: [], allocation: null, sale: state.session ? { id: state.session.id, state: state.session.state } : null });
    const seed = { client_id: 42, client: clients[0], product_price_id: 8, saved_intent_id: '11111111-1111-4111-8111-111111111111', purchase: purchase(42) };
    const rows = input => input.items.map((i, index) => ({ id: `item-${index}`, version: 1, position: index + 1, state: 'draft', client_id: i.client_id, client: clients.find(c => c.id === i.client_id), purchase: purchase(i.client_id), amount_minor: 3500000, deal_id: null }));
    await page.route('**/api/**', async route => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        let data = { data: [], total: 0 };
        if (request.method() !== 'GET') state.mutations.push({ path, body: request.postDataJSON() });
        if (path === '/api/crm/me') data = { user: staff };
        else if (path.endsWith('/collections/capabilities')) data = { enabled: true, entitlement_writes_enabled: false };
        else if (path.endsWith('/collections/device-health')) data = { checked_at: '2026-11-07T00:00:00Z', summary: { label: 'Receiving normally', tone: 'teal', detail: 'Fresh check-in.' }, devices: [] };
        else if (path.endsWith('/collections/account-policies')) data = { accounts: [] };
        else if (path.endsWith('/collections/receipts')) data = { data: [receipt()], current_page: 1, last_page: 1, total: 1 };
        else if (path.endsWith('/receipts/1')) data = receipt();
        else if (path.endsWith('/receipts/1/review-context')) data = { payer: receipt().payer, receipt_version: 2, capture_only: true, can_activate: false, suggestions: [], needs_choice: false, history: { prior_count: 0, crm_count: 0, observed_only_count: 0, complete: true, items: [], scope: { label: 'Permitted receiving markets' } }, saved_choice: { id: seed.saved_intent_id, valid: true, client: clients[0], purchase: purchase(42) }, prices: [price], quotes: [], subsidiary_clients: [], subsidiary_platform_id: null };
        else if (path.endsWith('/receipts/1/candidates')) data = { clients: url.searchParams.has('selected_client_id') ? clients.filter(c => String(c.id) === url.searchParams.get('selected_client_id')) : clients, prices: [price], quotes: [], subsidiary_clients: [], subsidiary_platform_id: null };
        else if (path.endsWith('/receipts/1/messages')) data = { complete: true, messages: [{ id: 1, sender: 'M-PESA', message: 'FIXTURE0001 Confirmed.\nReceived TZS 35,000 from EXAMPLE PAYER.', received_at: '2026-11-07T00:00:00Z', copies: 1 }] };
        else if (path.endsWith('/sales/subsidiary-trial-targets')) data = { targets: trial ? [{ id: 2, name: 'Tanzania', config_errors: [] }] : [], recommended_platform_id: trial ? 2 : null, pair_unavailable: false };
        else if (path.endsWith('/sales/subsidiary-trial-preview')) data = { preview_token: 'fixture-subsidiary-preview', default_duration_days: 9, resolution: { kind: 'existing', client_id: 72, name: 'Example linked profile', candidates: [] }, config_errors: [], target_platform: { id: 2, name: 'Tanzania', trial_product_id: 12 } };
        else if (path.endsWith('/trial-approval-policy')) data = { approval_mode: 'admin_or_pin' };
        else if (path.endsWith('/receipts/1/sales')) data = { session: state.session, seed, receipt_version: state.receiptVersion, storage_ready: true };
        else if (/\/sales\/(save|preview|activate)$/.test(path)) {
            const input = request.postDataJSON(); const items = rows(input);
            if (path.endsWith('/preview')) data = { items, allocated_minor: items.length * 3500000, remainder_minor: receipt().principal_minor - items.length * 3500000, preview_token: 'fixture-reviewed-token', can_activate: !held, blocked_reasons: held ? ['manual_activation_connection_required'] : [] };
            else {
                if (path.endsWith('/save') && state.failSave) return route.fulfill({ status: 409, json: { message: 'The saved sale changed. Refresh its version and review.' } });
                const active = path.endsWith('/activate');
                if (active) expect(input.preview_token).toBe('fixture-reviewed-token');
                state.session = { id: '22222222-2222-4222-8222-222222222222', version: (state.session?.version || 0) + 1, state: active ? (partial ? 'needs_review' : 'confirmed') : 'draft', items: items.map((i, index) => ({ ...i, state: active ? (partial && index === 1 ? 'unknown' : 'confirmed') : 'draft', deal_id: active ? 100 + index : null, last_error_code: partial && index === 1 ? 'site_confirmation_required' : null })), allocated_minor: items.length * 3500000, principal_minor: receipt().principal_minor, remainder_minor: receipt().principal_minor - items.length * 3500000, remainder_reason: input.remainder_reason || null, currency: 'TZS' };
                data = { session: state.session, receipt_version: state.receiptVersion };
            }
        } else if (/\/deals\/\d+\/activation-summary$/.test(path)) data = { primary: { name: 'Example Profile A', market: 'TZ', status: 'active', public_url: 'https://example.test/advertiser-a/', expires_at: '2026-11-14T20:59:59Z', timezone: 'Africa/Dar_es_Salaam' }, subsidiary: { status: 'not_included' } };
        await route.fulfill({ json: data });
    });
    await page.goto('/tests/browser/fixtures/collection-payments.html');
    await page.getByRole('button', { name: 'Review receipt', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Saved purchase', exact: true })).toBeVisible();
    return state;
}
async function addSecond(page) {
    const row = page.getByRole('group', { name: 'PROFILE 2', exact: true });
    await row.getByRole('textbox').fill('Example');
    await row.getByRole('button', { name: 'Example Profile B · Profile 10002', exact: true }).click();
    await row.getByRole('combobox', { name: 'Purchased service' }).selectOption('8');
}

test('saved choice has an explicit next step and activates without re-entering payment evidence', async ({ page }) => {
    const state = await setup(page);
    await expect(page.getByRole('button', { name: 'Save purchase choice', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await expect(page.getByRole('group', { name: 'PROFILE 1', exact: true })).toContainText('Example Profile A');
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await page.screenshot({ path: 'output/payment-sales-workflow-2026-11-07/desktop-review.png' });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    const writes = state.mutations.filter(m => m.path.endsWith('/sales/activate'));
    expect(writes).toHaveLength(1); expect(writes[0].body.items[0]).toMatchObject({ client_id: 42, product_price_id: 8 });
    expect(state.mutations.filter(m => m.path.endsWith('/intents') || m.path.endsWith('/approve'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Customer message and links' }).click();
    await page.getByRole('button', { name: 'Copy customer message' }).click();
    expect((await page.evaluate(() => window.fixtureCopies))[0]).toContain('https://example.test/advertiser-a/');
    await page.screenshot({ path: 'output/payment-sales-workflow-2026-11-07/customer-handoff.png' });
});

test('shared payment balances allocations and retains one confirmed child if another needs checking', async ({ page }) => {
    await setup(page, { shared: true, partial: true });
    await page.getByRole('button', { name: 'Split across profiles', exact: true }).click();
    await expect(page.getByText('TZS 35,000 remaining', { exact: true })).toBeVisible();
    await addSecond(page);
    await expect(page.getByText('Fully allocated', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await page.getByRole('button', { name: 'Activate 2 subscriptions', exact: true }).click();
    await expect(page.getByText('1 of 2 subscriptions confirmed')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Customer message and links' })).toHaveCount(1);
    await expect(page.getByText('Manager: check saved activation', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Copy office summary' }).click();
    const text = (await page.evaluate(() => window.fixtureCopies))[0];
    expect(text).toContain('1 of 2 subscriptions confirmed'); expect(text).not.toContain('https://');
});

test('original SMS copy is explicit, preserves full text and offers manual copy if clipboard is denied', async ({ page }) => {
    const state = await setup(page, { denied: true });
    await page.getByRole('button', { name: 'Copy original SMS', exact: true }).click();
    await page.getByRole('button', { name: 'Copy this SMS', exact: true }).click();
    await expect(page.getByText('Clipboard unavailable. Select and copy this text.', { exact: true })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Clipboard unavailable. Select and copy this text.' })).toHaveValue('FIXTURE0001 Confirmed.\nReceived TZS 35,000 from EXAMPLE PAYER.');
    expect(state.mutations.filter(m => /sales|intents|approve/.test(m.path))).toHaveLength(0);
});

test('unreleased market gives an actionable reason and never offers an enabled activation', async ({ page }) => {
    const state = await setup(page, { held: true });
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await expect(page.getByText('An administrator must finish the activation connection', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(0);
});

test('saved shared allocations reopen with both profiles without duplicating a sale', async ({ page }) => {
    const state = await setup(page, { shared: true });
    await page.getByRole('button', { name: 'Split across profiles', exact: true }).click(); await addSecond(page);
    await page.getByRole('button', { name: 'Save allocations', exact: true }).click();
    await expect(page.getByText('Allocations saved. Review activation when you are ready.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'Review receipt', exact: true }).click();
    await expect(page.getByRole('group', { name: 'PROFILE 2', exact: true })).toContainText('Example Profile B');
    expect(state.mutations.filter(m => m.path.endsWith('/sales/save'))).toHaveLength(1);
});

test('mobile allocation keeps next action visible and fits the drawer', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 }); await setup(page, { shared: true });
    await page.getByRole('button', { name: 'Split across profiles', exact: true }).click(); await addSecond(page);
    const action = page.getByRole('button', { name: 'Review activation', exact: true });
    await expect(action).toBeInViewport();
    const overflow = await page.getByRole('dialog').evaluate(dialog => dialog.scrollWidth > dialog.clientWidth + 1);
    expect(overflow).toBe(false);
    await page.screenshot({ path: 'output/payment-sales-workflow-2026-11-07/mobile-allocations.png' });
});


test('the finalized subsidiary selector preselects the paired market and binds trial terms to the paid child', async ({ page }) => {
    const state = await setup(page, { trial: true });
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await expect(page.getByRole('checkbox', { name: 'Include Tanzania free trial', exact: false })).toBeChecked();
    await expect(page.getByText('Existing profile: Example linked profile', { exact: true })).toBeVisible();
    await expect(page.getByText('Your admin approval will be recorded with the trial.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    const preview = state.mutations.find(m => m.path.endsWith('/sales/preview')).body;
    expect(preview.items[0].subsidiary_trial).toMatchObject({ enabled: true, platform_id: 2, client_id: 72, duration_days: 9, preview_token: 'fixture-subsidiary-preview' });
});

test('a failed allocation save keeps selections and the retry request identity', async ({ page }) => {
    const state = await setup(page); state.failSave = true;
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    await page.getByRole('button', { name: 'Save allocations', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'The saved sale changed.' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'PROFILE 1', exact: true })).toContainText('Example Profile A');
    state.failSave = false;
    await page.getByRole('button', { name: 'Save allocations', exact: true }).click();
    const saves = state.mutations.filter(m => m.path.endsWith('/sales/save'));
    expect(saves[0].body.request_key).toBe(saves[1].body.request_key);
});

test('review at 200 percent text reflows and keyboard close restores the receipt action', async ({ page }) => {
    await setup(page, { shared: true });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await page.getByRole('button', { name: 'Split across profiles', exact: true }).click();
    await addSecond(page);
    await page.getByRole('button', { name: 'Review activation', exact: true }).click();
    const dialog = page.getByRole('dialog');
    expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: 'output/payment-sales-workflow-2026-11-07/text-reflow.png' });
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Review receipt', exact: true })).toBeFocused();
});
