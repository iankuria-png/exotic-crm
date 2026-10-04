import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { seedAuthState } from './support/auth.js';

const authFile = process.env.REBATES_QA_AUTH;
const wpAuthFile = process.env.REBATES_WP_QA_AUTH;
const shots = process.env.REBATES_QA_OUTPUT || 'tests/browser/artifacts/rebates';
const wpUrl = process.env.REBATES_WP_URL || 'http://exotic.local/escort/monetize-qa-studio/#account-billing';

test('CRM rebate editor, simulation, published revision, ledger and responsive layout', async ({ page }) => {
    test.skip(!authFile, 'Set REBATES_QA_AUTH to a temporary Local admin auth JSON.');
    const auth = JSON.parse(fs.readFileSync(authFile, 'utf8'));
    await seedAuthState(page, auth);
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('/settings?tab=billing');
    await page.getByRole('button', { name: 'Rebates', exact: true }).click();
    await page.getByLabel('Market', { exact: true }).selectOption('12');
    await expect(page.getByRole('heading', { name: /Kenya rebate program/ })).toBeVisible();
    await expect(page.locator('.rbt-state')).toHaveText('sandbox');
    await expect(page.locator('.rbt-quote > strong')).toHaveText('+KES 600');
    await page.getByRole('button', { name: 'Journey', exact: true }).click();
    await expect(page.locator('.rbt-quote > strong')).toHaveText('+KES 900');
    await page.getByRole('button', { name: 'Activation', exact: true }).click();
    await page.getByRole('combobox', { name: 'Channel', exact: true }).selectOption('staff_link');
    await expect(page.locator('.rbt-quote > strong')).toHaveText('+KES 100');
    await page.getByLabel('Tier 3 rate').fill('9');
    await expect(page.getByText('Unpublished changes', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review & publish' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.getByRole('dialog').getByLabel('Reason').fill('Automated local sandbox UI QA');
    await page.getByRole('button', { name: 'Publish revision', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await expect(page.getByText('Unpublished changes', { exact: true })).not.toBeVisible();
    await page.getByLabel('Tier 3 rate').fill('8');
    await page.getByRole('button', { name: 'Review & publish' }).click();
    await page.getByRole('dialog').getByLabel('Reason').fill('Restore approved sandbox defaults after QA');
    await page.getByRole('button', { name: 'Publish revision', exact: true }).click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
        await page.locator('[data-rebates-workspace]').scrollIntoViewIfNeeded();
        await page.locator('[data-rebates-workspace]').screenshot({ path: `${shots}/crm-program-${width}.png` });
    }
    await page.getByRole('button', { name: 'Performance', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Is the reward changing behaviour?' })).toBeVisible();
    await page.getByRole('button', { name: 'Ledger', exact: true }).click();
    await expect(page.getByPlaceholder('Name, phone or payment ID')).toBeVisible();
    await page.screenshot({ path: `${shots}/crm-ledger-390.png`, fullPage: true });
    await test.info().attach('console-errors', { body: JSON.stringify(errors), contentType: 'application/json' });
    expect(errors).toEqual([]);
});

test('Companion wallet carousel, quote receipt, caps, keyboard and program pause', async ({ page, context }) => {
    test.skip(!wpAuthFile, 'Set REBATES_WP_QA_AUTH to a temporary Local WordPress fixture session.');
    const auth = JSON.parse(fs.readFileSync(wpAuthFile, 'utf8'));
    const { session_token, user_id, ...cookie } = auth;
    await context.addCookies([cookie]);
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(wpUrl, { waitUntil: 'domcontentloaded' });
    const ageButton = page.getByRole('button', { name: /18.*(older|old)/ });
    if (await ageButton.isVisible()) await ageButton.click();
    await expect(page.locator('[data-rebate-levels]')).toBeVisible();
    await expect(page.locator('[data-rebate-badge]')).toHaveText('Up to 8% back');
    for (const width of [1440, 390, 360]) {
        await page.setViewportSize({ width, height: 950 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
        await page.locator('[data-rebate-levels]').scrollIntoViewIfNeeded();
        await page.locator('#wallet-section').screenshot({ path: `${shots}/wallet-${width}.png` });
    }
    const track = page.locator('[data-rebate-track]');
    await track.focus(); await page.keyboard.press('ArrowRight');
    await expect.poll(() => track.evaluate(el => el.scrollLeft)).toBeGreaterThan(0);
    await page.locator('[data-rebate-dot="3"]').click();
    await expect(page.locator('[data-rebate-dot="3"]')).toHaveAttribute('aria-pressed', 'true');
    await page.locator('#wallet-topup-btn').click();
    await page.locator('.exotic-topup__preset[data-amount="5000"]').click();
    await expect(page.locator('[data-rebate-receipt]')).toContainText('KES 5,600');
    await expect(page.locator('[data-rebate-receipt]')).toContainText('First top-up bonus');
    await expect(page.locator('[data-rebate-receipt]')).toContainText('Sandbox preview');
    await page.screenshot({ path: `${shots}/topup-390.png` });
    await page.locator('#wallet-custom-amount').fill('4000');
    await expect(page.locator('.cx-rebate-nudge')).toContainText('Add KES 1,000 more');
    await page.keyboard.press('Escape');
    await page.locator('[data-rebate-help]').click();
    await expect(page.locator('.cx-rebate-faq')).toContainText('Staff payment link');
    await expect(page.locator('.cx-rebate-faq')).toContainText('cannot be withdrawn');
    await page.keyboard.press('Escape');
    for (const [locale, badge, receipt] of [['fr_FR', 'Jusqu’à 8% en crédit', 'Ajouté à votre portefeuille'], ['sw_KE', 'Rejeshewa hadi 8%', 'Jumla inayoingia kwenye pochi']]) {
        const copyFile = process.env.REBATES_LOCALE_QA_DIR && `${process.env.REBATES_LOCALE_QA_DIR}/rebates-copy-${locale}.json`;
        if (!copyFile) continue;
        const copy = JSON.parse(fs.readFileSync(copyFile, 'utf8'));
        await page.evaluate(copy => { window.exoticRebateCopy = copy; window.exoticRebates.render(); }, copy);
        await expect(page.locator('[data-rebate-badge]')).toHaveText(badge);
        await page.locator('#wallet-topup-btn').click();
        await page.locator('.exotic-topup__preset[data-amount="5000"]').click();
        await expect(page.locator('[data-rebate-receipt]')).toContainText(receipt);
        await page.screenshot({ path: `${shots}/topup-${locale}-390.png` });
        await page.keyboard.press('Escape');
    }
    // Verify client-side hiding on a fresh paused config; no stale fallback.
    await page.evaluate(() => {
        window.exoticWalletState.config.rebates = null;
        document.dispatchEvent(new CustomEvent('exotic:wallet:updated'));
    });
    await expect(page.locator('[data-rebate-levels]')).not.toBeVisible();
    if (authFile) {
        const crmAuth = JSON.parse(fs.readFileSync(authFile, 'utf8'));
        const base = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';
        const headers = { Authorization: `Bearer ${crmAuth.token}` };
        try {
            const paused = await page.request.post(`${base}/api/crm/settings/billing/rebates/12/pause`, { headers, data: { paused: true, reason: 'Local browser QA actual WordPress pause sync' } });
            expect(paused.ok()).toBe(true);
            expect((await paused.json()).sync.status).toBe('synced');
            await page.goto(wpUrl, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('#wallet-section')).toBeVisible();
            await expect(page.locator('[data-rebate-levels]')).not.toBeVisible();
        } finally {
            const resumed = await page.request.post(`${base}/api/crm/settings/billing/rebates/12/pause`, { headers, data: { paused: false, reason: 'Restore sandbox after actual pause sync QA' } });
            expect(resumed.ok()).toBe(true);
            expect((await resumed.json()).sync.status).toBe('synced');
        }
    }

    await test.info().attach('console-errors', { body: JSON.stringify(errors), contentType: 'application/json' });
    expect(errors.filter(e => e !== `Unexpected token 'E', \"Error 4: S\"... is not valid JSON`)).toEqual([]);
});
