import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { getOptionalFixture, missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

test.describe('billing browser placeholders', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('sub_admin forbidden state when market scope blocks billing access', async () => {
        test.fixme(true, 'Needs a seeded out-of-scope sub_admin fixture once Billing workspace permissions are decomposed.');
    });

    test('degraded payment diagnostics drawer state', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        const paymentId = getOptionalFixture('PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS');
        test.skip(!paymentId, 'Set PLAYWRIGHT_PAYMENT_ID_FOR_DIAGNOSTICS to run degraded diagnostics coverage.');

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

        await page.route(`**/api/crm/payments/${paymentId}/diagnostics*`, async (route) => {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({
                    message: 'Forced diagnostics failure for baseline coverage.',
                }),
            });
        });

        await page.getByPlaceholder('Phone or reference...').fill(String(searchValue));

        const matchingRow = page.locator('tbody tr').filter({ hasText: String(searchValue) }).first();
        await expect(matchingRow).toBeVisible();
        await matchingRow.click();

        await expect(page.getByRole('heading', { name: 'Payment Diagnostics' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Diagnostics unavailable' })).toBeVisible();
        await expect(page.getByText(/CRM could not load this payment/)).toBeVisible();
        await expect(page.getByText(/Close the drawer and retry from the payment row/)).toBeVisible();
    });

    test('billing diagnostics admin health surface', async () => {
        test.fixme(true, 'Awaiting BILL-705/BILL-706 implementation of Settings > Billing > Diagnostics.');
    });

    test('wallet renewal fallback visibility for operators', async () => {
        test.fixme(
            true,
            `Needs renewal fallback runtime data or a seeded client fixture. Optional fixture env: PLAYWRIGHT_CLIENT_ID_FOR_WALLET=${getOptionalFixture('PLAYWRIGHT_CLIENT_ID_FOR_WALLET') || '<unset>'}.`,
        );
    });
});
