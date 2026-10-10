import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import { seedAuthState } from './support/auth';

// Real scoped Laravel APIs over a disposable SQLite fixture; no production hosts.
test.describe.configure({ mode: 'serial' });
test.use({ locale: 'en-GB', timezoneId: 'Africa/Nairobi' });
const fixture = '/tests/browser/fixtures/db-observatory-experience.html';
const authFile = new URL('../../output/db-observatory-ux-2026-10-11/browser-auth.json', import.meta.url);
function auth() { return JSON.parse(fs.readFileSync(authFile, 'utf8')); }
async function open(page, query = '', role = 'admin') {
    await seedAuthState(page, auth()[role]);
    await page.goto(`${fixture}${query ? `?${query}` : ''}`);
    await expect(page.getByRole('heading', { name: 'Database Observatory', exact: true })).toBeVisible();
}

test('overview separates evidence from worker health and includes blocked domains', async ({ page }) => {
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await open(page);
    await expect(page.getByRole('heading', { name: 'Evidence to review', exact: true })).toBeVisible();
    await expect(page.getByText('ug.example.test', { exact: true })).toBeVisible();
    await expect(page.getByText('Configure the reader connection', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Worker health and scan coverage are separate.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: /^Login campaigns/ }).click();
    await expect(page).toHaveURL(/family=login_campaign/);
    await expect(page.getByRole('heading', { name: 'Related review groups' })).toBeVisible();
    await expect(page.getByText('Shared login indicator: 192.0.2.25', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('related group drilldown preserves its counting unit and links to historical account evidence', async ({ page }) => {
    await open(page, 'tab=findings&family=login_campaign');
    const group = page.locator('details').filter({ has: page.locator('summary', { hasText: 'Shared login indicator: 192.0.2.25' }) });
    await group.locator('summary').click();
    await expect(group.getByText(/2 finding records · 2 market records/)).toBeVisible();
    await group.getByRole('button', { name: 'View all 2 member records →' }).click();
    await expect(page).toHaveURL(/review_group=[a-f0-9]{64}/);
    await page.getByRole('button', { name: /Shared successful IP observed across markets/ }).first().click();
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByText('Not recorded for this finding', { exact: true })).toBeVisible();
    await expect(drawer.getByText(/Authorized linked activity from/)).toBeVisible();
    await drawer.getByRole('button', { name: /Egypt · Moody/ }).click();
    await expect(drawer.getByText('Earliest recorded activity', { exact: true })).toBeVisible();
    await expect(drawer.getByText('Not established by this finding', { exact: true })).toBeVisible();
    await expect(drawer.getByText(/11 Sept? 2026/).first()).toBeVisible();
    await page.reload();
    await expect(page.getByRole('dialog').getByText('Earliest recorded activity')).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('dialog').getByText('Linked evidence · 2 authorized findings')).toBeVisible();
});

test('remediation report is saved through the real API and never marks the finding resolved', async ({ page }) => {
    const seed = auth();
    await open(page, `tab=findings&finding=${seed.script_id}&family=stored_script`);
    const drawer = page.getByRole('dialog');
    await drawer.getByRole('button', { name: 'Record remediation', exact: true }).click();
    const save = drawer.getByRole('button', { name: 'Save remediation report', exact: true });
    await expect(save).toBeDisabled();
    await drawer.getByLabel('What was fixed and what evidence supports it?').fill('Synthetic fixture review: exact script removed and database proof attached to tracker TEST-001.');
    await save.click();
    await expect(drawer.getByText('Reported fix; awaiting verification', { exact: true })).toBeVisible();
    await expect(drawer.getByText('Synthetic fixture review: exact script removed and database proof attached to tracker TEST-001.').first()).toBeVisible();
    const response = await page.request.get(`/api/crm/db-observatory/findings/${seed.script_id}`, { headers: { Authorization: `Bearer ${seed.admin.token}` } });
    expect(response.ok()).toBeTruthy();
    const result = await response.json();
    expect(result.finding.status).toBe('open');
    expect(result.events.some(e => e.type === 'remediation_recorded')).toBeTruthy();
    await page.reload();
    await expect(page.getByRole('dialog').getByText('Reported fix; awaiting verification', { exact: true })).toBeVisible();
});

test('URL family, search, status and drawer survive reload and browser Back', async ({ page }) => {
    await open(page, 'tab=findings');
    await page.getByLabel('Evidence family', { exact: true }).selectOption('permissions');
    await expect(page).toHaveURL(/family=permissions/);
    await page.reload();
    await expect(page.getByLabel('Evidence family')).toHaveValue('permissions');
    await page.getByLabel('Group by').selectOption('');
    await page.getByRole('button', { name: /Role grants administrative capability/ }).first().click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.goBack();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByLabel('Evidence family')).toHaveValue('permissions');
});

test('daily aggregate date has no invented precise activity time and stored scripts stay inert', async ({ page }) => {
    const seed = auth();
    await open(page, `tab=findings&finding=${seed.daily_id}`);
    await expect(page.getByRole('dialog').getByText('Activity date', { exact: true })).toBeVisible();
    await expect(page.getByRole('dialog').getByText('12 Sept 2026', { exact: true })).toBeVisible();
    let dialogs = 0; page.on('dialog', async d => { dialogs++; await d.dismiss(); });
    await page.goto(`${fixture}?tab=findings&finding=${seed.script_id}`);
    const drawer = page.getByRole('dialog');
    await drawer.getByText('Technical evidence and stored location', { exact: true }).click();
    await expect(drawer.getByText('<script>alert("inert evidence")</script>', { exact: true })).toBeVisible();
    expect(dialogs).toBe(0);
});

test('scoped reviewer never sees another market or mutation controls', async ({ page }) => {
    const seed = auth();
    await open(page, `tab=findings&finding=${seed.correlation_ids[0]}`, 'sub_admin');
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByRole('button', { name: /Egypt · Moody/ })).toBeVisible();
    await expect(drawer.getByText(/Other-market account/)).toHaveCount(0);
    await expect(drawer.getByRole('button', { name: 'Record remediation', exact: true })).toHaveCount(0);
    const denied = await page.request.patch(`/api/crm/db-observatory/findings/${seed.account_id}`, { headers: { Authorization: `Bearer ${seed.sub_admin.token}` }, data: { record_remediation: true, note: 'Unauthorized synthetic fixture report' } });
    expect(denied.status()).toBe(403);
});

test('mobile keyboard drawer retains context and closes with Escape without losing filters', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page, 'tab=findings&family=stored_script');
    const summary = page.locator('summary').filter({ hasText: 'widget_text' });
    await summary.focus(); await page.keyboard.press('Enter');
    const finding = page.getByRole('button', { name: /Stored third-party script needs ownership review/ });
    await finding.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('dialog').getByText('egypt.example.test', { exact: false }).first()).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page).toHaveURL(/family=stored_script/);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
    expect(overflow).toBe(false);
});

test('scan detail exposes timing and finished-with-gaps independently of successful work', async ({ page }) => {
    await open(page, `tab=runs&run=${auth().run_id}`);
    await expect(page.getByText('Admitted to queue', { exact: true })).toBeVisible();
    await expect(page.getByText('Last worker heartbeat', { exact: true })).toBeVisible();
    await expect(page.getByText(/Finished with gaps means the inspected database surfaces/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Stop this scan', exact: true })).toHaveCount(0);
});


test('short mobile viewport keeps remediation fields reachable and evidence scrollable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 568 });
    await open(page, `tab=findings&finding=${auth().script_id}`);
    const drawer = page.getByRole('dialog');
    await drawer.getByRole('button', { name: 'Record remediation', exact: true }).click();
    const note = drawer.getByLabel('What was fixed and what evidence supports it?');
    await note.fill('Exact synthetic script reviewed; TEST-001 has the repair evidence.');
    const save = drawer.getByRole('button', { name: 'Save remediation report', exact: true });
    await save.scrollIntoViewIfNeeded();
    await expect(save).toBeInViewport();
    await expect(drawer.getByRole('button', { name: 'Close', exact: true })).toBeInViewport();
    expect(await drawer.evaluate(el => el.getBoundingClientRect().height)).toBeLessThanOrEqual(568);
});
