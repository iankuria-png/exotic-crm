import { test, expect } from '@playwright/test';
import { setup } from './support/collection-matching-fixture';

test('payment queue stays ahead of settings and the summary explains the Finance hold', async ({ page }) => {
    const state = await setup(page, { automation: true });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    const summary = page.getByRole('region', { name: 'Automatic activation status', exact: true });
    await expect(summary).toContainText('Automatic activation · Paused');
    await expect(summary).toContainText('Awaiting Finance acceptance');
    await expect(page.getByRole('dialog', { name: 'Automatic activation' })).toHaveCount(0);
    await expect(page.getByRole('table')).toBeVisible();
    await summary.getByRole('button', { name: 'Automation settings' }).click();
    const settings = page.getByRole('dialog', { name: 'Automatic activation' });
    await expect(settings).toBeVisible();
    await expect(settings.getByRole('button', { name: 'Close', exact: true })).toBeFocused();
    await expect(settings.getByRole('button', { name: 'Switch on automatic activation' })).toBeDisabled();
    await page.keyboard.press('Escape');
    await expect(settings).toHaveCount(0);
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('paused automation still explains client and package matches without claiming activation', async ({ page }) => {
    const state = await setup(page, { automation: true, unsaved: true });
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toContainText('SMS received');
    await expect(progress).toContainText('Client matched');
    await expect(progress).toContainText('90 / 100');
    await expect(progress).toContainText('Package matched');
    await expect(progress).toContainText('VIP · 7 days');
    await expect(progress).toContainText('Not activated');
    await expect(progress).toContainText('Automatic activation is paused');
    await expect(page.getByRole('combobox', { name: 'Purchased service' })).toHaveValue('8');
    await expect(progress).not.toContainText('Site confirmed');
    await expect(progress.getByRole('link', { name: /Open Example Profile A/ })).toHaveAttribute('href', '/clients/42');
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('unknown automatic outcome shows confirmation recovery and no activate button', async ({ page }) => {
    const state = await setup(page, { automaticState: 'uncertain' });
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toContainText('Checking confirmation');
    await expect(progress).toContainText('Do not activate again');
    await expect(progress).not.toContainText('Site confirmed');
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toHaveCount(0);
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('confirmed automatic result is visible without requiring another activation', async ({ page }) => {
    await setup(page, { automaticState: 'active_review_pending', savedMatch: true });
    await expect(page.getByRole('region', { name: 'Payment progress', exact: true })).toContainText('Site confirmed');
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'output/collection-auto-2026-11-09/journey-confirmed.png', fullPage: true });
});

test('desktop queue presents the payment journey before configuration', async ({ page }) => {
    await setup(page, { automation: true });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.screenshot({ path: 'output/collection-auto-2026-11-09/journey-queue.png', fullPage: true });
});

test('ambiguous identity remains a staff exception', async ({ page }) => {
    const state = await setup(page, { ambiguous: true, unsaved: true });
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toContainText('Client needs review');
    await expect(progress).toContainText('Package needs review');
    await expect(progress).toContainText('Not activated');
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('site confirmation follows the saved activation result and cannot be inferred from a match', async ({ page }) => {
    const state = await setup(page);
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toContainText('Not activated');
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(progress).toContainText('Site confirmed');
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(1);
});

test('an existing cash record is shown as recorded without a new activation', async ({ page }) => {
    const state = await setup(page, { used: true });
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toContainText('Already recorded');
    await expect(progress).toContainText('No new activation');
    await expect(progress).not.toContainText('Site confirmed');
    expect(state.mutations.filter(m => m.path.includes('/collections/'))).toHaveLength(0);
});

test('mobile progress wraps inside the receipt and settings remain secondary', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await setup(page, { automation: true, unsaved: true });
    const progress = page.getByRole('region', { name: 'Payment progress', exact: true });
    await expect(progress).toBeVisible();
    expect(await progress.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true);
    await page.screenshot({ path: 'output/collection-auto-2026-11-09/journey-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Automatic activation' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Match & activate', exact: true })).toBeInViewport();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
    await page.screenshot({ path: 'output/collection-golive-2026-11-09/queue-mobile.png', fullPage: true });
});
