import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { getOptionalFixture, missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

test.describe('billing operator workflow baselines', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('admin can inspect the current payment diagnostics drawer contract', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const paymentId = getOptionalFixture('PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS');
        test.skip(!paymentId, 'Set PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS to run diagnostics drawer coverage.');

        const authPayload = await loginAndOpen(page, request, 'admin', '/payments');
        const diagnosticsResponse = await request.get(`/api/crm/payments/${paymentId}/diagnostics`, {
            headers: {
                Authorization: `Bearer ${authPayload.token}`,
            },
        });

        expect(diagnosticsResponse.ok(), `Diagnostics fixture ${paymentId} must be accessible to the admin test role.`).toBeTruthy();

        const diagnosticsData = await diagnosticsResponse.json();
        const searchValue = diagnosticsData?.payment?.transaction_reference || diagnosticsData?.payment?.phone;

        expect(searchValue, 'Diagnostics fixture must expose a phone number or transaction reference visible in the queue.').toBeTruthy();

        await page.getByPlaceholder('Phone or reference...').fill(String(searchValue));

        const matchingRow = page.locator('tbody tr').filter({ hasText: String(searchValue) }).first();
        await expect(matchingRow).toBeVisible();
        await matchingRow.click();

        await expect(page.getByRole('heading', { name: 'Payment Diagnostics' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Overview' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Telemetry' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'History' })).toBeVisible();
        await expect(page.getByText('API Performance')).toBeVisible();
        await expect(page.getByText('Recent Attempts')).toBeVisible();
    });

    test('admin can inspect the current activation dialog contract', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const clientId = getOptionalFixture('PLAYWRIGHT_CLIENT_ID_FOR_ACTIVATION');
        test.skip(!clientId, 'Set PLAYWRIGHT_CLIENT_ID_FOR_ACTIVATION to run activation dialog coverage.');

        await loginAndOpen(page, request, 'admin', `/clients/${clientId}`);

        await expect(page).toHaveURL(new RegExp(`/clients/${clientId}(\\?.*)?$`));

        const activateButton = page.getByRole('button', { name: /^Activate$/ }).first();
        await expect(activateButton).toBeVisible();
        await activateButton.click();

        await expect(page.getByRole('heading', { name: 'Activate Subscription' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Manual Payment' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'STK Push' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Payment Link' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Free Trial' })).toBeVisible();

        await page.getByRole('button', { name: 'Payment Link' }).click();
        await expect(page.getByLabel('Link provider')).toBeVisible();

        await page.getByRole('button', { name: 'Free Trial' }).click();
        await expect(page.getByLabel('Free-trial PIN')).toBeVisible();
        await expect(page.getByText('Apply Discount')).toHaveCount(0);

        await page.getByRole('button', { name: 'Manual Payment' }).click();
        await expect(page.getByLabel('MPESA / Transaction Reference')).toBeVisible();
    });
});
