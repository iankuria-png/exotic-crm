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
});
