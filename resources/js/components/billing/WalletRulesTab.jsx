import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

/**
 * WalletRulesTab component displays and manages per-market wallet funding policies.
 * Includes wallet limits, wallet funding presets, auto-renewal rules, and UI preferences.
 * Phase 3 is read-only; write operations deferred to Phase 4.
 */
export default function WalletRulesTab({ platforms = [] }) {
    const [selectedMarket, setSelectedMarket] = useState(null);

    const marketId = selectedMarket?.id;

    /**
     * Fetch wallet rules for the selected market.
     * Query key includes marketId to refetch when market changes.
     * staleTime: 10 minutes - wallet rules change less frequently
     */
    const walletRulesQuery = useQuery({
        queryKey: ['billing-wallet-rules', marketId],
        queryFn: () => api.get(`/crm/settings/billing/wallet-rules/${marketId}`).then(
            (response) => response.data
        ),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000, // 10 minutes
    });

    const { data = {} } = walletRulesQuery;
    const walletRule = useMemo(() => data.wallet_rule || null, [data.wallet_rule]);

    // Handle loading state
    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Wallet Rules"
                    title="No markets available"
                    message="Create or enable markets in Platform settings before configuring wallet rules."
                />
            </div>
        );
    }

    if (!selectedMarket) {
        return (
            <div className="space-y-4 p-5">
                {/* Introduction section */}
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h4 className="text-sm font-semibold text-slate-900">
                        Wallet Rules Configuration
                    </h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Select a market below to view and configure its wallet funding policies,
                        including limits, wallet funding presets, and auto-renewal settings.
                    </p>
                </section>

                {/* Markets grid */}
                <div className="grid gap-4 xl:grid-cols-3">
                    {platforms.map((platform) => (
                        <MarketCard
                            key={platform.id}
                            platform={platform}
                            onSelect={() => setSelectedMarket(platform)}
                        />
                    ))}
                </div>
            </div>
        );
    }

    if (walletRulesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="space-y-4">
                    {[...Array(3)].map((_, i) => (
                        <div
                            key={i}
                            className="h-40 rounded-xl border border-slate-200 bg-white"
                        />
                    ))}
                </div>
            </div>
        );
    }

    if (walletRulesQuery.isError) {
        if (isForbiddenQueryError(walletRulesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Wallet Rules"
                        title="Wallet funding rules are outside your billing scope"
                        message="This role cannot inspect the wallet funding policy for the selected market."
                    />
                    <button
                        type="button"
                        onClick={() => setSelectedMarket(null)}
                        className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Back to Markets
                    </button>
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Wallet Rules"
                    title="Wallet rules unavailable"
                    message="CRM could not load wallet rules for this market. Refresh the page to retry."
                />
                <button
                    type="button"
                    onClick={() => setSelectedMarket(null)}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Back to Markets
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            {/* Header with back button */}
            <section className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                <div className="flex-1">
                    <h4 className="text-sm font-semibold text-slate-900">
                        {selectedMarket?.name} Wallet Rules
                    </h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Wallet funding policies for this market including limits, presets, and
                        auto-renewal settings. These rules govern wallet-based payment flows.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setSelectedMarket(null)}
                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                    Back
                </button>
            </section>

            {walletRule ? (
                <>
                    {/* Wallet Status */}
                    <section className="rounded-xl border border-slate-200 bg-white p-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h5 className="text-sm font-semibold text-slate-900">
                                    Wallet Status
                                </h5>
                                <p className="mt-1 text-xs text-slate-600">
                                    Enable or disable wallet funding for this market
                                </p>
                            </div>
                            <span
                                className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] ${
                                    walletRule.enabled
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-slate-100 text-slate-700'
                                }`}
                            >
                                {walletRule.enabled ? 'Enabled' : 'Disabled'}
                            </span>
                        </div>
                    </section>

                    {/* Currency & Presets */}
                    {walletRule.currency_code && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h5 className="text-sm font-semibold text-slate-900">
                                Currency & Wallet Funding Presets
                            </h5>
                            <div className="mt-3 space-y-3">
                                <div>
                                    <p className="text-xs font-medium text-slate-600">
                                        Currency Code
                                    </p>
                                    <p className="mt-1 rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900">
                                        {walletRule.currency_code}
                                    </p>
                                </div>

                                {walletRule.topup_preset_json && (
                                    <div>
                                        <p className="text-xs font-medium text-slate-600">
                                            Wallet Funding Presets
                                        </p>
                                        <div className="mt-1 flex flex-wrap gap-2">
                                            {Array.isArray(walletRule.topup_preset_json) ? (
                                                walletRule.topup_preset_json.map((preset, idx) => (
                                                    <span
                                                        key={`preset-${walletRule.id}-${idx}`}
                                                        className="rounded-full bg-teal-100 px-3 py-1 text-sm font-medium text-teal-700"
                                                    >
                                                        {preset}
                                                    </span>
                                                ))
                                            ) : (
                                                Object.entries(walletRule.topup_preset_json).map(
                                                    ([key, value], idx) => (
                                                        <span
                                                            key={`preset-obj-${walletRule.id}-${idx}`}
                                                            className="rounded-full bg-teal-100 px-3 py-1 text-sm font-medium text-teal-700"
                                                        >
                                                            {value}
                                                        </span>
                                                    )
                                                )
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        </section>
                    )}

                    {/* Wallet Limits */}
                    {walletRule.limit_json && Object.keys(walletRule.limit_json).length > 0 && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h5 className="text-sm font-semibold text-slate-900">
                                Wallet Limits
                            </h5>
                            <dl className="mt-3 space-y-2">
                                {Object.entries(walletRule.limit_json).map(([key, value], idx) => (
                                    <div
                                        key={`limit-${walletRule.id}-${idx}`}
                                        className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                                    >
                                        <dt className="text-xs font-medium text-slate-700">
                                            {key}
                                        </dt>
                                        <dd className="font-mono text-sm font-semibold text-slate-900">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    )}

                    {/* Auto-Renewal Policy */}
                    {walletRule.auto_renew_json && Object.keys(walletRule.auto_renew_json).length > 0 && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h5 className="text-sm font-semibold text-slate-900">
                                Auto-Renewal Policy
                            </h5>
                            <dl className="mt-3 space-y-2">
                                {Object.entries(walletRule.auto_renew_json).map(([key, value]) => (
                                    <div
                                        key={key}
                                        className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                                    >
                                        <dt className="text-xs font-medium text-slate-700">
                                            {key}
                                        </dt>
                                        <dd className="text-right text-sm text-slate-900">
                                            {typeof value === 'boolean'
                                                ? value
                                                    ? '✓ Enabled'
                                                    : '✗ Disabled'
                                                : String(value)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    )}

                    {/* UI Preferences */}
                    {walletRule.ui_json && Object.keys(walletRule.ui_json).length > 0 && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <h5 className="text-sm font-semibold text-slate-900">
                                UI Preferences
                            </h5>
                            <dl className="mt-3 space-y-2">
                                {Object.entries(walletRule.ui_json).map(([key, value], idx) => (
                                    <div
                                        key={`ui-${walletRule.id}-${idx}`}
                                        className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                                    >
                                        <dt className="text-xs font-medium text-slate-700">
                                            {key}
                                        </dt>
                                        <dd className="text-right text-sm text-slate-900">
                                            {String(value)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    )}

                    {!walletRule.enabled && (
                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                            <p className="text-sm text-slate-600">
                                Wallet funding is currently disabled for this market. Enable wallet rules
                                in Phase 4 to allow customers to fund wallets for bill payments.
                            </p>
                        </section>
                    )}
                </>
            ) : (
                <BillingStateNotice
                    state="empty"
                    eyebrow={`${selectedMarket?.name} Wallet Rules`}
                    title="No wallet rules configured"
                    message="Create wallet rules in Phase 4 to set wallet funding limits, presets, and auto-renewal policies for this market."
                />
            )}

            {/* Phase 3 Notice */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">
                    Phase 3 Read-Only Mode
                </h4>
                <p className="mt-2 text-sm text-slate-600">
                    Wallet rule management (create, edit, delete) is available in Phase 4.
                    This view displays existing wallet configurations for this market.
                </p>
            </section>
        </div>
    );
}

/**
 * MarketCard displays a single market for selection.
 */
function MarketCard({ platform, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            className="rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-slate-300 hover:shadow-sm"
        >
            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                Market
            </p>
            <h5 className="mt-1 text-sm font-semibold text-slate-900">
                {platform.name}
            </h5>
            {platform.country && (
                <p className="mt-1 text-xs text-slate-600">
                    Country: {platform.country}
                </p>
            )}
            <div className="mt-3 flex items-center gap-2 pt-3 text-[11px] text-slate-500">
                <svg className="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
                View Wallet Rules
            </div>
        </button>
    );
}
