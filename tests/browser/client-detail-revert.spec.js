import { test, expect } from '@playwright/test';

const fixtureUrl = '/tests/browser/fixtures/sales-profile-revert.html';
const clientId = 912345;
function profile(overrides = {}) {
    return {
        id: clientId, platform_id: 1, wp_post_id: 12345, wp_user_id: 123,
        client_type: 'escort', name: 'Removal test advertiser', phone_normalized: '254700000001',
        city: 'Nairobi CBD', profile_status: 'publish', lifecycle_state: 'expired',
        needs_payment: false, notactive: false, can_revert_profile: true,
        escort_expire: Date.parse('2026-10-01T20:59:59Z') / 1000,
        platform: { id: 1, name: 'Local Kenya fixture', currency_code: 'KES', phone_prefix: '254', billing_method_policy: {} },
        deals: [], notes: [], payments: [], active_deal: null, can_deactivate_without_deal: false,
        ...overrides,
    };
}

async function setup(page, { role = 'sales', initial = {}, failOnce = false, delay = 0 } = {}) {
    const user = { id: 77, name: 'Sales fixture', role, status: 'active', assigned_market_ids: [1] };
    await page.addInitScript((staff) => {
        localStorage.setItem('crm_token', 'isolated-browser-fixture');
        localStorage.setItem('crm_user', JSON.stringify(staff));
    }, user);
    let current = profile(initial);
    const calls = [];
    await page.route('**/api/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        let body = { data: [], total: 0, score: 80, checks: [], components: [] };
        if (path === '/api/crm/me') body = { user };
        if (path === `/api/crm/clients/${clientId}`) body = current;
        if (path === `/api/crm/clients/${clientId}/revert`) {
            calls.push(route.request().postDataJSON());
            current = { ...current, profile_removal_requested_at: '2026-10-12T10:00:00Z' };
            if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
            if (failOnce && calls.length === 1) {
                return route.fulfill({ status: 502, json: { message: 'WordPress removal was not confirmed. Retry the request.' } });
            }
            current = { ...current, profile_status: 'private', lifecycle_state: 'active', needs_payment: true,
                profile_reverted_at: '2026-10-12T10:01:00Z', can_revert_profile: false };
            body = { client: current, message: 'Profile reverted and removed from the public website.' };
        }
        await route.fulfill({ json: body });
    });
    await page.goto(fixtureUrl);
    await expect(page.getByRole('heading', { name: 'Removal test advertiser', exact: true })).toBeVisible();
    return calls;
}

for (const width of [1440, 390]) {
    test(`sales can confirm removal and see Reverted at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        const calls = await setup(page, { delay: 400 });
        const trigger = page.getByRole('button', { name: 'Revert profile', exact: true });
        await trigger.click();
        const dialog = page.getByRole('dialog', { name: 'Revert this profile?' });
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await expect(dialog.getByRole('button', { name: 'Revert profile', exact: true })).toBeDisabled();
        await page.keyboard.press('Escape');
        await expect(trigger).toBeFocused();
        await trigger.click();
        await dialog.getByLabel('Reason').fill('Advertiser requested website removal.');
        await page.keyboard.press('Shift+Tab');
        await expect(dialog.getByRole('button', { name: 'Revert profile', exact: true })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(dialog.getByLabel('Reason')).toBeFocused();
        await page.screenshot({ path: `output/sales-profile-revert-2026-10-12/confirm-${width}.png`, fullPage: true });
        await dialog.getByRole('button', { name: 'Revert profile', exact: true }).click();
        await expect(dialog.getByRole('button', { name: 'Working...' })).toBeDisabled();
        await expect(dialog).toBeHidden();
        await expect(page.getByText('Reverted', { exact: true })).toBeVisible();
        await expect(page.getByText(/Reverted — removed from the public website/)).toBeVisible();
        expect(calls).toEqual([{ reason: 'Advertiser requested website removal.' }]);
        await expect(trigger).toBeHidden();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        await page.screenshot({ path: `output/sales-profile-revert-2026-10-12/reverted-${width}.png`, fullPage: true });
    });
}

test('failed removal refetches pending state, retains reason and supports retry', async ({ page }) => {
    const calls = await setup(page, { failOnce: true });
    await page.getByRole('button', { name: 'Revert profile', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Revert this profile?' });
    await dialog.getByLabel('Reason').fill('Advertiser asked to leave.');
    await dialog.getByRole('button', { name: 'Revert profile', exact: true }).click();
    await expect(dialog.getByRole('alert')).toContainText('Retry the request');
    await expect(dialog.getByLabel('Reason')).toHaveValue('Advertiser asked to leave.');
    await expect(page.getByText('Removal pending', { exact: true })).toBeVisible();
    await expect(page.getByText('Reverted', { exact: true })).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Revert profile', exact: true }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByText('Reverted', { exact: true })).toBeVisible();
    expect(calls).toHaveLength(2);
});

test('active paid and marketing profiles do not expose removal', async ({ page }) => {
    await setup(page, { initial: { can_revert_profile: false, lifecycle_state: 'active' } });
    await expect(page.getByRole('button', { name: 'Revert profile', exact: true })).toHaveCount(0);
    await setup(page, { role: 'marketing' });
    await expect(page.getByRole('button', { name: 'Revert profile', exact: true })).toHaveCount(0);
});

test('private expired Payment Required profile exposes Revert and records removal', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 1000 });
    const calls = await setup(page, { initial: { profile_status: 'private', lifecycle_state: 'expired', needs_payment: true, escort_expire: null } });
    await expect(page.getByText('Payment Required', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Revert profile', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Revert this profile?' });
    await dialog.getByLabel('Reason').fill('Advertiser requested permanent removal from public listings.');
    await dialog.getByRole('button', { name: 'Revert profile', exact: true }).click();
    await expect(page.getByText('Reverted', { exact: true })).toBeVisible();
    expect(calls).toHaveLength(1);
    await expect(page.getByRole('button', { name: 'Revert profile', exact: true })).toHaveCount(0);
});
