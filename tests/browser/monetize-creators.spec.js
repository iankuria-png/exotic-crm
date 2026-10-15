import { test, expect } from '@playwright/test';

const overview = {
    summary: { attempts: 78, successful_payments: 21, completion_rate: 26.9, active: 21, failed: 34, pending: 23, review: 0, active_sellers: 12, expiring_sellers: 1 },
    providers: [], wallet_attribution: [], content: [], safety: [], setup: [], can_manage: true,
    totals: [], sales: { data: [], total: 0, last_page: 1 },
    activation: [{ currency: 'KES', revenue: 1500, list_value: 4500, subsidy: 3000 }],
    platforms: [{ id: 1, name: 'Kenya', currency_code: 'KES' }, { id: 2, name: 'Senegal', currency_code: 'CFA' }],
};
const stats = { currency: 'KES', earned_outstanding: 0, earned_spent: 500 };
const creators = [
    { id: 11, name: 'Kelly video call', platform_name: 'Kenya', currency: 'KES', state: 'active', expires_at: '2026-11-27T00:00:00Z', current_type: 'free', passes: 2, paid_passes: 1, pass_paid: 500, sales_count: 3, sales_gross: 1500, stats, pass: { id: 9 } },
    { id: 12, name: 'Nuria', platform_name: 'Kenya', currency: 'KES', state: 'expiring', expires_at: '2026-10-18T00:00:00Z', current_type: 'paid', passes: 1, paid_passes: 1, pass_paid: 1000, sales_count: 0, sales_gross: 0, stats: { ...stats, earned_spent: 0 }, pass: null },
    { id: 13, name: 'Awa', platform_name: 'Senegal', currency: 'CFA', state: 'ended', expires_at: null, current_type: null, passes: 1, paid_passes: 0, pass_paid: 0, sales_count: 3, sales_gross: 8700, stats: { currency: 'CFA', earned_outstanding: 8700, earned_spent: 0 }, pass: null },
];
const passes = [
    { id: 41, client: { id: 12, name: 'Nuria' }, platform_name: 'Kenya', status: 'active', type: 'paid', grant_source: 'wallet', duration_key: '1_month', currency: 'KES', list_amount: 1500, subsidy_amount: 500, paid_amount: 1000, payment_reference: 'MP-41', created_at: '2026-10-11T08:00:00Z', starts_at: '2026-10-11T08:00:00Z', expires_at: '2026-10-18T08:00:00Z' },
    { id: 40, client: { id: 11, name: 'Kelly video call' }, platform_name: 'Kenya', status: 'expired', type: 'paid', grant_source: 'wallet', duration_key: '2_weeks', currency: 'KES', list_amount: 500, subsidy_amount: 0, paid_amount: 500, payment_reference: 'MP-40', created_at: '2026-09-20T08:00:00Z', starts_at: '2026-09-20T08:00:00Z', expires_at: '2026-10-04T08:00:00Z' },
];

async function fixture(page) {
    const calls = { creators: [], passes: [] };
    await page.route('**/api/crm/settings/reporting-currency', route => route.fulfill({ json: { settings: { target_currency: 'USD', allow_user_override: true } } }));
    await page.route(/\/api\/crm\/monetization\/analytics/, route => route.fulfill({ json: { window: { currency: 'USD', currency_mode: 'flat', all_time: true }, totals: {}, prior_totals: null, points: [], markets: [] } }));
    await page.route(/\/api\/crm\/monetization\/creators\?/, route => {
        const params = new URL(route.request().url()).searchParams; calls.creators.push(params);
        const state = params.get('state');
        const rows = creators.filter(row => (!state || row.state === state) && (!params.get('search') || row.name.toLowerCase().includes(params.get('search').toLowerCase())));
        return route.fulfill({ json: { data: rows, total: rows.length, page: 1, per_page: 30, last_page: 1, reporting_currency: 'USD', counts: { all: 3, active: 1, expiring: 1, unclaimed: 0, held: 0, ended: 1 } } });
    });
    await page.route(/\/api\/crm\/monetization\/passes\?/, route => {
        const params = new URL(route.request().url()).searchParams; calls.passes.push(params);
        return route.fulfill({ json: { passes: { data: passes, total: 2, current_page: 1, last_page: 1 }, totals: [{ currency: 'KES', passes: 2, creators: 2, paid: 1500, list_value: 2000, subsidy: 500 }] } });
    });
    await page.route(/\/api\/crm\/monetization\?/, route => route.fulfill({ json: overview }));
    await page.goto('/tests/browser/fixtures/monetize-overview.html');
    await expect(page.getByRole('heading', { name: 'Selling-pass revenue' })).toBeVisible();
    return calls;
}

test('pass revenue links to the buyers and the creator directory filters, sorts and manages passes', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const calls = await fixture(page);

    await page.getByRole('button', { name: 'See who bought →' }).click();
    await expect(page.getByRole('button', { name: 'Pass purchases' })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('link', { name: 'Nuria' })).toBeVisible();
    await expect(page.getByText('MP-41')).toBeVisible();
    await expect(page.getByText('KES 1,500', { exact: true })).toBeVisible();
    expect(calls.passes.at(-1).get('pass_type')).toBe('paid');
    await page.getByLabel('Pass type').selectOption('all');
    await expect.poll(() => calls.passes.at(-1).get('pass_type')).toBe('all');

    await page.getByRole('button', { name: 'Creators', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Awa' })).toBeVisible();
    await page.getByRole('button', { name: /Ends within 7 days/ }).click();
    await expect.poll(() => calls.creators.at(-1).get('state')).toBe('expiring');
    await expect(page.getByRole('link', { name: 'Awa' })).toHaveCount(0);
    await page.getByRole('group', { name: 'Pass state' }).getByRole('button', { name: /^All/ }).click();
    await page.getByRole('button', { name: /Paid for passes/ }).click();
    await expect.poll(() => `${calls.creators.at(-1).get('sort')}:${calls.creators.at(-1).get('direction')}`).toBe('pass_paid:desc');
    await expect(page.getByRole('columnheader', { name: /Paid for passes/ })).toHaveAttribute('aria-sort', 'descending');
    await page.getByLabel('Search', { exact: true }).fill('nur');
    await expect.poll(() => calls.creators.at(-1).get('search')).toBe('nur');

    await page.getByRole('button', { name: 'Manage pass' }).first().click();
    await expect(page.getByRole('dialog', { name: 'Manage creator pass' })).toBeVisible();
    expect(errors).toEqual([]);
});
