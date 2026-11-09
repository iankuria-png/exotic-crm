import { test, expect } from '@playwright/test';
import { setup } from './support/collection-matching-fixture';

test('manager can save strict matching and one-hour package policy while Finance holds enablement', async ({ page }) => {
    const state = await setup(page, { automation: true });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByText('Automatic activation limits', { exact: true }).click();
    const controls = page.getByRole('region', { name: 'Automatic activation for TZ office wallet' });
    await expect(controls).toContainText('Maximum payment age: 60 minutes');
    await controls.getByRole('button', { name: 'Configure automatic activation' }).click();
    await controls.getByLabel('Match score must be above').fill('85');
    await controls.getByLabel('Reason for this change').fill('Approved TZ pilot matching policy');
    await expect(controls.getByRole('button', { name: 'Enable for new payments' })).toBeDisabled();
    await controls.getByRole('button', { name: 'Save policy', exact: true }).click();
    await expect(controls.getByRole('status')).toHaveText('Automatic activation policy saved.');
    const mutation = state.mutations.find(m => m.path.endsWith('/accounts/fixture/policy'));
    expect(mutation.body).toMatchObject({ automation_enabled: false, match_threshold: 85, max_auto_age_seconds: 3600, purchase_matching: 'exact_catalogue_or_reviewed' });
});

test('observation exception cannot bypass a pending Finance requirement', async ({ page }) => {
    await setup(page, { automation: true });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByText('Automatic activation limits', { exact: true }).click();
    const controls = page.getByRole('region', { name: 'Automatic activation for TZ office wallet' });
    await controls.getByRole('button', { name: 'Configure automatic activation' }).click();
    await controls.getByRole('checkbox', { name: /24-hour TZ exception/ }).check();
    await controls.getByLabel('Reason for this change').fill('Observation-only exception approved');
    await expect(controls.getByRole('button', { name: 'Enable for new payments' })).toBeDisabled();
    await controls.getByText('Readiness · 1 checks pending').click();
    await expect(controls).toContainText('Pending · Independent payment statement comparison accepted');
});

test('stale automatic policy preserves manager entries for review', async ({ page }) => {
    const state = await setup(page, { automation: true }); state.failPolicy = true;
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByText('Automatic activation limits', { exact: true }).click();
    const controls = page.getByRole('region', { name: 'Automatic activation for TZ office wallet' });
    await controls.getByRole('button', { name: 'Configure automatic activation' }).click();
    await controls.getByLabel('Reason for this change').fill('Keep this reviewed reason');
    await controls.getByRole('button', { name: 'Save policy', exact: true }).click();
    await expect(controls.getByRole('alert')).toContainText('saved policy or a readiness check changed');
    await expect(controls.getByLabel('Reason for this change')).toHaveValue('Keep this reviewed reason');
});

test('a reviewed correction changes one activation without another activate request', async ({ page }) => {
    const state = await setup(page, { correction: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await page.getByRole('button', { name: 'Revert this activation', exact: true }).click();
    await page.getByLabel('Reason for the correction').fill('Wrong advertiser chosen');
    await page.getByRole('button', { name: 'Preview correction', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm correction', exact: true }).click();
    await expect(page.getByText('Payment allocation unassigned', { exact: true })).toBeVisible();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(1);
    expect(state.mutations.filter(m => m.path.endsWith('/correction-preview'))).toHaveLength(1);
    expect(state.mutations.filter(m => m.path.includes('/sales/corrections/') && m.path.endsWith('/execute'))).toHaveLength(1);
});

test('unavailable original snapshot explains the correction hold before a write', async ({ page }) => {
    const state = await setup(page, { correction: true, correctionHeld: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await page.getByRole('button', { name: 'Revert this activation', exact: true }).click();
    await page.getByLabel('Reason for the correction').fill('Wrong advertiser chosen');
    await page.getByRole('button', { name: 'Preview correction', exact: true }).click();
    await expect(page.getByText(/original site operation does not contain a complete reversible snapshot/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirm correction', exact: true })).toBeDisabled();
    expect(state.mutations.filter(m => m.path.includes('/sales/corrections/') && m.path.endsWith('/execute'))).toHaveLength(0);
});
