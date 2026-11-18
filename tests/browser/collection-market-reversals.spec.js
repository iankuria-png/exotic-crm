import { test, expect } from '@playwright/test';

async function setup(page) {
    const staff = { id: 7, name: 'Office fixture', role: 'admin', status: 'active', assigned_market_ids: [3, 6] };
    await page.addInitScript(staff => {
        localStorage.setItem('crm_token', 'isolated-market-fixture');
        localStorage.setItem('crm_user', JSON.stringify(staff));
    }, staff);
    const requests = [];
    await page.route('**/api/**', async route => {
        const url = new URL(route.request().url());
        const path = url.pathname;
        const market = url.searchParams.get('platform_id');
        requests.push({ path, market });
        let data = { data: [], total: 0 };
        if (path === '/api/crm/me') data = { user: staff };
        else if (path.endsWith('/collections/capabilities')) data = { enabled: true };
        else if (path.endsWith('/collections/account-policies')) data = { accounts: [] };
        else if (path.endsWith('/collections/claim-checks')) data = { accounts: [] };
        else if (path.endsWith('/collections/device-health')) data = { devices: [] };
        else if (path.endsWith('/collections/receipts')) {
            const gh = { id: 30, account_label: 'Ghana office MTN MoMo wallet', reference: '94000000001', currency: 'GHS', principal_minor: 38000, funds_state: 'disputed', flagged_at: new Date().toISOString(), service_active: true, reviewed: null };
            const tz = { id: 60, account_label: 'TZ office M-PESA wallet', reference: 'TZFIXTURE01', currency: 'TZS', principal_minor: 3500000, funds_state: 'reversed', flagged_at: new Date().toISOString(), service_active: false, reviewed: { decision: 'Keep service active' } };
            data = { data: [], total: 0, reversals: market === '3' ? [gh] : market === '6' ? [tz] : [gh, tz] };
        } else if (path.endsWith('/receipts/30')) return route.fulfill({ status: 503, json: { message: 'Fixture drawer unavailable' } });
        await route.fulfill({ json: data });
    });
    await page.goto('/tests/browser/fixtures/collection-markets.html');
    await expect(page.getByRole('combobox', { name: 'Receiving market', exact: true })).toBeVisible();
    return requests;
}

test('reversals start collapsed, open by keyboard and keep review actions inside', async ({ page }) => {
    await setup(page);
    const summary = page.locator('summary').filter({ hasText: 'Reversed & held payments' });
    const details = summary.locator('..');
    await expect(summary).toContainText('1 to review');
    await expect(summary).toContainText('1 reviewed');
    await expect(details).not.toHaveAttribute('open', '');
    await expect(page.getByRole('button', { name: 'Review payment 94000000001' })).toBeHidden();
    await page.screenshot({ path: '../reversals-collapsed-desktop.png', fullPage: true });
    await summary.focus();
    await page.keyboard.press('Enter');
    await expect(details).toHaveAttribute('open', '');
    await expect(summary).toContainText('Hide details');
    await expect(page.getByText('Ghana office MTN MoMo wallet', { exact: true })).toBeVisible();
    await page.screenshot({ path: '../reversals-expanded-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Review payment 94000000001' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
});

test('market changes scope all requests, clear prior reversals and collapse details again', async ({ page }) => {
    const requests = await setup(page);
    const selector = page.getByRole('combobox', { name: 'Receiving market', exact: true });
    const summary = page.locator('summary').filter({ hasText: 'Reversed & held payments' });
    await summary.click();
    await expect(page.getByText('TZFIXTURE01', { exact: true })).toBeVisible();
    await selector.selectOption('3');
    await expect(summary).toBeVisible();
    await expect(summary.locator('..')).not.toHaveAttribute('open', '');
    await summary.click();
    await expect(page.getByText('94000000001', { exact: true })).toBeVisible();
    await expect(page.getByText('TZFIXTURE01', { exact: true })).toHaveCount(0);
    await expect(page.getByText('On hold', { exact: false })).toBeVisible();
    for (const endpoint of ['receipts', 'account-policies', 'claim-checks', 'device-health']) {
        expect(requests.some(r => r.path.endsWith(`/collections/${endpoint}`) && r.market === '3')).toBeTruthy();
    }
    await selector.selectOption('6');
    await expect(summary).toContainText('All reviewed');
    await expect(summary.locator('..')).not.toHaveAttribute('open', '');
    await summary.click();
    await expect(page.getByText('TZFIXTURE01', { exact: true })).toBeVisible();
    await expect(page.getByText('94000000001', { exact: true })).toHaveCount(0);
});

test('collapsed and expanded reversals fit a phone with long reviewed text', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page);
    await page.locator('summary').filter({ hasText: 'Reversed & held payments' }).click();
    const review = page.getByRole('button', { name: 'Review payment 94000000001' });
    await expect(review).toBeVisible();
    await page.screenshot({ path: '../reversals-expanded-mobile.png', fullPage: true });
    expect((await review.boundingBox()).height).toBeGreaterThanOrEqual(44);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});
