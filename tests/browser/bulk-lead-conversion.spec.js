import { test, expect } from '@playwright/test';

const fixture = '/tests/browser/fixtures/bulk-lead-conversion.html';
const output = 'output/bulk-lead-conversion-2026-11-23';
const platform = { id: 1, name: 'Kenya', phone_prefix: '254', currency_code: 'KES' };
const names = ['Zaidi', 'Talisha', 'Peanut', 'Kazi', 'Maya'];
const lead = (id) => ({ id, name: names[id - 1], platform_id: 1, platform, assigned_agent: { id: 7, name: 'Zeddy Oluoch' }, phone_normalized: `25471111111${id}`, source: 'import', status: 'new' });
const review = (id) => ({ id, name: names[id - 1], market: 'Kenya', owner: 'Zeddy Oluoch', contact: `25471111111${id}`, action: id <= 3 ? 'create' : id === 4 ? 'existing_match' : 'needs_attention', message: id === 4 ? 'Existing client matches. Use Reconcile selected.' : id === 5 ? 'A valid phone number or email is needed.' : 'Create private profile', ...(id <= 3 ? { review_token: `review-${id}` } : {}), ...(id === 4 ? { client_id: 44 } : {}) });

async function setup(page, { outcome = 'success', failPreview = false, delay = 40, allBlocked = false } = {}) {
    const user = { id: 7, name: 'Ian Kuria', role: 'sales', status: 'active', assigned_market_ids: [1] };
    await page.addInitScript((value) => { localStorage.setItem('crm_token', 'isolated-browser-fixture'); localStorage.setItem('crm_user', JSON.stringify(value)); }, user);
    const state = { calls: [], previews: 0 };
    await page.route('**/api/**', async (route) => {
        const path = new URL(route.request().url()).pathname;
        let body = { data: [], total: 0, checks: [] }, status = 200;
        if (path === '/api/crm/me') body = { user };
        if (path === '/api/crm/leads') body = { data: names.map((_, i) => lead(i + 1)), total: 5, current_page: 1, last_page: 1, per_page: 50 };
        if (path === '/api/crm/settings/integrations') body = { platforms: [{ platform_id: 1, platform_name: 'Kenya', phone_prefix: '254' }] };
        if (path === '/api/crm/leads/bulk-convert/preview') {
            state.previews++;
            const ids = route.request().postDataJSON().lead_ids;
            if (failPreview && state.previews === 1) { status = 503; body = { message: 'Review temporarily unavailable.' }; }
            else body = { rows: ids.map((id) => allBlocked ? { ...review(id), action: 'needs_attention', review_token: undefined, message: 'Review this identity individually.' } : review(id)), expires_at: '2099-11-23T12:00:00Z' };
        }
        if (/\/leads\/\d+\/bulk-convert$/.test(path)) {
            const id = Number(path.split('/').at(-2));
            state.calls.push({ id, payload: route.request().postDataJSON() });
            await new Promise((resolve) => setTimeout(resolve, delay));
            if (outcome === 'mixed' && id === 2 && state.calls.filter((call) => call.id === 2).length === 1) { status = 500; body = { status: 'failed', message: 'Conversion could not finish. Retry this lead safely.' }; }
            else if (outcome === 'changed' && id === 2) { status = 409; body = { status: 'review_required', message: 'This lead or its client match changed. Review the selection again.' }; }
            else if (outcome === 'network' && id === 1) { await route.abort(); return; }
            else body = { status: id === 2 ? 'already_converted' : 'created', client_id: id + 100, message: id === 2 ? 'Already converted. No new profile was created.' : 'Client created. Profile is private.' };
        }
        await route.fulfill({ status, json: body });
    });
    await page.goto(fixture);
    for (const name of names) await page.getByRole('row', { name: new RegExp(`\\b${name}\\b`) }).getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Convert selected to clients' }).click();
    return state;
}

for (const width of [1440, 390, 360]) {
    test(`review, selection, creation and results at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        const errors = []; page.on('pageerror', (error) => errors.push(error.message));
        const state = await setup(page);
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Convert leads to clients' })).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Create 3 clients' })).toBeEnabled();
        await expect(dialog.getByText('Existing matches', { exact: true })).toBeVisible();
        expect(state.calls).toHaveLength(0);
        await dialog.getByRole('button', { name: 'Review 2', exact: true }).click();
        await expect(dialog.getByText('A valid phone number or email is needed.')).toBeVisible();
        await dialog.getByRole('button', { name: 'All 5', exact: true }).click();
        await dialog.getByLabel('Create client for Peanut').uncheck();
        await dialog.locator('.lead-conversion-body').evaluate((node) => { node.scrollTop = 0; });
        await page.screenshot({ path: `${output}/review-${width}.png` });
        await dialog.getByLabel('Batch note').fill('Kenya follow-up batch');
        const box = await dialog.boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(width);
        await dialog.getByRole('button', { name: 'Create 2 clients' }).click();
        await expect(dialog.getByRole('heading', { name: '2 clients ready for follow-up' })).toBeVisible();
        expect(state.calls.map((call) => call.id)).toEqual([1, 2]);
        expect(state.calls[0].payload).toEqual({ review_token: 'review-1', reason: 'Kenya follow-up batch' });
        await expect(dialog.getByRole('link', { name: /View client for Zaidi/ })).toHaveAttribute('href', '/clients/101');
        await page.screenshot({ path: `${output}/results-${width}.png` });
        await dialog.getByRole('button', { name: 'Done', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        expect(errors).toEqual([]);
    });
}

test('retry only failed rows; completed rows are retained', async ({ page }) => {
    const state = await setup(page, { outcome: 'mixed' });
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Create 3 clients' }).click();
    await expect(dialog.getByRole('button', { name: 'Retry / continue' })).toBeVisible();
    await expect(dialog.getByText('Could not finish', { exact: true })).toBeVisible();
    await dialog.getByRole('button', { name: 'Retry / continue' }).click();
    await expect(dialog.getByRole('heading', { name: '3 clients ready for follow-up' })).toBeVisible();
    expect(state.calls.map((call) => call.id)).toEqual([1, 2, 3, 2]);
});

test('stop waits for the current lead, escape cannot hide active work, then continue', async ({ page }) => {
    const state = await setup(page, { delay: 500 });
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Create 3 clients' }).click();
    await expect(dialog.getByRole('heading', { name: 'Creating your clients' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Stop after current' }).click();
    await expect(dialog.getByRole('heading', { name: '1 client ready for follow-up' })).toBeVisible();
    expect(state.calls.map((call) => call.id)).toEqual([1]);
    await dialog.getByRole('button', { name: 'Continue 2 remaining' }).click();
    await expect(dialog.getByRole('heading', { name: '3 clients ready for follow-up' })).toBeVisible();
});

test('preview failure is recoverable and no eligible rows disables confirmation', async ({ page }) => {
    const state = await setup(page, { failPreview: true, allBlocked: true });
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('alert')).toHaveText('Review temporarily unavailable.');
    await dialog.getByRole('button', { name: 'Try review again' }).click();
    await expect(dialog.getByRole('button', { name: 'Create 0 clients' })).toBeDisabled();
    expect(state.calls).toHaveLength(0);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Convert selected to clients' })).toBeFocused();
});

test('changed leads require a new review rather than blind retry', async ({ page }) => {
    await setup(page, { outcome: 'changed' });
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Create 3 clients' }).click();
    await expect(dialog.getByText('Review again', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Review selection again' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Retry / continue' })).toHaveCount(0);
});

test('network loss stops the batch and retains safe retry', async ({ page }) => {
    const state = await setup(page, { outcome: 'network' });
    await page.getByRole('dialog').getByRole('button', { name: 'Create 3 clients' }).click();
    await expect(page.getByRole('alert')).toContainText('batch stopped');
    expect(state.calls.map((call) => call.id)).toEqual([1]);
    await expect(page.getByRole('button', { name: 'Retry / continue' })).toBeVisible();
});

test('keyboard stays in dialog and reduced motion removes spinner animation', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await setup(page);
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('button', { name: 'Create 3 clients' })).toBeEnabled();
    for (let i = 0; i < 20; i++) {
        await page.keyboard.press('Tab');
        expect(await dialog.evaluate((node) => node.contains(document.activeElement))).toBe(true);
    }
    await page.screenshot({ path: `${output}/keyboard-reduced-motion.png` });
});
