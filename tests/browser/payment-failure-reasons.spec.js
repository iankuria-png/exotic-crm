import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

function recoveryPayload(items = failureReasonItems()) {
    const total = items.reduce((sum, item) => sum + item.failed_count, 0);
    const unclassified = items.find((item) => item.code === 'unclassified')?.failed_count || 0;
    const recovered = Math.min(4, total);

    return {
        filters: {
            platform_id: null,
            from: '2026-05-16',
            to: '2026-06-14',
            limit: 100,
            currency_mode: 'flat',
            reporting_currency: 'USD',
        },
        metrics: {
            failed_payments: total,
            recovered_payments: recovered,
            lost_payments: Math.max(0, total - recovered),
            payment_recovery_rate: total > 0 ? (recovered / total) * 100 : 0,
            failed_customers: total,
            recovered_customers: recovered,
            lost_customers: Math.max(0, total - recovered),
            customer_recovery_rate: total > 0 ? (recovered / total) * 100 : 0,
            failed_amount_breakdown: { USD: 1000 },
            recovered_amount_breakdown: { USD: 400 },
            lost_amount_breakdown: { USD: 600 },
            failed_normalized_amount: 1000,
            recovered_normalized_amount: 400,
            lost_normalized_amount: 600,
            normalized_currency: 'USD',
            window: { from: '2026-05-16', to: '2026-06-14' },
        },
        failure_reasons: {
            total,
            classified: total - unclassified,
            unclassified,
            coverage_pct: total > 0 ? ((total - unclassified) / total) * 100 : 0,
            items,
        },
        recovered_pairs: [],
    };
}

function failureReasonItems() {
    return [
        reason('authorization_timeout', 'Authorization timed out', 4, 3, 1, 40),
        reason('customer_declined', 'Customer declined', 3, 1, 2, 30),
        reason('insufficient_funds', 'Insufficient funds', 2, 0, 2, 20),
        reason('invalid_phone_account', 'Invalid phone or account', 1, 0, 1, 10),
        reason('provider_network_unavailable', 'Provider or network unavailable', 1, 0, 1, 10),
        reason('limits_compliance', 'Limits or compliance restriction', 1, 0, 1, 10),
        reason('unclassified', 'Unclassified', 1, 0, 1, 10),
    ];
}

function reason(code, label, failed, recovered, unresolved, amount) {
    return {
        code,
        label,
        failed_count: failed,
        percentage: 10,
        recovered_count: recovered,
        unresolved_count: unresolved,
        recovery_rate: failed > 0 ? (recovered / failed) * 100 : 0,
        failed_amount_breakdown: { USD: amount },
        failed_normalized_amount: amount,
        failed_normalization_meta: null,
        normalized_currency: 'USD',
    };
}

async function openRecovery(page, request) {
    await loginAndOpen(page, request, 'admin', '/payments');
    await page.getByRole('button', { name: 'Failed recovery' }).click();
}

test.describe('payment failure reasons aggregator', () => {
    test.afterEach(async ({ page, request }) => {
        await cleanupAuthState(page, request);
    });

    test('shows ranked reasons, supports keyboard expansion, and fits narrow screens', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        await page.setViewportSize({ width: 390, height: 844 });
        await page.route('**/api/crm/payments/recovery-report*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(recoveryPayload()),
            });
        });

        await openRecovery(page, request);

        await expect(page.getByRole('heading', { name: 'Why payments fail' })).toBeVisible();
        await expect(page.getByText('Authorization timed out')).toBeVisible();
        await expect(page.getByText('Limits or compliance restriction')).toHaveCount(0);

        const showAll = page.getByRole('button', { name: 'Show all 7' });
        await showAll.focus();
        await expect(showAll).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(page.getByText('Limits or compliance restriction')).toBeVisible();
        await expect(page.getByText(/unclassified failure retained without guessing/i)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });

    test('shows intentional empty and retryable error states', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        let responseMode = 'empty';
        await page.route('**/api/crm/payments/recovery-report*', async (route) => {
            if (responseMode === 'error') {
                await route.fulfill({
                    status: 503,
                    contentType: 'application/json',
                    body: JSON.stringify({ message: 'Recovery metrics are warming up.' }),
                });
                return;
            }

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(recoveryPayload([])),
            });
        });

        await openRecovery(page, request);
        await expect(page.getByText('No payment failures in this window')).toBeVisible();

        responseMode = 'error';
        await page.getByRole('button', { name: '90 days' }).click();

        await expect(page.getByRole('heading', { name: 'Recovery analysis is temporarily unavailable' })).toBeVisible();
        await expect(page.getByText('Recovery metrics are warming up.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
    });

    test('reserves aggregator space while the report loads', async ({ page, request }) => {
        test.skip(!roleCredentialsAvailable('admin'), missingRoleMessage('admin'));

        await page.route('**/api/crm/payments/recovery-report*', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1200));
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(recoveryPayload()),
            });
        });

        await openRecovery(page, request);

        await expect(page.getByTestId('payment-failure-reasons-loading')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Why payments fail' })).toBeVisible();
    });
});
