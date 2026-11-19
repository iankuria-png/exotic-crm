import { test, expect } from '@playwright/test';
import { setup } from './support/collection-matching-fixture';

const report = {
    range: { from: '2026-10-20T21:00:00Z', to: '2026-11-18T19:00:00Z', timezone: 'Africa/Nairobi' }, generated_at: '2026-11-18T19:00:00Z', mode: 'saved_suggestions',
    totals: { receipts: 772, with_outcome: 545, suggested: 225, agreed: 167, disagreed: 58, in_list_not_top: 15, no_suggestion: 320 },
    rates: { coverage: 0.4128, agreement: 0.3064, precision: 0.7422 },
    automatic: { activated: 17, moved_by_staff: 3, accuracy: 0.8235 }, would_auto: { count: 149, correct: 125, precision: 0.8389 },
    bands: [{ band: '95+', n: 70, correct: 52, precision: 0.7429 }, { band: '90–94', n: 79, correct: 73, precision: 0.9241 }, { band: '81–89', n: 0, correct: 0, precision: null },
        { band: '80', n: 33, correct: 19, precision: 0.5758 }, { band: '65–79', n: 8, correct: 6, precision: 0.75 }, { band: '50–64', n: 0, correct: 0, precision: null }, { band: '1–49', n: 35, correct: 17, precision: 0.4857 }],
    rules: [{ rule: 'payment_history_3plus', label: '3 or more earlier payments', n: 79, correct: 73, precision: 0.9241 }, { rule: 'staff_confirmed_payer', label: 'Payer confirmed for this profile before', n: 70, correct: 52, precision: 0.7429 }],
    guards: [{ guard: 'payer_funds_several_profiles', count: 12 }],
    daily: [{ date: '2026-11-16', with_outcome: 40, suggested: 20, agreed: 15 }, { date: '2026-11-17', with_outcome: 44, suggested: 22, agreed: 17 }, { date: '2026-11-18', with_outcome: 38, suggested: 18, agreed: 14 }],
    reasons: [{ reason: 'advertiser_confirmed', label: 'Advertiser told us which profile', count: 4 }, { reason: 'pays_for_several', label: 'Payer pays for several profiles', count: 2 },
        { reason: 'new_profile', label: 'New profile for this payment', count: 1 }, { reason: 'old_duplicate', label: 'Suggestion is an old or duplicate profile', count: 0 }, { reason: 'other', label: 'Other reason', count: 0 }],
    feedback: { disagreements: 58, explained: 7 },
    cases: { disagreed: [{ receipt_id: 1, at: '2026-11-18T18:58:00Z', amount_minor: 4000000, currency: 'TZS', suggested: { id: 43, name: 'Example Profile B', score: 95, rule: 'staff_confirmed_payer' },
        chosen: [{ id: 42, name: 'Example Profile A' }], reason: null, reason_label: null, source: 'recorded_payment', automatic_moved: false }], no_suggestion: [] },
};

async function feedbackRoutes(page) {
    const posts = [];
    let saved = null;
    await page.route('**/api/crm/collections/receipts/1/match-feedback', async route => {
        const request = route.request();
        if (request.method() === 'POST') {
            const body = request.postDataJSON(); posts.push(body);
            saved = { client_id: body.client_id, reason: body.reason, reason_label: 'Payer pays for several profiles', note: body.note || null, by: 'Office fixture', at: '2026-11-18T19:05:00Z', agreed: false };
            return route.fulfill({ status: 201, json: { feedback: [saved], agreed: false } });
        }
        return route.fulfill({ json: { suggestion: { client_id: 43, name: 'Example Profile B', score: 95, rules: ['staff_confirmed_payer'] },
            outcomes: [{ id: 42, name: 'Example Profile A', agreed: false, explained: Boolean(saved) }], feedback: saved ? [saved] : [], reasons: [] } });
    });
    return posts;
}

async function reopen(page) {
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('button', { name: 'View receipt', exact: true }).click();
}

test('a recorded payment asks once why staff chose another profile and keeps the answer', async ({ page }) => {
    await setup(page, { used: true });
    const posts = await feedbackRoutes(page);
    await reopen(page);
    const check = page.getByRole('region', { name: 'Matching check', exact: true });
    await expect(check).toContainText('Suggested: Example Profile B · 95 / 100');
    await expect(check).toContainText('Not the suggested profile.');
    const save = check.getByRole('button', { name: 'Save reason', exact: true });
    await expect(save).toBeDisabled();
    await check.getByRole('radio', { name: 'Pays for several profiles' }).check({ force: true });
    await expect(save).toBeEnabled();
    await save.click();
    await expect(check).toContainText('Payer pays for several profiles');
    await expect(check.getByRole('button', { name: 'Change', exact: true })).toBeVisible();
    expect(posts).toEqual([{ client_id: 42, reason: 'pays_for_several', source: 'recorded' }]);
});

test('choosing a profile other than the suggestion needs a one-tap reason before activation', async ({ page }) => {
    const state = await setup(page);
    const profile = page.getByRole('group', { name: 'PROFILE 1', exact: true });
    const activate = page.getByRole('button', { name: 'Activate subscription', exact: true });
    await expect(profile.getByText('Not the suggested profile.')).toHaveCount(0);
    await profile.getByRole('button', { name: 'Change profile', exact: true }).click();
    await profile.getByRole('textbox', { name: 'Find advertiser profile' }).fill('Example');
    await profile.getByRole('button', { name: /Example Profile B.*Profile 10002/ }).click();
    await profile.getByRole('combobox', { name: 'Purchased service' }).selectOption('8');
    await expect(profile).toContainText('Not the suggested profile.');
    await expect(profile).toContainText('Choose a reason to continue. It takes one tap.');
    await expect(activate).toBeDisabled();
    await profile.getByRole('radio', { name: 'Other' }).check({ force: true });
    await expect(activate).toBeDisabled();
    await profile.getByRole('textbox', { name: 'What happened?' }).fill('Agency owner named it in the group chat');
    await expect(activate).toBeEnabled();
    await profile.getByRole('radio', { name: 'Advertiser told us' }).check({ force: true });
    await activate.click();
    await expect(page.getByText('1 of 1 subscriptions confirmed')).toBeVisible();
    await expect.poll(() => state.mutations.filter(m => m.path.endsWith('/match-feedback')).map(m => m.body))
        .toEqual([{ client_id: 43, source: 'activation', reason: 'advertiser_confirmed' }]);
});

test('managers see matching quality and can open a payment to learn from', async ({ page }) => {
    await setup(page, { used: true });
    await feedbackRoutes(page);
    let requested = null;
    await page.route('**/api/crm/collections/match-accuracy**', route => { requested = new URL(route.request().url()).searchParams.get('days'); return route.fulfill({ json: { accounts: 1, report } }); });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('tab', { name: 'Matching quality', exact: true }).click();
    await expect(page).toHaveURL(/view=quality/);
    const quality = page.getByRole('region', { name: 'Matching quality', exact: true });
    await expect(quality).toContainText('Top suggestion was right');
    await expect(quality).toContainText('31%');
    await expect(quality).toContainText('3 of 17 moved to another profile by staff');
    await expect(quality.getByRole('region', { name: 'Score vs. how often it was right' })).toContainText('Overconfident');
    await expect(quality).toContainText('7 of 58 differences explained');
    await expect(quality).toContainText('This payer has paid for more than one profile');
    expect(requested).toBe('30');
    await quality.getByRole('button', { name: '7 days', exact: true }).click();
    await expect.poll(() => requested).toBe('7');
    await quality.getByRole('button', { name: /Example Profile B.*Example Profile A.*Add reason/ }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Matching check', exact: true })).toContainText('Not the suggested profile.');
});

test('matching quality has honest empty and error states', async ({ page }) => {
    let mode = 'empty';
    await setup(page, { used: true });
    await page.route('**/api/crm/collections/match-accuracy**', route => mode === 'error' ? route.fulfill({ status: 500, json: {} })
        : route.fulfill({ json: { accounts: 1, report: { ...report, totals: { ...report.totals, with_outcome: 0 } } } }));
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await page.getByRole('tab', { name: 'Matching quality', exact: true }).click();
    await expect(page.getByText('No activations to measure yet')).toBeVisible();
    mode = 'error';
    await page.getByRole('button', { name: 'Refresh', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Matching quality could not be loaded.');
});

test('a custom-duration quote can be chosen and is sent as the quote itself', async ({ page }) => {
    const state = await setup(page);
    const price = { id: 8, price: '35000.00', currency: 'TZS', duration_days: 7, product: { id: 4, name: 'VIP', tier: 'vip', platform_id: 6 } };
    const custom = { id: 500, client_id: 43, product_price_id: null, plan_type: 'premium', duration_days: 16, amount: '35000.00', currency: 'TZS', status: 'pending' };
    await page.route('**/api/crm/collections/receipts/1/candidates**', route => {
        const url = new URL(route.request().url());
        const clients = [{ id: 42, name: 'Example Profile A', wp_post_id: 10001, platform_id: 6 }, { id: 43, name: 'Example Profile B', wp_post_id: 10002, platform_id: 6 }];
        return route.fulfill({ json: { clients: url.searchParams.has('selected_client_id') ? clients.filter(c => String(c.id) === url.searchParams.get('selected_client_id')) : clients,
            discount_policy: { pin_configured: true, max_percentage: 40 }, prices: [price], quotes: url.searchParams.get('selected_client_id') === '43' ? [custom] : [], subsidiary_clients: [], subsidiary_platform_id: null } });
    });
    const profile = page.getByRole('group', { name: 'PROFILE 1', exact: true });
    await profile.getByRole('button', { name: 'Change profile', exact: true }).click();
    await profile.getByRole('textbox', { name: 'Find advertiser profile' }).fill('Example');
    await profile.getByRole('button', { name: /Example Profile B.*Profile 10002/ }).click();
    const service = profile.getByRole('combobox', { name: 'Purchased service' });
    await expect(service.locator('option', { hasText: 'Custom quote · PREMIUM · 16 days · TZS 35,000' })).toHaveCount(1);
    await service.selectOption('quote:500');
    await expect(profile).toContainText('Custom quote created by staff. 16 days for TZS 35,000. This price and duration are final');
    await expect(profile.getByRole('checkbox', { name: /^Include (Tanzania|another market)/ })).toHaveCount(0);
    await profile.getByRole('radio', { name: 'Advertiser told us' }).check({ force: true });
    const activate = page.getByRole('button', { name: 'Activate subscription', exact: true });
    await expect(activate).toBeEnabled();
    await activate.click();
    await expect.poll(() => state.mutations.find(m => m.path.endsWith('/sales/preview'))?.body.items[0]).toMatchObject({ client_id: 43, product_price_id: null, deal_id: 500 });
});
