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

async function seedBrowserAuth(page) {
    await page.addInitScript(() => {
        window.localStorage.setItem('crm_token', 'browser-test-token');
        window.localStorage.setItem('crm_user', JSON.stringify({ id: 1, role: 'sales', name: 'Sales User' }));
        window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
    });
}

async function openMediaTab(page) {
    await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Edit Profile' }).click();
    await page.getByRole('button', { name: 'Media' }).click();
}

test.describe('client detail media background upload', () => {
    test('clears the picker and keeps the media tab usable while upload is pending', async ({ page }) => {
        await seedBrowserAuth(page);
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

        await openMediaTab(page);

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

        await expect(page.getByText(/Uploading/).first()).toBeVisible();
        await expect(page.getByRole('button', { name: '1 upload active' })).toBeVisible();
        await expect(uploadButton).toBeDisabled();
        await expect(fileInput).toBeEnabled();
        await expect(page.getByText('You can keep working while media finishes.')).toBeVisible();
        expect(mediaPostCount).toBe(1);

        await expect(page.getByRole('main').getByText('1 media file uploaded to WordPress.', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: '1 upload active' })).toHaveCount(0);
    });

    test('failed upload shows manual retry and never retries automatically', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);

        let mediaPostCount = 0;
        await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, async (route) => {
            if (route.request().method() === 'POST') {
                mediaPostCount += 1;
                if (mediaPostCount === 1) {
                    await route.fulfill({
                        status: 503,
                        contentType: 'application/json',
                        body: JSON.stringify({ message: 'WordPress upload temporarily unavailable.' }),
                    });
                    return;
                }

                await route.fulfill({
                    contentType: 'application/json',
                    body: JSON.stringify({ success: true, uploaded_count: 1 }),
                });
                return;
            }

            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
        });

        await openMediaTab(page);

        const fileInput = page.locator('input[type="file"][accept*="image/jpeg"]');
        await fileInput.setInputFiles({
            name: 'retry-photo.jpeg',
            mimeType: 'image/jpeg',
            buffer: Buffer.from('fake image bytes'),
        });
        await page.getByRole('button', { name: 'Upload in background' }).click();

        await expect(page.getByText('WordPress upload temporarily unavailable.').first()).toBeVisible();
        await expect(page.getByRole('button', { name: '1 upload failed' })).toBeVisible();
        expect(mediaPostCount).toBe(1);

        await page.waitForTimeout(1000);
        expect(mediaPostCount).toBe(1);

        await page.getByRole('main').getByRole('button', { name: 'Retry' }).click();
        await expect(page.getByRole('main').getByText('1 media file uploaded to WordPress.', { exact: true })).toBeVisible();
        expect(mediaPostCount).toBe(2);
    });

    test('mixed media uploads one file per request sequentially with per-file status', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);

        let mediaPostCount = 0;
        let inFlightPostCount = 0;
        let maxInFlightPostCount = 0;
        const releases = [];

        await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, async (route) => {
            if (route.request().method() === 'POST') {
                mediaPostCount += 1;
                const currentPostCount = mediaPostCount;
                inFlightPostCount += 1;
                maxInFlightPostCount = Math.max(maxInFlightPostCount, inFlightPostCount);

                await new Promise((resolve) => {
                    releases.push(resolve);
                });

                inFlightPostCount -= 1;
                await route.fulfill({
                    contentType: 'application/json',
                    body: JSON.stringify({
                        success: true,
                        uploaded_count: 1,
                        attachment: {
                            id: 9000 + currentPostCount,
                            url: `https://ghana.example.test/wp-content/uploads/media-${currentPostCount}`,
                            mime_type: currentPostCount === 3 ? 'video/mp4' : 'image/jpeg',
                            is_main: false,
                        },
                    }),
                });
                return;
            }

            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
        });

        await openMediaTab(page);

        const fileInput = page.locator('input[type="file"][accept*="image/jpeg"]');
        await fileInput.setInputFiles([
            {
                name: 'first-photo.jpeg',
                mimeType: 'image/jpeg',
                buffer: Buffer.from('fake first image bytes'),
            },
            {
                name: 'second-photo.jpeg',
                mimeType: 'image/jpeg',
                buffer: Buffer.from('fake second image bytes'),
            },
            {
                name: 'intro-video.mp4',
                mimeType: 'video/mp4',
                buffer: Buffer.from('fake mp4 bytes'),
            },
        ]);

        await expect(page.getByText('2 images, 1 video')).toBeVisible();
        await page.getByRole('button', { name: 'Upload in background' }).click();

        await expect.poll(() => mediaPostCount).toBe(1);
        await expect(page.getByText('first-photo.jpeg')).toBeVisible();
        await expect(page.getByText('second-photo.jpeg')).toBeVisible();
        await expect(page.getByText('intro-video.mp4')).toBeVisible();

        await page.waitForTimeout(150);
        expect(mediaPostCount).toBe(1);
        releases.shift()();

        await expect.poll(() => mediaPostCount).toBe(2);
        await page.waitForTimeout(150);
        expect(mediaPostCount).toBe(2);
        releases.shift()();

        await expect.poll(() => mediaPostCount).toBe(3);
        await page.waitForTimeout(150);
        expect(mediaPostCount).toBe(3);
        releases.shift()();

        await expect(page.getByRole('main').getByText('3 media files uploaded to WordPress.', { exact: true })).toBeVisible();
        expect(maxInFlightPostCount).toBe(1);
    });

    test('oversized files are blocked before posting to CRM', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);

        let mediaPostCount = 0;
        await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, async (route) => {
            if (route.request().method() === 'POST') {
                mediaPostCount += 1;
            }
            await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) });
        });

        await openMediaTab(page);

        const fileInput = page.locator('input[type="file"][accept*="image/jpeg"]');
        await fileInput.setInputFiles({
            name: 'too-large.jpeg',
            mimeType: 'image/jpeg',
            buffer: Buffer.alloc(6 * 1024 * 1024, 1),
        });

        await expect(page.getByText(/Images must be 5MB or smaller/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Upload in background' })).toBeDisabled();
        expect(mediaPostCount).toBe(0);
    });
});
