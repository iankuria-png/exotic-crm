import { test, expect } from '@playwright/test';

// Isolated UI fixture (no Laravel / database):
// npx vite --config tests/browser/fixtures/auth-focus-vite.config.js
// PLAYWRIGHT_BASE_URL=http://127.0.0.1:5191 npx playwright test auth-focus-recheck.spec.js
const fixtureUrl = '/tests/browser/fixtures/auth-focus.html';
const clientId = 912345;
const user = {
    id: 77, name: 'Admin fixture', role: 'admin', status: 'active', assigned_market_ids: [1],
    access_policy_version: 'fixture-v1', home_path: '/', capabilities: { admin_dashboard: true, payments_workspace: true },
};
const client = {
    id: clientId, platform_id: 1, wp_post_id: 12345, wp_user_id: 123, client_type: 'escort',
    name: 'Focus test advertiser', phone_normalized: '254700000001', city: 'Nairobi CBD',
    profile_status: 'publish', lifecycle_state: 'active', needs_payment: false, notactive: false,
    platform: { id: 1, name: 'Local Kenya fixture', currency_code: 'KES', phone_prefix: '254', billing_method_policy: {} },
    deals: [], notes: [], payments: [], active_deal: null,
};

async function setup(page, { status = 200 } = {}) {
    await page.addInitScript((staff) => {
        // Seed once per tab so a logout redirect does not restore the session.
        if (sessionStorage.getItem('auth-focus-seeded')) return;
        sessionStorage.setItem('auth-focus-seeded', '1');
        localStorage.setItem('crm_token', 'isolated-browser-fixture');
        localStorage.setItem('crm_user', JSON.stringify(staff));
    }, user);
    const me = { calls: 0, status };
    await page.route('**/api/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        if (path === '/api/crm/me') {
            me.calls += 1;
            if (me.status !== 200) return route.fulfill({ status: me.status, json: { message: 'fixture failure' } });
            return route.fulfill({ json: { user } });
        }
        if (path === `/api/crm/clients/${clientId}`) return route.fulfill({ json: client });
        return route.fulfill({ json: { data: [], total: 0, score: 80, checks: [], components: [] } });
    });
    await page.goto(fixtureUrl);
    return me;
}

const heading = (page) => page.getByRole('heading', { name: 'Focus test advertiser', exact: true });

async function markWorkspace(page) {
    await page.evaluate(() => { document.querySelector('main').dataset.focusMarker = 'kept'; });
}

async function focusWindow(page, me) {
    const before = me.calls;
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect.poll(() => me.calls).toBeGreaterThan(before);
    await page.waitForTimeout(300);
}

test('returning to the tab re-checks access without rebuilding the workspace', async ({ page }) => {
    const me = await setup(page);
    await expect(heading(page)).toBeVisible();
    await markWorkspace(page);

    await focusWindow(page, me);

    await expect(heading(page)).toBeVisible();
    await expect(page.locator('main')).toHaveAttribute('data-focus-marker', 'kept');
});

test('a failed background re-check keeps the open workspace', async ({ page }) => {
    const me = await setup(page);
    await expect(heading(page)).toBeVisible();
    await markWorkspace(page);
    me.status = 503;

    await focusWindow(page, me);

    await expect(page.getByText('Access could not be checked')).toHaveCount(0);
    await expect(page.locator('main')).toHaveAttribute('data-focus-marker', 'kept');
});

test('a revoked session on re-check still signs the user out', async ({ page }) => {
    const me = await setup(page);
    await expect(heading(page)).toBeVisible();
    me.status = 401;

    await focusWindow(page, me);

    await expect(heading(page)).toHaveCount(0);
    await expect.poll(() => page.evaluate(() => localStorage.getItem('crm_token'))).toBeNull();
});

test('a failed first check offers Try again, which opens the workspace', async ({ page }) => {
    const me = await setup(page, { status: 503 });
    await expect(page.getByText('Access could not be checked')).toBeVisible();
    me.status = 200;

    await page.getByRole('button', { name: 'Try again' }).click();

    await expect(heading(page)).toBeVisible();
});
