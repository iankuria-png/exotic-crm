import { test, expect } from '@playwright/test';

const CLIENT_ID = 921810;

function clientPayload() {
    return {
        id: CLIENT_ID,
        platform_id: 1,
        wp_post_id: 130663,
        wp_user_id: 33088,
        wp_profile_url: 'https://ghana.example.test/?p=130663',
        wp_profile_permalink: 'https://ghana.example.test/escort/background-upload/',
        wp_profile_slug: 'background-upload',
        name: 'Background Upload',
        phone_normalized: '233535106626',
        email: 'background@example.test',
        city: 'Ablekuma',
        profile_status: 'private',
        needs_payment: true,
        notactive: true,
        premium: false,
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
        last_synced_at: '2026-05-12T14:16:08.000000Z',
        platform: {
            id: 1,
            name: 'Ghana',
            phone_prefix: '233',
            currency_code: 'GHS',
            billing_method_policy: {},
            payment_link_providers: { active_provider: null, providers: {} },
        },
        assigned_agent: null,
        deals: [],
        notes: [],
        payments: [],
        active_deal: null,
        can_deactivate_without_deal: false,
    };
}

async function stubClientDetail(page) {
    await page.route('**/api/crm/me', async (route) => {
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ user: { id: 1, role: 'sales', name: 'Sales User' } }),
        });
    });
    await page.route('**/api/crm/products*', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}/wp-profile`, async (route) => {
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ post: { content: '' }, meta: {}, taxonomies: {} }),
        });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}`, async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify(clientPayload()) });
    });
}

test.describe('client detail media background upload', () => {
    test('clears the picker and keeps the media tab usable while upload is pending', async ({ page }) => {
        await page.addInitScript(() => {
            window.localStorage.setItem('crm_token', 'browser-test-token');
            window.localStorage.setItem('crm_user', JSON.stringify({ id: 1, role: 'sales', name: 'Sales User' }));
            window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
        });
        await stubClientDetail(page);

        let mediaPostCount = 0;
        let resolveUploadStarted;
        const uploadStarted = new Promise((resolve) => {
            resolveUploadStarted = resolve;
        });

        await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, async (route) => {
            if (route.request().method() === 'POST') {
                mediaPostCount += 1;
                resolveUploadStarted();
                await new Promise((resolve) => setTimeout(resolve, 750));

                await route.fulfill({
                    contentType: 'application/json',
                    body: JSON.stringify({
                        success: true,
                        uploaded_count: 1,
                        attachment: {
                            id: 7771,
                            url: 'https://ghana.example.test/wp-content/uploads/slow-photo.jpeg',
                            mime_type: 'image/jpeg',
                            is_main: false,
                        },
                    }),
                });
                return;
            }

            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
        });

        await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Edit Profile' }).click();
        await page.getByRole('button', { name: 'Media' }).click();

        const fileInput = page.locator('input[type="file"][accept*="image/jpeg"]');
        await fileInput.setInputFiles({
            name: 'slow-photo.jpeg',
            mimeType: 'image/jpeg',
            buffer: Buffer.from('fake image bytes'),
        });

        const uploadButton = page.getByRole('button', { name: 'Upload in background' });
        await expect(uploadButton).toBeEnabled();
        await uploadButton.click();
        await uploadStarted;

        await expect(page.getByText('Uploading in the background', { exact: true })).toBeVisible();
        await expect(uploadButton).toBeDisabled();
        await expect(fileInput).toBeEnabled();
        await expect(page.getByText('You can keep working while media finishes.')).toBeVisible();
        expect(mediaPostCount).toBe(1);

        await expect(page.getByRole('main').getByText('Media uploaded to WordPress.', { exact: true })).toBeVisible();
    });
});
