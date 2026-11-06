import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';

// Use the exact isolated production bundle, with every request fulfilled locally.
// This runs without a web server or access to any CRM account.
const release = path.resolve(process.env.NATIVE_SCORECARD_BUILD || 'output/native-scorecard-2026-11-06/release-source/public/build');
const manifest = JSON.parse(fs.readFileSync(path.join(release, 'manifest.json'), 'utf8'))['resources/js/app.jsx'];
const compare = (key, current, prior) => ({ key, currency: key, current, prior, delta: current - prior, delta_percent: prior ? (current - prior) / prior * 100 : null, daily: current / 7, prior_daily: prior / 7 });
const kes = compare('KES', 14000, 7000);
const ghs = compare('GHS', 700, 1400);
const usd = compare('USD', 35.25, 0);
const market = { platform_id: 1, name: 'Kenya', currency: 'USD', current: 140, prior: 70, delta: 70 };
const body = {
    version: 'executive_scorecard_v3', headline: 'Revenue grew across the portfolio.',
    period: { label: 'Week 44', display: '26 Oct–1 Nov 2026', prior_label: 'Week 43' },
    scorecards: [
        { key: 'revenue', label: 'Revenue', current: 200, prior: 100, currency: 'USD', unit: 'money', delta_percent: 100, status: 'good' },
        { key: 'average_daily_revenue', label: 'Daily average', current: 28.57, prior: 14.29, currency: 'USD', unit: 'money', delta_percent: 100, status: 'good' },
        { key: 'payments_count', label: 'Payments', current: 10, prior: 7, unit: 'count', delta_percent: 42.9 },
        { key: 'lost_value_to_churn', label: 'Lost to churn', current: 80, currency: 'USD', unit: 'money', prior: null },
    ],
    native_reporting: { status: 'ready', source: 'historical_read', captured_at: '2026-11-06T08:00:00Z', currencies: [ghs, kes, usd], markets: [{ platform_id: 1, name: 'Kenya', amounts: [kes, usd] }], members: [{ user_id: 3, amounts: [kes, usd] }] },
    market_movement: { growing: [market], declining: [] },
    payment_recovery: { recovered_value: 30, recovered_value_display: 'USD 30', recovered_value_breakdown: { KES: 4000, CFA: 1500 }, lost_value: 0, lost_value_breakdown: {} },
    customer_movement: { lost_value_to_churn: 80, lost_value_to_churn_display: 'USD 80', lost_value_to_churn_currency: 'USD' },
    team_execution: { members: [{ user_id: 3, name: 'Test Agent', currency: 'USD', revenue: 140, revenue_delta: 70 }] },
};

async function open(page, overrides = {}) {
    await page.addInitScript(() => {
        localStorage.setItem('crm_token', 'local-fixture-token');
        localStorage.setItem('crm_user', JSON.stringify({ id: 1, name: 'Fixture Admin', role: 'admin' }));
        sessionStorage.setItem('crm_session_token', 'local-fixture-session');
        window.__copiedMarkdown = '';
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: async (text) => { window.__copiedMarkdown = text; } } });
        window.print = () => { window.__printCalled = true; };
    });
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/build/')) {
            const name = url.pathname.slice('/build/'.length);
            return route.fulfill({ body: fs.readFileSync(path.join(release, name)), contentType: name.endsWith('.js') ? 'text/javascript' : name.endsWith('.css') ? 'text/css' : 'image/png' });
        }
        if (url.pathname.startsWith('/api/')) {
            let payload = { data: [] };
            if (url.pathname.endsWith('/me')) payload = { user: { id: 1, name: 'Fixture Admin', role: 'admin' } };
            if (url.pathname.includes('/briefings/shared/')) payload = { audience: 'ceo', scope: { org_wide: true }, generated_at: '2026-11-02T08:00:00Z', body: { ...body, ...overrides } };
            if (url.pathname.endsWith('/platforms')) payload = { platforms: [{ id: 1, name: 'Kenya' }] };
            return route.fulfill({ json: payload });
        }
        if (url.pathname.startsWith('/b/')) return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${manifest.css.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app"></div><script type="module" src="/build/${manifest.file}"></script></body></html>` });
        return route.fulfill({ body: '', status: 404 });
    });
    await page.goto('/b/local-fixture');
    await expect(page.getByRole('heading', { name: body.headline })).toBeVisible();
}

for (const width of [320, 390, 1440]) {
    test(`currency toggle and export at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await open(page);
        const native = page.getByRole('button', { name: 'Native currencies', exact: true });
        await expect(page.getByRole('button', { name: 'Reporting USD', exact: true })).toHaveAttribute('aria-pressed', 'true');
        await native.focus();
        await page.keyboard.press('Enter');
        await expect(native).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByText('GHS revenue', { exact: true })).toBeVisible();
        await expect(page.getByText('-50%', { exact: true }).first()).toHaveClass(/text-rose-700/);
        await page.getByLabel('Revenue currency', { exact: true }).selectOption('USD');
        await expect(page.getByText('USD 35.25', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Historical payments read', { exact: false })).toBeVisible();
        await page.getByLabel('Revenue currency', { exact: true }).selectOption('KES');
        await expect(page.getByText('KES revenue', { exact: true })).toBeVisible();
        await expect(page.getByText('KES 14,000', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('KES 7,000 previous', { exact: true }).first()).toBeVisible();
        await expect(page.getByText('Lost to churn · estimate', { exact: true }).first()).toBeVisible();
        await expect(page.locator('#movement')).toContainText('USD 80');
        await expect(page.locator('#movement')).not.toContainText('NaN');
        await expect(page.locator('#markets')).toContainText('USD 35.25');
        await expect(page.locator('#recovery')).toContainText('KES 4,000 / CFA 1,500');
        await page.getByRole('button', { name: 'Copy markdown', exact: true }).click();
        const markdown = await page.evaluate(() => window.__copiedMarkdown);
        expect(markdown).toContain('Currency view: Native currencies');
        expect(markdown).toContain('| GHS | GHS 700 | GHS 1,400 |');
        expect(markdown).toContain('KES 14,000 / USD 35.25');
        expect(markdown).toContain('KES 4,000 / CFA 1,500');
        await page.getByRole('button', { name: 'Generate PDF', exact: true }).click();
        expect(await page.evaluate(() => window.__printCalled)).toBe(true);
        await page.emulateMedia({ media: 'print' });
        await expect(native).toBeHidden();
        await expect(page.getByText('KES revenue', { exact: true })).toBeVisible();
        await page.emulateMedia({ media: 'screen' });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.screenshot({ path: `output/native-scorecard-2026-11-06/native-${width}.png`, fullPage: true });
        await page.getByRole('button', { name: 'Reporting USD', exact: true }).click();
        await expect(page.getByLabel('Revenue currency', { exact: true })).toHaveCount(0);
        await expect(page.getByText('Revenue', { exact: true }).first()).toBeVisible();
    });
}

test('empty payments and unavailable archive are explicit', async ({ page }) => {
    await open(page, { native_reporting: { status: 'ready', source: 'snapshot', currencies: [], markets: [], members: [] } });
    await page.getByRole('button', { name: 'Native currencies', exact: true }).click();
    await expect(page.getByText('No reportable payments in either week.', { exact: false })).toBeVisible();
    await page.reload();
    await open(page, { native_reporting: { status: 'unavailable' } });
    await expect(page.getByRole('button', { name: 'Native currencies', exact: true })).toBeDisabled();
    await expect(page.getByText('Native amounts are unavailable for this archived report.', { exact: false })).toBeVisible();
});


test('large native amounts and unresolved currency labels fit a small phone', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 1000 });
    const large = compare('TZS', 15241000, 13296010);
    const unresolved = { ...compare('unresolved:1:CFA', 19924999.99, 0), currency: 'CFA (Unassigned market with a long name)', unresolved: true };
    await open(page, { native_reporting: { status: 'ready', source: 'snapshot', currencies: [large, unresolved], markets: [], members: [] } });
    await page.getByRole('button', { name: 'Native currencies', exact: true }).click();
    await expect(page.getByText('TZS 15,241,000', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByLabel('Revenue currency', { exact: true }).selectOption(unresolved.key);
    await expect(page.getByText(`${unresolved.currency} 19,924,999.99`, { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
