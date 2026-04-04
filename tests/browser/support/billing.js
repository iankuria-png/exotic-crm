export async function stubBillingWorkspace(page, options = {}) {
    const {
        features = {},
        providerFamilies = {},
        services = {},
        wallet = {},
        onIntegrationsRequest = null,
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

    await page.route('**/api/crm/settings/integrations*', async (route) => {
        if (typeof onIntegrationsRequest === 'function') {
            onIntegrationsRequest(route.request());
        }

        const response = await route.fetch();
        const payload = await response.json();
        const billing = payload?.billing || {};

        await route.fulfill({
            response,
            json: {
                ...payload,
                billing: {
                    ...billing,
                    enabled: true,
                    features: {
                        ...(billing.features || {}),
                        workspace: true,
                        diagnostics_v2: true,
                        ...features,
                    },
                    provider_families: {
                        ...defaultProviderFamilies,
                        ...(billing.provider_families || {}),
                        ...providerFamilies,
                    },
                },
                wallet: {
                    ...(payload?.wallet || {}),
                    ...wallet,
                },
                services: {
                    ...defaultServices,
                    ...(payload?.services || {}),
                    ...services,
                },
            },
        });
    });
}
