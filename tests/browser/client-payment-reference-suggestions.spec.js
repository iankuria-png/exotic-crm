import { test, expect } from '@playwright/test';

const clientId = 912345;
const fixtureUrl = '/tests/browser/fixtures/sales-profile-revert.html';
function payment(overrides = {}) {
    return { receipt_id: 401, reference: 'DEMO8X4Q2A', sender_name: 'Sheilah', sender_state: 'supplied', principal_minor: 50000, currency: 'KES',
        paid_at: new Date(Date.now() - 240000).toISOString(), received_at: new Date().toISOString(), timezone: 'Africa/Nairobi',
        account_label: 'Kenya till fixture', coverage: 'complete', use_code_available: true, unavailable_reason: null,
        possible_profiles: [
            { id: 601, name: 'Hellen', role: 'suggested', reasons: ['Previously paid by this payer.'] },
            { id: clientId, name: 'Ivy', role: 'alternative', current_profile: true, reasons: ['Also paid for by this payer.'] },
            { id: 602, name: 'Cate', role: 'alternative', reasons: ['Payer phone matches this profile.'] },
            { id: 603, name: 'Mia', role: 'alternative', reasons: ['Other supported profile.'] },
        ], ...overrides };
}
async function setup(page, { action = 'activate', emptyStart = false, failLookup = false, conflict = false, paginate = false, disabled = false, delayedLookup = false } = {}) {
    const user = { id: 77, name: 'CS fixture', role: 'sales', status: 'active', assigned_market_ids: [1] };
    await page.addInitScript(staff => { localStorage.setItem('crm_token', 'fixture'); localStorage.setItem('crm_user', JSON.stringify(staff)); }, user);
    const deal = { id: 1, client_id: clientId, platform_id: 1, product_id: 26, plan_type: 'vip', amount: '500.00', currency: 'KES',
        status: action === 'activate' ? 'pending' : action === 'extend' ? 'active' : 'expired', duration: 'weekly', duration_days: 7,
        product: { id: 26, name: 'VIP' }, subscription_lifecycle: 'renewal', activated_at: action === 'activate' ? null : new Date(Date.now() - 86400000).toISOString(),
        expires_at: new Date(Date.now() + (action === 'extend' ? 86400000 : -86400000)).toISOString() };
    let client = { id: clientId, platform_id: 1, wp_post_id: 76439, wp_user_id: 16147, client_type: 'escort', name: 'Ivy', phone_normalized: '254700000001',
        city: 'Nairobi', profile_status: 'publish', lifecycle_state: 'expired', needs_payment: false, notactive: false,
        platform: { id: 1, name: 'Kenya fixture', currency_code: 'KES', phone_prefix: '254', billing_method_policy: { activation: { crm_methods: ['manual', 'link'] }, renewal: { crm_methods: ['manual', 'link'] } } },
        notes: [], payments: [], active_deal: null, can_deactivate_without_deal: false, deals: emptyStart ? [] : [deal] };
    const writes = [];
    let lookups = 0;
    await page.route('**/api/**', async route => {
        const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
        let body = { data: [], total: 0, score: 80, checks: [], components: [] };
        if (request.method() !== 'GET' && path !== '/api/crm/heartbeat') writes.push({ path, body: request.postDataJSON() });
        if (path === '/api/crm/me') body = { user };
        if (path === `/api/crm/clients/${clientId}`) body = client;
        if (path === '/api/crm/products') body = [{ id: 26, name: 'VIP', active_prices: [{ id: 40, duration_label: '7 days', duration_days: 7, price: 500, currency: 'KES' }] }];
        if (path === '/api/crm/deals' && request.method() === 'POST') { client = { ...client, deals: [deal] }; body = deal; }
        if (/\/deals\/1\/(activate|extend|renew)$/.test(path)) {
            if (conflict) return route.fulfill({ status: 422, json: { message: 'Payment was just used.', errors: { payment_reference: ['This payment was already used. Choose another code.'] } } });
            body = { ...deal, status: 'active' };
        }
        if (path.endsWith('/reference-suggestions')) {
            lookups++;
            if (delayedLookup) await new Promise(resolve => setTimeout(resolve, 500));
            if (failLookup && lookups === 1) return route.fulfill({ status: 503, json: { message: 'Lookup unavailable' } });
            if (disabled) body = { enabled: false, items: [], scan_complete: true };
            else if (url.searchParams.get('search') === 'MISSING') body = { enabled: true, items: [], scan_complete: true, lookup: { state: 'not_found' } };
            else if (paginate && !url.searchParams.get('cursor')) body = { enabled: true, items: [], scan_complete: false, next_cursor: 'fixture-next' };
            else body = { enabled: true, items: [payment()], scan_complete: true, next_cursor: null, coverage: 'complete' };
        }
        await route.fulfill({ json: body });
    });
    await page.goto(fixtureUrl);
    await expect(page.getByRole('heading', { name: 'Ivy', exact: true })).toBeVisible();
    if (emptyStart) {
        await page.getByRole('button', { name: 'New subscription', exact: true }).click();
        await page.locator('form select').first().selectOption('26');
        await page.getByRole('button', { name: 'Create subscription', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Create New Subscription', exact: true })).toHaveCount(0);
    }
    await page.getByRole('button', { name: /^Subscriptions \(/ }).click();
    await page.getByRole('button', { name: action === 'activate' ? 'Activate' : action === 'extend' ? 'Extend' : 'Renew', exact: true }).click();
    return { writes, lookups: () => lookups };
}
const suggestions = page => page.getByTestId('payment-reference-suggestions');

for (const width of [1440, 390]) {
    test(`create subscription, use code and inspect any candidate in a new tab at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        const { writes } = await setup(page, { emptyStart: true });
        const area = suggestions(page);
        await expect(area.getByText('4 possible profiles', { exact: false })).toBeVisible();
        await expect(area.getByRole('link')).toHaveCount(4);
        const input = page.getByLabel('MPESA / Transaction Reference', { exact: true });
        await input.fill('TYPEDCODE');
        const popupPromise = page.waitForEvent('popup');
        await area.getByRole('link', { name: /^Hellen/ }).click();
        const popup = await popupPromise; await expect(popup).toHaveURL(/\/clients\/601$/); await popup.close();
        await expect(input).toHaveValue('TYPEDCODE');
        await expect(page.getByRole('heading', { name: 'Activate Subscription', exact: true })).toBeVisible();
        expect(writes).toHaveLength(1); // Only the explicit subscription creation.
        await area.getByRole('button', { name: 'Replace code', exact: true }).click();
        await expect(input).toHaveValue('DEMO8X4Q2A');
        await expect(input).toBeFocused();
        expect(writes).toHaveLength(1);
        await area.getByRole('button', { name: 'Change', exact: true }).click();
        await area.getByRole('button', { name: 'Dismiss payment suggestions' }).click();
        await expect(input).toHaveValue('DEMO8X4Q2A');
        await area.getByRole('button', { name: 'Show incoming payments', exact: true }).click();
        await expect(area.getByText('DEMO8X4Q2A', { exact: true })).toBeVisible();
        await page.screenshot({ path: `output/reference-suggestions-${width}.png`, fullPage: true });
        await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
        await expect.poll(() => writes.length).toBe(2);
        expect(writes[1].body.payment_reference).toBe('DEMO8X4Q2A');
        expect(writes[1].body.payment_method).toBe('manual');
    });
    for (const action of ['extend', 'renew']) test(`${action} retains days while using a suggestion at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        const { writes } = await setup(page, { action });
        const days = page.locator('label', { hasText: 'Additional days' }).locator('xpath=following-sibling::input[1]');
        await days.fill('9');
        await suggestions(page).getByRole('button', { name: 'Use code', exact: true }).click();
        await expect(days).toHaveValue('9'); expect(writes).toHaveLength(0);
        await page.getByRole('button', { name: action === 'extend' ? 'Extend Subscription' : 'Renew Subscription', exact: true }).click();
        await expect.poll(() => writes.length).toBe(1);
        expect(writes[0].body.payment_reference).toBe('DEMO8X4Q2A'); expect(writes[0].body.additional_days).toBe(9);
    });
}

test('lookup failure, retry, pagination and exact search do not interrupt manual entry', async ({ page }) => {
    const { writes, lookups } = await setup(page, { failLookup: true, paginate: true });
    const area = suggestions(page); const input = page.getByLabel('MPESA / Transaction Reference', { exact: true });
    await expect(area.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    await input.fill('MANUALINPUT'); await area.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(area.getByText('DEMO8X4Q2A', { exact: true })).toBeVisible();
    expect(lookups()).toBeGreaterThanOrEqual(3); await expect(input).toHaveValue('MANUALINPUT');
    await area.getByRole('button', { name: 'Find another payment', exact: true }).click();
    await area.getByLabel('Find by exact transaction code').fill('MISSING'); await area.getByRole('button', { name: 'Find code', exact: true }).click();
    await expect(area.getByText(/No incoming payment found/)).toBeVisible();
    await expect(input).toHaveValue('MANUALINPUT'); expect(writes).toHaveLength(0);
});
test('concurrent receipt use keeps the entered code and exposes the server refusal', async ({ page }) => {
    const { writes } = await setup(page, { conflict: true });
    await suggestions(page).getByRole('button', { name: 'Use code', exact: true }).click();
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(suggestions(page).getByText('This payment was already used. Choose another code.')).toBeVisible();
    await expect(page.getByLabel('MPESA / Transaction Reference', { exact: true })).toHaveValue('DEMO8X4Q2A');
    expect(writes).toHaveLength(1);
});
test('disabled capture leaves the ordinary manual input usable', async ({ page }) => {
    const { writes } = await setup(page, { disabled: true });
    await expect(suggestions(page)).toHaveCount(0);
    await page.getByLabel('MPESA / Transaction Reference', { exact: true }).fill('TYPEASNORMAL');
    expect(writes).toHaveLength(0);
});


test('late suggestions never overwrite typed reference; keyboard links keep the original form at 200% zoom', async ({ page }) => {
    await page.setViewportSize({ width: 780, height: 1100 });
    const { writes } = await setup(page, { delayedLookup: true });
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    const input = page.getByLabel('MPESA / Transaction Reference', { exact: true });
    await input.fill('KEEPWHATTYPED');
    const link = suggestions(page).getByRole('link', { name: /^Cate/ });
    await expect(link).toBeVisible(); await link.focus();
    const popupPromise = page.waitForEvent('popup'); await page.keyboard.press('Enter');
    const popup = await popupPromise; await expect(popup).toHaveURL(/\/clients\/602$/); await popup.close();
    await expect(input).toHaveValue('KEEPWHATTYPED');
    expect(writes).toHaveLength(0);
    await suggestions(page).getByRole('button', { name: 'Dismiss payment suggestions' }).click();
    await expect(input).toBeFocused(); await expect(input).toHaveValue('KEEPWHATTYPED');
});
