import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

/**
 * SubscriptionRulesTab component displays and manages market-level subscription rules.
 * Per-market subscription rules determine activation methods, renewal policies, and free trial settings.
 * Phase 3 is read-only; write operations deferred to Phase 4.
 */
export default function SubscriptionRulesTab({ platforms = [] }) {
    const [selectedMarket, setSelectedMarket] = useState(null);

    const marketId = selectedMarket?.id;

    /**
     * Fetch subscription rules for the selected market.
     * Query key includes marketId to refetch when market changes.
     * staleTime: 10 minutes - subscription rules change less frequently
     */
    const subscriptionRulesQuery = useQuery({
        queryKey: ['billing-subscription-rules', marketId],
        queryFn: () =>
            api.get(`/crm/settings/billing/subscription-rules/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000, // 10 minutes
    });

    const { data = {} } = subscriptionRulesQuery;
    const subscriptionRules = useMemo(() => data.subscription_rule || null, [data.subscription_rule]);

    // Handle empty state
    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Subscription Rules"
                    title="No markets available"
                    message="Create or enable markets in Platform settings before configuring subscription rules."
                />
            </div>
        );
    }

    // Market selection view
    if (!selectedMarket) {
        return (
            <div className="space-y-4 p-5">
                {/* Introduction section */}
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h4 className="text-sm font-semibold text-slate-900">Subscription Rules Configuration</h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Select a market below to view and configure subscription activation methods, renewal
                        policies, and free trial settings.
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

    // Loading state
    if (subscriptionRulesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="space-y-4">
                    {[...Array(3)].map((_, i) => (
                        <div key={i} className="h-40 rounded-xl border border-slate-200 bg-white" />
                    ))}
                </div>
            </div>
        );
    }

    // Error state
    if (subscriptionRulesQuery.isError) {
        if (isForbiddenQueryError(subscriptionRulesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Subscription Rules"
                        title="Subscription policy access is restricted"
                        message="This role cannot inspect subscription activation and wallet-paid renewal policy for the selected market."
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
                    eyebrow="Subscription Rules"
                    title="Subscription rules unavailable"
                    message="CRM could not load subscription rules for this market. Refresh the page to retry."
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

    // Empty subscription rules state
    if (!subscriptionRules) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow={`Subscription Rules - ${selectedMarket?.name}`}
                    title="No subscription rules configured"
                    message="Create subscription rules in Phase 4 to define activation methods and renewal policies for this market."
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
                    <h4 className="text-sm font-semibold text-slate-900">{selectedMarket?.name} Subscription Rules</h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Subscription configuration rules for this market including activation methods, renewal
                        policies, and free trial settings.
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

            {/* Activation Methods */}
            {subscriptionRules.activation_method_json && Object.keys(subscriptionRules.activation_method_json).length > 0 && (
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Activation Methods</h5>
                    <p className="mt-1 text-xs text-slate-600">Subscription types enabled for this market</p>
                    <dl className="mt-3 space-y-2">
                        {Object.entries(subscriptionRules.activation_method_json).map(([method, enabled], idx) => (
                            <div
                                key={`activation-${subscriptionRules.id}-${idx}`}
                                className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt className="text-xs font-medium text-slate-700">{method}</dt>
                                <dd>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                            enabled
                                                ? 'bg-emerald-100 text-emerald-700'
                                                : 'bg-slate-100 text-slate-700'
                                        }`}
                                    >
                                        {enabled ? 'Enabled' : 'Disabled'}
                                    </span>
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {/* Renewal Methods */}
            {subscriptionRules.renewal_method_json && Object.keys(subscriptionRules.renewal_method_json).length > 0 && (
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Renewal Methods</h5>
                    <p className="mt-1 text-xs text-slate-600">Subscription renewal and wallet-paid renewal policies</p>
                    <dl className="mt-3 space-y-2">
                        {Object.entries(subscriptionRules.renewal_method_json).map(([method, config], idx) => (
                            <div
                                key={`renewal-${subscriptionRules.id}-${idx}`}
                                className="rounded-lg border border-slate-100 bg-slate-50 p-3"
                            >
                                <dt className="text-xs font-semibold text-slate-900">{method}</dt>
                                {typeof config === 'object' && config !== null ? (
                                    <dl className="mt-2 space-y-1 text-xs text-slate-600">
                                        {Object.entries(config).map(([key, value], subIdx) => (
                                            <div key={`${method}-${subIdx}`} className="flex justify-between">
                                                <dt className="font-medium text-slate-700">{key}</dt>
                                                <dd>{String(value)}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                ) : (
                                    <dd className="mt-2 text-xs font-medium text-slate-900">{String(config)}</dd>
                                )}
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {/* Free Trial Settings */}
            {subscriptionRules.free_trial_json && Object.keys(subscriptionRules.free_trial_json).length > 0 && (
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Free Trial Settings</h5>
                    <p className="mt-1 text-xs text-slate-600">Free trial policies and duration</p>
                    <dl className="mt-3 space-y-2">
                        {Object.entries(subscriptionRules.free_trial_json).map(([key, value], idx) => (
                            <div
                                key={`freetrial-${subscriptionRules.id}-${idx}`}
                                className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt className="text-xs font-medium text-slate-700">{key}</dt>
                                <dd className="font-mono text-sm font-semibold text-slate-900">{String(value)}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {/* Discount Policies */}
            {subscriptionRules.discount_json && Object.keys(subscriptionRules.discount_json).length > 0 && (
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Discount Policies</h5>
                    <p className="mt-1 text-xs text-slate-600">Subscription discount rules</p>
                    <dl className="mt-3 space-y-2">
                        {Object.entries(subscriptionRules.discount_json).map(([key, value], idx) => (
                            <div
                                key={`discount-${subscriptionRules.id}-${idx}`}
                                className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt className="text-xs font-medium text-slate-700">{key}</dt>
                                <dd className="font-mono text-sm font-semibold text-slate-900">{String(value)}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {/* Expiry Policies */}
            {subscriptionRules.expiry_policy_json && Object.keys(subscriptionRules.expiry_policy_json).length > 0 && (
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Expiry Policies</h5>
                    <p className="mt-1 text-xs text-slate-600">Subscription expiry and cleanup policies</p>
                    <dl className="mt-3 space-y-2">
                        {Object.entries(subscriptionRules.expiry_policy_json).map(([key, value], idx) => (
                            <div
                                key={`expiry-${subscriptionRules.id}-${idx}`}
                                className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt className="text-xs font-medium text-slate-700">{key}</dt>
                                <dd className="font-mono text-sm font-semibold text-slate-900">{String(value)}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {/* Phase 3 Notice */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">Phase 3 Read-Only Mode</h4>
                <p className="mt-2 text-sm text-slate-600">
                    Subscription rule configuration (create, edit, delete) is available in Phase 4. This view
                    displays existing subscription rules and their status.
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
            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Market</p>
            <h5 className="mt-1 text-sm font-semibold text-slate-900">{platform.name}</h5>
            {platform.country && (
                <p className="mt-1 text-xs text-slate-600">Country: {platform.country}</p>
            )}
            <div className="mt-3 flex items-center gap-2 pt-3 text-[11px] text-slate-500">
                <svg className="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
                View Subscription Rules
            </div>
        </button>
    );
}
