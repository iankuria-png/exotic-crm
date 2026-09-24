import path from 'node:path';
import { test, expect } from '@playwright/test';

// Bio text warnings in Client detail → Edit Profile and the SEO bio review
// modal. All CRM API calls are stubbed. Set BIO_TEXT_SCREENSHOT_DIR to keep
// screenshots for review.

const CLIENT_ID = 921811;
const SCREENSHOT_DIR = process.env.BIO_TEXT_SCREENSHOT_DIR || '';
const GARBLED = '<p>Rencontre sans dÃ©tour, une prÃ©sence qui met Ã l\'aise.</p>';
const REPAIRED = '<p>Rencontre sans détour, une présence qui met à l\'aise.</p>';

function clientPayload() {
    return {
        id: CLIENT_ID,
        platform_id: 1,
        wp_post_id: 130664,
        wp_user_id: 33089,
        wp_profile_permalink: 'https://ci.example.test/escort/amina/',
        wp_profile_slug: 'amina',
        name: 'Amina',
        phone_normalized: '2250700000000',
        city: 'Cocody',
        profile_status: 'publish',
        verified: false,
        platform: { id: 1, name: 'Côte d’Ivoire', phone_prefix: '225', currency_code: 'XOF', billing_method_policy: {}, payment_link_providers: { active_provider: null, providers: {} } },
        deals: [],
        notes: [],
        payments: [],
        active_deal: null,
    };
}

async function stub(page, { bio = GARBLED, onSave = () => {}, generated = null } = {}) {
    await page.addInitScript(() => {
        window.localStorage.setItem('crm_token', 'browser-test-token');
        window.localStorage.setItem('crm_user', JSON.stringify({ id: 1, role: 'admin', name: 'Admin' }));
        window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
    });
    await page.route('**/api/crm/me', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ user: { id: 1, role: 'admin', name: 'Admin' } }) }));
    await page.route('**/api/crm/products*', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: [] }) }));
    // Registered first: Playwright tries the most recent route first.
    await page.route('**/api/crm/seo/**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true }) }));
    await page.route('**/api/crm/seo/provider-options*', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ options: [] }) }));
    await page.route('**/api/crm/seo/generate-bio', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ bio_html: generated, score: 87, breakdown: { word_count: 20, links: 10, completeness: 20, media: 20 }, language: 'fr', provider_used: 'openrouter' }),
    }));
    await page.route(`**/api/crm/clients/${CLIENT_ID}/wp-profile`, async (route) => {
        if (route.request().method() === 'PATCH') {
            onSave(route.request().postDataJSON());
            return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true }) });
        }
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ wp_profile: { name: 'Amina', post: { title: 'Amina', content: bio }, meta: {}, taxonomies: {} } }) });
    });
    await page.route(`**/api/crm/clients/${CLIENT_ID}`, (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(clientPayload()) }));
}

async function openEditProfile(page) {
    await page.goto(`/clients/${CLIENT_ID}`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'Edit Profile' }).click();
    await expect(page.getByText('Profile Bio')).toBeVisible();
}

async function snap(target, name) {
    if (!SCREENSHOT_DIR) return;
    await target.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`) });
}

test.describe('bio text warnings while editing', () => {
    test('the profile editor flags garbled accents and fixes them in one click', async ({ page }) => {
        const saves = [];
        await stub(page, { onSave: (body) => saves.push(body) });
        await openEditProfile(page);

        const check = page.getByTestId('bio-text-check');
        await expect(check).toContainText('This bio would look broken on the profile');
        await expect(check.locator('del', { hasText: 'dÃ©tour,' })).toBeVisible();
        await expect(check.locator('ins', { hasText: 'détour,' })).toBeVisible();
        await check.scrollIntoViewIfNeeded();
        await snap(check, 'editor-inline-check');

        await check.getByRole('button', { name: 'Fix accents' }).click();
        await expect(page.getByTestId('bio-text-check')).toHaveCount(0);
        await expect(page.locator('textarea[placeholder="Public profile description"]')).toHaveValue(REPAIRED);

        await page.getByRole('button', { name: 'Save profile changes' }).click();
        await expect.poll(() => saves.length).toBe(1);
        expect(saves[0].fields.content).toBe(REPAIRED);
    });

    test('saving a bio with problems asks first, and can save it as it is', async ({ page }) => {
        const saves = [];
        await stub(page, { bio: '<p>Douce et discrète.</p>', onSave: (body) => saves.push(body) });
        await openEditProfile(page);

        await page.locator('textarea[placeholder="Public profile description"]').fill('<p>Douce et discrète 💋 à bientôt ✨</p>');
        await page.getByRole('button', { name: 'Save profile changes' }).click();

        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toContainText('This bio has text worth tidying');
        await expect(dialog.getByRole('button', { name: 'Fix and save' })).toBeFocused();
        await snap(dialog, 'editor-save-warning');
        expect(saves).toHaveLength(0);

        await dialog.getByRole('button', { name: 'Save as it is' }).click();
        await expect.poll(() => saves.length).toBe(1);
        expect(saves[0].fields.content).toBe('<p>Douce et discrète 💋 à bientôt ✨</p>');
    });

    test('the review modal will not use a garbled draft without asking, and fixes it on the way in', async ({ page }) => {
        const generated = '<p>A <a href="/escorts/cocody">Cocody</a>, pour une rencontre sans dÃ©tour. Une prÃ©sence qui met Ã l\'aise.</p>';
        await stub(page, { bio: '', generated });
        await openEditProfile(page);

        await page.getByRole('button', { name: /Generate SEO Bio/ }).click();
        await expect(page.getByText('1 text problem')).toBeVisible();
        const modalCheck = page.getByTestId('bio-text-check');
        await expect(modalCheck).toContainText('Garbled accents');
        await snap(page, 'modal-inline-check');

        await page.getByRole('button', { name: 'Use this bio' }).click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toContainText('Before you use it');
        await snap(dialog, 'modal-use-warning');
        await dialog.getByRole('button', { name: 'Fix and use it' }).click();

        await expect(page.locator('textarea[placeholder="Public profile description"]')).toHaveValue(
            // The modal escapes apostrophes when it rebuilds the HTML.
            '<p>A <a href="/escorts/cocody">Cocody</a>, pour une rencontre sans détour. Une présence qui met à l&#039;aise.</p>',
        );
    });
});
