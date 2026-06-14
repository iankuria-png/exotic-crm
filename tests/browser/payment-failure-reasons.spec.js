import { test, expect } from '@playwright/test';
import { cleanupAuthState, loginAndOpen } from './support/auth.js';
import { missingRoleMessage, roleCredentialsAvailable } from './support/env.js';

function recoveryPayload(items = failureReasonItems()) {
    const total = items.reduce((sum, item) => sum + item.failed_count, 0);
    const unclassified = items.find((item) => item.code === 'other_provider_response')?.failed_count || 0;
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
            recorded: total,
            reason_unavailable: 0,
            coverage_pct: total > 0 ? ((total - unclassified) / total) * 100 : 0,
            recorded_pct: total > 0 ? 100 : 0,
            items,
        },
        friction_breakdowns: {
            markets: breakdown([
                frictionItem('Kenya', 7, 3, 4, 700, { platform_id: 1, country: 'Kenya' }),
                frictionItem('Zambia', 4, 1, 3, 300, { platform_id: 2, country: 'Zambia' }),
                frictionItem('DRC', 2, 0, 2, 200, { platform_id: 3, country: 'DRC' }),
            ], total),
            packages: breakdown([
                frictionItem('VIP', 8, 3, 5, 800, { product_id: 10, tier: 'vip' }),
                frictionItem('Premium', 4, 1, 3, 350, { product_id: 11, tier: 'premium' }),
                frictionItem('Basic', 1, 0, 1, 50, { product_id: 12, tier: 'basic' }),
            ], total),
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
        reason('other_provider_response', 'Other provider response', 1, 0, 1, 10),
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

function frictionItem(label, failed, recovered, unresolved, amount, extra) {
    return {
        ...extra,
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

function breakdown(items, total) {
    return {
        total,
        attributed: total,
        unattributed: 0,
        coverage_pct: total > 0 ? 100 : 0,
        items,
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

        await expect(page.getByRole('heading', { name: 'Payment friction intelligence' })).toBeVisible();
        await expect(page.getByText('Primary cause')).toBeVisible();
        await expect(page.getByText('Most affected market')).toBeVisible();
        await expect(page.getByText('Most affected package')).toBeVisible();
        await expect(page.getByText('Kenya')).toBeVisible();
        await expect(page.getByText('VIP')).toBeVisible();
        await expect(page.getByText('Authorization timed out')).toBeVisible();
        await expect(page.getByText('Limits or compliance restriction')).toHaveCount(0);

        const showAll = page.getByRole('button', { name: 'Show all 7' });
        await showAll.focus();
        await expect(showAll).toBeFocused();
        await page.keyboard.press('Enter');

        await expect(page.getByText('Limits or compliance restriction')).toBeVisible();
        await expect(page.getByText(/provider detail recorded/i)).toBeVisible();

        const causesTab = page.getByRole('tab', { name: 'Causes' });
        await causesTab.focus();
        await page.keyboard.press('ArrowRight');
        await expect(page.getByRole('tab', { name: 'Markets' })).toBeFocused();
        await expect(page.getByRole('tabpanel')).toContainText('Zambia');

        await page.getByRole('tab', { name: 'Packages' }).click();
        await expect(page.getByRole('tabpanel')).toContainText('Premium');
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
        await expect(page.getByRole('heading', { name: 'Payment friction intelligence' })).toBeVisible();
    });
});
