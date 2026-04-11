import React, { useState } from 'react';

function hasMarketOverride(entry) {
    return Boolean(
        entry?.active_provider
        || entry?.fallback_provider
        || entry?.africastalking?.username
        || entry?.africastalking?.api_key_configured
        || entry?.africastalking?.api_key
        || entry?.africastalking?.sender_id
        || entry?.legacy_gateway?.gateway_url
        || entry?.legacy_gateway?.org_code
    );
}

function marketIsReady(entry, globalForm) {
    const provider = entry?.active_provider || globalForm.active_provider;

    if (provider === 'africastalking') {
        const username = entry?.africastalking?.username?.trim() || globalForm.africastalking.username.trim();
        const keyConfigured = entry?.africastalking?.api_key_configured
            || entry?.africastalking?.api_key?.trim()
            || globalForm.africastalking.api_key_configured
            || globalForm.africastalking.api_key?.trim();

        return Boolean(username) && Boolean(keyConfigured);
    }

    if (provider === 'legacy_gateway') {
        const gatewayUrl = entry?.legacy_gateway?.gateway_url?.trim() || globalForm.legacy_gateway.gateway_url.trim();
        const orgCode = entry?.legacy_gateway?.org_code?.trim() || globalForm.legacy_gateway.org_code.trim();

        return Boolean(gatewayUrl) && Boolean(orgCode);
    }

    return true;
}

function MarketSmsRoutingRow({
    onEntryChange,
    platform,
    smsProviderForm,
    smsProviderLabel,
    entry = {},
}) {
    const [expanded, setExpanded] = useState(false);

    const hasOverride = hasMarketOverride(entry);
    const isReady = marketIsReady(entry, smsProviderForm);
    const effectiveProvider = entry.active_provider || smsProviderForm.active_provider;

    const patch = (updates) => onEntryChange({ ...entry, ...updates });
    const patchAt = (field, value) => patch({
        africastalking: {
            ...(entry.africastalking ?? {}),
            [field]: value,
        },
    });
    const patchLegacy = (field, value) => patch({
        legacy_gateway: {
            ...(entry.legacy_gateway ?? {}),
            [field]: value,
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
                        Active provider: {smsProviderLabel(effectiveProvider)}{entry.active_provider ? ' (override)' : ' (global)'}
                    </p>
                </div>

                <select
                    value={entry.active_provider ?? ''}
                    onChange={(event) => patch({ active_provider: event.target.value || null })}
                    className="crm-select text-sm"
                    aria-label={`${platform.name} active provider`}
                >
                    <option value="">Global default</option>
                    <option value="legacy_gateway">Legacy Gateway</option>
                    <option value="africastalking">Africa&apos;s Talking</option>
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
                            <option value="legacy_gateway">Legacy Gateway</option>
                            <option value="africastalking">Africa&apos;s Talking</option>
                        </select>
                    </div>

                    <div>
                        <p className="text-xs font-semibold text-slate-700 mb-1">Africa&apos;s Talking</p>
                        <p className="text-xs text-slate-400 mb-2">Leave blank to use global credentials.</p>
                        <div className="grid gap-2 md:grid-cols-2">
                            <div>
                                <label htmlFor={`sms-market-at-username-${platform.id}`} className="mb-1 block text-xs text-slate-600">Username</label>
                                <input
                                    id={`sms-market-at-username-${platform.id}`}
                                    value={entry.africastalking?.username ?? ''}
                                    onChange={(event) => patchAt('username', event.target.value)}
                                    className="crm-input text-sm"
                                    placeholder={`Global: ${smsProviderForm.africastalking.username || '(not set)'}`}
                                />
                            </div>
                            <div>
                                <label htmlFor={`sms-market-at-sender-${platform.id}`} className="mb-1 block text-xs text-slate-600">Sender ID</label>
                                <input
                                    id={`sms-market-at-sender-${platform.id}`}
                                    value={entry.africastalking?.sender_id ?? ''}
                                    onChange={(event) => patchAt('sender_id', event.target.value)}
                                    className="crm-input text-sm"
                                    placeholder="Optional sender ID override"
                                />
                            </div>
                            <div className="md:col-span-2">
                                <label htmlFor={`sms-market-at-key-${platform.id}`} className="mb-1 block text-xs text-slate-600">API key</label>
                                <input
                                    id={`sms-market-at-key-${platform.id}`}
                                    type="password"
                                    value={entry.africastalking?.api_key ?? ''}
                                    onChange={(event) => patchAt('api_key', event.target.value)}
                                    className="crm-input text-sm"
                                    placeholder="Leave blank to use global API key"
                                />
                                <p className={`mt-1 text-xs ${entry.africastalking?.api_key_configured ? 'text-emerald-700' : 'text-slate-400'}`}>
                                    {entry.africastalking?.api_key_configured
                                        ? 'Custom key stored. Enter a new value only when rotating it.'
                                        : 'No custom key stored. This market will inherit the global key.'}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p className="text-xs font-semibold text-slate-700 mb-1">Legacy Gateway</p>
                        <p className="text-xs text-slate-400 mb-2">Leave blank to use global credentials.</p>
                        <div className="grid gap-2 md:grid-cols-2">
                            <div className="md:col-span-2">
                                <label htmlFor={`sms-market-legacy-url-${platform.id}`} className="mb-1 block text-xs text-slate-600">Gateway URL</label>
                                <input
                                    id={`sms-market-legacy-url-${platform.id}`}
                                    value={entry.legacy_gateway?.gateway_url ?? ''}
                                    onChange={(event) => patchLegacy('gateway_url', event.target.value)}
                                    className="crm-input text-sm"
                                    placeholder={`Global: ${smsProviderForm.legacy_gateway.gateway_url || '(not set)'}`}
                                />
                            </div>
                            <div>
                                <label htmlFor={`sms-market-legacy-org-${platform.id}`} className="mb-1 block text-xs text-slate-600">Org code</label>
                                <input
                                    id={`sms-market-legacy-org-${platform.id}`}
                                    value={entry.legacy_gateway?.org_code ?? ''}
                                    onChange={(event) => patchLegacy('org_code', event.target.value)}
                                    className="crm-input text-sm"
                                    placeholder={`Global: ${smsProviderForm.legacy_gateway.org_code || '76'}`}
                                />
                            </div>
                        </div>
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
    smsReady,
    smsTestForm,
    smsTestReady,
    statusChip,
    testSmsProviderMutation,
    updateSmsProviderField,
}) {
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
                                    <option value="legacy_gateway">Legacy Gateway</option>
                                    <option value="africastalking">Africa&apos;s Talking</option>
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

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Legacy Gateway</h4>
                        <p className="mt-1 text-xs text-slate-500">Existing SMS connector used in current operations.</p>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                value={smsProviderForm.legacy_gateway.gateway_url}
                                onChange={(event) => updateSmsProviderField('legacy_gateway', 'gateway_url', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="Gateway URL"
                            />
                            <input
                                value={smsProviderForm.legacy_gateway.org_code}
                                onChange={(event) => updateSmsProviderField('legacy_gateway', 'org_code', event.target.value)}
                                className="crm-input"
                                placeholder="Org code"
                            />
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Africa&apos;s Talking</h4>
                        <p className="mt-1 text-xs text-slate-500">Use this provider for managed delivery with API-key authentication.</p>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                value={smsProviderForm.africastalking.endpoint}
                                onChange={(event) => updateSmsProviderField('africastalking', 'endpoint', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="API endpoint"
                            />
                            <input
                                value={smsProviderForm.africastalking.username}
                                onChange={(event) => updateSmsProviderField('africastalking', 'username', event.target.value)}
                                className="crm-input"
                                placeholder="Username"
                            />
                            <input
                                value={smsProviderForm.africastalking.sender_id}
                                onChange={(event) => updateSmsProviderField('africastalking', 'sender_id', event.target.value)}
                                className="crm-input"
                                placeholder="Sender ID (optional)"
                            />
                            <input
                                type="password"
                                value={smsProviderForm.africastalking.api_key}
                                onChange={(event) => updateSmsProviderField('africastalking', 'api_key', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="API key (leave blank to keep current key)"
                            />
                        </div>
                        <p className={`mt-2 text-xs ${smsProviderForm.africastalking.api_key_configured ? 'text-emerald-700' : 'text-amber-700'}`}>
                            {smsProviderForm.africastalking.api_key_configured
                                ? 'API key is already stored. Add a new value only when rotating credentials.'
                                : 'No API key is currently configured for Africa\'s Talking.'}
                        </p>
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
                            </div>
                        </section>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
