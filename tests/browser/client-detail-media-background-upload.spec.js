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

const TEST_IMAGE = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60" fill="#0f766e"/></svg>';

function photo(id = 7101) {
    return { id, title: 'Test photo', url: `https://ghana.example.test/wp-content/uploads/photo-${id}.jpeg`, mime_type: 'image/jpeg', is_main: false };
}

async function stubMedia(page, data) {
    await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, (route) => route.fulfill({ json: { data } }));
}

test.describe('client detail media background upload', () => {
    test('automatically recovers a failed image without HEAD checks or media writes', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        let mediaGets = 0;
        let mediaWrites = 0;
        let previewGets = 0;
        let previewHeads = 0;
        const requestedUrls = [];
        await page.route(`**/api/crm/clients/${CLIENT_ID}/media`, async (route) => {
            if (route.request().method() === 'GET') mediaGets += 1;
            else mediaWrites += 1;
            await route.fulfill({ json: { data: [photo()] } });
        });
        await page.route('**/api/crm/image-proxy*', async (route) => {
            if (route.request().method() === 'HEAD') previewHeads += 1;
            else previewGets += 1;
            requestedUrls.push(new URL(route.request().url()));
            await route.fulfill(previewGets === 1
                ? { status: 502, body: 'Temporarily unavailable' }
                : { contentType: 'image/svg+xml', body: TEST_IMAGE });
        });

        await openMediaTab(page);
        await expect(page.getByText('Retrying preview…')).toBeVisible();
        await expect(page.getByText('Preview unavailable', { exact: true })).toHaveCount(0);
        await expect(page.getByRole('img', { name: 'Test photo' })).toBeVisible();
        expect(previewGets).toBe(2);
        expect(previewHeads).toBe(0);
        expect(mediaGets).toBe(1);
        expect(mediaWrites).toBe(0);
        expect(requestedUrls[1].searchParams.get('url')).toBe(photo().url);
        expect(requestedUrls[1].searchParams.has('preview_attempt')).toBe(true);

        await page.getByRole('button', { name: 'Refresh media' }).click();
        await expect(page.getByText('Media refreshed from WordPress.')).toBeVisible();
        expect(mediaGets).toBe(2);
        expect(mediaWrites).toBe(0);
    });

    test('retries an empty successful response rather than trusting its HTTP status', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [photo()]);
        let requests = 0;
        await page.route('**/api/crm/image-proxy*', async (route) => {
            requests += 1;
            await route.fulfill({ contentType: 'image/svg+xml', body: requests === 1 ? '' : TEST_IMAGE });
        });
        await openMediaTab(page);
        await expect(page.getByRole('img', { name: 'Test photo' })).toBeVisible();
        expect(requests).toBe(2);
        expect(await page.getByRole('img', { name: 'Test photo' }).evaluate((img) => img.naturalWidth)).toBe(80);
    });

    test('stops after two retries, keeps actions usable and supports keyboard retry on mobile', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [photo()]);
        let recovered = false;
        let requests = 0;
        await page.route('**/api/crm/image-proxy*', async (route) => {
            requests += 1;
            await route.fulfill(recovered
                ? { contentType: 'image/svg+xml', body: TEST_IMAGE }
                : { status: 404, body: 'Missing' });
        });
        await openMediaTab(page);
        await expect(page.getByText('Preview unavailable', { exact: true })).toBeVisible();
        expect(requests).toBe(3);
        await page.waitForTimeout(1800);
        expect(requests).toBe(3);
        await expect(page.getByRole('button', { name: 'Set main', exact: true })).toBeEnabled();
        await expect(page.getByRole('button', { name: 'Delete', exact: true })).toBeEnabled();
        await page.getByRole('checkbox', { name: 'Select Test photo' }).check();
        await expect(page.getByRole('button', { name: 'Delete selected (1)' })).toBeEnabled();
        recovered = true;
        const retry = page.getByRole('button', { name: 'Retry preview for Test photo' });
        await retry.focus();
        await page.screenshot({ path: 'output/media-preview-reliability-2026-10-26/mobile-unavailable.png', fullPage: true });
        await page.keyboard.press('Enter');
        await expect(page.getByRole('img', { name: 'Test photo' })).toBeVisible();
        expect(requests).toBe(4);
        await expect(page.getByRole('checkbox', { name: 'Select Test photo' })).toBeChecked();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.screenshot({ path: 'output/media-preview-reliability-2026-10-26/mobile-recovered.png', fullPage: true });
    });

    test('opening Media again rechecks failures and cancels abandoned retries', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [photo()]);
        let recovered = false;
        let requests = 0;
        await page.route('**/api/crm/image-proxy*', async (route) => {
            requests += 1;
            await route.fulfill(recovered
                ? { contentType: 'image/svg+xml', body: TEST_IMAGE }
                : { status: 503, body: 'Market unavailable' });
        });
        await openMediaTab(page);
        await expect(page.getByText('Retrying preview…')).toBeVisible();
        await page.getByRole('button', { name: 'Personal Info', exact: true }).click();
        const requestsOnLeaving = requests;
        await page.waitForTimeout(1800);
        expect(requests).toBe(requestsOnLeaving);
        recovered = true;
        await page.getByRole('button', { name: 'Media', exact: true }).click();
        await expect(page.getByRole('img', { name: 'Test photo' })).toBeVisible();
        expect(requests).toBe(requestsOnLeaving + 1);
    });

    test('loads at most two previews concurrently and advances the queue', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, Array.from({ length: 6 }, (_, i) => photo(i + 1)));
        let started = 0;
        let active = 0;
        let peak = 0;
        const releases = [];
        await page.route('**/api/crm/image-proxy*', async (route) => {
            started += 1;
            active += 1;
            peak = Math.max(peak, active);
            await new Promise((resolve) => releases.push(resolve));
            active -= 1;
            await route.fulfill({ contentType: 'image/svg+xml', body: TEST_IMAGE });
        });
        await openMediaTab(page);
        await expect.poll(() => started).toBe(2);
        await page.waitForTimeout(250);
        expect(started).toBe(2);
        releases.shift()();
        await expect.poll(() => started).toBe(3);
        while (started < 6) {
            const previous = started;
            releases.shift()();
            await expect.poll(() => started).toBe(previous + 1);
        }
        releases.splice(0).forEach((release) => release());
        await expect(page.getByRole('img', { name: 'Test photo', exact: true })).toHaveCount(6);
        await expect(page.getByText('Loading preview…')).toHaveCount(0);
        expect(peak).toBe(2);
        await page.getByRole('img', { name: 'Test photo', exact: true }).first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'output/media-preview-reliability-2026-10-26/desktop-gallery.png', fullPage: true });
    });

    test('recovers a failed video and loads real playable metadata', async ({ page, browser, baseURL }) => {
        // Generate a harmless video locally; the browser must decode real media,
        // rather than treating a mocked loadedmetadata event as successful playback.
        const generator = await browser.newPage();
        await generator.route('**/*', (route) => route.fulfill({ contentType: 'text/html', body: '<html></html>' }));
        await generator.goto(baseURL);
        const bytes = await generator.evaluate(async () => {
            const canvas = document.createElement('canvas');
            canvas.width = 80;
            canvas.height = 60;
            const stream = canvas.captureStream(10);
            const recorder = new MediaRecorder(stream, { mimeType: 'video/webm;codecs=vp8' });
            const chunks = [];
            recorder.ondataavailable = (event) => chunks.push(event.data);
            const stopped = new Promise((resolve) => { recorder.onstop = resolve; });
            recorder.start();
            for (let frame = 0; frame < 8; frame += 1) {
                canvas.getContext('2d').fillStyle = frame % 2 ? '#0f766e' : '#115e59';
                canvas.getContext('2d').fillRect(0, 0, 80, 60);
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            recorder.stop();
            await stopped;
            stream.getTracks().forEach((track) => track.stop());
            return Array.from(new Uint8Array(await new Blob(chunks).arrayBuffer()));
        });
        await generator.close();
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [{ ...photo(), title: 'Test video', mime_type: 'video/webm', url: 'https://ghana.example.test/clip.webm' }]);
        let requests = 0;
        await page.route('**/api/crm/image-proxy*', async (route) => {
            requests += 1;
            await route.fulfill(requests === 1
                ? { status: 502, body: 'Temporary video failure' }
                : { contentType: 'video/webm', body: Buffer.from(bytes) });
        });
        await openMediaTab(page);
        const video = page.getByLabel('Test video', { exact: true });
        await expect(video).toBeVisible();
        await expect.poll(() => video.evaluate((element) => element.readyState)).toBeGreaterThanOrEqual(1);
        expect(await video.evaluate((element) => element.videoWidth)).toBe(80);
        await video.evaluate(async (element) => { element.muted = true; await element.play(); });
        await expect.poll(() => video.evaluate((element) => element.currentTime)).toBeGreaterThan(0);
        expect(requests).toBe(2);
    });

    test('a stalled preview times out and lets the next file load', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [photo(1), photo(2), { ...photo(3), title: 'Next photo' }]);
        await page.clock.install();
        const stalledRoutes = [];
        await page.route('**/api/crm/image-proxy*', async (route) => {
            const source = new URL(route.request().url()).searchParams.get('url');
            if (source === photo(3).url) {
                await route.fulfill({ contentType: 'image/svg+xml', body: TEST_IMAGE });
            } else {
                stalledRoutes.push(route);
            }
        });
        await openMediaTab(page);
        await expect.poll(() => stalledRoutes.length).toBe(2);
        await page.clock.fastForward(20050);
        await expect(page.getByRole('img', { name: 'Next photo' })).toBeVisible();
        await expect(page.getByText('Retrying preview…')).toHaveCount(2);
        await page.getByRole('button', { name: 'Personal Info', exact: true }).click();
        for (const route of stalledRoutes) await route.abort().catch(() => {});
    });

    test('a missing preview URL does not block other media or imply the file was deleted', async ({ page }) => {
        await seedBrowserAuth(page);
        await stubClientDetail(page);
        await stubMedia(page, [{ ...photo(1), url: '', title: 'No link' }, photo(2)]);
        let requests = 0;
        await page.route('**/api/crm/image-proxy*', async (route) => {
            requests += 1;
            await route.fulfill({ contentType: 'image/svg+xml', body: TEST_IMAGE });
        });
        await openMediaTab(page);
        await expect(page.getByText('No preview link is available for this file.')).toBeVisible();
        await expect(page.getByRole('img', { name: 'Test photo' })).toBeVisible();
        expect(requests).toBe(1);
        await expect(page.getByRole('button', { name: 'Retry preview for No link' })).toHaveCount(0);
    });

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
