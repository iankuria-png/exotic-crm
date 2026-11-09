import { test, expect } from '@playwright/test';
import { setup, addSecond } from './support/collection-matching-fixture';

test('an optional draft reopens with both allocations', async ({ page }) => {
    const state = await setup(page, { shared: true });
    await page.getByRole('button', { name: 'Share payment with another profile' }).click(); await addSecond(page);
    await page.getByRole('button', { name: 'Save draft for later' }).click();
    await expect(page.getByText('Allocations saved. Review activation when you are ready.')).toBeVisible();
    await page.getByRole('button', { name: 'Close', exact: true }).click(); await page.getByRole('button', { name: 'Match & activate', exact: true }).click();
    await expect(page.getByRole('group', { name: 'PROFILE 2', exact: true })).toContainText('Example Profile B');
    expect(state.mutations.filter(m => m.path.endsWith('/sales/save'))).toHaveLength(1);
});

test('failed draft save preserves the selection and idempotency key', async ({ page }) => {
    const state = await setup(page); state.failSave = true;
    await page.getByRole('button', { name: 'Save draft for later' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'The saved sale changed.' })).toBeVisible();
    state.failSave = false; await page.getByRole('button', { name: 'Save draft for later' }).click();
    const saves = state.mutations.filter(m => m.path.endsWith('/sales/save'));
    expect(saves).toHaveLength(2); expect(saves[0].body.request_key).toBe(saves[1].body.request_key);
});

test('allocated total prevents activation with an unexplained balance', async ({ page }) => {
    await setup(page, { shared: true });
    await expect(page.getByText('TZS 35,000 remaining', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeDisabled();
    await page.getByRole('checkbox', { name: 'Leave remainder for Finance' }).check();
    await page.getByRole('textbox', { name: 'Reason', exact: true }).fill('Office will reconcile remainder');
    await expect(page.getByRole('button', { name: 'Activate subscription', exact: true })).toBeEnabled();
});

test('office summary excludes advertiser links', async ({ page }) => {
    await setup(page, { partial: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await page.getByRole('button', { name: 'Copy office summary' }).click();
    const copies = await page.evaluate(() => window.fixtureCopies);
    expect(copies[0]).toContain('1 of 1 subscriptions confirmed'); expect(copies[0]).not.toContain('https://');
});

test('subsidiary opt-in binds the approved target to the paid child', async ({ page }) => {
    const state = await setup(page, { trial: true });
    await page.getByRole('checkbox', { name: /^Include (Tanzania|another market)/ }).check();
    await expect(page.getByText('Existing profile: Example linked profile')).toBeVisible();
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    const preview = state.mutations.find(m => m.path.endsWith('/sales/preview')).body;
    expect(preview.items[0].subsidiary_trial).toMatchObject({ enabled: true, platform_id: 2, client_id: 72, duration_days: 9 });
});

test('removing optional exposure clears its activation request', async ({ page }) => {
    const state = await setup(page, { trial: true });
    await page.getByRole('checkbox', { name: /^Include (Tanzania|another market)/ }).check();
    await expect(page.getByText('Existing profile: Example linked profile')).toBeVisible();
    await page.getByRole('checkbox', { name: /^Include (Tanzania|another market)/ }).uncheck();
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    expect(state.mutations.find(m => m.path.endsWith('/sales/activate')).body.items[0].subsidiary_trial.enabled).toBe(false);
});
