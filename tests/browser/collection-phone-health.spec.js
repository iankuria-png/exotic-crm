import { test, expect } from '@playwright/test';

const fixture = '/tests/browser/fixtures/collection-payments.html';
async function setup(page, role = 'admin') {
    const user = { id: 7, name: 'Office fixture', role, status: 'active', assigned_market_ids: [6, 2] };
    await page.addInitScript(staff => { localStorage.setItem('crm_token', 'isolated-phone-fixture'); localStorage.setItem('crm_user', JSON.stringify(staff)); }, user);
    const state = { health: 'no_recent_contact', error: false, stale: false, saves: [], policy: { account_id: 'fixture-account', label: 'TZ office account', currency: 'TZS', version: 1, configured: true,
        limits: { single_cap_minor: null, daily_cap_minor: null, unreviewed_cap_minor: null, unverified_cap_minor: null } } };
    await page.route('**/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        let data = { data: [], total: 0 };
        if (path === '/api/crm/me') data = { user };
        if (path.endsWith('/collections/capabilities')) data = { enabled: true, entitlement_writes_enabled: false };
        if (path.endsWith('/collections/arrivals')) data = { unread_receipts: 0, cursor: 0, device_needs_attention: state.health === 'ready' ? 0 : 1 };
        if (path.endsWith('/collections/device-health')) {
            if (state.error) return route.fulfill({ status: 503, json: { message: 'Fixture unavailable' } });
            data = { devices: [{ id: 'fixture-device', label: 'Tanzania receiving phone', state: state.health, capture_ready: state.health === 'ready', last_contact_at: '2026-11-06T08:00:00Z' }], needs_attention: state.health === 'ready' ? 0 : 1, checked_at: '2026-11-06T08:08:00Z' };
        }
        if (path.endsWith('/collections/account-policies')) data = { accounts: [state.policy] };
        if (path.endsWith('/accounts/fixture-account/policy')) {
            const payload = route.request().postDataJSON(); state.saves.push(payload);
            if (state.stale) { state.stale = false; state.policy.version++; return route.fulfill({ status: 409, json: { reason: 'collection_policy_changed' } }); }
            state.policy = { ...state.policy, version: state.policy.version + 1, limits: Object.fromEntries(Object.entries(payload).filter(([key]) => key !== 'expected_version')) };
            data = state.policy;
        }
        await route.fulfill({ json: data });
    });
    await page.goto(fixture);
    await expect(page.getByRole('heading', { name: 'Incoming office payments' })).toBeVisible();
    return state;
}

test('phone outage gives recovery instructions and clears only after a fresh API check-in', async ({ page }) => {
    const state = await setup(page);
    const panel = page.getByRole('region', { name: 'Receiving phone health' });
    await panel.getByRole('button', { name: 'View phone details' }).click();
    await expect(panel.getByText('No recent check-in', { exact: true })).toBeVisible();
    await expect(panel.getByText('Charge and unlock the office phone.')).toBeVisible();
    await expect(page.getByText('Phone needs attention', { exact: true })).toBeVisible();
    state.error = true;
    await panel.getByRole('button', { name: 'Check again' }).click();
    await expect(panel.getByRole('alert')).toContainText('CRM could not refresh phone status');
    await expect(panel.getByText('No recent check-in', { exact: true })).toBeVisible();
    state.error = false; state.health = 'ready';
    await panel.getByRole('button', { name: 'Check again' }).click();
    await expect(panel.getByText('Receiving normally', { exact: true })).toBeVisible();
    await expect(panel.getByText('Charge and unlock the office phone.')).toHaveCount(0);
});

test('limits support explicit Unlimited and preserve edits after a stale version response', async ({ page }) => {
    const state = await setup(page);
    await page.getByRole('button', { name: 'Automation settings' }).click();
    await page.getByRole('button', { name: 'Configure limits' }).click();
    const day = page.getByRole('group', { name: 'Per day', exact: true });
    await day.getByLabel('Unlimited').uncheck();
    await day.getByLabel('Amount in TZS').fill('100000');
    state.stale = true;
    await page.getByRole('button', { name: 'Save limits', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Your entries are preserved');
    await expect(day.getByLabel('Amount in TZS')).toHaveValue('100000');
    await page.getByRole('button', { name: 'Reload latest limits' }).click();
    await page.getByRole('button', { name: 'Use this version for my next save' }).click();
    await page.getByRole('button', { name: 'Save limits', exact: true }).click();
    await expect(page.getByText('Limits saved.', { exact: true })).toBeVisible();
    expect(state.saves[1]).toEqual({ expected_version: 2, single_cap_minor: null, daily_cap_minor: 10000000, unreviewed_cap_minor: null, unverified_cap_minor: null });
});

test('sales can read recovery guidance and limits on mobile without a policy editing action', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page, 'sales');
    await page.getByRole('button', { name: 'View phone details' }).click();
    await expect(page.getByText('New payment SMS may be delayed.', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: 'Automation settings' }).click();
    await expect(page.getByRole('button', { name: 'Configure limits' })).toHaveCount(0);
    await expect(page.getByText('New payment SMS may be delayed.', { exact: false })).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
    expect(overflow).toBe(false);
    await page.screenshot({ path: 'output/manual-payment-phone-release-2026-11-06/phone-recovery-mobile.png', fullPage: true });
});
