import React, { useState } from 'react';

function isSecretField(field) {
    return Boolean(field?.secret) || field?.type === 'password';
}

function providerById(options, id) {
    return (options || []).find((provider) => provider.id === id) || null;
}

function fieldHasValue(creds, field) {
    if (isSecretField(field)) {
        return Boolean(creds?.[field.key]?.trim?.() || creds?.[`${field.key}_configured`]);
    }
    return Boolean(creds?.[field.key]?.trim?.());
}

function hasMarketOverride(entry, options) {
    if (entry?.active_provider || entry?.fallback_provider) {
        return true;
    }
    return (options || []).some((provider) => {
        const creds = entry?.providers?.[provider.id] || {};
        return (provider.fields || []).some((field) => fieldHasValue(creds, field));
    });
}

function providerReady(option, marketCreds, globalCreds) {
    if (!option) {
        return true;
    }
    return (option.fields || []).every((field) => {
        if (!field.required) {
            return true;
        }
        return fieldHasValue(marketCreds, field) || fieldHasValue(globalCreds, field);
    });
}

// Shared dynamic credential inputs for one provider.
function ProviderFields({ option, creds, onFieldChange, idPrefix, scope, globalCreds }) {
    return (
        <div className="grid gap-2 md:grid-cols-2">
            {(option.fields || []).map((field) => {
                const secret = isSecretField(field);
                const fullWidth = secret || field.type === 'url';
                const inputId = `${idPrefix}-${option.id}-${field.key}`;
                const configured = Boolean(creds?.[`${field.key}_configured`]);
                const globalValue = globalCreds?.[field.key];

                let placeholder = field.default || field.label;
                if (scope === 'market') {
                    placeholder = secret
                        ? 'Leave blank to inherit global'
                        : (globalValue ? `Global: ${globalValue}` : `Global: (not set)`);
                } else if (secret) {
                    placeholder = 'Leave blank to keep current value';
                }

                return (
                    <div key={field.key} className={fullWidth ? 'md:col-span-2' : ''}>
                        <label htmlFor={inputId} className="mb-1 block text-xs font-medium text-slate-600">
                            {field.label}{field.required ? '' : ' (optional)'}
                        </label>
                        <input
                            id={inputId}
                            type={secret ? 'password' : 'text'}
                            value={creds?.[field.key] ?? ''}
                            onChange={(event) => onFieldChange(field.key, event.target.value)}
                            className="crm-input text-sm"
                            placeholder={placeholder}
                            autoComplete={secret ? 'new-password' : 'off'}
                        />
                        {secret ? (
                            <p className={`mt-1 text-xs ${configured ? 'text-emerald-700' : 'text-slate-400'}`}>
                                {configured
                                    ? (scope === 'market'
                                        ? 'Custom secret stored. Enter a new value only when rotating it.'
                                        : 'Secret stored. Enter a new value only when rotating it.')
                                    : (scope === 'market'
                                        ? 'No custom secret stored. This market inherits the global value.'
                                        : 'No secret stored yet.')}
                            </p>
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
    smsProviderOptions,
    smsProviderLabel,
    entry = {},
}) {
    const [expanded, setExpanded] = useState(false);

    const hasOverride = hasMarketOverride(entry, smsProviderOptions);
    const effectiveProviderId = entry.active_provider || smsProviderForm.active_provider;
    const effectiveOption = providerById(smsProviderOptions, effectiveProviderId);

    const marketCreds = entry.providers?.[effectiveProviderId] || {};
    const globalCreds = smsProviderForm.providers?.[effectiveProviderId] || {};
    const isReady = providerReady(effectiveOption, marketCreds, globalCreds);

    const patch = (updates) => onEntryChange({ ...entry, ...updates });
    const patchProviderField = (providerId, key, value) => patch({
        providers: {
            ...(entry.providers || {}),
            [providerId]: {
                ...(entry.providers?.[providerId] || {}),
                [key]: value,
            },
        },
    });

    return (
        <div className="py-3">
            <div className="flex items-center gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="text-sm font-medium text-slate-800">{platform.name}</span>
                        {hasOverride ? (
                            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200">Custom</span>
                        ) : (
                            <span className="text-xs text-slate-400">Using global</span>
                        )}
                        {hasOverride && !isReady ? (
                            <span className="text-xs text-amber-600">Provider not ready</span>
                        ) : null}
                    </div>
                    <p className="text-xs text-slate-500 mt-0.5">
                        Active provider: {smsProviderLabel(effectiveProviderId)}{entry.active_provider ? ' (override)' : ' (global)'}
                    </p>
                </div>

                <select
                    value={entry.active_provider ?? ''}
                    onChange={(event) => patch({ active_provider: event.target.value || null })}
                    className="crm-select text-sm"
                    aria-label={`${platform.name} active provider`}
                >
                    <option value="">Global default</option>
                    {(smsProviderOptions || []).map((provider) => (
                        <option key={provider.id} value={provider.id}>{provider.label}</option>
                    ))}
                </select>

                <button
                    type="button"
                    onClick={() => setExpanded((current) => !current)}
                    className="text-xs text-teal-700 hover:underline whitespace-nowrap"
                    aria-expanded={expanded}
                >
                    {expanded ? 'Less' : 'Configure'}
                </button>
            </div>

            {expanded ? (
                <div className="mt-3 space-y-4 border-l-2 border-slate-100 ml-2 pl-3">
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
                            {(smsProviderOptions || []).map((provider) => (
                                <option key={provider.id} value={provider.id}>{provider.label}</option>
                            ))}
                        </select>
                    </div>

                    {effectiveOption ? (
                        <div>
                            <p className="text-xs font-semibold text-slate-700 mb-1">{effectiveOption.label} credentials</p>
                            <p className="text-xs text-slate-400 mb-2">Leave blank to inherit the global credentials for this provider.</p>
                            <ProviderFields
                                option={effectiveOption}
                                creds={entry.providers?.[effectiveProviderId] || {}}
                                globalCreds={globalCreds}
                                scope="market"
                                idPrefix={`sms-market-${platform.id}`}
                                onFieldChange={(key, value) => patchProviderField(effectiveProviderId, key, value)}
                            />
                        </div>
                    ) : null}

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
    smsProviderOptions,
    smsProviderLabel,
    smsReady,
    smsTestForm,
    smsTestReady,
    statusChip,
    testSmsProviderMutation,
    updateSmsProviderField,
}) {
    const options = smsProviderOptions || [];
    const activeOption = providerById(options, smsProviderForm.active_provider);

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
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, active_provider: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    {options.map((provider) => (
                                        <option key={provider.id} value={provider.id}>{provider.label}</option>
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
                                    {fallbackOptions.map((option) => (
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

                    {options.map((provider) => {
                        const isActive = provider.id === smsProviderForm.active_provider;
                        return (
                            <section key={provider.id} className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <h4 className="text-sm font-semibold text-slate-900">{provider.label}</h4>
                                    {isActive ? (
                                        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200">Active</span>
                                    ) : null}
                                </div>
                                <p className="mt-1 text-xs text-slate-500">Global credentials for {provider.label}. Markets inherit these unless overridden below.</p>
                                <div className="mt-3">
                                    <ProviderFields
                                        option={provider}
                                        creds={smsProviderForm.providers?.[provider.id] || {}}
                                        scope="global"
                                        idPrefix="sms-global"
                                        onFieldChange={(key, value) => updateSmsProviderField(provider.id, key, value)}
                                    />
                                </div>
                            </section>
                        );
                    })}

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
                                        smsProviderOptions={options}
                                        smsProviderLabel={smsProviderLabel}
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
                            {activeOption ? `${activeOption.label} credentials are incomplete.` : 'Active provider credentials are incomplete.'} Complete required fields before saving or sending tests.
                        </p>
                    ) : null}

                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={saveSmsProviderConfig}
                            disabled={saveSmsProviderMutation.isPending || !smsProviderForm.reason.trim() || fallbackInvalid || !smsProviderForm.default_prefix.trim() || (smsProviderForm.enabled && !smsReady)}
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
                                    Test for market <span className="text-slate-400 font-normal">(optional)</span>
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
                            <div>
                                <label htmlFor="sms-test-provider" className="mb-1 block text-sm font-medium text-slate-700">
                                    Provider to test <span className="text-slate-400 font-normal">(optional)</span>
                                </label>
                                <select
                                    id="sms-test-provider"
                                    value={smsTestForm.provider ?? ''}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, provider: event.target.value || '' }))}
                                    className="crm-select w-full"
                                >
                                    <option value="">Market default (active provider)</option>
                                    {options.map((provider) => (
                                        <option key={provider.id} value={provider.id}>{provider.label}</option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-slate-500">Pick a specific gateway to test it directly, bypassing the market's active choice.</p>
                            </div>
                            <label htmlFor="sms-test-skip-fallback" className="flex items-start gap-2 rounded-md border border-slate-200 bg-slate-50 p-2.5">
                                <input
                                    id="sms-test-skip-fallback"
                                    type="checkbox"
                                    checked={Boolean(smsTestForm.skip_fallback)}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, skip_fallback: event.target.checked }))}
                                    className="mt-0.5 h-4 w-4 rounded border-slate-300"
                                />
                                <span className="text-xs text-slate-600">
                                    <span className="font-medium text-slate-800">Skip fallback</span> — send only through the chosen provider so its real
                                    result isn't masked by a fallback. Recommended when testing a single gateway.
                                </span>
                            </label>
                            <input
                                value={smsTestForm.phone}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, phone: event.target.value }))}
                                className="crm-input"
                                placeholder="Phone (example: +254712000000)"
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
                                disabled={testSmsProviderMutation.isPending || !smsTestReady || !smsProviderForm.enabled || !smsTestForm.phone.trim() || !smsTestForm.message.trim() || !smsTestForm.reason.trim()}
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
                                <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {latestSmsTestResult.provider_response || 'No provider response message.'}</p>
                                {latestSmsTestResult.fallback_attempted ? (
                                    <p><span className="font-semibold text-slate-800">Fallback:</span> Attempted from {smsProviderLabel(latestSmsTestResult.fallback_from || smsProviderForm.active_provider)}</p>
                                ) : null}
                                {latestSmsTestResult.fallback_skipped ? (
                                    <p><span className="font-semibold text-slate-800">Fallback:</span> Skipped (single-provider test)</p>
                                ) : null}
                            </div>

                            {Array.isArray(latestSmsTestResult.trace?.attempts) && latestSmsTestResult.trace.attempts.length > 0 ? (
                                <details className="mt-3 rounded-md border border-slate-200 bg-white">
                                    <summary className="cursor-pointer select-none px-3 py-2 text-xs font-semibold text-slate-700">
                                        Diagnostics ({latestSmsTestResult.trace.attempts.length} attempt{latestSmsTestResult.trace.attempts.length === 1 ? '' : 's'})
                                    </summary>
                                    <div className="space-y-2 border-t border-slate-200 p-3">
                                        {latestSmsTestResult.trace.attempts.map((attempt, index) => (
                                            <div key={`${attempt.provider}-${index}`} className="rounded-md border border-slate-200 bg-slate-50 p-2.5 text-xs text-slate-600">
                                                <div className="mb-1 flex items-center justify-between gap-2">
                                                    <span className="font-semibold text-slate-800">
                                                        {attempt.provider_label || attempt.provider}
                                                        <span className="ml-1 font-normal text-slate-400">({attempt.role})</span>
                                                    </span>
                                                    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(attempt.success ? 'success' : 'failed')}`}>
                                                        {attempt.success ? 'sent' : 'failed'}
                                                    </span>
                                                </div>
                                                <dl className="grid grid-cols-[auto,1fr] gap-x-3 gap-y-0.5">
                                                    <dt className="text-slate-500">Configured</dt>
                                                    <dd>{attempt.configured ? 'yes' : 'no'}</dd>
                                                    <dt className="text-slate-500">HTTP</dt>
                                                    <dd>{attempt.http_code ?? '--'}</dd>
                                                    {attempt.expected_success_code != null ? (
                                                        <>
                                                            <dt className="text-slate-500">Code</dt>
                                                            <dd>expected {attempt.expected_success_code}, got {attempt.actual_success_code ?? '--'}</dd>
                                                        </>
                                                    ) : null}
                                                    {attempt.request && Object.keys(attempt.request).length > 0 ? (
                                                        <>
                                                            <dt className="text-slate-500">Request</dt>
                                                            <dd className="break-all">
                                                                {Object.entries(attempt.request).map(([key, value]) => (
                                                                    <span key={key} className="mr-2 inline-block"><span className="text-slate-500">{key}=</span>{String(value)}</span>
                                                                ))}
                                                            </dd>
                                                        </>
                                                    ) : null}
                                                    <dt className="text-slate-500">Response</dt>
                                                    <dd className="break-all">{attempt.provider_response || '--'}</dd>
                                                </dl>
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            ) : null}
                        </section>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
