import { test, expect } from '@playwright/test';
import { setup } from './support/collection-matching-fixture';

async function openSettings(page) {
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'Automation settings' }).click();
    return page.getByRole('region', { name: 'Automatic activation for TZ office wallet' });
}

test('manager can save strict matching rules while Finance holds switch-on', async ({ page }) => {
    const state = await setup(page, { automation: true });
    const controls = await openSettings(page);
    await expect(controls).toContainText('1 of 2 go-live checks complete');
    await expect(controls.getByRole('region', { name: 'Automatic activation rules' })).toContainText('1 hour');
    await expect(controls.getByRole('button', { name: 'Switch on automatic activation' })).toBeDisabled();
    await controls.getByRole('button', { name: 'Edit rules' }).click();
    await controls.getByLabel('Match score must be above').fill('85');
    await controls.getByLabel('Reason for this change').fill('Approved TZ pilot matching policy');
    await controls.getByRole('button', { name: 'Save rules', exact: true }).click();
    await expect(controls.getByRole('status')).toHaveText('Rules saved.');
    const mutation = state.mutations.find(m => m.path.endsWith('/accounts/fixture/policy'));
    expect(mutation.body).toEqual({ expected_version: 1, match_threshold: 85, max_auto_age_seconds: 3600, purchase_matching: 'exact_catalogue_or_reviewed', reason: 'Approved TZ pilot matching policy' });
});

test('recording the Finance sign-off unlocks an attributed switch-on with the 24-hour pilot', async ({ page }) => {
    const state = await setup(page, { automation: true });
    const controls = await openSettings(page);
    await controls.getByRole('button', { name: 'Record sign-off: Statement comparison' }).click();
    const form = controls.getByRole('form', { name: 'Record sign-off: Statement comparison' });
    await form.getByRole('button', { name: 'Record sign-off', exact: true }).click();
    await expect(form.getByRole('alert')).toContainText('Name who accepted this');
    await form.getByLabel('Who accepted it').fill('Finance — Grace M.');
    await form.getByLabel('Evidence or note').fill('Statement 1–8 Nov matched');
    await form.getByRole('button', { name: 'Record sign-off', exact: true }).click();
    await expect(controls).toContainText('Accepted by Finance — Grace M.');
    expect(state.mutations.find(m => m.path.endsWith('/signoffs')).body).toEqual({ key: 'independent_reconciliation_accepted_at', expected_version: 1, note: 'Statement 1–8 Nov matched', accepted_by: 'Finance — Grace M.' });
    await expect(controls).toContainText('Ready to switch on');
    await controls.getByRole('button', { name: 'Switch on automatic activation' }).click();
    const confirm = controls.getByRole('form', { name: 'Confirm switch on' });
    await expect(confirm).toContainText('Only payments received after you switch on');
    await expect(confirm).toContainText('24-hour pilot');
    await confirm.getByLabel('Reason for switching on').fill('Finance accepted TZ go-live');
    await confirm.getByRole('button', { name: 'Switch on now' }).click();
    await expect(controls).toContainText('Automatic activation is live');
    await expect(controls).toContainText('24-hour pilot · ends');
    await expect(page.getByRole('region', { name: 'Automatic activation status', exact: true })).toContainText('Automatic activation · Live');
    const enable = state.mutations.filter(m => m.path.endsWith('/accounts/fixture/policy')).pop();
    expect(enable.body).toMatchObject({ expected_version: 2, automation_enabled: true, observation_pilot_hours: 24, match_threshold: 80, max_auto_age_seconds: 3600, reason: 'Finance accepted TZ go-live' });
    await controls.getByRole('button', { name: 'Pause automatic activation' }).click();
    await controls.getByRole('button', { name: 'Pause now' }).click();
    await expect(controls.getByRole('alert')).toContainText('Say why it is being paused');
});

test('stale automatic policy preserves manager entries for review', async ({ page }) => {
    const state = await setup(page, { automation: true }); state.failPolicy = true;
    const controls = await openSettings(page);
    await controls.getByRole('button', { name: 'Edit rules' }).click();
    await controls.getByLabel('Reason for this change').fill('Keep this reviewed reason');
    await controls.getByRole('button', { name: 'Save rules', exact: true }).click();
    await expect(controls.getByRole('alert')).toContainText('Someone else changed this policy');
    await expect(controls.getByLabel('Reason for this change')).toHaveValue('Keep this reviewed reason');
});

test('a reviewed correction changes one activation without another activate request', async ({ page }) => {
    const state = await setup(page, { correction: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await page.getByRole('button', { name: 'Remove activation', exact: true }).click();
    await page.getByRole('form', { name: 'Remove payment activation' }).getByRole('button', { name: 'Remove activation', exact: true }).click();
    await expect(page.getByText('Payment allocation unassigned', { exact: true })).toBeVisible();
    expect(state.mutations.filter(m => m.path.endsWith('/sales/activate'))).toHaveLength(1);
    expect(state.mutations.filter(m => m.path.endsWith('/correction-preview'))).toHaveLength(1);
    expect(state.mutations.filter(m => m.path.includes('/sales/corrections/') && m.path.endsWith('/execute'))).toHaveLength(1);
});

test('unavailable original snapshot explains the correction hold before a write', async ({ page }) => {
    const state = await setup(page, { correction: true, correctionHeld: true });
    await page.getByRole('button', { name: 'Activate subscription', exact: true }).click();
    await page.getByRole('button', { name: 'Remove activation', exact: true }).click();
    await page.getByRole('form', { name: 'Remove payment activation' }).getByRole('button', { name: 'Remove activation', exact: true }).click();
    await expect(page.getByText(/original activation cannot be reversed automatically/)).toBeVisible();
    await expect(page.getByRole('form', { name: 'Remove payment activation' }).getByRole('alert')).toBeVisible();
    expect(state.mutations.filter(m => m.path.includes('/sales/corrections/') && m.path.endsWith('/execute'))).toHaveLength(0);
});


test('saved automatic score stays distinct from current suggestions', async ({ page }) => {
    await setup(page, { savedMatch: true });
    const matching = page.getByRole('region', { name: 'Profile matching', exact: true });
    await expect(matching).toContainText('Automatic decision · saved match score 95 / 100');
    await expect(matching).toContainText('Match confidence 90 / 100');
    await expect(matching).toContainText('suggestions below reflect the current evidence');
});
