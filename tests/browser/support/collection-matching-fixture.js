import { expect } from '@playwright/test';

export async function setup(page, { shared = false, held = false, partial = false, denied = false, trial = false, used = false, unsaved = false, ambiguous = false, missing = false, principal = null, discountCap = 40 } = {}) {
    const staff = { id: 7, name: 'Office fixture', role: 'admin', status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(({ staff, denied }) => {
        localStorage.setItem('crm_token', 'isolated-sales-fixture'); localStorage.setItem('crm_user', JSON.stringify(staff));
        window.fixtureCopies = [];
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async text => { if (denied) throw new Error('Clipboard denied'); window.fixtureCopies.push(text); } } });
    }, { staff, denied });
    const clients = [{ id: 42, name: 'Example Profile A', wp_post_id: 10001, platform_id: 6 }, { id: 43, name: 'Example Profile B', wp_post_id: 10002, platform_id: 6 }];
    const price = { id: 8, price: '35000.00', currency: 'TZS', duration_days: 7, product: { id: 4, name: 'VIP', tier: 'vip', platform_id: 6 } };
    const purchase = id => ({ client_id: id, platform_id: 6, product_price_id: 8, tier: 'vip', duration_days: 7, amount_minor: 3500000, catalogue_minor: 3500000, currency: 'TZS', subsidiary: null });
    const state = { historyError: false, session: null, mutations: [], receiptVersion: 2, failSave: false, partial };
    const usage = used ? { state: 'already_recorded', complete: true, matches: [{ payment_id: 909, status: 'completed', client: clients[0], deal: { id: 100, status: 'active', plan_type: 'vip', expires_at: '2026-11-13T20:59:59Z' }, responsibility: { state: 'staff', actor: { name: 'Zeddy' }, activated_at: '2026-11-06T18:23:22Z' }, destination: 'not_verified' }] } : { state: 'unrecorded', complete: true, matches: [] };
    const receipt = () => ({ provider_at: '2026-11-06T18:20:00Z', received_at: '2026-11-06T18:20:51Z', usage, id: 1, version: state.receiptVersion, account_id: 'fixture', account_label: 'TZ office wallet', account_mode: 'shadow', reference: 'FIXTURE0001', principal_minor: principal ?? (shared ? 7000000 : 3500000), currency: 'TZS', decision_state: state.session ? `sales_${state.session.state}` : 'needs_review', evidence_level: 'phone', historical_only: false, payer: missing ? { name: null, phone: null, complete: false } : { name: 'EXAMPLE PAYER', phone: '+255700000001', complete: true }, captured_payer_receipts: 1, clients: state.session?.items.map(i => i.client) || [], reasons: [], corrections: [], allocation: null, sale: state.session ? { id: state.session.id, state: state.session.state } : null });
    const seed = { client_id: 42, client: clients[0], product_price_id: 8, saved_intent_id: '11111111-1111-4111-8111-111111111111', purchase: purchase(42) };
    const rows = input => input.items.map((i, index) => ({ id: `item-${index}`, version: 1, position: index + 1, state: 'draft', client_id: i.client_id, client: clients.find(c => c.id === i.client_id), purchase: { ...purchase(i.client_id), ...(i.discount?.enabled ? { amount_minor: Math.round(Number(i.discount.payable_amount) * 100), original_amount: '35000.00', discount_percentage: i.discount.percentage, receipt_discount_requested: true, replace_saved_price: i.replace_saved_price } : {}) }, amount_minor: i.discount?.enabled ? Math.round(Number(i.discount.payable_amount) * 100) : 3500000, deal_id: null }));
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
        else if (path.endsWith('/receipts/1/review-context')) { if (state.historyError) return route.fulfill({ status: 503, json: { message: 'History unavailable' } }); data = { payer: receipt().payer, receipt_version: 2, capture_only: true, can_activate: false, suggestions: missing ? [] : ambiguous ? clients.map(client => ({ ...client, score: 49, reasons: ['The payer evidence needs a staff choice.'] })) : [{ ...clients[0], score: 90, reasons: ['The full SMS payer phone matches this profile.'] }], selected_client: ambiguous || missing ? null : clients[0], recommended_client_id: ambiguous || missing ? null : 42, needs_choice: ambiguous, history: { prior_count: missing ? null : 0, crm_count: missing ? null : 0, observed_only_count: 0, complete: true, items: [], scope: { label: 'Permitted receiving markets' } }, saved_choice: unsaved ? null : { id: seed.saved_intent_id, valid: true, client: clients[0], purchase: purchase(42) }, discount_policy: { pin_configured: true, max_percentage: discountCap }, prices: [price], quotes: [], subsidiary_clients: [], subsidiary_platform_id: null }; }
        else if (path.endsWith('/receipts/1/candidates')) data = { clients: url.searchParams.has('selected_client_id') ? clients.filter(c => String(c.id) === url.searchParams.get('selected_client_id')) : clients, discount_policy: { pin_configured: true, max_percentage: discountCap }, prices: [price], quotes: [], subsidiary_clients: [], subsidiary_platform_id: null };
        else if (path.endsWith('/receipts/1/messages')) data = { complete: true, messages: [{ id: 1, sender: 'M-PESA', message: 'FIXTURE0001 Confirmed.\nReceived TZS 35,000 from EXAMPLE PAYER.', received_at: '2026-11-07T00:00:00Z', copies: 1 }] };
        else if (path.endsWith('/sales/subsidiary-trial-targets')) data = { targets: trial ? [{ id: 2, name: 'Tanzania', config_errors: [] }] : [], recommended_platform_id: trial ? 2 : null, pair_unavailable: false };
        else if (path.endsWith('/sales/subsidiary-trial-preview')) data = { preview_token: 'fixture-subsidiary-preview', default_duration_days: 9, resolution: { kind: 'existing', client_id: 72, name: 'Example linked profile', candidates: [] }, config_errors: [], target_platform: { id: 2, name: 'Tanzania', trial_product_id: 12 } };
        else if (path.endsWith('/trial-approval-policy')) data = { approval_mode: 'admin_or_pin' };
        else if (path.endsWith('/receipts/1/sales')) data = { session: state.session, seed: unsaved ? null : seed, receipt_version: state.receiptVersion, storage_ready: true };
        else if (/\/sales\/(save|preview|activate)$/.test(path)) {
            const input = request.postDataJSON(); const items = rows(input); const allocated = items.reduce((sum, i) => sum + i.amount_minor, 0);
            if (input.items.some(i => i.discount?.enabled && i.discount.pin !== "123456")) return route.fulfill({ status: 422, json: { message: "Validation failed", errors: { "items.0.discount.pin": ["Discount PIN is invalid."] } } });
            if (path.endsWith('/preview')) data = { items: items.map((i, index) => ({ ...i, subsidiary_trial: input.items[index].subsidiary_trial?.enabled ? { ...input.items[index].subsidiary_trial, market_name: 'Tanzania' } : null })), allocated_minor: allocated, remainder_minor: receipt().principal_minor - allocated, preview_token: 'fixture-reviewed-token', can_activate: !held, blocked_reasons: held ? ['manual_activation_connection_required'] : [] };
            else {
                if (path.endsWith('/save') && state.failSave) return route.fulfill({ status: 409, json: { message: 'The saved sale changed. Refresh its version and review.' } });
                const active = path.endsWith('/activate');
                if (active) expect(input.preview_token).toBe('fixture-reviewed-token');
                state.session = { id: '22222222-2222-4222-8222-222222222222', version: (state.session?.version || 0) + 1, state: active ? (partial ? 'needs_review' : 'confirmed') : 'draft', items: items.map((i, index) => ({ ...i, state: active ? (partial && index === 1 ? 'unknown' : 'confirmed') : 'draft', deal_id: active ? 100 + index : null, last_error_code: partial && index === 1 ? 'site_confirmation_required' : null })), allocated_minor: allocated, principal_minor: receipt().principal_minor, remainder_minor: receipt().principal_minor - allocated, remainder_reason: input.remainder_reason || null, currency: 'TZS' };
                data = { session: state.session, receipt_version: state.receiptVersion };
            }
        } else if (/\/deals\/\d+\/activation-summary$/.test(path)) data = { primary: { name: 'Example Profile A', market: 'TZ', status: 'active', public_url: 'https://example.test/advertiser-a/', expires_at: '2026-11-14T20:59:59Z', timezone: 'Africa/Dar_es_Salaam' }, subsidiary: { status: 'not_included' } };
        await route.fulfill({ json: data });
    });
    await page.goto('/tests/browser/fixtures/collection-payments.html');
    await page.getByRole('button', { name: used ? 'View receipt' : 'Match & activate', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    if (!used) await expect(page.getByRole('group', { name: 'PROFILE 1', exact: true })).toBeVisible();
    return state;
}
export async function addSecond(page) {
    const row = page.getByRole('group', { name: 'PROFILE 2', exact: true });
    await row.getByRole('textbox').fill('Example');
    await row.getByRole('button', { name: /Example Profile B.*Profile 10002/ }).click();
    await row.getByRole('combobox', { name: 'Purchased service' }).selectOption('8');
}
