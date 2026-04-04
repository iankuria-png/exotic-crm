import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen, loginViaApi, seedAuthState } from './support/auth.js';
import { stubBillingWorkspace } from './support/billing.js';
import { missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

test.describe('billing browser smoke coverage', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('admin can reach settings wallet workspace', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        await loginAndOpen(page, request, 'admin', '/settings?integrationArea=wallet');

        const walletSurface = page.locator('section.crm-surface').filter({
            has: page.getByRole('heading', { name: 'Wallet Configuration' }),
        });

        await expect(page).toHaveURL(/\/settings\?integrationArea=wallet$/);
        await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Wallet Configuration' })).toBeVisible();
        await expect(walletSurface.getByText('Global wallet settings are read-only for this role.')).toHaveCount(0);
        await expect(walletSurface.locator('fieldset').first()).not.toHaveAttribute('disabled', '');
        await expect(walletSurface.locator('select').first()).toBeVisible();
        await expect(page.getByPlaceholder('KES')).toBeVisible();
    });

    test('admin can reach payments queue shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        await loginAndOpen(page, request, 'admin', '/payments');

        await expect(page).toHaveURL(/\/payments$/);
        await expect(page.getByRole('heading', { name: 'Payments' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auto-match queue' })).toBeVisible();
    });

    test('admin can open the billing workspace shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await stubBillingWorkspace(page);

        await page.goto('/settings', { waitUntil: 'domcontentloaded' });

        await expect(page.getByRole('button', { name: 'Billing' })).toBeVisible();
        await page.getByRole('button', { name: 'Billing' }).click();

        await expect(page.getByRole('heading', { name: 'Billing' })).toBeVisible();
        await expect(page.getByText(/Read-only Phase 0B shell/)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Overview' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Providers' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Billing System' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Diagnostics' })).toBeVisible();
    });

    test('admin lazy-loads billing diagnostics after opening the billing workspace', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);

        let overviewRequests = 0;
        let diagnosticsRequests = 0;
        await stubBillingWorkspace(page, {
            onOverviewRequest: () => {
                overviewRequests += 1;
            },
            onDiagnosticsRequest: () => {
                diagnosticsRequests += 1;
            },
        });

        await page.goto('/settings', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Billing' }).click();

        await expect(page.getByText(/Phase 0B Scope/)).toBeVisible();

        const overviewRequestsBeforeDiagnostics = overviewRequests;

        await page.getByRole('button', { name: 'Diagnostics' }).click();

        await expect(page.getByText('Billing Diagnostics Foundation')).toBeVisible();
        await expect(page.getByText('Wallet System')).toBeVisible();
        await expect(page.getByText('KopoKopo')).toBeVisible();
        await expect(page.getByText('Payment Service')).toBeVisible();
        await expect(page.getByText('SendGrid')).toBeVisible();
        await expect.poll(() => overviewRequests).toBe(overviewRequestsBeforeDiagnostics);
        await expect.poll(() => diagnosticsRequests).toBeGreaterThan(0);
    });

    test('admin sees providers tab forbidden state while registry rollout is disabled', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await stubBillingWorkspace(page, {
            features: {
                registry: false,
                workspace: true,
            },
        });

        await page.goto('/settings', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Billing' }).click();
        await page.getByRole('button', { name: 'Providers' }).click();

        await expect(page.getByRole('heading', { name: 'Provider registry is still locked' })).toBeVisible();
        await expect(page.getByText(/new provider-family registry stays read-only/i)).toBeVisible();
    });

    test('admin sees wallet auto-renew fallback on the legacy path while the feature is disabled', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const authPayload = await loginViaApi(request, 'admin');
        await seedAuthState(page, authPayload);
        await stubBillingWorkspace(page, {
            features: {
                wallet_auto_renew: false,
                workspace: true,
            },
            wallet: {
                system: {
                    mode: 'sandbox',
                },
            },
        });

        await page.goto('/settings', { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Billing' }).click();

        await expect(page.getByRole('heading', { name: 'Wallet auto-renew fallback remains on the legacy path' })).toBeVisible();
        await expect(page.getByText(/still governed by the legacy runtime/i)).toBeVisible();
    });

    test('sub_admin can reach settings wallet workspace', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('sub_admin'), missingRoleMessage('sub_admin'));

        await loginAndOpen(page, request, 'sub_admin', '/settings?integrationArea=wallet');

        const walletSurface = page.locator('section.crm-surface').filter({
            has: page.getByRole('heading', { name: 'Wallet Configuration' }),
        });

        await expect(page).toHaveURL(/\/settings\?integrationArea=wallet$/);
        await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Wallet Configuration' })).toBeVisible();
        await expect(
            walletSurface.getByText('Global wallet settings are read-only for this role. Only admin can change wallet mode, billing domains, and SMTP.'),
        ).toBeVisible();
        await expect(walletSurface.locator('fieldset').first()).toHaveAttribute('disabled', '');
        await expect(page.getByPlaceholder('KES')).toBeVisible();
    });

    test('sub_admin can reach payments queue shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('sub_admin'), missingRoleMessage('sub_admin'));

        await loginAndOpen(page, request, 'sub_admin', '/payments');

        await expect(page).toHaveURL(/\/payments$/);
        await expect(page.getByRole('heading', { name: 'Payments' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auto-match queue' })).toBeVisible();
    });

    test('sales is redirected away from settings', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('sales'), missingRoleMessage('sales'));

        await loginAndOpen(page, request, 'sales', '/settings?integrationArea=wallet');

        await expect(page).toHaveURL(/\/$/);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Wallet Configuration' })).toHaveCount(0);
    });

    test('sales can reach payments queue shell', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('sales'), missingRoleMessage('sales'));

        await loginAndOpen(page, request, 'sales', '/payments');

        await expect(page).toHaveURL(/\/payments$/);
        await expect(page.getByRole('heading', { name: 'Payments' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auto-match queue' })).toBeVisible();
    });
});
