import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginViaApi, seedAuthState } from './support/auth.js';
import { missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

const CLIENT_ID = 912345;

function clientPayload(overrides = {}) {
    return {
        id: CLIENT_ID,
        platform_id: 1,
        wp_post_id: 10026,
        wp_user_id: 34647,
        wp_profile_url: 'https://kenya.example.test/?p=10026',
        wp_profile_permalink: 'https://kenya.example.test/escort/faithvideossquirtingnudes/',
        wp_profile_slug: 'faithvideossquirtingnudes',
        name: 'Faith Videos',
        phone_normalized: '254712345678',
        email: 'faith@example.test',
        city: 'Nairobi',
        profile_status: 'publish',
        needs_payment: false,
        notactive: false,
        premium: true,
        premium_expire: null,
        featured: false,
        featured_expire: null,
        escort_expire: null,
        verified: false,
        force_new: false,
        new_badge_mode: 'auto',
        main_image_url: '',
        display_image_url: '',
        last_online_at: null,
        last_synced_at: '2026-05-06T09:00:00.000000Z',
        platform: {
            id: 1,
            name: 'Kenya',
            phone_prefix: '254',
            currency_code: 'KES',
            billing_method_policy: {},
            payment_link_providers: { active_provider: null, providers: {} },
        },
        assigned_agent: null,
        deals: [],
        notes: [],
        payments: [],
        active_deal: {
            id: 5001,
            plan_type: 'vip',
            status: 'active',
            expires_at: '2099-01-15T09:00:00.000000Z',
            product: { id: 9, name: 'VIP Profile', display_name: 'VIP Profile', slug: 'vip-profile', tier: 'vip' },
        },
        can_deactivate_without_deal: false,
        ...overrides,
    };
}

async function stubClientDetail(page, client = clientPayload()) {
    await page.route('**/api/crm/me', async (route) => {
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ user: { id: 1, role: 'admin', name: 'Admin' } }),
        });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}/completeness`, async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ score: 80, checks: [] }) });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}/retention-insight`, async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ score: 10, band: 'low', components: [] }) });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}/tours`, async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });
    await page.route('**/api/crm/products*', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}`, async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify(client) });
    });
}

test.describe('client detail profile URL peek and copy', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('shows permalink, short URL, slug, and copies URL plus expiry message', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await page.addInitScript(() => {
            window.__copiedText = '';
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: async (text) => {
                        window.__copiedText = text;
                    },
                },
            });
        });
        await stubClientDetail(page);

        await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Peek' }).click();

        await expect(page.getByText('https://kenya.example.test/escort/faithvideossquirtingnudes/')).toBeVisible();
        await expect(page.getByText('https://kenya.example.test/?p=10026')).toBeVisible();
        await expect(page.getByText('faithvideossquirtingnudes')).toBeVisible();
        await expect(page.getByText(/Subscription will expire on/)).toBeVisible();

        await page.getByRole('button', { name: 'Copy message' }).click();
        await expect.poll(() => page.evaluate(() => window.__copiedText)).toContain('Profile: https://kenya.example.test/escort/faithvideossquirtingnudes/');
        await expect.poll(() => page.evaluate(() => window.__copiedText)).toContain('Subscription will expire on');
    });

    test('falls back to the short URL and surfaces expired or forever states', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await page.addInitScript(() => {
            window.__copiedText = '';
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: async (text) => {
                        window.__copiedText = text;
                    },
                },
            });
        });
        await stubClientDetail(page, clientPayload({
            wp_profile_permalink: null,
            wp_profile_slug: null,
            active_deal: null,
            escort_expire: 1579046400,
        }));

        await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Peek' }).click();
        await expect(page.getByText('Not synced yet')).toBeVisible();
        await expect(page.getByText(/Subscription expired on/)).toBeVisible();
        await page.getByRole('button', { name: 'Copy message' }).click();
        await expect.poll(() => page.evaluate(() => window.__copiedText)).toContain('Profile: https://kenya.example.test/?p=10026');
        await expect.poll(() => page.evaluate(() => window.__copiedText)).toContain('Subscription expired on');
    });

    test('shows clipboard failure when copying is unavailable', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: undefined,
            });
        });
        await stubClientDetail(page, clientPayload({
            active_deal: null,
            deals: [],
            premium: false,
            featured: false,
            escort_expire: null,
            premium_expire: null,
            featured_expire: null,
        }));

        await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Peek' }).click();
        await expect(page.getByText('Subscription does not expire')).toBeVisible();
        await page.getByRole('button', { name: 'Copy message' }).click();
        await expect(page.getByText('Profile message could not be copied.')).toBeVisible();
    });
});
