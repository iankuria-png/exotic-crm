import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
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
