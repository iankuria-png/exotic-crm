import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';

// Profile URLs tab (Clients → Profile URLs). All CRM API calls are stubbed.
// Set PROFILE_URL_AUDIT_FIXTURE_DIR to a folder holding audit-all.json and
// audit-<kind>.json captured from a local market to review real data; those
// captures stay out of the repository.

const CRM_USER = { id: 1, role: 'admin', name: 'Admin', email: 'admin@example.test' };
const SCREENSHOT_DIR = process.env.PROFILE_URL_SCREENSHOT_DIR || '';

const profile = (postId, title, status = 'publish', slug = title.toLowerCase().replace(/\s+/g, '-')) => ({
    post_id: postId,
    title,
    status,
    slug,
    url: status === 'trash' ? '' : `https://kenya.example.test/escort/${slug}/`,
});

const SYNTHETIC_ITEMS = {
    wrong_target: {
        kind: 'wrong_target',
        slug: 'lisa',
        post_type: 'escort',
        url: 'https://kenya.example.test/escort/lisa/',
        owner: null,
        trashed_owner: profile(502, 'Lisa', 'trash', 'lisa__trashed'),
        keep: null,
        release: [profile(501, 'Lisa Queen')],
        before: { state: 'redirect', profile: profile(501, 'Lisa Queen') },
        after: { state: 'not_found', profile: null },
    },
    revivable: {
        kind: 'revivable',
        slug: 'april',
        post_type: 'escort',
        url: 'https://kenya.example.test/escort/april/',
        owner: null,
        trashed_owner: null,
        keep: profile(611, 'April Nairobi'),
        release: [profile(610, 'April Old', 'private')],
        before: { state: 'not_found', profile: null },
        after: { state: 'redirect', profile: profile(611, 'April Nairobi') },
    },
    at_risk: {
        kind: 'at_risk',
        slug: 'abby',
        post_type: 'escort',
        url: 'https://kenya.example.test/escort/abby/',
        owner: profile(701, 'Abby', 'private'),
        trashed_owner: null,
        keep: null,
        release: [profile(700, 'Abbigael')],
        before: { state: 'owned', profile: profile(701, 'Abby', 'private') },
        after: { state: 'owned', profile: profile(701, 'Abby', 'private') },
    },
};

function syntheticAudit(kind = '', capabilities = []) {
    const items = kind ? [SYNTHETIC_ITEMS[kind]] : Object.values(SYNTHETIC_ITEMS);
    return {
        summary: {
            checked_at: new Date().toISOString(),
            capabilities,
            post_types: ['escort', 'agency'],
            urls: 3,
            aliases: 3,
            kinds: {
                wrong_target: { urls: 1, aliases: 1 },
                revivable: { urls: 1, aliases: 1 },
                at_risk: { urls: 1, aliases: 1 },
            },
            changes: { redirect_to_404: 1, '404_to_redirect': 1, retargeted: 0, unchanged: 1 },
        },
        items,
        total: items.length,
        page: 1,
        per_page: 25,
    };
}

function capturedAudit(kind = '') {
    const dir = process.env.PROFILE_URL_AUDIT_FIXTURE_DIR;
    if (!dir) return null;
    const file = path.join(dir, kind ? `audit-${kind}.json` : 'audit-all.json');
    return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : null;
}

const HEALTHY = {
    summary: {
        checked_at: new Date().toISOString(),
        urls: 0,
        aliases: 0,
        kinds: { wrong_target: { urls: 0, aliases: 0 }, revivable: { urls: 0, aliases: 0 }, at_risk: { urls: 0, aliases: 0 } },
        changes: { redirect_to_404: 0, '404_to_redirect': 0, retargeted: 0, unchanged: 0 },
    },
    items: [],
    total: 0,
    page: 1,
    per_page: 25,
};

const finishedRun = (overrides = {}) => ({
    id: 7,
    platform_id: 1,
    status: 'completed',
    requested_by: 'Admin',
    restored_by: null,
    target_urls: 713,
    urls_processed: 713,
    aliases_released: 920,
    released_by_kind: { wrong_target: 4, revivable: 197, at_risk: 719 },
    restored_count: 0,
    backup_count: 920,
    changes: null,
    notes: null,
    can_restore: true,
    created_at: '2026-09-24T09:00:00+00:00',
    started_at: '2026-09-24T09:00:02+00:00',
    finished_at: '2026-09-24T09:00:05+00:00',
    restored_at: null,
    ...overrides,
});

async function stubShell(page, healthHandler, { onRunsPost, onRestorePost } = {}) {
    await page.addInitScript(({ user }) => {
        window.localStorage.setItem('crm_token', 'browser-test-token');
        window.localStorage.setItem('crm_user', JSON.stringify(user));
        window.sessionStorage.setItem('crm_session_token', 'browser-test-session');
    }, { user: CRM_USER });

    await page.route('**/api/crm/me', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ user: CRM_USER }) }));
    await page.route('**/api/crm/settings/integrations*', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ platforms: [{ platform_id: 1, platform_name: 'Kenya', name: 'Kenya', phone_prefix: '254' }] }),
    }));
    await page.route('**/api/crm/clients*', (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ current_page: 1, data: [], last_page: 1, per_page: 50, total: 0, stats: { total: 0, active: 0, verified: 0, segments: {} } }),
    }));
    await page.route((url) => /\/api\/crm\/profile-url-health\/runs\/\d+\/restore$/.test(url.pathname), async (route) => {
        const body = onRestorePost ? onRestorePost(route) : { data: finishedRun({ status: 'restoring', can_restore: false }) };
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.route((url) => url.pathname === '/api/crm/profile-url-health/runs', async (route) => {
        const body = onRunsPost ? onRunsPost(route) : { data: finishedRun({ status: 'queued', urls_processed: 0, aliases_released: 0, backup_count: 0, can_restore: false }) };
        await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.route((url) => url.pathname === '/api/crm/profile-url-health', async (route) => {
        const url = new URL(route.request().url());
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify(healthHandler(url)) });
    });
}

async function openTab(page) {
    await page.goto('/clients?tab=profile_urls&platform_id=1', { waitUntil: 'domcontentloaded' });
    await page.getByRole('tab', { name: /Profile URLs/ }).click();
}

async function snap(page, name) {
    if (!SCREENSHOT_DIR) return;
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`), fullPage: true });
}

test.describe('clients → profile URLs', () => {
    test('shows where each old URL goes today and after the repair, and queues a repair', async ({ page }) => {
        let started = false;
        const requestedKinds = [];
        await stubShell(page, (url) => {
            const kind = url.searchParams.get('kind') || '';
            requestedKinds.push(kind);
            const audit = capturedAudit(kind) || syntheticAudit(kind);
            const runs = started
                ? [finishedRun({ status: 'running', urls_processed: 250, aliases_released: 302, backup_count: 302, can_restore: false })]
                : [finishedRun({ id: 6, status: 'restored', can_restore: false, restored_by: 'Admin', restored_count: 920 })];
            return { platform: { id: 1, name: 'Kenya' }, runs, available: true, audit };
        }, {
            onRunsPost: () => {
                started = true;
                return { data: finishedRun({ id: 8, status: 'queued', urls_processed: 0, aliases_released: 0, backup_count: 0, can_restore: false }) };
            },
        });

        await openTab(page);

        await expect(page.getByRole('button', { name: /Going to the wrong profile/ })).toBeVisible();
        await expect(page.getByText('Today').first()).toBeVisible();
        await expect(page.getByText('After repair').first()).toBeVisible();
        await snap(page, 'profile-urls-desktop');
        if (SCREENSHOT_DIR) {
            await page.getByRole('heading', { name: 'Affected URLs' }).scrollIntoViewIfNeeded();
            await snap(page, 'profile-urls-list');
            await page.getByRole('heading', { name: 'Repair history' }).scrollIntoViewIfNeeded();
            await snap(page, 'profile-urls-history');
        }

        if (!process.env.PROFILE_URL_AUDIT_FIXTURE_DIR) {
            await expect(page.getByText('1 old URL is sending visitors to the wrong profile')).toBeVisible();
            await expect(page.getByRole('link', { name: '/escort/lisa/' })).toBeVisible();
            await expect(page.getByText('404 — not found').first()).toBeVisible();
            await expect(page.getByText('Last used by “Lisa” (in trash)')).toBeVisible();
        }

        await page.getByRole('group', { name: 'Filter by case' }).getByRole('button', { name: /Can work again/ }).click();
        await expect.poll(() => requestedKinds.includes('revivable')).toBe(true);

        await page.getByRole('button', { name: /^Repair \d/ }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText('removed and backed up');
        await snap(page, 'profile-urls-confirm');
        await dialog.getByRole('button', { name: 'Start repair' }).click();

        await expect(page.getByRole('progressbar', { name: 'Repair progress' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Repair running…' })).toBeDisabled();
    });

    test('repairs only the URLs an admin ticks', async ({ page }) => {
        let posted = null;
        await stubShell(page, (url) => ({
            platform: { id: 1, name: 'Kenya' },
            runs: [finishedRun({ id: 5, scope: 'selected', target_urls: 2 })],
            available: true,
            audit: syntheticAudit(url.searchParams.get('kind') || '', ['scoped_repair']),
        }), {
            onRunsPost: (route) => {
                posted = route.request().postDataJSON();
                return { data: finishedRun({ id: 9, scope: 'selected', status: 'queued', target_urls: 2, urls_processed: 0, aliases_released: 0, backup_count: 0, can_restore: false }) };
            },
        });

        await openTab(page);

        await expect(page.getByRole('button', { name: 'Repair all 3 URLs' })).toBeVisible();
        await expect(page.getByText('2 selected URLs')).toBeVisible();
        await page.getByRole('checkbox', { name: 'Select /escort/lisa/' }).check();
        await page.getByRole('checkbox', { name: 'Select /escort/april/' }).check();

        const bar = page.getByRole('region', { name: 'Selected URLs' });
        await expect(bar).toContainText('2 URLs selected');
        await expect(bar).toContainText('1 stop misrouting · 1 start working · 2 stale claims removed');
        await expect(page.getByRole('checkbox', { name: 'Select all on this page' })).toHaveJSProperty('indeterminate', true);
        await snap(page, 'profile-urls-selected');

        await bar.getByRole('button', { name: 'Repair selected' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText('Repair 2 selected URLs?');
        await expect(dialog).toContainText('/escort/lisa/');
        await expect(dialog).not.toContainText('/escort/abby/');
        await dialog.getByRole('button', { name: 'Repair selected' }).click();

        await expect.poll(() => posted).not.toBeNull();
        expect(posted.targets).toEqual([
            { post_type: 'escort', slug: 'lisa' },
            { post_type: 'escort', slug: 'april' },
        ]);
        await expect(bar).toHaveCount(0);

        await page.getByRole('checkbox', { name: 'Select all on this page' }).check();
        await expect(page.getByRole('region', { name: 'Selected URLs' })).toContainText('3 URLs selected');
        await page.getByRole('button', { name: 'Clear' }).click();
        await expect(page.getByRole('region', { name: 'Selected URLs' })).toHaveCount(0);
    });

    test('hides selection on a market without scoped repair', async ({ page }) => {
        await stubShell(page, (url) => ({
            platform: { id: 1, name: 'Kenya' },
            runs: [],
            available: true,
            audit: syntheticAudit(url.searchParams.get('kind') || ''),
        }));

        await openTab(page);

        await expect(page.getByText(/Repairing chosen URLs needs exotic-crm-sync 1.3.14/)).toBeVisible();
        await expect(page.getByRole('checkbox')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Repair 3 URLs' })).toBeVisible();
    });

    test('restores a finished run from the history', async ({ page }) => {
        let restoreCalled = false;
        await stubShell(page, () => ({
            platform: { id: 1, name: 'Kenya' },
            runs: [finishedRun()],
            available: true,
            audit: HEALTHY,
        }), {
            onRestorePost: () => {
                restoreCalled = true;
                return { data: finishedRun({ status: 'restoring', can_restore: false }) };
            },
        });

        await openTab(page);

        await expect(page.getByText('Every old profile URL points where it should')).toBeVisible();
        await expect(page.getByText('Nothing to repair')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Backup CSV' })).toBeVisible();
        await snap(page, 'profile-urls-healthy');

        await page.getByRole('button', { name: 'Restore', exact: true }).click();
        await expect(page.getByRole('dialog')).toContainText('920');
        await page.getByRole('button', { name: 'Restore old URLs' }).click();
        await expect.poll(() => restoreCalled).toBe(true);
    });

    test('explains when the market needs the plugin update', async ({ page }) => {
        await stubShell(page, () => ({
            platform: { id: 1, name: 'Kenya' },
            runs: [],
            available: false,
            reason: 'plugin_outdated',
            message: 'This market needs exotic-crm-sync 1.3.13 or later before its profile URLs can be checked.',
        }));

        await openTab(page);

        await expect(page.getByText(/needs a plugin update first/)).toBeVisible();
        await expect(page.getByText(/exotic-crm-sync 1.3.13/)).toBeVisible();
        await snap(page, 'profile-urls-outdated');
    });

    test('stays usable on a phone', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await stubShell(page, (url) => ({
            platform: { id: 1, name: 'Kenya' },
            runs: [finishedRun()],
            available: true,
            audit: capturedAudit(url.searchParams.get('kind') || '') || syntheticAudit(url.searchParams.get('kind') || ''),
        }));

        await openTab(page);

        await expect(page.getByText('After repair').first()).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        await snap(page, 'profile-urls-mobile');
        expect(overflow).toBeLessThanOrEqual(1);
    });
});
