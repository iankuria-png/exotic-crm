import { test, expect } from '@playwright/test';

// Reuses the isolated ClientDetail fixture: no Laravel or database, every API call is mocked.
const fixtureUrl = '/tests/browser/fixtures/sales-profile-revert.html';
const clientId = 912345;

function deal(overrides) {
    return {
        client_id: clientId, platform_id: 6, product_id: 26, plan_type: 'vip', amount: '35000.00', currency: 'TZS',
        status: 'expired', effective_status: 'expired', product: { id: 26, name: 'VIP' }, ...overrides,
    };
}

async function setup(page) {
    const user = { id: 77, name: 'Sales fixture', role: 'sales', status: 'active', assigned_market_ids: [6] };
    await page.addInitScript((staff) => {
        localStorage.setItem('crm_token', 'isolated-browser-fixture');
        localStorage.setItem('crm_user', JSON.stringify(staff));
    }, user);
    const client = {
        id: clientId, platform_id: 6, wp_post_id: 76439, wp_user_id: 16147, client_type: 'escort',
        name: 'Renew days fixture', phone_normalized: '255700000001', city: 'Dar es Salaam', profile_status: 'publish',
        lifecycle_state: 'expired', needs_payment: false, notactive: false,
        platform: { id: 6, name: 'TZ fixture', currency_code: 'TZS', phone_prefix: '255', billing_method_policy: {} },
        notes: [], payments: [], active_deal: null, can_deactivate_without_deal: false,
        deals: [
            // A weekly deal that was previously stretched to 30 days must still renew as a week.
            deal({ id: 1, duration: 'weekly', duration_days: null, activated_at: '2026-10-06T17:41:40Z', expires_at: '2026-11-05T17:41:40Z', status: 'cancelled', effective_status: 'cancelled' }),
            deal({ id: 2, duration: 'manual', duration_days: 1, amount: '1000.00', activated_at: '2026-10-01T09:00:00Z', expires_at: '2026-10-01T20:59:59Z' }),
        ],
    };
    await page.route('**/api/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        let body = { data: [], total: 0, score: 80, checks: [], components: [] };
        if (path === '/api/crm/me') body = { user };
        if (path === `/api/crm/clients/${clientId}`) body = client;
        await route.fulfill({ json: body });
    });
    await page.goto(fixtureUrl);
    await expect(page.getByRole('heading', { name: 'Renew days fixture', exact: true })).toBeVisible();
    await page.getByRole('button', { name: /^Subscriptions \(2\)/ }).click();
}

const additionalDays = (page) => page.locator('label', { hasText: 'Additional days' }).locator('xpath=following-sibling::input[1]');

for (const width of [1440, 390]) {
    test(`renew defaults to the renewed plan's length at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await setup(page);
        const renewButtons = page.getByRole('button', { name: 'Renew', exact: true });
        await expect(renewButtons).toHaveCount(2);

        await renewButtons.nth(0).click();
        await expect(additionalDays(page)).toHaveValue('7');
        await page.screenshot({ path: `output/renew-days-2026-10-18/weekly-${width}.png`, fullPage: true });
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();

        await renewButtons.nth(1).click();
        await expect(additionalDays(page)).toHaveValue('1');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    });
}
