import { test, expect } from '@playwright/test';

const CRM_USER = {
    id: 1,
    role: 'admin',
    name: 'Admin',
    email: 'admin@example.test',
};

const rows = [
    {
        id: 912345,
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
        featured: false,
        verified: false,
        main_image_url: '',
        display_image_url: '',
        last_online_at: null,
        signup_source: 'fast_signup',
        platform: { id: 1, name: 'Kenya' },
        active_deal: null,
        plan_label: 'Basic',
        plan_key: 'basic',
        retention_insight: { band: 'Stable', primary_tag: 'Stable' },
        lifetime_payment_count: 0,
    },
    {
        id: 912346,
        platform_id: 1,
        wp_post_id: 20026,
        wp_user_id: 34648,
        wp_profile_url: 'https://kenya.example.test/?p=20026',
        wp_profile_permalink: null,
        wp_profile_slug: null,
        name: 'Short Link',
        phone_normalized: '254712345679',
        email: 'short@example.test',
        city: 'Mombasa',
        profile_status: 'publish',
        needs_payment: false,
        notactive: false,
        premium: false,
        featured: false,
        verified: false,
        main_image_url: '',
        display_image_url: '',
        last_online_at: null,
        signup_source: 'crm_manual',
        platform: { id: 1, name: 'Kenya' },
        active_deal: null,
        plan_label: 'Basic',
        plan_key: 'basic',
        retention_insight: { band: 'Stable', primary_tag: 'Stable' },
        lifetime_payment_count: 0,
    },
];

function clientsPayload(search = '') {
    return {
        current_page: 1,
        data: rows,
        from: 1,
        last_page: 1,
        per_page: 50,
        to: rows.length,
        total: rows.length,
        stats: {
            total: rows.length,
            active: 1,
            premium: 0,
            verified: 0,
            high_risk: 0,
            inactive: 0,
            expired_public: 0,
            archived: 0,
            with_chat: 0,
            online_now: 0,
            new_users: 0,
            retention_watch: 0,
            segments: {},
            closed_recent: 0,
            closed_recent_7d: 0,
            purging_soon: 0,
        },
        search_resolution: search
            ? {
                mode: 'conflict',
                source: 'stored_permalink_conflict',
                matched_client_ids: [912345],
                crm_wp_post_id: 10026,
                live_resolved_wp_post_id: 44822,
                matched_platform_ids: [1],
            }
            : null,
    };
}

async function stubClientsPage(page) {
    await page.addInitScript(({ user }) => {
        window.localStorage.setItem('crm_token', 'browser-test-token');
        window.localStorage.setItem('crm_user', JSON.stringify(user));
        window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
        window.__copiedText = '';
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: async (text) => {
                    window.__copiedText = text;
                },
            },
        });
    }, { user: CRM_USER });

    await page.route('**/api/crm/me', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ user: CRM_USER }) });
    });

    await page.route('**/api/crm/clients/cities*', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ cities: [] }) });
    });

    await page.route('**/api/crm/settings/integrations*', async (route) => {
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                platforms: [
                    {
                        platform_id: 1,
                        platform_name: 'Kenya',
                        name: 'Kenya',
                        phone_prefix: '254',
                    },
                ],
            }),
        });
    });

    await page.route('**/api/crm/clients*', async (route) => {
        const url = new URL(route.request().url());
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify(clientsPayload(url.searchParams.get('search') || '')),
        });
    });
}

test.describe('clients profile URL table identity', () => {
    test('shows slug actions, copies best URL, and renders conflict notices', async ({ page }) => {
        await stubClientsPage(page);

        await page.goto('/clients', { waitUntil: 'domcontentloaded' });

        await expect(page.getByText('faithvideossquirtingnudes')).toBeVisible();
        await expect(page.getByText('?p=20026')).toBeVisible();
        await expect(page.getByText('Open profile')).toHaveCount(0);

        const permalinkAction = page.getByRole('link', { name: 'Open public profile for Faith Videos' });
        await expect(permalinkAction).toHaveAttribute('href', 'https://kenya.example.test/escort/faithvideossquirtingnudes/');
        await expect(page.getByRole('link', { name: 'Open public profile for Short Link' })).toHaveAttribute('href', 'https://kenya.example.test/?p=20026');

        await page.getByRole('button', { name: 'Copy profile URL for Faith Videos' }).click();
        await expect.poll(() => page.evaluate(() => window.__copiedText)).toBe('https://kenya.example.test/escort/faithvideossquirtingnudes/');
        await expect(page).toHaveURL(/\/clients$/);

        await page.getByPlaceholder('Name, phone, email, or profile URL...').fill('https://kenya.example.test/escort/faithvideossquirtingnudes/');
        await page.getByRole('button', { name: 'Run client search' }).click();

        await expect(page.getByText('CRM profile found, but WordPress resolves this URL differently')).toBeVisible();
        await expect(page.getByText(/CRM has WP #10026; WordPress resolves this URL to WP #44822/)).toBeVisible();
    });
});
