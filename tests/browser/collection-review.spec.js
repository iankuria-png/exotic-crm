import { test, expect } from '@playwright/test';
import { setup } from './support/collection-matching-fixture';

test('payer evidence is available on demand without burying activation', async ({ page }) => {
    await setup(page);
    await expect(page.getByRole('region', { name: 'SMS payer' })).not.toBeVisible();
    await page.getByText('Payer, receiving message & payment details', { exact: true }).click();
    await expect(page.getByRole('region', { name: 'SMS payer' })).toContainText('EXAMPLE PAYER');
    await expect(page.getByRole('region', { name: 'SMS payer' })).toContainText('+255700000001');
});

test('changing the profile clears the previous package', async ({ page }) => {
    await setup(page);
    await page.getByRole('button', { name: 'Change profile', exact: true }).click();
    await page.getByRole('textbox', { name: 'Find advertiser profile' }).fill('Example');
    await page.getByRole('button', { name: /Example Profile B.*Profile 10002/ }).click();
    await expect(page.getByRole('combobox', { name: 'Purchased service' })).toHaveValue('');
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
});

test('ambiguous payer requires explicit profile choice', async ({ page }) => {
    await setup(page, { ambiguous: true, unsaved: true });
    await expect(page.getByText('Choose the intended profile.', { exact: false })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Purchased service' })).toHaveCount(0);
    await page.getByRole('button', { name: /Example Profile B.*Profile 10002/ }).click();
    await expect(page.getByRole('combobox', { name: 'Purchased service' })).toBeVisible();
});

test('missing payer details stay explicit and do not imply zero earlier payments', async ({ page }) => {
    await setup(page, { missing: true, unsaved: true });
    await page.getByText('Payer, receiving message & payment details', { exact: true }).click();
    await expect(page.getByRole('region', { name: 'SMS payer' })).toContainText('Name not supplied');
    await page.getByText('Earlier payments', { exact: true }).click();
    await expect(page.getByText('No earlier payments found.', { exact: true })).toHaveCount(0);
});

test('history error has a retry and does not fabricate a profile choice', async ({ page }) => {
    const state = await setup(page); state.historyError = true;
    await page.getByRole('button', { name: 'Close', exact: true }).click(); await page.getByRole('button', { name: 'Match & activate', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'Could not load payment details.' })).toBeVisible();
    state.historyError = false;
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(page.getByRole('group', { name: 'PROFILE 1', exact: true })).toBeVisible();
});

test('keyboard focus wraps through visible controls with evidence collapsed', async ({ page }) => {
    await setup(page);
    const dialog = page.getByRole('dialog'); await dialog.getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Shift+Tab');
    await expect(dialog.getByRole('button', { name: 'Activate subscription', exact: true })).toBeFocused();
    await page.keyboard.press('Tab'); await expect(dialog.getByRole('button', { name: 'Close', exact: true })).toBeFocused();
});
