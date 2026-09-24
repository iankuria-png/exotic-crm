import path from 'node:path';
import { test, expect } from '@playwright/test';

// Clients → Bio text. All CRM API calls are stubbed. Set BIO_TEXT_SCREENSHOT_DIR
// to keep full-page screenshots for review.

const CRM_USER = { id: 1, role: 'admin', name: 'Admin', email: 'admin@example.test' };
const SCREENSHOT_DIR = process.env.BIO_TEXT_SCREENSHOT_DIR || '';

const GARBLED_HTML = '<p>A <a href="/escorts/cocody">Cocody</a>, pour une rencontre sans dÃ©tour. Je propose un massage relaxant et une prÃ©sence qui met Ã l\'aise. DisponibilitÃ© flexible, oÃ¹ le naturel prime.</p>';
const REPAIRED_HTML = '<p>A <a href="/escorts/cocody">Cocody</a>, pour une rencontre sans détour. Je propose un massage relaxant et une présence qui met à l\'aise. Disponibilité flexible, où le naturel prime.</p>';

const scan = (overrides = {}) => ({
    id: 12,
    platform_id: 1,
    status: 'scanned',
    requested_by: 'Admin',
    repair_requested_by: null,
    restore_requested_by: null,
    total_profiles: 1840,
    profiles_scanned: 1840,
    profiles_unreadable: 0,
    profiles_affected: 47,
    profiles_fixable: 44,
    profiles_manual: 4,
    issue_counts: { broken_accents: 41, ai_text: 4 },
    status_counts: { found: 47 },
    open_fixable: 44,
    restorable: 0,
    repair: { target: 0, repaired: 0, unchanged: 0, failed: 0 },
    restore: { target: 0, restored: 0, failed: 0 },
    can_repair: true,
    can_restore: false,
    notes: null,
    created_at: '2026-09-24T09:00:00+00:00',
    started_at: '2026-09-24T09:00:01+00:00',
    scanned_at: new Date(Date.now() - 4 * 60 * 1000).toISOString(),
    repair_started_at: null,
    finished_at: '2026-09-24T09:06:00+00:00',
    ...overrides,
});

const finding = (overrides = {}) => ({
    id: 301,
    client_id: 5501,
    wp_post_id: 88123,
    name: 'Aïcha Cocody',
    profile_url: 'https://ci.example.test/escort/aicha/',
    issues: [{
        kind: 'broken_accents',
        severity: 'error',
        fixable: true,
        count: 5,
        samples: [{ found: 'dÃ©tour.', fixed: 'détour.' }, { found: 'prÃ©sence', fixed: 'présence' }],
    }],
    severity: 'error',
    fixable: true,
    status: 'found',
    error: null,
    original_html: GARBLED_HTML,
    repaired_html: null,
    repaired_at: null,
    restored_at: null,
    ...overrides,
});

const FINDINGS = [
    finding(),
    finding({
        id: 302,
        client_id: 5502,
        wp_post_id: 88124,
        name: 'Nadia Plateau',
        issues: [{ kind: 'ai_text', severity: 'error', fixable: false, count: 1, samples: [{ found: "Here's a revised bio for Nadia:", fixed: null }] }],
        fixable: false,
        original_html: "<p>Here's a revised bio for Nadia:</p><p>Discrète et chaleureuse, au Plateau.</p>",
    }),
    finding({
        id: 303,
        client_id: 5503,
        wp_post_id: 88125,
        name: 'Grace Marcory',
        status: 'repaired',
        repaired_html: REPAIRED_HTML,
        repaired_at: '2026-09-24T09:10:00+00:00',
    }),
];

async function stubShell(page, { overview, onScanPost, onAction }) {
    await page.addInitScript(({ user }) => {
        window.localStorage.setItem('crm_token', 'browser-test-token');
        window.localStorage.setItem('crm_user', JSON.stringify(user));
        window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
    }, { user: CRM_USER });

    await page.route('**/api/crm/me', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ user: CRM_USER }) }));
    await page.route('**/api/crm/settings/integrations*', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ platforms: [{ platform_id: 1, platform_name: 'Côte d’Ivoire', name: 'Côte d’Ivoire', phone_prefix: '225' }] }),
    }));
    await page.route('**/api/crm/clients*', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ current_page: 1, data: [], last_page: 1, per_page: 50, total: 0, stats: { total: 0, active: 0, verified: 0, segments: {} } }),
    }));
    await page.route((url) => url.pathname === '/api/crm/bio-text-health', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify(overview()),
    }));
    await page.route((url) => url.pathname === '/api/crm/bio-text-health/scans', (route) => route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ data: onScanPost ? onScanPost() : scan({ status: 'queued' }) }),
    }));
    await page.route((url) => /\/api\/crm\/bio-text-health\/scans\/\d+\/findings$/.test(url.pathname), (route) => {
        const view = new URL(route.request().url()).searchParams.get('view');
        const rows = view === 'manual' ? FINDINGS.filter((row) => !row.fixable) : FINDINGS;
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: rows, total: rows.length, page: 1, per_page: 25 }) });
    });
    await page.route((url) => /\/api\/crm\/bio-text-health\/scans\/\d+\/(repair|restore)$/.test(url.pathname), (route) => {
        const action = route.request().url().endsWith('/repair') ? 'repair' : 'restore';
        onAction?.(action, route.request().postDataJSON() || {});
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: scan({ status: action === 'repair' ? 'repairing' : 'restoring' }) }) });
    });
}

async function openTab(page) {
    await page.goto('/clients?tab=bio_text&platform_id=1', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: /Bio text/ }).click();
}

async function snap(page, name, section = null) {
    if (!SCREENSHOT_DIR) return;
    if (section) {
        const panel = page.locator('section', { has: page.getByRole('heading', { name: section, exact: true }) });
        await panel.scrollIntoViewIfNeeded();
        await panel.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`) });
        return;
    }
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

test.describe('clients → bio text', () => {
    test('introduces the check on a market that has never been checked, and starts one', async ({ page }) => {
        let started = false;
        await stubShell(page, {
            overview: () => ({
                platform: { id: 1, name: 'Côte d’Ivoire' },
                linked_profiles: 1840,
                scan: started ? scan({ status: 'scanning', profiles_scanned: 420, scanned_at: null }) : null,
                history: [],
            }),
            onScanPost: () => {
                started = true;
                return scan({ status: 'queued', profiles_scanned: 0, scanned_at: null });
            },
        });

        await openTab(page);
        await expect(page.getByRole('heading', { name: /Find bios in .* that read as broken text/ })).toBeVisible();
        await snap(page, 'bio-text-intro');

        await page.getByRole('button', { name: 'Check 1,840 bios' }).click();
        await expect(page.getByText('Reading bios from WordPress…')).toBeVisible();
        await expect(page.getByText('420 of 1,840 bios')).toBeVisible();
        await snap(page, 'bio-text-scanning');
    });

    test('shows each broken bio as proofreading marks and repairs the chosen ones', async ({ page }) => {
        const actions = [];
        await stubShell(page, {
            overview: () => ({
                platform: { id: 1, name: 'Côte d’Ivoire' },
                linked_profiles: 1840,
                scan: scan({ status_counts: { found: 46, repaired: 1 }, restorable: 1, can_restore: true, open_fixable: 43 }),
                history: [scan({ status_counts: undefined })],
            }),
            onAction: (action, body) => actions.push({ action, body }),
        });

        await openTab(page);

        await expect(page.getByText('47 bios show broken text to visitors and Google')).toBeVisible();
        await expect(page.getByRole('button', { name: /Garbled accents/ })).toBeVisible();
        const row = page.locator('li', { hasText: 'Aïcha Cocody' });
        await expect(row.locator('del', { hasText: 'dÃ©tour.' }).first()).toBeVisible();
        await expect(row.locator('ins', { hasText: 'détour.' }).first()).toBeVisible();
        await expect(page.locator('li', { hasText: 'Nadia Plateau' }).getByText('Rewrite by hand')).toBeVisible();
        await expect(page.locator('li', { hasText: 'Nadia Plateau' }).locator('mark').first()).toBeVisible();
        await snap(page, 'bio-text-review');
        await snap(page, 'bio-text-list', 'Bios with broken text');

        await row.getByRole('checkbox').check();
        await expect(page.getByRole('region', { name: 'Selected bios' })).toContainText('1 bio selected');
        await page.getByRole('region', { name: 'Selected bios' }).getByRole('button', { name: 'Repair 1' }).click();
        await expect(page.getByRole('dialog')).toContainText('Repair 1 bio?');
        await snap(page, 'bio-text-confirm');
        await page.getByRole('button', { name: 'Start repair' }).click();

        await expect.poll(() => actions.length).toBe(1);
        expect(actions[0]).toEqual({ action: 'repair', body: { finding_ids: [301] } });
    });

    test('a repaired bio can be compared before and after, and undone on its own', async ({ page }) => {
        const actions = [];
        await stubShell(page, {
            overview: () => ({
                platform: { id: 1, name: 'Côte d’Ivoire' },
                linked_profiles: 1840,
                scan: scan({ status: 'repaired', status_counts: { found: 46, repaired: 1 }, restorable: 1, can_restore: true }),
                history: [],
            }),
            onAction: (action, body) => actions.push({ action, body }),
        });

        await openTab(page);
        const row = page.locator('li', { hasText: 'Grace Marcory' });
        await row.getByRole('button', { name: 'Show before and after' }).click();
        await expect(row.getByText('WordPress now shows')).toBeVisible();
        await expect(row.getByText(/présence qui met à l'aise/)).toBeVisible();
        await snap(page, 'bio-text-before-after', 'Bios with broken text');

        await row.getByRole('button', { name: 'Undo' }).click();
        await expect(page.getByRole('dialog')).toContainText('Undo the repair of Grace Marcory’s bio?');
        await page.getByRole('button', { name: 'Put them back' }).click();
        await expect.poll(() => actions.length).toBe(1);
        expect(actions[0]).toEqual({ action: 'restore', body: { finding_ids: [303] } });
    });
});
