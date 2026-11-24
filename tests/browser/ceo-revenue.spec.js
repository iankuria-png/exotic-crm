import { test, expect } from '@playwright/test';

const money = (amount, count = 1) => ({ native: { USD: amount }, normalized_total: amount, normalized_currency: 'USD', payments_count: count, fx_coverage: { complete: true, missing_payments_count: 0 } });
const window = { from: '2026-10-25T00:00:00+03:00', to: '2026-11-23T12:00:00+03:00', days: 30, partial: true };
const prior_window = { from: '2026-09-25T00:00:00+03:00', to: '2026-10-24T12:00:00+03:00' };
const products = [['subscription', 'Subscription revenue', 67670.26, 4142, 9.3], ['contact_unlock', 'Contact unlocks', 1825.19, 459, 14.7], ['premium_content_sale', 'Content sales', 624.64, 84, 211.2], ['send_love', 'Gifts', 28.65, 6, 97.2], ['passes', 'Passes', 0, 0, null]].map(([key, label, amount, count, change_percent]) => ({ key, label, ...money(amount, count), share_percent: 100 * amount / 70148.74, change_percent, wallet_funded: money(key === 'passes' ? 4.44 : 0, key === 'passes' ? 1 : 0) }));
const site = (id, name, amount) => ({ platform_id: id, name, currency: 'USD', country: 'Kenya', ...money(amount, 500), today: money(400), week: money(400), change_percent: 12.4, targets: [{ period: 'weekly', target: 300, target_currency: 'USD', actual: money(400), percentage: 133.3 }] });
const markets = [{ key: 'kenya', country: 'Kenya', ...money(40000, 2200), change_percent: 13.5, today: money(800), week: money(800), platforms: [site(1, 'Kenya primary', 25000), site(2, 'Kenya secondary', 15000)] }, { key: 'ghana', country: 'Ghana', ...money(30148.74, 2491), change_percent: -4.3, today: money(801.24), week: money(801.24), platforms: [{ ...site(3, 'Ghana primary', 30148.74), country: 'Ghana' }] }];
const cash = { as_of: '2026-11-23T12:00:00+03:00', timezone: 'Africa/Nairobi', window, prior_window, selected: { ...money(70148.74, 4691), change_percent: 10.1 }, today: money(1601.24, 100), week: money(1601.24, 100), month: money(53484.36, 3586), products, markets, coverage: { unmapped_products: money(0, 0) } };

async function setup(page, state = 'ready') {
    const errors = []; page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (message) => { if (message.type() === 'error') console.log('Browser error:', message.text()); });
    const calls = []; const user = { id: 999, name: 'Executive Fixture', email: 'fixture@example.invalid', role: 'admin', is_ceo: true, status: 'active' };
    await page.addInitScript((u) => {
        localStorage.setItem('crm_token', 'isolated-ceo-fixture'); localStorage.setItem('crm_user', JSON.stringify(u));
        localStorage.setItem('exoticcrm.dashboard.widget_config', JSON.stringify({ ai_analyst: false }));
    }, user);
    await page.route('**/api/**', async (route) => {
        const url = new URL(route.request().url()); const path = url.pathname; calls.push(url.pathname + url.search);
        let body = { data: [], markets: [], priorities: [], events: [], summary: {}, totals: {}, settings: {} };
        if (path === '/api/crm/me') body = { user };
        if (path.endsWith('/settings/reporting-currency')) body = { settings: { target_currency: 'USD', allow_user_override: true }, currencies: ['USD', 'KES'] };
        if (path.endsWith('/my-markets')) body = [ { id: 1, name: 'Kenya primary', country: 'Kenya', currency_code: 'USD' }, { id: 2, name: 'Kenya secondary', country: 'Kenya', currency_code: 'USD' }, { id: 3, name: 'Ghana primary', country: 'Ghana', currency_code: 'USD' } ];
        if (path.endsWith('/ceo/summary')) body = { window: { from: '2026-10-25', to: '2026-11-23', target_currency: 'USD' }, metrics: { active_clients: { label: 'Active Subscribers', value: { count: 2140 } }, new_user_revenue: { label: 'New User Revenue', value: { normalized_amount: 11647.58, payments_count: 100, clients_count: 85, share_percent: 17.5 } }, existing_user_revenue: { label: 'Existing User Revenue', value: { normalized_amount: 55028.21, payments_count: 3000, clients_count: 2000, share_percent: 82.5 } } }, insights: [], customer_mix: { buckets: {} } };
        if (path.endsWith('/ceo/product-revenue')) {
            if (state === 'loading') await new Promise((r) => setTimeout(r, 2500));
            if (state === 'error') return route.fulfill({ status: 503, json: { message: 'Revenue temporarily unavailable.' } });
            body = structuredClone(cash);
            if (url.searchParams.has('platform_id')) { body.selected = money(25000); body.markets = [markets[0]]; }
            if (state === 'empty') { body.selected = money(0, 0); body.products = body.products.map((p) => ({ ...p, ...money(0, 0), share_percent: null, change_percent: null })); body.markets = []; }
            if (state === 'fx') { body.selected.normalized_total = null; body.selected.fx_coverage = { complete: false }; body.selected.native = { KES: 10000 }; body.products = body.products.map((p) => ({ ...p, normalized_total: null, share_percent: null })); }
            if (url.searchParams.get('analysis') === '1') {
                const key = url.searchParams.get('product') || 'all';
                const selectedProduct = key === 'all' ? { ...body.selected, label: 'All products' } : body.products.find((p) => p.key === key);
                const selected = structuredClone(selectedProduct);
                const prior = money(selected.normalized_total == null ? 15 : Math.round(selected.normalized_total / 1.1 * 100) / 100, selected.payments_count);
                if (state === 'prior-fx') { prior.normalized_total = null; prior.fx_coverage = { complete: false }; prior.native = { KES: 1234 }; }
                const bucket = url.searchParams.get('bucket') === 'auto' ? 'day' : url.searchParams.get('bucket');
                const first = selected.normalized_total == null ? null : Math.round(selected.normalized_total * .4 * 100) / 100;
                const second = first == null ? null : Math.round((selected.normalized_total - first) * 100) / 100;
                const counts = [Math.floor(selected.payments_count / 2), selected.payments_count - Math.floor(selected.payments_count / 2)];
                const points = [first, second].map((value, index) => ({ label: ['2026-11-22', '2026-11-23'][index], value, payments_count: counts[index], prior_value: prior.normalized_total == null ? null : prior.normalized_total / 2, prior_payments_count: counts[index], average_ticket: value == null ? null : counts[index] > 0 ? Math.round(value / counts[index] * 100) / 100 : 0, prior_average_ticket: prior.normalized_total == null ? null : 1 }));
                const peak = { window: { from: '2026-10-25', to: '2026-11-23' }, timezone: 'Africa/Nairobi', target_currency: 'USD', cells: Array.from({ length: 168 }, (_, i) => ({ dow: Math.floor(i / 24), hour: i % 24, value: i === 11 ? selected.normalized_total : 0, payments_count: i === 11 ? selected.payments_count : 0 })), weekdays: [{ dow: 0, label: 'Monday', value: selected.normalized_total, payments_count: selected.payments_count, average_ticket: 1, occurrences: 5, active_days: 1, gap_count: 4, gap_dates: [] }] };
                body.collections = { product: key, label: selectedProduct.label, selected, prior, average_ticket: selected.normalized_total == null ? null : selected.payments_count ? Math.round(selected.normalized_total / selected.payments_count * 100) / 100 : 0, change_percent: selected.normalized_total == null || prior.normalized_total == null ? null : 10, wallet_funded: selectedProduct.wallet_funded || money(4.44), trend: { bucket: bucket || 'day', window: { from: '2026-10-25', to: '2026-11-23', target_currency: 'USD', timezone: 'Africa/Nairobi' }, points }, peak_hours: peak };
                if (state === 'slow' && key === 'send_love') await new Promise((r) => setTimeout(r, 1800));
            }

        }
        if (path.endsWith('/ceo/revenue-trend')) body = { window: { target_currency: 'USD' }, bucket: url.searchParams.get('bucket') || 'day', points: [{ label: '2026-11-22', value: 700, prior_value: 650, payments_count: 50 }, { label: '2026-11-23', value: 900, prior_value: 700, payments_count: 60 }] };
        if (path.endsWith('/ceo/market-pie')) body = { total: 66674.4, markets: [], channels: [] };
        if (path.endsWith('/ceo/recent-payments')) body = { payments: [], agents: [{ id: 7, name: 'Agent fixture' }], channels: [{ key: 'all', label: 'All channels' }] };
        if (path.endsWith('/ceo/market-health')) body = { markets: [], summary: {} };
        if (path.endsWith('/country-revenue')) body = [];
        if (path.includes('/country-performance/')) body = { market: { name: 'Kenya primary' }, summary: { current_revenue_breakdown: { USD: 25000 }, previous_revenue_breakdown: { USD: 20000 }, current_revenue_normalized: 25000, previous_revenue_normalized: 20000 }, trend: { points: [], revenue_breakdown: {}, normalized_total: 25000 }, insights: {}, user_summary: {}, availability: {} };
        if (path.includes('/forecast/baseline')) body = { state: 'cold', baseline: { levers: {} } };
        await route.fulfill({ json: body });
    });
    await page.goto('/tests/browser/fixtures/ceo-revenue.html', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body')).toHaveCSS('font-family', /IBM Plex Sans/);
    return { calls, errors };
}

for (const width of [1440, 1024, 768, 390, 320]) test(`actual dashboard cash, source tabs and country expansion at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    const { errors } = await setup(page);
    const card = page.getByRole('article', { name: 'Collected revenue' });
    await expect(card).toContainText('70,148.74'); await expect(card).toContainText('1,601.24');
    if (width >= 1024) {
        const revenueBox = await card.boundingBox();
        const compactCards = await page.locator('.ceo-metric-secondary .crm-kpi').all();
        expect(compactCards).toHaveLength(4);
        const compactBox = await compactCards[0].boundingBox();
        expect(compactBox.x).toBeGreaterThan(revenueBox.x + revenueBox.width);
        expect(compactBox.height).toBeLessThan(revenueBox.height * 0.65);
    }
    await page.screenshot({ path: `output/playwright/ceo-revenue-implementation/overview-${width}.png` });
    if (width >= 1024) await page.locator('.ceo-metric-strip').screenshot({ path: `output/playwright/ceo-revenue-implementation/metrics-${width}.png` });
    await card.getByRole('button', { name: 'Collected revenue · open revenue sources' }).click();
    const sources = page.getByRole('tab', { name: 'Where revenue comes from' });
    await expect(sources).toBeFocused(); await expect(sources).toHaveAttribute('aria-selected', 'true');
    const panel = page.getByRole('tabpanel', { name: 'Where revenue comes from' });
    await expect(panel.getByRole('row', { name: /Content sales/ })).toContainText('624.64');
    await expect(panel.getByRole('row', { name: /^Gifts/ })).toContainText('28.65');
    await expect(panel.getByRole('row', { name: /^Subscription revenue/ })).toContainText('4,142');
    await expect(panel.getByRole('row', { name: /^Passes/ })).toContainText('4.44');
    await expect(page.getByRole('link', { name: 'View payments' })).toBeVisible();
    await sources.press('Home'); await expect(page.getByRole('tab', { name: 'Trend', exact: true })).toBeFocused();
    await page.getByRole('tab', { name: 'Trend', exact: true }).press('End'); await expect(sources).toBeFocused();
    await page.screenshot({ path: `output/playwright/ceo-revenue-implementation/sources-${width}.png` });
    const country = page.getByRole('button', { name: /Kenya.*2 websites/ }); await country.click();
    await expect(page.getByText('133.3% attained', { exact: false }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Open website performance →' }).first().click();
    await expect(page.getByText('Website subscription performance', { exact: false })).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: `output/playwright/ceo-revenue-implementation/width-${width}.png`, fullPage: true });
    expect(errors).toEqual([]);
});

test('source export, scope and retained controls survive sources and Admin roundtrip', async ({ page }) => {
    const { calls, errors } = await setup(page);
    await page.getByRole('tab', { name: 'Trend', exact: true }).click();
    await page.getByRole('button', { name: 'Chart options', exact: true }).click();
    await page.getByRole('button', { name: 'Weekly', exact: true }).first().click();
    await page.getByRole('tab', { name: 'Where revenue comes from' }).click();
    const download = page.waitForEvent('download'); await page.getByRole('button', { name: /Export CSV/ }).click();
    expect((await download).suggestedFilename()).toBe('revenue-sources.csv');
    await page.getByRole('tab', { name: 'Trend', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Weekly', exact: true }).first()).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('tab', { name: 'Where revenue comes from' }).click();
    await page.getByRole('combobox', { name: 'Market scope', exact: true }).selectOption('1');
    await expect.poll(() => calls.some((c) => c.includes('product-revenue') && c.includes('platform_id=1'))).toBe(true);
    await page.getByRole('button', { name: 'Custom', exact: true }).click();
    await page.getByLabel('From', { exact: true }).fill('2026-11-01');
    await page.getByLabel('To', { exact: true }).fill('2026-11-20');
    await expect.poll(() => calls.some((c) => c.includes('product-revenue') && c.includes('from=2026-11-01') && c.includes('to=2026-11-20'))).toBe(true);
    // Recent payments load once their section scrolls near the viewport.
    await page.getByRole('combobox', { name: 'Payment agent', exact: true }).scrollIntoViewIfNeeded();
    await page.getByRole('combobox', { name: 'Payment agent', exact: true }).selectOption('7');
    await expect.poll(() => calls.some((c) => c.includes('recent-payments') && c.includes('agent_id=7'))).toBe(true);
    await page.getByRole('button', { name: 'Open forecast', exact: true }).click();
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByPlaceholder('Investigate Ghana decline and assign recovery owner').fill('Retained CEO draft');
    await page.getByRole('button', { name: /Admin view/ }).first().click();
    await page.getByRole('button', { name: 'Switch to CEO view' }).click();
    await expect(page.getByRole('tab', { name: 'Where revenue comes from' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('combobox', { name: 'Payment agent', exact: true })).toHaveValue('7');
    await expect(page.getByLabel('From', { exact: true })).toHaveValue('2026-11-01');
    await expect(page.getByPlaceholder('Investigate Ghana decline and assign recovery owner')).toHaveValue('Retained CEO draft');
    expect(errors).toEqual([]);
});

for (const state of ['loading', 'error', 'empty', 'fx']) test(`actual dashboard ${state} state and reduced motion`, async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await setup(page, state);
    if (state === 'loading') await expect(page.getByRole('article', { name: 'Collected revenue' })).toContainText('Loading recorded revenue');
    await page.getByRole('tab', { name: 'Where revenue comes from' }).click();
    const panel = page.getByRole('tabpanel', { name: 'Where revenue comes from' });
    if (state === 'error') { await expect(panel.getByRole('alert')).toContainText('Revenue temporarily unavailable'); await expect(panel.getByRole('button', { name: 'Try again' })).toBeVisible(); }
    if (state === 'empty') await expect(panel).toContainText('No external cash payments');
    if (state === 'fx') { await expect(panel).toContainText('Some payments have no cached FX'); await expect(panel).toContainText('FX unavailable'); }
});


async function chooseProduct(page, label, key) {
    const select = page.getByRole('combobox', { name: 'Revenue product', exact: true });
    if (await select.isVisible()) await select.selectOption(key);
    else await page.getByRole('radio', { name: label, exact: true }).click();
}

for (const width of [1440, 1024, 768, 390, 320]) test(`revenue products and progressive chart disclosure at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const { calls, errors } = await setup(page);
    const section = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Revenue collections', exact: true }) }).first();
    const options = page.getByRole('button', { name: 'Chart options', exact: true });
    await expect(options).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByRole('group', { name: 'Trend metric', exact: true })).toBeHidden();
    await chooseProduct(page, 'Content sales', 'premium_content_sale');
    await expect(section.locator('.collections-value')).toContainText('624.64');
    await expect.poll(() => calls.some((c) => c.includes('analysis=1') && c.includes('product=premium_content_sale'))).toBe(true);
    await options.click();
    await page.getByRole('group', { name: 'Trend metric', exact: true }).getByRole('button', { name: 'Payments', exact: true }).click();
    await page.getByRole('button', { name: 'Weekly', exact: true }).click();
    await options.click();
    await expect(page.getByRole('group', { name: 'Trend metric', exact: true })).toBeHidden();
    await chooseProduct(page, 'Gifts', 'send_love');
    await expect(section.locator('.collections-value')).toContainText('28.65');
    await page.getByRole('tab', { name: 'Peak hours', exact: true }).click();
    await expect(page.getByTestId('peak-hours-cell')).toHaveCount(168);
    await page.getByRole('button', { name: 'Monday 11:00–12:00 revenue summary' }).click();
    await expect(page.getByRole('dialog')).toContainText('28.65');
    await expect(page.getByRole('dialog')).not.toContainText('Best sales member');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('tab', { name: 'Customer mix', exact: true }).click();
    await expect(page.getByText('Subscription customer mix.', { exact: true })).toBeVisible();
    await expect(page.getByRole('radiogroup', { name: 'Revenue product' })).toHaveCount(0);
    await page.getByRole('tab', { name: 'Trend', exact: true }).click();
    await expect(section.locator('.collections-value')).toContainText('28.65');
    await options.click();
    await expect(page.getByRole('group', { name: 'Trend metric', exact: true }).getByRole('button', { name: 'Payments', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Weekly', exact: true }).press('Escape');
    await expect(options).toBeFocused();
    await chooseProduct(page, 'Passes', 'passes');
    await expect(section).toContainText('4.44');
    await expect(section.getByRole('tabpanel', { name: 'Trend', exact: true })).toContainText('No external cash payments');
    await chooseProduct(page, 'Subscriptions', 'subscription');
    await expect(section.locator('.collections-value')).toContainText('67,670.26');
    await chooseProduct(page, 'Contact unlocks', 'contact_unlock');
    await expect(section.locator('.collections-value')).toContainText('1,825.19');
    await chooseProduct(page, 'All products', 'all');
    await expect(section.locator('.collections-value')).toContainText('70,148.74');
    if (width >= 640) {
        const radio = page.getByRole('radio', { name: 'All products', exact: true });
        await radio.focus(); await radio.press('End');
        await expect(page.getByRole('radio', { name: 'Passes', exact: true })).toHaveAttribute('aria-checked', 'true');
        await page.getByRole('radio', { name: 'Passes', exact: true }).press('Home');
    }
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await expect(section.locator('.collections-value')).toContainText('70,148.74');
    await options.click();
    await page.getByRole('group', { name: 'Trend metric', exact: true }).getByRole('button', { name: 'Revenue', exact: true }).click();
    await options.click();
    await expect(section.locator('.collections-chart-note')).toContainText('Revenue · USD');
    await section.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `output/playwright/ceo-revenue-collections/collections-${width}.png` });
    expect(errors).toEqual([]);
});

test('product switching does not show stale cash and missing prior FX never becomes zero', async ({ page }) => {
    await setup(page, 'slow');
    await chooseProduct(page, 'Gifts', 'send_love');
    await expect(page.locator('.collections-summary')).toContainText('Loading gifts');
    await expect(page.locator('.collections-summary')).not.toContainText('70,148.74');
    await expect(page.locator('.collections-value')).toContainText('28.65');
    await chooseProduct(page, 'Content sales', 'premium_content_sale');
    await expect(page.locator('.collections-value')).toContainText('624.64');
});

for (const fxState of ['prior-fx', 'fx']) test(`${fxState} and reduced motion keep cash gaps explicit`, async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await setup(page, fxState);
    if (fxState === 'prior-fx') await expect(page.locator('.collections-summary-comparison')).toContainText('FX unavailable');
    await expect(page.getByRole('tabpanel', { name: 'Trend', exact: true })).toContainText('Missing amounts appear as gaps');
    await expect(page.locator('.collections-value')).toContainText(fxState === 'fx' ? 'FX unavailable' : '70,148.74');
    await expect(page.locator('.collections-summary-comparison')).not.toContainText('+10.0%');
});

test('first screen makes one revenue request and defers below-the-fold sections', async ({ page }) => {
    const { calls, errors } = await setup(page);
    const card = page.getByRole('article', { name: 'Collected revenue' });
    await expect(card).toContainText('70,148.74');
    await expect(page.locator('.collections-value')).toContainText('70,148.74');
    await page.waitForTimeout(500);
    const revenueCalls = calls.filter((c) => c.includes('/ceo/product-revenue'));
    expect(revenueCalls).toHaveLength(1);
    expect(revenueCalls[0]).toContain('analysis=1');
    expect(calls.some((c) => c.includes('/ceo/agent-performance'))).toBe(false);
    expect(calls.some((c) => c.includes('/reports/summary'))).toBe(false);
    // Switching the chart's product keeps the cards' figures and adds one request.
    await chooseProduct(page, 'Content sales', 'premium_content_sale');
    await expect(page.locator('.collections-value')).toContainText('624.64');
    await expect(card).toContainText('70,148.74');
    await page.getByRole('heading', { name: 'Revenue by Package', exact: true }).scrollIntoViewIfNeeded();
    await expect.poll(() => calls.some((c) => c.includes('/reports/summary'))).toBe(true);
    await expect.poll(() => calls.some((c) => c.includes('/ceo/recent-payments'))).toBe(true);
    await page.getByRole('heading', { name: 'Agent Portfolio Performance', exact: true }).scrollIntoViewIfNeeded();
    await expect.poll(() => calls.some((c) => c.includes('/ceo/agent-performance'))).toBe(true);
    expect(errors).toEqual([]);
});
