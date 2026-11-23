import { test, expect } from '@playwright/test';
import { setup } from './support/kopokopo-fixture';

for (const width of [1440, 390, 320]) {
    test(`Kenya queue and receipt at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 960 });
        const state = await setup(page, { unsaved: true });
        await expect(page.getByRole('region', { name: 'Payment progress' })).toContainText('Payment received');
        await expect(page.getByRole('dialog')).toContainText('Phone protected by provider');
        await expect(page.getByRole('dialog')).not.toContainText('SMS received');
        await page.getByRole('button', { name: 'Close', exact: true }).click();
        const feed = page.getByRole('region', { name: 'KopoKopo', exact: true });
        await expect(feed).toContainText('Connected · Reconciliation current');
        await expect(page.getByRole('heading', { name: 'Incoming till payments' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Match & activate', exact: true })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const automation = page.getByRole('region', { name: 'Automatic activation status', exact: true });
        const summaryBox = await automation.boundingBox();
        const actionBox = await automation.getByRole('button', { name: 'Automation settings' }).boundingBox();
        expect(actionBox.x + actionBox.width).toBeLessThanOrEqual(summaryBox.x + summaryBox.width);
        await feed.scrollIntoViewIfNeeded();
        await page.screenshot({ path: `../screenshots/kopokopo-queue-${width}.png`, fullPage: true });
        await feed.getByRole('button', { name: 'Connection details' }).click();
        await expect(feed.getByLabel('Connection setup progress')).toBeVisible();
        await feed.getByText('Technical connection information').click();
        await expect(feed.getByLabel('Receiving callback')).toHaveValue('https://crm.example.test/fixture-callback');
        expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
    });
}

test('sales sees protected identity and no connection management controls', async ({ page }) => {
    await setup(page, { role: 'sales' });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    const feed = page.getByRole('region', { name: 'KopoKopo', exact: true });
    await expect(feed.getByRole('button', { name: 'Connection details' })).toHaveCount(0);
    await expect(feed.getByRole('button', { name: 'Check reconciliation' })).toHaveCount(0);
});

test('interrupted subscription exposes verification and blocks duplicate registration', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await setup(page, { feedState: 'setup_pending' });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    const feed = page.getByRole('region', { name: 'KopoKopo', exact: true });
    await expect(feed.getByRole('button', { name: 'Connect KopoKopo' })).toHaveCount(0);
    await feed.getByRole('button', { name: 'Connection details' }).click();
    await feed.getByText('Technical connection information').click();
    await feed.getByLabel('KopoKopo subscription address').fill('https://api.kopokopo.com/api/v2/webhook_subscriptions/fixture-1');
    await feed.getByRole('button', { name: 'Verify subscription', exact: true }).focus();
    await expect(feed.getByRole('button', { name: 'Verify subscription', exact: true })).toBeFocused();
});
