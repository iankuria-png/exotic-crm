import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

test.describe('settings pbn smoke coverage', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('sales can use settings pbn preview and queue shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('sales'), missingRoleMessage('sales'));

        await page.route('**/api/crm/settings/integrations', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    services: {},
                    wallet: {},
                    platforms: [
                        { platform_id: 5, platform_name: 'Exotic Uganda', country: 'Uganda', domain: 'exoticuganda.com' },
                    ],
                    scraper: { sources: [], recent_runs: [] },
                },
            });
        });

        await page.route('**/api/crm/settings/integrations/pbn-sites', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    platforms: [
                        { platform_id: 5, platform_name: 'Exotic Uganda', country: 'Uganda', domain: 'exoticuganda.com' },
                    ],
                    sites: [
                        {
                            id: 1,
                            name: 'Uganda Hot Girls',
                            domain: 'ugandahotgirls.com',
                            status: 'ready',
                            is_active: true,
                            country: 'Uganda',
                            timezone: 'Africa/Nairobi',
                            currency_code: 'UGX',
                            phone_prefix: '256',
                            default_source_platform_id: 5,
                            source_platform_ids: [5],
                            sources: [{ platform_id: 5, platform_name: 'Exotic Uganda', country: 'Uganda' }],
                            copy_policy: { post_status: 'publish', phone: 'copy', media: 'two_stage' },
                            wp_sync: { credentials_ready: true, api_url: 'https://ugandahotgirls.com/wp-json/exotic-crm-sync/v1', api_user: 'crm' },
                            wp_provisioning: { credentials_ready: true, db_host: '127.0.0.1', db_name: 'wp', db_user: 'root', db_prefix: 'wp_', db_pass_configured: true },
                            latest_seed: null,
                            can_configure: false,
                            can_seed: true,
                        },
                    ],
                },
            });
        });

        await page.route('**/api/crm/settings/integrations/pbn-sites/1/locations', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    locations: [
                        { id: 10, name: 'Central', children: [{ id: 20, name: 'Kampala' }] },
                    ],
                },
            });
        });

        await page.route('**/api/crm/settings/integrations/pbn-sites/1/preview', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    preview_token: 'a'.repeat(64),
                    pbn_site_id: 1,
                    target_count: 1,
                    eligible_count: 1,
                    selected_count: 1,
                    selected_client_ids: [101],
                    warnings: [],
                    candidates: [
                        {
                            client_id: 101,
                            source_platform_id: 5,
                            source_platform_name: 'Exotic Uganda',
                            name: 'Sharon',
                            city: 'Kampala',
                            seo_score: 92,
                            duplicate_state: 'none',
                            selected: true,
                        },
                    ],
                },
            });
        });

        await page.route('**/api/crm/settings/integrations/pbn-sites/1/batches', async (route) => {
            await route.fulfill({
                status: 201,
                contentType: 'application/json',
                json: { message: 'PBN seed batch queued.', batch: { id: 42, status: 'queued' } },
            });
        });

        await loginAndOpen(page, request, 'sales', '/settings?integrationArea=pbn');

        await expect(page).toHaveURL(/\/settings\?integrationArea=pbn$/);
        await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'PBN Websites' })).toBeVisible();
        await expect(page.getByRole('button', { name: /Seed profiles/i })).toBeVisible();

        await page.getByRole('button', { name: /Seed profiles/i }).click();
        await page.getByRole('button', { name: /Preview candidates/i }).click();
        await expect(page.getByText('Sharon')).toBeVisible();
        await page.getByRole('button', { name: /Queue 1 profiles/i }).click();
        await expect(page.getByText('PBN seed batch queued.')).toBeVisible();
    });

    test('admin can use dedicated pbn operations workspace shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const sitesPayload = {
            platforms: [
                { platform_id: 5, platform_name: 'Exotic Uganda', country: 'Uganda', domain: 'exoticuganda.com' },
            ],
            sites: [
                {
                    id: 1,
                    name: 'Uganda Hot Girls',
                    domain: 'ugandahotgirls.com',
                    status: 'ready',
                    is_active: true,
                    country: 'Uganda',
                    timezone: 'Africa/Nairobi',
                    currency_code: 'UGX',
                    phone_prefix: '256',
                    default_source_platform_id: 5,
                    source_platform_ids: [5],
                    sources: [{ platform_id: 5, platform_name: 'Exotic Uganda', country: 'Uganda' }],
                    copy_policy: { post_status: 'publish', phone: 'copy', media: 'two_stage' },
                    wp_sync: { credentials_ready: true, api_url: 'https://ugandahotgirls.com/wp-json/exotic-crm-sync/v1', api_user: 'crm' },
                    wp_provisioning: { credentials_ready: true, db_host: '127.0.0.1', db_name: 'wp', db_user: 'root', db_prefix: 'wp_', db_pass_configured: true },
                    latest_seed: { id: 42, status: 'partial', selected_count: 2, created_count: 1, failed_count: 1 },
                    can_configure: true,
                    can_seed: true,
                },
            ],
        };

        await page.route('**/api/crm/settings/integrations/pbn-sites', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', json: sitesPayload });
        });
        await page.route('**/api/crm/pbn/overview', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    sites: { total: 1, ready: 1, blocked: 0 },
                    batches: { active: 1, completed: 3, partial: 1, failed: 0, reverted: 0 },
                    items: { created: 28, created_last_7_days: 9, media_pending: 2, failed: 1, reverted: 0, skipped_duplicates: 4 },
                    recent_failures: [
                        {
                            id: 900,
                            batch_id: 42,
                            site_name: 'Uganda Hot Girls',
                            source_client_id: 101,
                            source_client: { name: 'Sharon', city: 'Kampala' },
                            failure_reason: 'REST timeout',
                            status: 'failed',
                        },
                    ],
                    can_revert: true,
                },
            });
        });
        await page.route('**/api/crm/pbn/batches?*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    data: [
                        {
                            id: 42,
                            pbn_site_id: 1,
                            site: { id: 1, name: 'Uganda Hot Girls', domain: 'ugandahotgirls.com', status: 'ready', is_active: true },
                            creator: { name: 'Ian Kuria', email: 'ian@example.test', role: 'admin' },
                            status: 'partial',
                            selected_count: 2,
                            created_count: 1,
                            failed_count: 1,
                            reverted_count: 0,
                            warnings: [],
                            notes: 'Kampala seed',
                            created_at: '2026-08-31 10:00:00',
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
                },
            });
        });
        await page.route('**/api/crm/pbn/items?*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    data: [
                        {
                            id: 500,
                            batch_id: 42,
                            site_name: 'Uganda Hot Girls',
                            source_platform_name: 'Exotic Uganda',
                            source_client_id: 101,
                            source_wp_post_id: 8801,
                            target_wp_post_id: 9901,
                            status: 'created',
                            quality_score: 92,
                            source_client: { name: 'Sharon', city: 'Kampala', display_image_url: null },
                            updated_at: '2026-08-31 10:05:00',
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
                },
            });
        });
        await page.route('**/api/crm/pbn/events?*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                json: {
                    data: [
                        {
                            id: 1,
                            pbn_site_id: 1,
                            site_name: 'Uganda Hot Girls',
                            batch_id: 42,
                            type: 'item_failed',
                            level: 'error',
                            message: 'PBN seed item failed.',
                            actor: { name: 'Ian Kuria' },
                            created_at: '2026-08-31 10:06:00',
                        },
                    ],
                    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
                },
            });
        });

        await loginAndOpen(page, request, 'admin', '/pbn');

        await expect(page).toHaveURL(/\/pbn$/);
        await expect(page.getByRole('link', { name: 'PBN' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'PBN' })).toBeVisible();
        await expect(page.getByText('Uganda Hot Girls')).toBeVisible();
        await expect(page.getByText('Ready Sites')).toBeVisible();
        await expect(page.getByText('Recent Failures')).toBeVisible();

        await page.getByRole('button', { name: 'Batches' }).click();
        await expect(page.getByText('Kampala seed')).toBeVisible();

        await page.getByRole('button', { name: 'Items' }).click();
        await expect(page.getByText('Sharon')).toBeVisible();

        await page.getByRole('button', { name: 'Observability' }).click();
        await expect(page.getByText('PBN seed item failed.')).toBeVisible();
    });
});
