import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { roleCredentialsAvailable } from './support/env.js';

const settingsPayload = {
    settings: {
        enabled: true,
        endpoint: '/api/mcp',
        protocol_versions: ['2026-07-28', '2025-03-26'],
        pii_mode: 'pseudonymous',
        limits: { rate_per_minute: 30, daily_row_budget: 200000, daily_bytes_budget: 50000000, on_exhaustion: 'throttle' },
        sql_hatch: { enabled: false, views: ['vw_mcp_lifecycle_rollup', 'vw_mcp_revenue_rollup'], default_row_limit: 50, max_row_limit: 500, timeout_seconds: 10 },
        sanitisation: { truncate_chars: 500, strip_urls: true, strip_credentials: true },
        audit: { retain_days: 90, alert_bytes_per_hour: 10000000 },
        tools: {},
    },
    endpoint: 'http://127.0.0.1:8000/api/mcp',
    tools: [
        { name: 'exotic_catalog', title: 'CRM catalog', description: 'List enabled MCP tools, markets and reporting coverage.', domain: 'catalog', min_role: 'sales', configured_role: 'sales', enabled: true, inputSchema: { properties: {} }, backing_service: 'Curated CRM service', views: [], returns_no: ['names', 'phones', 'emails', 'bios', 'free text'] },
        { name: 'exotic_revenue_summary', title: 'Revenue summary', description: 'Return CEO-dashboard revenue and customer-mix metrics for a window.', domain: 'revenue', min_role: 'sub_admin', configured_role: 'sub_admin', enabled: true, inputSchema: { properties: { window: { type: 'string' } } }, backing_service: 'CeoDashboardDataService', views: [], returns_no: ['names', 'phones', 'emails', 'bios', 'free text'] },
        { name: 'exotic_run_reporting_sql', title: 'Reporting SQL', description: 'Run a SELECT against explicitly allow-listed aggregate reporting views.', domain: 'schema', min_role: 'admin', configured_role: 'admin', enabled: false, inputSchema: { properties: { sql: { type: 'string' }, limit: { type: 'integer' } } }, backing_service: 'SqlSafetyValidator', views: ['vw_mcp_lifecycle_rollup'], returns_no: ['names', 'phones', 'emails', 'bios', 'free text'] },
    ],
};

const activityPayload = {
    rows: [{ id: 1, tool: 'exotic_revenue_summary', token_label: 'ian-laptop', status: 'success', row_count: 12, bytes_out: 1024, latency_ms: 240, created_at: new Date().toISOString(), refusal_reason: null }],
    summary: { calls_today: 12, rows_today: 340, bytes_today: 12400, refusals_today: 1, p95_latency_ms: 412, active_tokens: 2, expiring_tokens: 1 },
    tool_stats: { exotic_revenue_summary: { calls_7d: 96, bytes_7d: 8000, avg_latency_ms: 240, errors_7d: 0 } },
};

test.describe('MCP settings control station', () => {
    test.afterEach(async ({ page, request }) => cleanupAuthState(page, request));

    test('admin can inspect and operate every control-station surface', async ({ page, request }) => {
        await page.route('**/api/crm/settings/billing/overview*', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: { enabled: false, features: { workspace: false } } }));
        await page.route('**/api/crm/settings/mcp/activity*', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: activityPayload }));
        await page.route('**/api/crm/settings/mcp/tokens*', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: { tokens: [{ id: 1, label: 'ian-laptop', owner: 'Ian Kuria', role: 'admin', abilities: ['mcp:read'], status: 'active', expires_at: new Date(Date.now() + 86400000 * 90).toISOString(), last_used_at: new Date().toISOString(), calls_7d: 96, bytes_7d: 8000 }] } }));
        await page.route('**/api/crm/settings/mcp/tools/*/preview', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: { tool: 'exotic_catalog', payload: { tools: ['exotic_catalog'], markets: [] }, bytes: 48, row_count: 0, pii_scan: { clean: true, fields_checked: ['name', 'phone', 'email', 'bio'] } } }));
        await page.route('**/api/crm/settings/mcp/self-test', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: { checks: [{ key: 'config', status: 'ok', message: 'MCP configuration loaded.', remediation: 'Ready.' }, { key: 'database', status: 'ok', message: 'Application database reachable.', remediation: 'Ready.' }] } }));
        await page.route('**/api/crm/settings/mcp', async (route) => {
            if (route.request().method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', json: settingsPayload });
            return route.fulfill({ status: 200, contentType: 'application/json', json: { settings: settingsPayload.settings } });
        });

        if (roleCredentialsAvailable('admin')) {
            await loginAndOpen(page, request, 'admin', '/settings?tab=mcp');
        } else {
            await page.route('**/api/crm/me', async (route) => route.fulfill({ status: 200, contentType: 'application/json', json: { user: { id: 1, name: 'MCP Test Admin', email: 'mcp-test@example.com', role: 'admin' } } }));
            await page.addInitScript(() => {
                localStorage.setItem('crm_token', 'browser-test-token');
                localStorage.setItem('crm_user', JSON.stringify({ id: 1, name: 'MCP Test Admin', email: 'mcp-test@example.com', role: 'admin' }));
                sessionStorage.setItem('crm_session_token', 'browser-test-session');
            });
            page.__crmAuthToken = 'browser-test-token';
            await page.goto('/settings?tab=mcp', { waitUntil: 'domcontentloaded' });
        }
        await expect(page.getByText('MCP control station', { exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Govern the CRM data boundary' })).toBeVisible();
        await expect(page.getByText('12 KB')).toBeVisible();

        await page.getByRole('button', { name: /Tools/ }).click();
        await expect(page.getByRole('heading', { name: 'Tool registry' })).toBeVisible();
        await expect(page.getByText('exotic_run_reporting_sql', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: /exotic_revenue_summary/ }).click();
        await page.getByRole('button', { name: 'Preview payload' }).click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByRole('button', { name: 'Run preview' }).click();
        await expect(page.getByText('PII scan passed')).toBeVisible();
        await page.getByRole('button', { name: 'Close dialog' }).click();

        await page.getByRole('button', { name: /Tokens/ }).click();
        await expect(page.getByText('ian-laptop', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Mint token' }).click();
        await expect(page.getByRole('dialog')).toContainText('Mint an MCP token');
        await page.getByRole('button', { name: 'Cancel' }).click();

        await page.getByRole('button', { name: /Data & Privacy/ }).click();
        await expect(page.getByRole('heading', { name: 'Payload preview' })).toBeVisible();
        await page.getByRole('button', { name: 'Run payload preview' }).click();
        await expect(page.getByText('PII scan passed')).toBeVisible();

        await page.getByRole('button', { name: /Activity/ }).click();
        await expect(page.getByRole('heading', { name: 'Activity' })).toBeVisible();
        await expect(page.getByRole('cell', { name: 'exotic_revenue_summary' })).toBeVisible();
        await page.getByRole('button', { name: 'Export CSV' }).click();

        await page.setViewportSize({ width: 390, height: 844 });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow).toBeLessThanOrEqual(1);
        await page.screenshot({ path: 'tests/browser/artifacts/mcp-settings-mobile.png', fullPage: false });
    });
});
