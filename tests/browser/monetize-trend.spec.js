import { test, expect } from '@playwright/test';

const overview = {
    summary: { attempts: 75, successful_payments: 19, completion_rate: 25.3, active: 19, failed: 34, pending: 22, review: 0, active_sellers: 12, expiring_sellers: 1 },
    providers: [], wallet_attribution: [], activation: [], creators: [], content: [], safety: [], setup: [], can_manage: false,
    totals: [{ currency: 'CFA', gross_sales: 8700, average_sale: 2900, sales_count: 3, provider_fees: 0, credited: 8700 }],
    sales: { data: [], total: 0, last_page: 1 },
    platforms: [{ id: 1, name: 'Kenya', currency_code: 'KES' }, { id: 2, name: 'Senegal', currency_code: 'CFA' }],
};
const days = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'];
function analytics(params) {
    const native = params.get('currency_mode') === 'native' && params.get('platform_id') === '2';
    const currency = native ? 'CFA' : (params.get('reporting_currency') || 'USD');
    const scale = native ? 580 : 1;
    const points = days.map((label, i) => ({
        label, gross: (i * 3.1 + 2) * scale, creator_credit: (i * 2.5 + 1.6) * scale, pass_revenue: (i % 3) * 4 * scale, purchases: i + 1, attempts: i * 3 + 4, paid_attempts: i + 1,
        completion_rate: Math.round(((i + 1) / (i * 3 + 4)) * 1000) / 10, average_sale: 3 * scale, fx_unresolved: false,
        prior_gross: (6 - i) * 2 * scale, prior_creator_credit: (6 - i) * 1.6 * scale, prior_pass_revenue: 2 * scale, prior_purchases: 7 - i, prior_attempts: 10, prior_paid_attempts: 3, prior_completion_rate: 30, prior_average_sale: 2.5 * scale,
    }));
    return {
        window: { from: days[0], to: days[6], all_time: false, days: 7, bucket: 'day', currency_mode: native ? 'native' : 'flat', currency, native_fallback: params.get('currency_mode') === 'native' && !native, source_currencies: native ? ['CFA'] : ['CFA', 'KES'] },
        totals: { gross: 79.1 * scale, creator_credit: 63.7 * scale, pass_revenue: 24 * scale, purchases: 28, attempts: 91, paid_attempts: 28, completion_rate: 30.8, average_sale: 2.83 * scale, unresolved_currencies: native ? [] : ['XAF'], fx_as_of: '2026-10-15', fx_stale: false },
        prior_totals: { gross: 42 * scale, purchases: 28, attempts: 70, paid_attempts: 21, completion_rate: 30, pass_revenue: 14 * scale },
        deltas: { gross: 88.3, creator_credit: 70.1, pass_revenue: 71.4, purchases: 0, completion_rate: 0.8, average_sale: 13.2 },
        points,
        markets: native ? [{ platform_id: 2, name: 'Senegal', country: 'Senegal', gross: 79.1 * scale, purchases: 28, completion_rate: 30.8, pass_revenue: 24 * scale, share_percent: 100, delta_percent: 88.3, fx_unresolved: false, native_gross: { CFA: 79.1 * scale } }] : [
            { platform_id: 1, name: 'Kenya', country: 'Kenya', gross: 64.2, purchases: 22, completion_rate: 33.1, pass_revenue: 18, share_percent: 81.2, delta_percent: 95.4, fx_unresolved: false, native_gross: { KES: 8300 } },
            { platform_id: 2, name: 'Senegal', country: 'Senegal', gross: 14.9, purchases: 6, completion_rate: 23.1, pass_revenue: 6, share_percent: 18.8, delta_percent: -12.5, fx_unresolved: false, native_gross: { CFA: 8700 } },
        ],
    };
}

async function fixture(page) {
    const calls = [];
    await page.route('**/api/crm/settings/reporting-currency', route => route.fulfill({ json: { settings: { target_currency: 'USD', allow_user_override: true } } }));
    await page.route(/\/api\/crm\/monetization\/analytics/, route => { const params = new URL(route.request().url()).searchParams; calls.push(params); return route.fulfill({ json: analytics(params) }); });
    await page.route(/\/api\/crm\/monetization\?/, route => route.fulfill({ json: overview }));
    await page.goto('/tests/browser/fixtures/monetize-overview.html');
    await expect(page.getByRole('heading', { name: 'Monetize Performance Trend' })).toBeVisible();
    return calls;
}

test('overview trend converts to the reporting currency and switches metrics, buckets and markets', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const calls = await fixture(page);
    await expect(page.getByText('USD 79.1', { exact: true })).toBeVisible();
    await expect(page.getByText(/No FX rate cached for XAF/)).toBeVisible();
    await expect(page.getByText('By market · content sales in USD')).toBeVisible();
    await page.getByRole('button', { name: 'Checkout', exact: true }).click();
    await expect(page.getByText('+0.8 pts', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Weekly', exact: true }).click();
    await expect.poll(() => calls.at(-1)?.get('bucket')).toBe('week');
    await expect.poll(() => calls.at(-1)?.get('currency_mode')).toBe('flat');

    await page.getByRole('button', { name: 'Native', exact: true }).click();
    await expect(page.getByText(/This scope mixes CFA, KES/)).toBeVisible();
    await page.getByLabel('Market').selectOption('2');
    await expect(page.getByText('CFA 45,878', { exact: true })).toBeVisible();
    await expect(page.getByText('By market · content sales')).toHaveCount(0);
    expect(errors).toEqual([]);
});
