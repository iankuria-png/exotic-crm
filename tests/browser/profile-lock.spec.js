import { test, expect } from '@playwright/test';

// Isolated UI fixture (no Laravel / database): run with the crm-fixture-vite server
// PLAYWRIGHT_BASE_URL=http://127.0.0.1:5176 npm run test:browser -- profile-lock.spec.js
const fixture = (entry) => `/tests/browser/fixtures/profile-lock.html?entry=${encodeURIComponent(entry)}`;
const shots = 'output/profile-lock-2026-11-15';
const platform = { id: 1, name: 'Kenya', currency_code: 'KES', phone_prefix: '254', billing_method_policy: {} };

function client(id, overrides = {}) {
    return {
        id, platform_id: 1, wp_post_id: 1000 + id, client_type: 'escort', name: `Advertiser ${id}`,
        phone_normalized: `25470000${String(id).padStart(4, '0')}`, city: 'Kilimani', profile_status: 'publish',
        lifecycle_state: 'active', needs_payment: false, notactive: false, escort_expire: Date.parse('2026-11-21T20:59:59Z') / 1000,
        platform, deals: [], notes: [], payments: [], active_deal: null, plan_label: 'VIP', ...overrides,
    };
}

const REASONS = [
    { code: 'verification_required', label: 'Verification needed', description: 'KYC, photos or identity still need checking.', requires_note: false },
    { code: 'policy_violation', label: 'Policy or content violation', description: 'Content, photos or behaviour break the rules.', requires_note: false },
    { code: 'fraud_or_dispute', label: 'Fraud or payment dispute', description: 'Chargeback, fake payment or suspected scam.', requires_note: false },
    { code: 'client_request', label: 'Client asked for it', description: 'The advertiser wants contacts hidden for now.', requires_note: false },
    { code: 'quality_review', label: 'Profile quality review', description: 'Bio, photos or details need fixing first.', requires_note: false },
    { code: 'other', label: 'Other', description: 'Explain in the note.', requires_note: true },
];

async function setup(page, { rows = [], detail = null, role = 'sales' } = {}) {
    const user = { id: 7, name: 'CS fixture', role, status: 'active', assigned_market_ids: [1] };
    await page.addInitScript((staff) => {
        localStorage.setItem('crm_token', 'isolated-browser-fixture');
        localStorage.setItem('crm_user', JSON.stringify(staff));
    }, user);

    const state = { detail, lockCalls: [], unlockCalls: [] };
    await page.route('**/api/**', async (route) => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        let body = { data: [], total: 0, score: 80, checks: [], components: [] };

        if (path === '/api/crm/me') body = { user };
        if (path === '/api/crm/clients') body = { data: rows, total: rows.length, current_page: 1, last_page: 1, per_page: 50 };
        if (path === '/api/crm/clients/lock-reasons') body = { reasons: REASONS };
        if (path === '/api/crm/clients/bulk-lock/preview') {
            const ids = request.postDataJSON().client_ids;
            const plan = (id) => {
                if (id === 3) return { action: 'already_locked', reason: 'Already locked by its lifecycle (Expired or Archived).' };
                if (id === 4) return { action: 'blocked', code: 'high_risk', reason: 'High-risk profiles stay offline.' };
                if (id === 2) return { action: 'republish' };
                return { action: 'lock' };
            };
            const out = ids.map((id) => ({ id, name: `Advertiser ${id}`, city: 'Kilimani', has_paid_plan: id === 1, ...plan(id) }));
            const count = (action) => out.filter((row) => row.action === action).length;
            body = {
                rows: out,
                summary: { lock: count('lock'), republish: count('republish'), already_locked: count('already_locked'), blocked: count('blocked'), paid: out.filter((row) => row.has_paid_plan && ['lock', 'republish'].includes(row.action)).length, missing: 0 },
                slice_limit: 2,
            };
        }
        if (path === '/api/crm/clients/bulk-lock') {
            const payload = request.postDataJSON();
            state.lockCalls.push(payload);
            await new Promise((resolve) => setTimeout(resolve, 250));
            body = {
                results: payload.client_ids.map((id) => ({ client_id: id, name: `Advertiser ${id}`, action: id === 2 ? 'republished' : 'locked', message: 'Locked.' })),
                summary: { locked: payload.client_ids.length, skipped: 0, failed: 0 },
            };
            if (state.detail && payload.client_ids.includes(state.detail.id)) {
                state.detail = { ...state.detail, lifecycle_state: 'expired', profile_locked_at: '2026-11-15T09:00:00Z', profile_lock_reason_code: payload.reason_code, profile_lock_reason_note: payload.note };
            }
        }
        if (state.detail && path === `/api/crm/clients/${state.detail.id}`) body = state.detail;
        if (state.detail && path === `/api/crm/clients/${state.detail.id}/unlock`) {
            state.unlockCalls.push(request.postDataJSON());
            state.detail = { ...state.detail, lifecycle_state: 'active', profile_locked_at: null };
            body = { message: 'Profile unlocked and live again on its paid plan.', outcome: 'active', client: state.detail };
        }

        await route.fulfill({ json: body });
    });

    return state;
}

for (const width of [1440, 390]) {
    test(`bulk lock from the clients table previews, locks in slices and reports at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 1000 });
        const rows = [1, 2, 3, 4, 5].map((id) => client(id, id === 2 ? { profile_status: 'private' } : {}));
        const state = await setup(page, { rows });
        await page.goto(fixture('/clients'));

        await expect(page.getByText('Advertiser 1', { exact: true })).toBeVisible();
        for (const id of [1, 2, 3, 4, 5]) {
            await page.getByRole('row', { name: new RegExp(`Advertiser ${id}\\b`) }).getByRole('checkbox').check();
        }
        await page.getByRole('button', { name: 'Lock profiles' }).click();

        const dialog = page.getByRole('dialog', { name: 'Lock 5 profiles' });
        await expect(dialog).toBeVisible();
        await expect(dialog.getByText('Will be locked')).toBeVisible();
        await expect(dialog.getByText(/1 has a running paid plan/)).toBeVisible();
        const submit = dialog.getByRole('button', { name: 'Lock 3 profiles' });
        await expect(submit).toBeDisabled();

        await dialog.getByRole('button', { name: /Show the 2 skipped profiles/ }).click();
        await expect(dialog.getByText('High-risk profiles stay offline.')).toBeVisible();

        await dialog.getByText('Other', { exact: true }).click();
        await expect(submit).toBeDisabled();
        await dialog.getByText('Policy or content violation').click();
        await dialog.getByLabel(/Note/).fill('Fake photos reported by visitors');
        await page.screenshot({ path: `${shots}/bulk-configure-${width}.png`, fullPage: false });
        await submit.click();

        await expect(dialog.getByText(/Locking \d of 3/)).toBeVisible();
        await expect(dialog.getByText('3 profiles locked')).toBeVisible();
        expect(state.lockCalls.map((call) => call.client_ids)).toEqual([[1, 2], [5]]);
        expect(state.lockCalls[0]).toMatchObject({ reason_code: 'policy_violation', note: 'Fake photos reported by visitors' });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
        await page.screenshot({ path: `${shots}/bulk-done-${width}.png`, fullPage: false });
        await dialog.getByRole('button', { name: 'Done' }).click();
        await expect(dialog).toBeHidden();
    });
}

test('client page locks a paid profile, shows the banner and unlocks it', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    const detail = client(1, {
        name: 'Patricia',
        active_deal: { id: 9, status: 'active', plan_type: 'vip', expires_at: '2026-11-21T20:59:59Z', product: { name: 'VIP biweekly' } },
    });
    const state = await setup(page, { detail });
    await page.goto(fixture('/clients/1'));
    await expect(page.getByRole('heading', { name: 'Patricia', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Lock profile', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Lock Patricia' });
    await expect(dialog.getByText(/Has a running paid plan/)).toBeVisible();
    await dialog.getByText('Verification needed').click();
    await page.screenshot({ path: `${shots}/detail-lock-dialog.png`, fullPage: false });
    await dialog.getByRole('button', { name: 'Lock profile', exact: true }).click();
    await expect(dialog).toBeHidden();

    await expect(page.getByText(/Locked by staff · Verification needed/)).toBeVisible();
    await expect(page.getByText('Locked', { exact: true }).first()).toBeVisible();
    await page.screenshot({ path: `${shots}/detail-locked.png`, fullPage: false });

    await page.getByRole('button', { name: 'Unlock profile', exact: true }).click();
    const unlock = page.getByRole('dialog', { name: 'Unlock Patricia' });
    await expect(unlock.getByText(/Goes live again on its VIP biweekly plan/)).toBeVisible();
    await unlock.getByRole('button', { name: 'Unlock profile' }).click();
    await expect(unlock).toBeHidden();
    await expect(page.getByText(/Locked by staff/)).toHaveCount(0);
    expect(state.unlockCalls).toHaveLength(1);
});

test('marketing sees no lock entry points', async ({ page }) => {
    await setup(page, { detail: client(1, { name: 'Read only' }), role: 'marketing' });
    await page.goto(fixture('/clients/1'));
    await expect(page.getByRole('heading', { name: 'Read only', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Lock profile', exact: true })).toHaveCount(0);
});
