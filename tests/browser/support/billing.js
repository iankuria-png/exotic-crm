export async function stubBillingWorkspace(page, options = {}) {
    const {
        features = {},
        providerFamilies = {},
        services = {},
        wallet = {},
        onOverviewRequest = null,
        onDiagnosticsRequest = null,
        onBillingSystemRequest = null,
    } = options;

    const defaultProviderFamilies = {
        kopokopo: { enabled: true },
        paystack: { enabled: false },
        nowpayments: { enabled: true },
    };

    const defaultServices = {
        wallet_system: { status: 'healthy', mode: 'sandbox' },
        kopokopo: { status: 'configured_disabled', note: 'Disabled for this environment' },
        payment_service: { status: 'unknown', note: 'No payment service configured' },
        sendgrid: { status: 'unknown', note: 'No email health summary available' },
    };

    const overviewPayload = {
        billing: {
            enabled: true,
            features: {
                workspace: true,
                diagnostics_v2: true,
                registry: true,
                ...features,
            },
            provider_families: {
                ...defaultProviderFamilies,
                ...providerFamilies,
            },
        },
        summary: {
            billingEnabled: true,
            walletMode: wallet?.system?.mode || 'sandbox',
            totalMarkets: 1,
            walletEnabledMarkets: 1,
        },
        markets: [
            {
                id: 1,
                name: 'Kenya',
                country: 'Kenya',
                wallet: {
                    enabled: true,
                    mode_override: null,
                },
            },
        ],
        last_checked_at: '2026-04-04 12:00:00',
    };

    await page.route('**/api/crm/settings/billing/overview*', async (route) => {
        if (typeof onOverviewRequest === 'function') {
            onOverviewRequest(route.request());
        }

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            json: overviewPayload,
        });
    });

    await page.route('**/api/crm/settings/billing/diagnostics-summary*', async (route) => {
        if (typeof onDiagnosticsRequest === 'function') {
            onDiagnosticsRequest(route.request());
        }

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            json: {
                services: {
                    ...defaultServices,
                    ...services,
                },
                last_checked_at: '2026-04-04 12:00:00',
            },
        });
    });

    await page.route('**/api/crm/settings/billing/system*', async (route) => {
        if (typeof onBillingSystemRequest === 'function') {
            onBillingSystemRequest(route.request());
        }

        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            json: {
                system: {
                    mode: wallet?.system?.mode || 'sandbox',
                    default_currency: 'KES',
                    max_single_topup_default: '50000.00',
                    max_wallet_balance_default: '200000.00',
                    billing_domains: {
                        sandbox: 'https://sandbox.example.test',
                        production: 'https://live.example.test',
                    },
                    billing_branding: {
                        sandbox: {
                            business_name: 'Sandbox Billing',
                            description: 'Sandbox description',
                        },
                        production: {
                            business_name: 'Live Billing',
                            description: 'Live description',
                        },
                    },
                    timing: {
                        redirect_delay_seconds: 3,
                        wallet_refresh_rate_limit_seconds: 15,
                        wallet_refresh_timeout_seconds: 15,
                        topup_poll_interval_seconds: 10,
                    },
                    smtp: {
                        enabled: true,
                        host: 'smtp.example.test',
                        from_address: 'billing@example.test',
                        password_configured: true,
                    },
                },
                source: {
                    live_read_enabled: Boolean(features.billing_system_live_read),
                    source_of_truth: features.billing_system_live_read
                        ? 'billing_system_settings'
                        : 'wallet_system_config',
                },
            },
        });
    });
}
