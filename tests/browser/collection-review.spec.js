import { test, expect } from '@playwright/test';

async function setup(page, mode = 'matched') {
    const user = { id: 7, name: 'Office fixture', role: 'admin', status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(staff => { localStorage.setItem('crm_token', 'isolated-payment-review'); localStorage.setItem('crm_user', JSON.stringify(staff)); }, user);
    const primary = { id: 42, name: 'Example Profile A', wp_post_id: 10001, platform_id: 6 };
    const other = { id: 43, name: 'Example Profile B', wp_post_id: 10002, platform_id: 6 };
    const price = { id: 8, price: '35000.00', currency: 'TZS', duration_days: 8, product: { id: 4, name: 'VIP', tier: 'vip', platform_id: 6 } };
    const state = { fail: false, historyError: false, saves: [], approvals: [], mode, version: 2, saved: null, active: false };
    const receipt = () => ({ id: 1, version: state.version, account_id: 'fixture-account', account_label: 'TZ office M-PESA wallet', account_mode: 'shadow',
        reference: 'FIXTURE0001', principal_minor: 3500000, currency: 'TZS', decision_state: 'needs_review', evidence_level: 'phone', historical_only: false,
        payer: state.mode === 'missing' ? { name: null, phone: null, name_state: 'missing', complete: true } : { name: 'EXAMPLE FULL PAYER NAME', phone: '+255700000001', name_state: 'supplied', complete: true },
        captured_payer_receipts: 4, clients: [], reasons: [], corrections: [], allocation: null, provider_at: '2026-11-06T12:30:00Z' });
    const snapshot = clientId => ({ client_id: clientId, platform_id: 6, product_price_id: 8, tier: 'vip', duration_days: 8, amount_minor: 3500000, currency: 'TZS', subsidiary: null });
    await page.route('**/api/**', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const path = url.pathname;
        let data = { data: [], total: 0 };
        if (path === '/api/crm/me') data = { user };
        if (path.endsWith('/collections/capabilities')) data = { enabled: true, entitlement_writes_enabled: state.active };
        if (path.endsWith('/collections/arrivals')) data = { unread_receipts: 1, cursor: 1 };
        if (path.endsWith('/collections/account-policies')) data = { accounts: [] };
        if (path.endsWith('/collections/device-health')) data = { devices: [], needs_attention: 0, checked_at: new Date().toISOString() };
        if (path.endsWith('/collections/receipts')) data = { data: [receipt()], total: 1, next_page_url: null };
        if (path.endsWith('/collections/receipts/1')) data = receipt();
        if (path.endsWith('/receipts/1/review-context')) {
            if (state.historyError) return route.fulfill({ status: 503, json: { message: 'History temporarily unavailable' } });
            const missing = state.mode === 'missing';
            const ambiguous = state.mode === 'ambiguous';
            const selected = url.searchParams.get('selected_client_id') === '43' ? other : primary;
            const suggestions = missing ? [] : [{ ...primary, score: ambiguous ? 49 : 95, reasons: ['Previously reviewed payer relationship.', '5 prior payments linked to this profile.'] }, ...(ambiguous ? [{ ...other, score: 49, reasons: ['This number also paid for this profile.'] }] : [])];
            data = { payer: receipt().payer, history: { prior_count: missing ? null : 6, crm_count: 5, observed_only_count: 1, complete: !missing,
                as_of: '2026-11-06T12:35:00Z', scope: { label: 'Subscription payments in permitted markets' }, items: missing ? [] : [{ key: 'p1', source: 'crm', amount_minor: 3500000, currency: 'TZS', at: '2026-11-04T09:00:00Z', reference: 'PRIOR000001' }], next_cursor: null },
                suggestions, recommended_client_id: ambiguous || missing ? null : 42, needs_choice: ambiguous, rule_version: 'collection-review-v1', receipt_version: state.version,
                selected_client: ambiguous || missing ? null : selected, prices: [price], quotes: [], subsidiary_platform_id: state.mode === 'subsidiary' ? 2 : null, subsidiary_clients: state.mode === 'subsidiary' ? [{ id: 72, name: 'Example Linked Profile', wp_post_id: 20001, platform_id: 2 }] : [], saved_choice: state.saved,
                recommended_intent: state.saved ? { id: state.saved.id, purchase: state.saved.purchase } : null,
                can_activate: state.active && !!url.searchParams.get('product_price_id'), capture_only: !state.active,
                blocked_reasons: state.active ? [] : ['account_entitlement_writes_disabled'] };
        }
        if (path.endsWith('/receipts/1/candidates')) data = { clients: url.searchParams.has('search') ? [other] : [url.searchParams.get('selected_client_id') === '43' ? other : primary],
            prices: [price], quotes: [], subsidiary_clients: state.mode === 'subsidiary' ? [{ id: 72, name: 'Example Linked Profile', wp_post_id: 20001, platform_id: 2 }] : [], subsidiary_platform_id: state.mode === 'subsidiary' ? 2 : null };
        if (path.endsWith('/receipts/1/intents')) {
            const payload = request.postDataJSON(); state.saves.push(payload);
            if (state.fail) return route.fulfill({ status: 409, json: { message: 'The receipt changed; refresh to review the latest evidence.' } });
            state.version++;
            state.saved = { id: '11111111-1111-4111-8111-111111111111', valid: true, client: payload.client_id === 43 ? other : primary, purchase: snapshot(payload.client_id) };
            data = { intent: { id: state.saved.id }, receipt_version: state.version, replayed: false };
        }
        if (path.endsWith('/receipts/1/approve')) { state.approvals.push(request.postDataJSON()); data = { state: 'prepared' }; }
        await route.fulfill({ json: data });
    });
    await page.goto('/tests/browser/fixtures/collection-payments.html');
    await page.getByRole('button', { name: 'Review receipt', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    return state;
}

test('review opens with full payer, prior payments and an explained suggested client', async ({ page }) => {
    const state = await setup(page);
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('region', { name: 'SMS payer' })).toContainText('EXAMPLE FULL PAYER NAME');
    await expect(dialog.getByRole('region', { name: 'SMS payer' })).toContainText('+255700000001');
    await expect(dialog.getByRole('heading', { name: '6 prior payments using this number' })).toBeVisible();
    await expect(dialog.getByText('95 / 100 · High')).toBeVisible();
    await expect(dialog.getByText('Previously reviewed payer relationship.')).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: 'Find a client' })).toHaveCount(0);
    await dialog.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    await expect(dialog.getByRole('button', { name: 'Save purchase choice', exact: true })).toBeEnabled();
    await dialog.getByRole('button', { name: 'Save purchase choice', exact: true }).click();
    await expect(dialog.getByRole('region', { name: 'Saved purchase', exact: true })).toContainText('Purchase choice saved');
    expect(state.saves[0]).toMatchObject({ client_id: 42, product_price_id: 8, remember_payer: false, expected_version: 2 });
    expect(state.saves[0].request_key).toBeTruthy();
    expect(state.approvals).toEqual([]);
    await dialog.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'Review receipt', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Saved purchase', exact: true })).toContainText('Example Profile A');
});

test('changing client clears the score and package before an explicit new selection', async ({ page }) => {
    await setup(page);
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    await dialog.getByRole('button', { name: 'Change client', exact: true }).click();
    await dialog.getByRole('textbox', { name: 'Find a client' }).fill('Example B');
    await dialog.getByRole('button', { name: 'Select Example Profile B, profile 10002', exact: true }).click();
    await expect(dialog.getByText('Selected by you')).toBeVisible();
    await expect(dialog.getByText('95 / 100 · High')).toHaveCount(0);
    await expect(dialog.getByRole('combobox', { name: 'Purchased service', exact: true })).toHaveValue('');
    await expect(dialog.getByRole('button', { name: 'Save purchase choice', exact: true })).toBeDisabled();
});

test('shared payer requires a client choice and missing details never become zero history', async ({ page }) => {
    await setup(page, 'ambiguous');
    await expect(page.getByRole('dialog').getByText('Choose the intended profile.', { exact: false })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Purchased service', exact: true })).toHaveCount(0);
    await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
    await page.unroute('**/api/**');
    await setup(page, 'missing');
    await expect(page.getByRole('dialog').getByText('Name not supplied in this SMS', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Payment history unavailable' })).toBeVisible();
    await expect(page.getByText('0 prior payments using this number')).toHaveCount(0);
});

test('failed save preserves inputs and the retry request key', async ({ page }) => {
    const state = await setup(page);
    state.fail = true;
    await page.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    const save = page.getByRole('button', { name: 'Save purchase choice', exact: true });
    await save.click();
    await expect(page.getByRole('dialog').getByRole('alert')).toContainText('The receipt changed');
    await expect(page.getByRole('combobox', { name: 'Purchased service', exact: true })).toHaveValue('price:8');
    state.fail = false;
    await save.click();
    await expect(page.getByRole('region', { name: 'Saved purchase', exact: true })).toContainText('Purchase choice saved');
    expect(state.saves[0].request_key).toBe(state.saves[1].request_key);
});

test('an unavailable history response gives a retry and no fabricated suggestion', async ({ page }) => {
    const state = await setup(page);
    state.historyError = true;
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'Review receipt', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Payment history', exact: true }).getByRole('alert')).toContainText('Could not load payment history');
    state.historyError = false;
    await page.getByRole('region', { name: 'Payment history', exact: true }).getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.getByText('95 / 100 · High')).toBeVisible();
});

test('polling preserves edits and requires review when the receipt version changes', async ({ page }) => {
    await page.clock.install();
    const state = await setup(page);
    await page.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    state.version = 3;
    await page.clock.fastForward(11000);
    await expect(page.getByRole('dialog').getByRole('alert')).toContainText('New receipt evidence is available');
    await expect(page.getByRole('combobox', { name: 'Purchased service', exact: true })).toHaveValue('price:8');
    await expect(page.getByRole('button', { name: 'Save purchase choice', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Load latest evidence', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save purchase choice', exact: true })).toBeEnabled();
});

test('mobile review contains its fields, supports keyboard close and restores focus', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page);
    await expect(page.getByText('95 / 100 · High')).toBeVisible();
    const overflow = await page.getByRole('dialog').evaluate(node => node.scrollWidth > node.clientWidth);
    expect(overflow).toBe(false);
    await page.screenshot({ path: 'output/payment-review-ux-2026-11-06/mobile.png', fullPage: true });
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review receipt', exact: true })).toBeFocused();
});

test('keyboard navigation wraps around visible controls while details are collapsed', async ({ page }) => {
    await setup(page);
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('95 / 100 · High')).toBeVisible();
    await dialog.getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Shift+Tab');
    await expect(dialog.locator('summary').filter({ hasText: 'Receipt reference and correction history' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(dialog.getByRole('button', { name: 'Close', exact: true })).toBeFocused();
});

test('assisted approval uses the newly saved intent and version exactly once', async ({ page }) => {
    const state = await setup(page);
    state.active = true;
    await page.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    await page.getByRole('button', { name: 'Confirm client and service', exact: true }).click();
    await expect.poll(() => state.approvals.length).toBe(1);
    expect(state.approvals[0]).toEqual({ intent_id: '11111111-1111-4111-8111-111111111111', expected_version: 3 });
});


test('linked Tanzania profile is suggested and included in the saved purchase', async ({ page }) => {
    const state = await setup(page, 'subsidiary');
    await expect(page.getByRole('combobox', { name: 'Linked Tanzania profile', exact: true })).toHaveValue('72');
    await page.getByRole('combobox', { name: 'Purchased service', exact: true }).selectOption('price:8');
    await page.getByRole('button', { name: 'Save purchase choice', exact: true }).click();
    await expect.poll(() => state.saves.length).toBe(1);
    expect(state.saves[0].subsidiary_client_id).toBe(72);
});
