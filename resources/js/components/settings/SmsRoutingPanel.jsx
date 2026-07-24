import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import SmsDispatchLog from './SmsDispatchLog';
import LifecycleSmsPanel from './LifecycleSmsPanel';

function formatMoney(amount, currency) {
    if (amount === null || amount === undefined) return '—';
    const n = Number(amount).toLocaleString(undefined, { maximumFractionDigits: 2 });
    return currency ? `${currency} ${n}` : n;
}

const SUB_TABS = [
    { id: 'routing', label: 'Routing' },
    { id: 'providers', label: 'Providers' },
    { id: 'markets', label: 'Markets' },
    { id: 'lifecycle', label: 'Lifecycle' },
    { id: 'test', label: 'Test & Logs' },
];

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

function Chevron({ open }) {
    return (
        <svg
            className={`h-4 w-4 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`}
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fillRule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clipRule="evenodd" />
        </svg>
    );
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
                            <span className="inline-flex items-center gap-1 text-xs text-amber-600">
                                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                Provider not ready
                            </span>
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

    const [subTab, setSubTab] = useState('routing');
    const [expandedProvider, setExpandedProvider] = useState(null);
    const [marketSearch, setMarketSearch] = useState('');
    const [showAllMarkets, setShowAllMarkets] = useState(false);
    const [revealedMarkets, setRevealedMarkets] = useState([]);
    const [addMarketId, setAddMarketId] = useState('');

    const balancesQuery = useQuery({
        queryKey: ['sms-provider-balances'],
        queryFn: () => api.get('/crm/settings/integrations/sms-provider/balances').then((r) => r.data),
        enabled: subTab === 'providers',
        staleTime: 300_000,
        refetchOnWindowFocus: false,
    });
    const balanceById = useMemo(
        () => Object.fromEntries((balancesQuery.data?.providers || []).map((p) => [p.id, p])),
        [balancesQuery.data],
    );

    const globalCredsFor = (providerId) => smsProviderForm.providers?.[providerId] || {};
    const providerConfigured = (provider) => (provider.fields || [])
        .every((field) => !field.required || fieldHasValue(globalCredsFor(provider.id), field));

    const allPlatforms = platforms ?? [];
    const marketHasOverride = (platform) => hasMarketOverride(markets?.[String(platform.id)] ?? {}, options);
    const overrideCount = useMemo(() => allPlatforms.filter(marketHasOverride).length, [allPlatforms, markets, options]);

    const visibleMarkets = useMemo(() => {
        const term = marketSearch.trim().toLowerCase();
        return allPlatforms.filter((platform) => {
            if (term && !platform.name.toLowerCase().includes(term)) {
                return false;
            }
            if (showAllMarkets) {
                return true;
            }
            return marketHasOverride(platform) || revealedMarkets.includes(platform.id);
        });
    }, [allPlatforms, markets, options, marketSearch, showAllMarkets, revealedMarkets]);

    const addableMarkets = useMemo(
        () => allPlatforms.filter((platform) => !marketHasOverride(platform) && !revealedMarkets.includes(platform.id)),
        [allPlatforms, markets, options, revealedMarkets],
    );

    const showFooter = subTab !== 'test' && subTab !== 'lifecycle';

    const saveDisabled = saveSmsProviderMutation.isPending
        || !smsProviderForm.reason.trim()
        || fallbackInvalid
        || !smsProviderForm.default_prefix.trim()
        || (smsProviderForm.enabled && !smsReady);

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">SMS Provider Routing</h3>
                    <p className="crm-panel-subtitle">Configure providers, per-market routing, and validate delivery.</p>
                </div>
                <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${smsProviderForm.enabled ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-100 text-slate-500 ring-slate-500/20'}`}>
                    <span className={`h-1.5 w-1.5 rounded-full ${smsProviderForm.enabled ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                    {smsProviderForm.enabled ? 'Dispatch enabled' : 'Dispatch disabled'}
                </span>
            </header>

            <div className="border-b border-slate-200 px-4 pt-3">
                <div className="inline-flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
                    {SUB_TABS.map((tab) => {
                        const active = subTab === tab.id;
                        const badge = tab.id === 'markets' && overrideCount > 0 ? overrideCount : null;
                        return (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => setSubTab(tab.id)}
                                className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}
                            >
                                {tab.label}
                                {badge != null ? (
                                    <span className="ml-1.5 rounded-full bg-teal-100 px-1.5 text-xs font-semibold text-teal-700">{badge}</span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="p-4">
                {subTab === 'routing' ? (
                    <div className="max-w-2xl space-y-4">
                        <label className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <input
                                type="checkbox"
                                checked={Boolean(smsProviderForm.enabled)}
                                onChange={(event) => setSmsProviderForm((current) => ({ ...current, enabled: event.target.checked }))}
                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            <span>
                                <span className="block text-sm font-medium text-slate-800">Enable SMS dispatch for operational events</span>
                                <span className="mt-0.5 block text-xs text-slate-500">When off, no SMS are sent for renewals, alerts, or payment events.</span>
                            </span>
                        </label>

                        <div className="grid gap-3 md:grid-cols-2">
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
                                <p className="mt-1 text-xs text-slate-400">The default gateway for markets without an override.</p>
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
                                <p className="mt-1 text-xs text-slate-400">Used only if the active provider fails.</p>
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
                                    placeholder="Reason for updating SMS routing (recorded in the audit log)"
                                />
                            </div>
                        </div>
                    </div>
                ) : null}

                {subTab === 'providers' ? (
                    <div className="space-y-2">
                        <p className="text-xs text-slate-500">Global credentials per provider. Markets inherit these unless overridden. Balance is fetched live where the provider exposes an API; spend is estimated from the last 30 days of sends × your cost per SMS.</p>
                        {options.map((provider) => {
                            const isActive = provider.id === smsProviderForm.active_provider;
                            const configured = providerConfigured(provider);
                            const open = expandedProvider === provider.id;
                            const bal = balanceById[provider.id];
                            const creds = globalCredsFor(provider.id);
                            return (
                                <div key={provider.id} className="rounded-lg border border-slate-200 bg-white">
                                    <button
                                        type="button"
                                        onClick={() => setExpandedProvider(open ? null : provider.id)}
                                        className="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left"
                                        aria-expanded={open}
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <span className="truncate text-sm font-medium text-slate-800">{provider.label}</span>
                                            {isActive ? (
                                                <span className="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200">Active</span>
                                            ) : null}
                                        </span>
                                        <span className="flex items-center gap-3">
                                            {bal ? (
                                                bal.balance ? (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700" title="Live account balance">
                                                        {formatMoney(bal.balance.amount, bal.balance.currency)}
                                                    </span>
                                                ) : bal.balance_supported ? (
                                                    <span className="text-[11px] text-slate-400" title="Balance API configured but unavailable right now">balance n/a</span>
                                                ) : null
                                            ) : (balancesQuery.isFetching ? <span className="text-[11px] text-slate-300">…</span> : null)}
                                            <span className={`inline-flex items-center gap-1.5 text-xs font-medium ${configured ? 'text-emerald-700' : 'text-slate-400'}`}>
                                                <span className={`h-1.5 w-1.5 rounded-full ${configured ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                                                {configured ? 'Configured' : 'Incomplete'}
                                            </span>
                                            <Chevron open={open} />
                                        </span>
                                    </button>
                                    {open ? (
                                        <div className="space-y-3 border-t border-slate-100 p-3">
                                            <ProviderFields
                                                option={provider}
                                                creds={creds}
                                                scope="global"
                                                idPrefix="sms-global"
                                                onFieldChange={(key, value) => updateSmsProviderField(provider.id, key, value)}
                                            />
                                            <div className="grid gap-3 rounded-md bg-slate-50 p-2.5 sm:grid-cols-3">
                                                <div>
                                                    <label htmlFor={`sms-cost-${provider.id}`} className="mb-1 block text-xs font-medium text-slate-600">Cost per SMS</label>
                                                    <input
                                                        id={`sms-cost-${provider.id}`}
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={creds.unit_cost ?? ''}
                                                        onChange={(e) => updateSmsProviderField(provider.id, 'unit_cost', e.target.value)}
                                                        className="crm-input text-sm"
                                                        placeholder="e.g. 0.80"
                                                    />
                                                    <p className="mt-1 text-[10px] text-slate-400">Your per-message rate, for the spend estimate.</p>
                                                </div>
                                                <div>
                                                    <p className="mb-1 text-xs font-medium text-slate-600">Sent (30d)</p>
                                                    <p className="text-sm font-semibold text-slate-800">{bal ? Number(bal.sent_count || 0).toLocaleString() : '—'}</p>
                                                </div>
                                                <div>
                                                    <p className="mb-1 text-xs font-medium text-slate-600">Est. spend (30d)</p>
                                                    <p className="text-sm font-semibold text-slate-800">
                                                        {bal && bal.estimated_spend !== null && bal.estimated_spend !== undefined
                                                            ? formatMoney(bal.estimated_spend, bal.balance?.currency || '')
                                                            : <span className="text-slate-400">set a cost</span>}
                                                    </p>
                                                </div>
                                            </div>
                                            {bal && !bal.balance_supported ? (
                                                <p className="text-[11px] text-slate-400">This provider has no balance API — check remaining credit on the provider portal.</p>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                ) : null}

                {subTab === 'markets' ? (
                    <div className="space-y-3">
                        <p className="text-xs text-slate-500">Markets inherit global routing by default. Add an override to use a different provider or separate credentials for a market.</p>

                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <input
                                type="search"
                                value={marketSearch}
                                onChange={(event) => setMarketSearch(event.target.value)}
                                placeholder="Search markets"
                                className="crm-input sm:max-w-xs"
                                aria-label="Search markets"
                            />
                            <label className="flex items-center gap-2 text-xs text-slate-600">
                                <input
                                    type="checkbox"
                                    checked={showAllMarkets}
                                    onChange={(event) => setShowAllMarkets(event.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300"
                                />
                                Show all {allPlatforms.length} markets
                            </label>
                        </div>

                        {!showAllMarkets && addableMarkets.length > 0 ? (
                            <select
                                value={addMarketId}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    if (value) {
                                        setRevealedMarkets((current) => [...current, Number(value)]);
                                        setAddMarketId('');
                                    }
                                }}
                                className="crm-select text-sm sm:max-w-xs"
                                aria-label="Add a market override"
                            >
                                <option value="">+ Add market override…</option>
                                {addableMarkets.map((platform) => (
                                    <option key={platform.id} value={platform.id}>{platform.name}</option>
                                ))}
                            </select>
                        ) : null}

                        {visibleMarkets.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-slate-200 py-10 text-center text-sm text-slate-400">
                                {marketSearch
                                    ? 'No markets match your search.'
                                    : 'No market overrides yet — every market uses global routing. Add one above to customize.'}
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-100 rounded-lg border border-slate-200 px-3">
                                {visibleMarkets.map((platform) => (
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
                                                setRevealedMarkets((current) => current.filter((id) => id !== platform.id));
                                                return;
                                            }
                                            onMarketsChange({ ...(markets || {}), [platformKey]: updated });
                                        }}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                ) : null}

                {subTab === 'lifecycle' ? <LifecycleSmsPanel /> : null}

                {subTab === 'test' ? (
                    <div className="space-y-6">
                        <div className="grid gap-4 lg:grid-cols-2">
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
                                    <p className="mt-2 text-xs text-amber-700">Enable SMS dispatch (Routing tab) before sending a provider test message.</p>
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
                            ) : (
                                <section className="flex items-center justify-center rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-400">
                                    Run a test dispatch to see the provider response and diagnostics here.
                                </section>
                            )}
                        </div>

                        <SmsDispatchLog
                            platforms={allPlatforms}
                            providerOptions={options}
                        />
                    </div>
                ) : null}
            </div>

            {showFooter ? (
                <footer className="space-y-2 border-t border-slate-200 bg-slate-50 px-4 py-3">
                    {fallbackInvalid ? (
                        <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                            Fallback provider must be different from the active provider.
                        </p>
                    ) : null}
                    {smsProviderForm.enabled && !smsReady ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                            {activeOption ? `${activeOption.label} credentials are incomplete.` : 'Active provider credentials are incomplete.'} Complete required fields (Providers tab) before saving or sending tests.
                        </p>
                    ) : null}
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-xs text-slate-400">Changes apply to all tabs and are saved together.</p>
                        <button
                            type="button"
                            onClick={saveSmsProviderConfig}
                            disabled={saveDisabled}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveSmsProviderMutation.isPending ? 'Saving...' : 'Save SMS settings'}
                        </button>
                    </div>
                </footer>
            ) : null}
        </section>
    );
}
