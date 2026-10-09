import { test, expect } from '@playwright/test';

const market = {
    platform_id: 5, config_revision: 1, wp_revision: 0, currency: 'UGX', premium_access_environment: 'production', enabled: false, rollout_mode: 'off', activation_kill_switch: false, checkout_kill_switch: false, test_client_ids: [],
    offer_policy_json: { photos_enabled: true, videos_enabled: true, single_enabled: true, bundles_enabled: true, min_price: 100, max_price: 5000, bundle_min_items: 2, bundle_max_items: 12, live_offer_limit: 50, upload_max_bytes: 52428800, max_video_seconds: 600 },
    surface_policy_json: { profile_section: true, home_private_content: true, videos_private_filter: true, show_prices: true }, checkout_policy_json: { allowed_providers: ['pawapay'], device_slots: 3, restore_per_hour: 5 }, delivery_policy_json: { grant_ttl: 300 },
    prices: [{ duration_key: '1_month', duration_label: '1 Month', price: 500, subsidy_mode: 'fixed', subsidy_value: 100, is_active: true }], readiness_json: { ready: false, checks: {} },
};
const keys = ['connection', 'server', 'wallet', 'providers', 'pricing', 'delivery', 'activation'];
const labels = ['Connect WordPress', 'Check server readiness', 'Confirm wallet authentication', 'Choose available checkout providers', 'Review pricing and selling policies', 'Verify protected delivery', 'Enable live market'];
function response() {
    return { market: structuredClone(market), platforms: [{ id: 5, name: 'Uganda', currency_code: 'UGX' }, { id: 1, name: 'Kenya', currency_code: 'KES' }], system: { enabled: true }, audit: [], can_edit_system: false, supported_currencies: ['UGX'], automation: {},
        setup: { market: { id: 5, name: 'Uganda', currency: 'UGX' }, configured_url: 'https://uganda.example.test/wp-json/exotic-crm-sync/v1', reported_url: 'https://www.uganda.example.test/wp-json/exotic-crm-sync/v1', repair_url: 'https://www.uganda.example.test/wp-json/exotic-crm-sync/v1', canonical_mismatch: true, environment: 'production', wallet_mode: 'production', is_live: false, ready_to_enable: false, providers: { environment: 'production', available: [{ key: 'pawapay', label: 'PawaPay' }], unavailable: [{ key: 'kopokopo', label: 'KopoKopo', message: 'KopoKopo is unavailable for Uganda production.' }], settings_url: '/settings?tab=integrations&integrationArea=wallet&platform_id=5' }, gates: keys.map((key, i) => ({ key, label: labels[i], passed: i > 1 && i < 5, message: i === 0 ? 'CRM and WordPress addresses differ. Use the WordPress address, then re-check.' : 'Complete this saved configuration step, then re-check.' })), server: { host_setup: { public_root: '/home/fixture/public_html', storage_location: '/home/fixture/exotic-private-content', config: "define('EXOTIC_PREMIUM_STORAGE_DIR', '/home/fixture/exotic-private-content');", commands: "mkdir -p -- '/home/fixture/exotic-private-content'", instructions: 'Create the private directory in cPanel, outside the public root. Place config before WordPress bootstrap.' }, video: { path: null, exec_available: true, libx264: false, aac: false }, issues: [{ code: 'ffmpeg_missing', message: 'Video processing is unavailable. Install FFmpeg, then re-check.' }] } } };
}
async function fixture(page, mutate = value => value) {
    let state = mutate(response());
    await page.route('**/api/crm/settings/monetization?*', route => route.fulfill({ json: state }));
    await page.route('**/api/crm/settings/monetization', route => route.fulfill({ json: state }));
    await page.goto('/tests/browser/fixtures/monetization-setup.html?platform_id=5');
    await expect(page.getByRole('heading', { name: 'Set up this market, one step at a time' })).toBeVisible();
    return { setState: value => { state = value; } };
}

test('failed validation and background reload preserve the price draft and audit reason', async ({ page }) => {
    await fixture(page);
    await page.route('**/api/crm/settings/monetization/markets/5', route => route.fulfill({ status: 422, json: { errors: { 'prices.0.price': ['Choose a price within the market limits.'] } } }));
    await page.getByRole('tab', { name: 'Pass pricing', exact: true }).click();
    const price = page.getByLabel('Standard price · UGX');
    await price.fill('750');
    await page.getByLabel('Reason for change').fill('Uganda draft rollout test');
    await page.getByRole('button', { name: 'Save & sync', exact: true }).click();
    await expect(price).toHaveValue('750');
    await expect(price).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByRole('status').first()).toContainText('Choose a price');
    await page.reload();
    await page.getByRole('tab', { name: 'Pass pricing', exact: true }).click();
    await expect(price).toHaveValue('750');
    await expect(page.getByLabel('Reason for change')).toHaveValue('Uganda draft rollout test');
});

test('server errors retain the draft and replace generic backend errors with repair copy', async ({ page }) => {
    await fixture(page);
    await page.route('**/api/crm/settings/monetization/markets/5', route => route.fulfill({ status: 500, json: { message: 'Server Error' } }));
    await page.getByRole('tab', { name: 'Offers & limits' }).click();
    await page.getByLabel('Minimum price', { exact: true }).fill('250');
    await page.getByLabel('Reason for change').fill('Test retained failed-save draft');
    await page.getByRole('button', { name: 'Save & sync', exact: true }).click();
    await expect(page.getByLabel('Minimum price', { exact: true })).toHaveValue('250');
    await expect(page.getByRole('status').first()).toContainText('Your draft is preserved');
    await expect(page.getByText('Server Error', { exact: true })).toHaveCount(0);
});

test('provider options, activation gates, canonical repair and mobile keyboard access', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await fixture(page);
    await expect(page.getByRole('checkbox', { name: 'PawaPay', exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox', { name: /KopoKopo/ })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Enable live market', exact: true })).toBeDisabled();
    await expect(page.getByText(/Market #5/)).toBeVisible();
    await expect(page.getByText('https://www.uganda.example.test/wp-json/exotic-crm-sync/v1', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Use reported canonical URL' }).click();
    await expect(page.getByLabel('Reason for change')).toBeFocused();
    await expect(page.getByRole('status').first()).toContainText('Enter a reason');
    const tab = page.getByRole('tab', { name: 'Guided setup' });
    await tab.focus(); await page.keyboard.press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Pass pricing', exact: true })).toBeFocused();
    await page.keyboard.press('Home'); await expect(tab).toBeFocused();
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 960 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.screenshot({ path: `output/playwright/monetization-setup-${width}.png`, fullPage: true });
    }
    expect(errors).toEqual([]);
});

test('saving and syncing gives a durable outcome and clears only the saved draft', async ({ page }) => {
    const state = response();
    const { setState } = await fixture(page);
    await page.route('**/api/crm/settings/monetization/markets/5', async route => {
        const submitted = route.request().postDataJSON();
        state.market.prices = submitted.prices; state.market.config_revision = 2; setState(state);
        await route.fulfill({ json: { market: state.market, sync: { status: 'synced' }, credentials: { status: 'synced' }, readiness: { ready: true } } });
    });
    await page.getByRole('tab', { name: 'Pass pricing', exact: true }).click();
    await page.getByLabel('Standard price · UGX').fill('900');
    await page.getByLabel('Reason for change').fill('Save this local rollout fixture');
    await page.getByRole('button', { name: 'Save & sync', exact: true }).click();
    await expect(page.getByRole('status').first()).toContainText('Saved and synced');
    await expect(page.getByLabel('Standard price · UGX')).toHaveValue('900');
    await expect.poll(() => page.evaluate(() => Object.keys(sessionStorage).filter(key => key.startsWith('monetization-draft:')))).toEqual([]);
});

test('market navigation preserves each market draft', async ({ page }) => {
    const state = response();
    await page.route('**/api/crm/settings/monetization?*', route => { const id = new URL(route.request().url()).searchParams.get('platform_id'); const value = structuredClone(state); value.market.platform_id = Number(id || 5); if (id === '1') value.setup.market.name = 'Kenya'; return route.fulfill({ json: value }); });
    await page.goto('/tests/browser/fixtures/monetization-setup.html?platform_id=5');
    await page.getByRole('tab', { name: 'Pass pricing', exact: true }).click();
    await page.getByLabel('Standard price · UGX').fill('875');
    await page.getByLabel('Reason for change').fill('Preserve Uganda during switching');
    await page.getByRole('combobox', { name: 'Market', exact: true }).selectOption('1');
    await expect(page.getByRole('combobox', { name: 'Market', exact: true })).toHaveValue('1');
    await expect(page.getByLabel('Standard price · UGX')).toHaveValue('500');
    await page.getByRole('combobox', { name: 'Market', exact: true }).selectOption('5');
    await expect(page.getByLabel('Standard price · UGX')).toHaveValue('875');
    await expect(page.getByLabel('Reason for change')).toHaveValue('Preserve Uganda during switching');
});

test('an unavailable provider has a repair path and cannot be selected', async ({ page }) => {
    await fixture(page, state => { state.setup.providers.available = []; state.setup.providers.unavailable.push({ key: 'pawapay', label: 'PawaPay', message: 'PawaPay is not enabled for Uganda production. Configure it in Wallet System.' }); return state; });
    await expect(page.getByRole('checkbox', { name: 'PawaPay', exact: true })).toHaveCount(0);
    await expect(page.getByText('No checkout provider is available', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Settings → Wallet System', exact: true })).toHaveAttribute('href', '/settings?tab=integrations&integrationArea=wallet&platform_id=5');
    await page.getByRole('button', { name: 'Remove unavailable PawaPay' }).click();
    await expect(page.getByRole('button', { name: 'Enable live market', exact: true })).toBeDisabled();
});

test('ready settings require the explicit audited activation action', async ({ page }) => {
    const state = response(); state.setup.ready_to_enable = true; state.setup.gates.forEach(gate => { gate.passed = true; });
    const { setState } = await fixture(page, () => state);
    let activations = 0;
    await page.route('**/api/crm/settings/monetization/markets/5/setup/activate', async route => {
        const body = route.request().postDataJSON(); expect(body.reason).toBe('Explicit local activation fixture'); expect(body.config_revision).toBe(1); activations++;
        state.market.enabled = true; state.market.rollout_mode = 'live'; state.market.config_revision = 2; state.setup.is_live = true; setState(state);
        await route.fulfill({ json: { market: state.market, setup: state.setup, message: 'Live market enabled and synced. Configuration checks do not verify a real payment.' } });
    });
    expect(activations).toBe(0);
    await page.getByLabel('Reason for change').fill('Explicit local activation fixture');
    await page.getByRole('button', { name: 'Enable live market', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Market is live', exact: true })).toBeDisabled();
    await expect(page.getByRole('status').first()).toContainText('do not verify a real payment');
    expect(activations).toBe(1);
});
