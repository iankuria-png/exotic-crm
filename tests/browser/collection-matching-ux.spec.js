import { test, expect } from '@playwright/test';
import { setup, addSecond } from './support/collection-matching-fixture';

test('match confidence and reasons stay visible before and after activation, with profile links', async ({ page }) => {
    await setup(page);
    const matching = page.getByRole('region', { name: 'Profile matching', exact: true });
    await expect(matching.getByText('Match confidence 90 / 100', { exact: true })).toBeVisible();
    await expect(matching.getByText('The full SMS payer phone matches this profile.', { exact: true })).toBeVisible();
    const profile = page.getByRole('group', { name: 'PROFILE 1', exact: true }).getByRole('link', { name: 'Example Profile A', exact: true });
    await expect(profile).toHaveAttribute('href', '/clients/42');
    await expect(profile).toHaveAttribute('target', '_blank');
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    await expect(matching.getByText('Match confidence 90 / 100', { exact: true })).toBeVisible();
});

test('used payment keeps matching evidence without an activation action', async ({ page }) => {
    await setup(page, { used: true });
    await expect(page.getByRole('region', { name: 'Profile matching' })).toContainText('Match confidence 90 / 100');
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toHaveCount(0);
});

for (const mode of ['ambiguous', 'missing']) {
    test(`${mode} matching evidence explains why staff must choose`, async ({ page }) => {
        await setup(page, { [mode]: true, unsaved: true });
        const matching = page.getByRole('region', { name: 'Profile matching', exact: true });
        await expect(matching).toContainText(mode === 'ambiguous' ? 'The evidence needs a staff choice' : 'No supported profile match found');
        await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
    });
}

test('direct activation has no required save and leaves optional exposure off', async ({ page }) => {
    const state = await setup(page);
    await expect(page.getByRole('button', { name: 'Save purchase choice', exact: true })).toHaveCount(0);
    await expect(page.getByRole('checkbox', { name: /Include.*trial/ })).not.toBeChecked();
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    expect(state.mutations.filter(m => /\/sales\/(save|activate)$/.test(m.path)).map(m => m.path.split('/').pop())).toEqual(['activate']);
    expect(state.mutations.find(m => m.path.endsWith('/sales/activate')).body.items[0].subsidiary_trial.enabled).toBe(false);
    await page.getByRole('button', { name: 'Customer message and links', exact: true }).click();
    await page.getByRole('button', { name: 'Copy customer message', exact: true }).click();
    expect((await page.evaluate(() => window.fixtureCopies))[0]).toContain('https://example.test/advertiser-a/');
});

test('fresh unsaved receipt goes directly from profile/package to activation', async ({ page }) => {
    const state = await setup(page, { unsaved: true });
    await page.getByRole('combobox', { name: 'Purchased service' }).selectOption('8');
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    expect(state.mutations.filter(m => /intents|approve|\/save$/.test(m.path))).toHaveLength(0);
});

test('code already recorded shows agent and subscription before any activation form', async ({ page }) => {
    const state = await setup(page, { used: true });
    await expect(page.getByRole('region', { name: 'Payment code status' }).last()).toContainText('Activated by Zeddy');
    await expect(page.getByRole('link', { name: 'View subscription', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toHaveCount(0);
    expect(state.mutations.filter(m => /sales|intents|approve/.test(m.path))).toHaveLength(0);
    await page.screenshot({ path: 'output/payment-sales-ux-2026-11-07/existing-code.png' });
});

test('shared activation retains confirmed child and offers unknown-state review', async ({ page }) => {
    await setup(page, { shared: true, partial: true });
    await page.getByRole('button', { name: 'Share payment with another profile', exact: true }).click();
    await addSecond(page);
    await page.getByRole('button', { name: 'Activate 2 subscriptions', exact: true }).click();
    await expect(page.getByText('1 of 2 subscriptions confirmed')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Customer message and links' })).toHaveCount(1);
    await expect(page.getByText('Manager: check saved activation')).toBeVisible();
});

test('queue shows provider time and sends date/sort filters before export', async ({ page }) => {
    await setup(page);
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await expect(page.getByText('Paid 06 Nov 2026, 21:20 EAT', { exact: true })).toBeVisible();
    const request = page.waitForRequest(r => r.url().includes('/collections/receipts?') && r.url().includes('date_from=2026-11-06'));
    await page.getByLabel('Paid from (EAT)', { exact: true }).fill('2026-11-06'); await request;
    await page.getByRole('combobox', { name: 'Sort payments', exact: true }).selectOption('oldest');
    await page.screenshot({ path: 'output/payment-sales-ux-2026-11-07/payment-queue.png' });
    const exportRequest = page.waitForRequest(r => r.url().includes('export=1'));
    await page.getByRole('button', { name: 'Export receipts', exact: true }).click();
    const url = new URL((await exportRequest).url());
    expect(url.searchParams.get('sort')).toBe('oldest'); expect(url.searchParams.get('date_from')).toBe('2026-11-06');
});

test('optional linked search stays inline; Escape closes it and preserves receipt', async ({ page }) => {
    const state = await setup(page, { trial: true });
    await page.getByRole('checkbox', { name: /Include.*trial/ }).check();
    await expect(page.getByText('Existing profile: Example linked profile', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Change details', exact: true }).click();
    await page.getByRole('button', { name: 'Choose a different profile', exact: true }).click();
    await page.getByLabel('Find linked profile', { exact: true }).fill('Example');
    await page.keyboard.press('Escape');
    await expect(page.getByLabel('Find linked profile', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Choose a different profile', exact: true })).toBeFocused();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(0);
});

test('mobile form and primary action fit the drawer; keyboard close returns focus', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 }); await setup(page);
    const action = page.getByRole('button', { name: 'Activate subscription', exact: true });
    await expect(action).toBeInViewport();
    expect(await page.getByRole('dialog').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: 'output/payment-sales-ux-2026-11-07/mobile-activate.png' });
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Match & activate', exact: true })).toBeFocused();
});

test('held manual gate gives a clear reason and does not dispatch', async ({ page }) => {
    const state = await setup(page, { held: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('An administrator must finish the activation connection', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm & activate subscription', exact: true })).toBeDisabled();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(0);
});

test('double activation clicks keep one dispatch and preserve explicit copied SMS on demand', async ({ page }) => {
    const state = await setup(page, { denied: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).dblclick();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(1);
    await page.getByText('Payer, receiving message & payment details', { exact: true }).click();
    await page.getByRole('button', { name: 'Copy original SMS', exact: true }).click();
    await page.getByRole('button', { name: 'Copy this SMS', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Clipboard unavailable. Select and copy this text.' })).toHaveValue('FIXTURE0001 Confirmed.\nReceived TZS 35,000 from EXAMPLE PAYER.');
});

test('200 percent text keeps controls within receipt width and evidence collapsed', async ({ page }) => {
    await setup(page); await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.getByRole('dialog').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await expect(page.getByRole('button', { name: 'Copy original SMS', exact: true })).not.toBeVisible();
    await page.screenshot({ path: 'output/payment-sales-ux-2026-11-07/text-reflow.png' });
});
