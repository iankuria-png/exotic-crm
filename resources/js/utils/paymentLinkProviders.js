function resolveConfig(source) {
    if (!source) {
        return null;
    }

    if (source.payment_link_providers && typeof source.payment_link_providers === 'object') {
        return source.payment_link_providers;
    }

    return typeof source === 'object' ? source : null;
}

function modeLabel(mode) {
    return mode === 'proxy_hosted_checkout' ? 'CRM Proxy' : 'Static URL';
}

export function getEnabledPaymentLinkProviders(source) {
    const config = resolveConfig(source);
    if (!config || typeof config.providers !== 'object') {
        return [];
    }

    return Object.entries(config.providers)
        .map(([key, provider]) => ({
            key,
            label: provider?.label?.trim() || key,
            mode: provider?.mode || 'static_url',
            enabled: provider?.enabled !== false,
        }))
        .filter((provider) => provider.enabled)
        .map((provider) => ({
            ...provider,
            optionLabel: `${provider.label} (${modeLabel(provider.mode)})`,
        }));
}

export function getDefaultPaymentLinkProviderKey(source) {
    const config = resolveConfig(source);
    const providers = getEnabledPaymentLinkProviders(config);
    if (!providers.length) {
        return '';
    }

    const activeProvider = String(config?.active_provider || '').trim();
    if (activeProvider && providers.some((provider) => provider.key === activeProvider)) {
        return activeProvider;
    }

    return providers[0].key;
}
