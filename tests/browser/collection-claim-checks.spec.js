import { test, expect } from '@playwright/test';

// Synthetic Ghana claim checks only. Run against the isolated collection fixture:
// PLAYWRIGHT_BASE_URL=http://127.0.0.1:5182 npx playwright test tests/browser/collection-claim-checks.spec.js
const account = (claims, extra = {}) => ({ account_id: 'gh', label: 'Ghana office MoMo wallet', currency: 'GHS', checked_from: '2026-11-04T09:00:00Z', grace_minutes: 30, complete: true,
    counts: { verified: 41, pending: 1, no_receipt: claims.filter(c => c.status === 'no_receipt').length, short_paid: claims.filter(c => c.status === 'short_paid').length, receipt_reused: claims.filter(c => c.status === 'receipt_reused').length, reversed: 0 },
    claims, ...extra });
const typo = { status: 'no_receipt', payment_id: 44654, amount: '230.00', currency: 'GHS', claimed_at: '2026-11-18T00:57:47Z', typed_reference: '9406979141',
    client: { id: 9, name: 'Example Profile G', wp_post_id: 3 }, deal: { id: 5, status: 'active', plan_type: 'premium' }, receipt: null,
    suggestion: { score: 3, reasons: ['transaction_id_one_digit_off', 'same_amount'], receipt: { id: 77, reference: '94069791412', principal_minor: 23000, currency: 'GHS', funds_state: 'observed', paid_at: '2026-11-18T00:45:00Z', payer_name: 'EXAMPLE PAYER' } } };
const fake = { status: 'no_receipt', payment_id: 32897, amount: '10000.00', currency: 'GHS', claimed_at: '2026-11-17T16:35:03Z', typed_reference: '2580', client: null, deal: null, receipt: null, suggestion: null };
const reused = { status: 'receipt_reused', payment_id: 21768, amount: '380.00', currency: 'GHS', claimed_at: '2026-11-16T10:00:00Z', typed_reference: '88128622191',
    client: { id: 10, name: 'Example Profile J', wp_post_id: 4 }, deal: { id: 6, status: 'active', plan_type: 'vip' },
    receipt: { id: 78, reference: '88128622191', principal_minor: 38000, currency: 'GHS', funds_state: 'observed', paid_at: '2026-11-16T09:50:00Z', claim_count: 2, claimed_total_minor: 76000 }, suggestion: null };

async function setup(page, { claims = [typo, fake, reused], accounts = null, failLink = false } = {}) {
    const state = { mutations: [], linked: false };
    const staff = { id: 7, name: 'Fixture manager', role: 'admin', status: 'active', assigned_market_ids: [3] };
    await page.addInitScript(user => { localStorage.setItem('crm_token', 'isolated-claims-fixture'); localStorage.setItem('crm_user', JSON.stringify(user)); }, staff);
    if (process.env.CLAIMS_DEBUG) { page.on('pageerror', e => console.log('PAGEERROR', e.message.slice(0, 500))); page.on('console', m => m.type() === 'error' && console.log('CONSOLE', m.text().slice(0, 300))); }
    await page.route('**/api/**', async route => {
        const request = route.request(); const path = new URL(request.url()).pathname;
        if (request.method() !== 'GET') state.mutations.push({ path, body: request.postDataJSON() });
        let data = { data: [], total: 0 };
        if (path === '/api/crm/me') data = { user: staff };
        else if (path.endsWith('/collections/capabilities')) data = { enabled: true, entitlement_writes_enabled: false };
        else if (path.endsWith('/collections/device-health')) data = { checked_at: '2026-11-18T09:00:00Z', summary: { label: 'Receiving normally' }, devices: [] };
        else if (path.endsWith('/collections/account-policies')) data = { accounts: [] };
        else if (path.endsWith('/collections/claim-checks')) data = { accounts: accounts ?? [account(state.linked ? claims.filter(c => c.payment_id !== typo.payment_id) : claims)] };
        else if (path.endsWith('/receipts/77/aliases')) {
            if (failLink) return route.fulfill({ status: 403, json: { message: 'Forbidden' } });
            state.linked = true; data = { saved: true };
        }
        await route.fulfill({ json: data });
    });
    await page.goto('/tests/browser/fixtures/collection-payments.html');
    return state;
}

test('flagged Ghana claims are summarised, filterable and never change a subscription by themselves', async ({ page }) => {
    const state = await setup(page);
    const panel = page.getByRole('region', { name: 'Customer payment claims to check' });
    await expect(panel).toContainText('3 to check against the receiving phone');
    await expect(panel).toContainText('Flag only — subscriptions stay active');
    await panel.getByRole('button', { name: 'Review claims' }).click();
    await expect(panel).toContainText('41 confirmed · 1 checking');
    await expect(panel).toContainText('No matching receipt');
    await expect(panel).toContainText('Likely receipt 94069791412 · GHS 230 from EXAMPLE PAYER');
    await expect(panel).toContainText('ID one digit off · Same amount');
    await expect(panel).toContainText('Receipt used twice');
    await expect(panel).toContainText('claimed 2× for GHS 760');
    await expect(panel).toContainText('No likely receipt was found within 48 hours');
    if (process.env.CLAIMS_SHOT) await page.screenshot({ path: process.env.CLAIMS_SHOT, fullPage: true });
    await panel.getByRole('combobox', { name: 'Problem' }).selectOption('receipt_reused');
    await expect(panel.getByRole('listitem')).toHaveCount(1);
    await expect(panel).not.toContainText('9406979141');
    await panel.getByRole('combobox', { name: 'Problem' }).selectOption('short_paid');
    await expect(panel).toContainText('No claims with this problem');
    const download = page.waitForEvent('download');
    await panel.getByRole('button', { name: 'Export' }).click();
    expect((await download).suggestedFilename()).toBe('claim-checks-ghana-office-momo-wallet.csv');
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('a manager links a typo claim to the suggested receipt and the flag clears', async ({ page }) => {
    const state = await setup(page);
    const panel = page.getByRole('region', { name: 'Customer payment claims to check' });
    await panel.getByRole('button', { name: 'Review claims' }).click();
    await panel.getByRole('button', { name: 'Link to this receipt' }).click();
    await expect(panel.getByRole('status')).toContainText('Linked payment #44654 to receipt 94069791412.');
    await expect(panel).toContainText('2 to check');
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toEqual([{ path: '/api/crm/collections/receipts/77/aliases', body: { reference: '9406979141', evidence_reference: 'Claim check: payment #44654' } }]);
});

test('link refusal is explained and the claim stays flagged', async ({ page }) => {
    await setup(page, { failLink: true });
    const panel = page.getByRole('region', { name: 'Customer payment claims to check' });
    await panel.getByRole('button', { name: 'Review claims' }).click();
    await panel.getByRole('button', { name: 'Link to this receipt' }).click();
    await expect(panel.getByRole('status')).toContainText('Only a manager can link a receipt.');
    await expect(panel).toContainText('3 to check');
});

test('TZ-only scope shows no claims panel and a clean Ghana window says so', async ({ page }) => {
    await setup(page, { accounts: [] });
    await expect(page.getByRole('region', { name: 'Incoming office payments' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Customer payment claims to check' })).toHaveCount(0);
    await page.unrouteAll({ behavior: 'ignoreErrors' });
    await setup(page, { claims: [] });
    const panel = page.getByRole('region', { name: 'Customer payment claims to check' });
    await expect(panel).toContainText('Every recent claim matches a phone receipt');
    await panel.getByRole('button', { name: 'Review claims' }).click();
    await expect(panel).toContainText('Nothing to check');
});

test('claims panel stays usable at phone width', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await setup(page);
    const panel = page.getByRole('region', { name: 'Customer payment claims to check' });
    await panel.getByRole('button', { name: 'Review claims' }).click();
    await expect(panel.getByRole('button', { name: 'Link to this receipt' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
