import React, { useMemo, useState } from 'react';

function getProviderOptions(smsProviderOptions = []) {
    return Array.isArray(smsProviderOptions) && smsProviderOptions.length
        ? smsProviderOptions
        : [
            {
                id: 'legacy_gateway',
                label: 'Legacy Gateway',
                fields: [
                    { key: 'gateway_url', label: 'Gateway URL', type: 'url', required: true },
                    { key: 'org_code', label: 'Org Code', type: 'text', required: true },
                ],
            },
            {
                id: 'africastalking',
                label: "Africa's Talking",
                fields: [
                    { key: 'endpoint', label: 'Endpoint', type: 'url', required: false, default: 'https://api.africastalking.com/version1/messaging' },
                    { key: 'username', label: 'Username', type: 'text', required: true },
                    { key: 'api_key', label: 'API Key', type: 'password', required: true, secret: true },
                    { key: 'sender_id', label: 'Sender ID', type: 'text', required: false },
                ],
            },
            {
                id: 'briq',
                label: 'Briq (Tanzania)',
                fields: [
                    { key: 'base_url', label: 'Base URL', type: 'url', required: true, default: 'https://karibu.briq.tz' },
                    { key: 'api_key', label: 'API Key', type: 'password', required: true, secret: true },
                    { key: 'sender_id', label: 'Sender ID', type: 'text', required: true },
                ],
            },
            {
                id: 'ghana_bulk_sms',
                label: 'BulkSMS (Ghana)',
                fields: [
                    {
                        key: 'base_url',
                        label: 'Gateway URL',
                        type: 'url',
                        required: true,
                        default: 'https://clientlogin.bulksmsgh.com/smsapi',
                    },
                    {
                        key: 'api_key',
                        label: 'API Key',
                        type: 'password',
                        required: true,
                        secret: true,
                    },
                    {
                        key: 'sender_id',
                        label: 'Sender ID',
                        type: 'text',
                        required: true,
                    },
                    {
                        key: 'success_code',
                        label: 'Success Code',
                        type: 'text',
                        required: false,
                        default: '1000',
                    },
                ],
            },
        ];
}

function isSecretField(field) {
    return Boolean(field?.secret || field?.type === 'password' || field?.type === 'secret');
}

function providerCredentials(entry, providerId) {
    if (!entry || typeof entry !== 'object') {
        return {};
    }

    if (entry.providers?.[providerId] && typeof entry.providers[providerId] === 'object') {
        return entry.providers[providerId];
    }

    // Backward compatibility for old shape: entry.legacy_gateway / entry.africastalking
    if (entry[providerId] && typeof entry[providerId] === 'object') {
        return entry[providerId];
    }

    return {};
}

function providerFieldConfigured(config, field) {
    return Boolean(config?.[`${field.key}_configured`]);
}

function providerFieldValue(config, field) {
    const value = config?.[field.key];

    if (value !== null && value !== undefined) {
        return value;
    }

    return field.default ?? '';
}

function providerReady(provider, localConfig = {}, globalConfig = {}) {
    if (!provider) {
        return false;
    }

    return (provider.fields || [])
        .filter((field) => field.required)
        .every((field) => {
            const localValue = localConfig?.[field.key];
            const globalValue = globalConfig?.[field.key];

            const localConfigured = localConfig?.[`${field.key}_configured`];
            const globalConfigured = globalConfig?.[`${field.key}_configured`];

            if (isSecretField(field)) {
                return Boolean(String(localValue || '').trim())
                    || Boolean(localConfigured)
                    || Boolean(String(globalValue || '').trim())
                    || Boolean(globalConfigured);
            }

            return Boolean(String(localValue || '').trim())
                || Boolean(String(globalValue || '').trim());
        });
}

function hasProviderOverride(entry, providerOptions) {
    if (!entry) {
        return false;
    }

    if (entry.active_provider || entry.fallback_provider) {
        return true;
    }

    return providerOptions.some((provider) => {
        const config = providerCredentials(entry, provider.id);

        return Object.entries(config || {}).some(([key, value]) => {
            if (key.endsWith('_configured')) {
                return Boolean(value);
            }

            return String(value ?? '').trim() !== '';
        });
    });
}

function renderProviderFields({
                                  provider,
                                  config,
                                  globalConfig = {},
                                  onChange,
                                  disabled = false,
                                  inheritGlobal = false,
                              }) {
    if (!provider) {
        return null;
    }

    return (
        <div className="grid gap-3 md:grid-cols-2">
            {(provider.fields || []).map((field) => {
                const secret = isSecretField(field);
                const configured = providerFieldConfigured(config, field);
                const globalConfigured = providerFieldConfigured(globalConfig, field);
                const inputType = secret ? 'password' : (field.type === 'url' ? 'url' : 'text');
                const globalValue = globalConfig?.[field.key];

                return (
                    <div key={`${provider.id}-${field.key}`} className={field.type === 'textarea' ? 'md:col-span-2' : ''}>
                        <label className="mb-1 block text-xs font-medium text-slate-600">
                            {field.label}
                            {field.required ? <span className="ml-1 text-rose-600">*</span> : null}
                        </label>

                        {field.type === 'textarea' ? (
                            <textarea
                                value={providerFieldValue(config, field)}
                                onChange={(event) => onChange(field.key, event.target.value)}
                                className="crm-input text-sm"
                                rows={3}
                                disabled={disabled}
                                placeholder={field.placeholder || field.label}
                            />
                        ) : (
                            <input
                                type={inputType}
                                value={providerFieldValue(config, field)}
                                onChange={(event) => onChange(field.key, event.target.value)}
                                className="crm-input text-sm"
                                disabled={disabled}
                                placeholder={
                                    secret && (configured || globalConfigured)
                                        ? '••••••••'
                                        : inheritGlobal && globalValue
                                            ? `Global: ${globalValue}`
                                            : (field.placeholder || field.label)
                                }
                            />
                        )}

                        {secret ? (
                            <p className={`mt-1 text-xs ${configured ? 'text-emerald-700' : 'text-slate-400'}`}>
                                {configured
                                    ? 'Secret is already stored. Enter a new value only when rotating it.'
                                    : inheritGlobal && globalConfigured
                                        ? 'No custom secret stored. This market will inherit the global secret.'
                                        : 'No secret stored yet.'}
                            </p>
                        ) : inheritGlobal && globalValue ? (
                            <p className="mt-1 text-xs text-slate-400">Leave blank to inherit global value.</p>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}

function MarketSmsRoutingRow({
                                 onEntryChange,
                                 platform,
                                 smsProviderForm,
                                 smsProviderLabel,
                                 smsProviderOptions,
                                 entry = {},
                             }) {
    const [expanded, setExpanded] = useState(false);
    const providerOptions = getProviderOptions(smsProviderOptions);

    const effectiveProviderId = entry.active_provider || smsProviderForm.active_provider || providerOptions[0]?.id;
    const activeProvider = providerOptions.find((provider) => provider.id === effectiveProviderId) || providerOptions[0];

    const marketConfig = providerCredentials(entry, effectiveProviderId);
    const globalConfig = providerCredentials(smsProviderForm, effectiveProviderId);

    const hasOverride = hasProviderOverride(entry, providerOptions);
    const isReady = providerReady(activeProvider, marketConfig, globalConfig);

    const patch = (updates) => onEntryChange({ ...entry, ...updates });

    const patchProviderField = (providerId, field, value) => {
        patch({
            providers: {
                ...(entry.providers || {}),
                [providerId]: {
                    ...(providerCredentials(entry, providerId) || {}),
                    [field]: value,
                },
            },
        });
    };

    return (
        <div className="py-3">
            <div className="flex items-center gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-slate-800">{platform.name}</span>
                        {hasOverride ? (
                            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200">
                                Custom
                            </span>
                        ) : (
                            <span className="text-xs text-slate-400">Using global</span>
                        )}
                        {hasOverride && !isReady ? (
                            <span className="text-xs text-amber-600">Provider not ready</span>
                        ) : null}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Active provider: {smsProviderLabel(effectiveProviderId)}
                        {entry.active_provider ? ' (override)' : ' (global)'}
                    </p>
                </div>

                <select
                    value={entry.active_provider ?? ''}
                    onChange={(event) => {
                        const nextProviderId = event.target.value || null;
                        patch({
                            active_provider: nextProviderId,
                            providers: nextProviderId
                                ? {
                                    ...(entry.providers || {}),
                                    [nextProviderId]: {
                                        ...(providerCredentials(entry, nextProviderId) || {}),
                                    },
                                }
                                : (entry.providers || {}),
                        });
                    }}
                    className="crm-select text-sm"
                    aria-label={`${platform.name} active provider`}
                >
                    <option value="">Global default</option>
                    {providerOptions.map((provider) => (
                        <option key={provider.id} value={provider.id}>
                            {provider.label}
                        </option>
                    ))}
                </select>

                <button
                    type="button"
                    onClick={() => setExpanded((current) => !current)}
                    className="whitespace-nowrap text-xs text-teal-700 hover:underline"
                    aria-expanded={expanded}
                >
                    {expanded ? 'Less' : 'Configure'}
                </button>
            </div>

            {expanded ? (
                <div className="mt-3 ml-2 space-y-4 border-l-2 border-slate-100 pl-3">
                    <div>
                        <label htmlFor={`sms-market-fallback-${platform.id}`} className="mb-1 block text-xs font-medium text-slate-600">
                            Fallback provider
                        </label>
                        <select
                            id={`sms-market-fallback-${platform.id}`}
                            value={entry.fallback_provider ?? ''}
                            onChange={(event) => patch({ fallback_provider: event.target.value || null })}
                            className="crm-select text-sm"
                        >
                            <option value="">Global default</option>
                            <option value="none">None</option>
                            {providerOptions
                                .filter((provider) => provider.id !== effectiveProviderId)
                                .map((provider) => (
                                    <option key={provider.id} value={provider.id}>
                                        {provider.label}
                                    </option>
                                ))}
                        </select>
                    </div>

                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <p className="text-xs font-semibold text-slate-700">
                                    {activeProvider?.label || 'Provider'} credentials
                                </p>
                                <p className="mt-1 text-xs text-slate-400">
                                    Leave blank to inherit global credentials where available.
                                </p>
                            </div>
                            <span className={`rounded px-2 py-0.5 text-xs ${isReady ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'}`}>
                                {isReady ? 'Ready' : 'Incomplete'}
                            </span>
                        </div>

                        {renderProviderFields({
                            provider: activeProvider,
                            config: marketConfig,
                            globalConfig,
                            inheritGlobal: true,
                            onChange: (field, value) => patchProviderField(effectiveProviderId, field, value),
                        })}
                    </div>

                    {hasOverride ? (
                        <div className="flex justify-end pt-1">
                            <button
                                type="button"
                                onClick={() => {
                                    onEntryChange(null);
                                    setExpanded(false);
                                }}
                                className="text-xs text-rose-600 hover:underline"
                            >
                                Clear all overrides for {platform.name}
                            </button>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

export default function SmsRoutingPanel({
                                            fallbackInvalid,
                                            fallbackOptions,
                                            latestSmsTestResult,
                                            markets,
                                            onMarketsChange,
                                            platforms,
                                            saveSmsProviderConfig,
                                            saveSmsProviderMutation,
                                            setSmsProviderForm,
                                            setSmsTestConfirmOpen,
                                            setSmsTestForm,
                                            smsProviderForm,
                                            smsProviderLabel,
                                            smsProviderOptions = [],
                                            smsReady,
                                            smsTestForm,
                                            smsTestReady,
                                            statusChip,
                                            testSmsProviderMutation,
                                        }) {
    const providerOptions = getProviderOptions(smsProviderOptions);

    const activeGlobalProvider = useMemo(
        () => providerOptions.find((provider) => provider.id === smsProviderForm.active_provider) || providerOptions[0],
        [providerOptions, smsProviderForm.active_provider],
    );

    const activeGlobalProviderConfig = providerCredentials(smsProviderForm, activeGlobalProvider?.id);

    const updateGlobalProviderField = (providerId, field, value) => {
        setSmsProviderForm((current) => ({
            ...current,
            providers: {
                ...(current.providers || {}),
                [providerId]: {
                    ...(providerCredentials(current, providerId) || {}),
                    [field]: value,
                },
            },
        }));
    };

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">SMS Provider Routing</h3>
                    <p className="crm-panel-subtitle">Choose an active SMS provider, set fallback behavior, and validate delivery from settings.</p>
                </div>
            </header>

            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-7">
                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Routing Controls</h4>
                        <p className="mt-1 text-xs text-slate-500">These settings define which provider is used first and what happens if dispatch fails.</p>

                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(smsProviderForm.enabled)}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, enabled: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Enable SMS dispatch for operational events
                            </label>

                            <div>
                                <label htmlFor="sms-active-provider" className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                <select
                                    id="sms-active-provider"
                                    value={smsProviderForm.active_provider}
                                    onChange={(event) => {
                                        const nextProviderId = event.target.value;

                                        setSmsProviderForm((current) => ({
                                            ...current,
                                            active_provider: nextProviderId,
                                            providers: {
                                                ...(current.providers || {}),
                                                [nextProviderId]: {
                                                    ...(providerCredentials(current, nextProviderId) || {}),
                                                },
                                            },
                                        }));
                                    }}
                                    className="crm-select w-full"
                                >
                                    {providerOptions.map((provider) => (
                                        <option key={provider.id} value={provider.id}>
                                            {provider.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label htmlFor="sms-fallback-provider" className="mb-1 block text-sm font-medium text-slate-700">Fallback provider</label>
                                <select
                                    id="sms-fallback-provider"
                                    value={smsProviderForm.fallback_provider}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, fallback_provider: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    {(fallbackOptions || [
                                        { value: 'none', label: 'No fallback' },
                                        ...providerOptions.map((provider) => ({ value: provider.id, label: provider.label })),
                                    ]).map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                            disabled={option.value !== 'none' && option.value === smsProviderForm.active_provider}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label htmlFor="sms-default-prefix" className="mb-1 block text-sm font-medium text-slate-700">Default phone prefix</label>
                                <input
                                    id="sms-default-prefix"
                                    value={smsProviderForm.default_prefix}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, default_prefix: event.target.value }))}
                                    className="crm-input"
                                    placeholder="254"
                                />
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="sms-config-reason" className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                <textarea
                                    id="sms-config-reason"
                                    rows={2}
                                    value={smsProviderForm.reason}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Reason for updating SMS routing"
                                />
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h4 className="text-sm font-semibold text-slate-900">Global Provider Credentials</h4>
                                <p className="mt-1 text-xs text-slate-500">
                                    These credentials are used when a market does not have its own override.
                                </p>
                            </div>
                            <span className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-medium text-slate-600">
                                {smsProviderLabel(activeGlobalProvider?.id)}
                            </span>
                        </div>

                        <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                            {renderProviderFields({
                                provider: activeGlobalProvider,
                                config: activeGlobalProviderConfig,
                                onChange: (field, value) => updateGlobalProviderField(activeGlobalProvider.id, field, value),
                            })}
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Market Provider Routing</h4>
                        <p className="mt-1 text-xs text-slate-500">
                            Markets inherit global settings by default. Configure overrides to use a different provider or separate credentials per market.
                        </p>

                        {(platforms ?? []).length === 0 ? (
                            <p className="mt-3 text-xs text-slate-400">No markets configured.</p>
                        ) : (
                            <div className="mt-2 divide-y divide-slate-100">
                                {(platforms ?? []).map((platform) => (
                                    <MarketSmsRoutingRow
                                        key={platform.id}
                                        platform={platform}
                                        entry={markets?.[String(platform.id)] ?? {}}
                                        smsProviderForm={smsProviderForm}
                                        smsProviderLabel={smsProviderLabel}
                                        smsProviderOptions={providerOptions}
                                        onEntryChange={(updated) => {
                                            const platformKey = String(platform.id);

                                            if (updated === null) {
                                                const nextMarkets = { ...(markets || {}) };
                                                delete nextMarkets[platformKey];
                                                onMarketsChange(nextMarkets);
                                                return;
                                            }

                                            onMarketsChange({
                                                ...(markets || {}),
                                                [platformKey]: updated,
                                            });
                                        }}
                                    />
                                ))}
                            </div>
                        )}
                    </section>

                    {fallbackInvalid ? (
                        <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                            Fallback provider must be different from the active provider.
                        </p>
                    ) : null}

                    {smsProviderForm.enabled && !smsReady ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                            Active provider credentials are incomplete. Complete required fields before saving or sending tests.
                        </p>
                    ) : null}

                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={saveSmsProviderConfig}
                            disabled={
                                saveSmsProviderMutation.isPending
                                || !smsProviderForm.reason.trim()
                                || fallbackInvalid
                                || !smsProviderForm.default_prefix.trim()
                                || (smsProviderForm.enabled && !smsReady)
                            }
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveSmsProviderMutation.isPending ? 'Saving...' : 'Save SMS settings'}
                        </button>
                    </div>
                </div>

                <div className="space-y-4 xl:col-span-5">
                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Test Dispatch</h4>
                        <p className="mt-1 text-xs text-slate-500">Send a controlled SMS to verify routing and provider response in real time.</p>

                        <div className="mt-3 space-y-3">
                            <div>
                                <label htmlFor="sms-test-market" className="mb-1 block text-sm font-medium text-slate-700">
                                    Test for market <span className="font-normal text-slate-400">(optional)</span>
                                </label>
                                <select
                                    id="sms-test-market"
                                    value={smsTestForm.market_id ?? ''}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, market_id: event.target.value || null }))}
                                    className="crm-select w-full"
                                >
                                    <option value="">Global routing</option>
                                    {(platforms ?? []).map((platform) => (
                                        <option key={platform.id} value={platform.id}>{platform.name}</option>
                                    ))}
                                </select>
                            </div>

                            <input
                                value={smsTestForm.phone}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, phone: event.target.value }))}
                                className="crm-input"
                                placeholder="Phone, example: +254712000000"
                            />

                            <textarea
                                rows={4}
                                value={smsTestForm.message}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, message: event.target.value }))}
                                className="crm-input"
                                placeholder="Test message content"
                            />

                            <input
                                value={smsTestForm.reason}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, reason: event.target.value }))}
                                className="crm-input"
                                placeholder="Reason for test dispatch"
                            />
                        </div>

                        <div className="mt-3 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSmsTestConfirmOpen(true)}
                                disabled={
                                    testSmsProviderMutation.isPending
                                    || !smsTestReady
                                    || !smsProviderForm.enabled
                                    || !smsTestForm.phone.trim()
                                    || !smsTestForm.message.trim()
                                    || !smsTestForm.reason.trim()
                                }
                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {testSmsProviderMutation.isPending ? 'Sending...' : 'Send test SMS'}
                            </button>
                        </div>

                        {!smsProviderForm.enabled ? (
                            <p className="mt-2 text-xs text-amber-700">Enable SMS dispatch before sending a provider test message.</p>
                        ) : null}
                    </section>

                    {latestSmsTestResult ? (
                        <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Latest SMS Test Result</h4>
                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestSmsTestResult.success ? 'success' : 'failed')}`}>
                                    {latestSmsTestResult.success ? 'success' : 'failed'}
                                </span>
                            </div>

                            <div className="mt-2 space-y-1 text-xs text-slate-600">
                                <p><span className="font-semibold text-slate-800">Provider:</span> {smsProviderLabel(latestSmsTestResult.provider)}</p>
                                <p><span className="font-semibold text-slate-800">Status:</span> {latestSmsTestResult.status || 'unknown'}</p>
                                <p><span className="font-semibold text-slate-800">Phone:</span> {latestSmsTestResult.phone || '--'}</p>
                                <p className="break-all">
                                    <span className="font-semibold text-slate-800">Response:</span>{' '}
                                    {typeof latestSmsTestResult.provider_response === 'object'
                                        ? JSON.stringify(latestSmsTestResult.provider_response)
                                        : (latestSmsTestResult.provider_response || 'No provider response message.')}
                                </p>
                                {latestSmsTestResult.fallback_attempted ? (
                                    <p>
                                        <span className="font-semibold text-slate-800">Fallback:</span>{' '}
                                        Attempted from {smsProviderLabel(latestSmsTestResult.fallback_from || smsProviderForm.active_provider)}
                                    </p>
                                ) : null}
                            </div>
                        </section>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
