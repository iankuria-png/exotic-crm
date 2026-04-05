import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

export default function SubscriptionRulesTab({ platforms = [] }) {
    const [selectedMarket, setSelectedMarket] = useState(null);

    const marketId = selectedMarket?.id;

    const subscriptionRulesQuery = useQuery({
        queryKey: ['billing-subscription-rules', marketId],
        queryFn: () =>
            api.get(`/crm/settings/billing/subscription-rules/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000,
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
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        Subscription Rules
                    </p>
                    <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                        Choose a market to review subscription policy
                    </h4>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Review activation methods, renewal posture, free trials, and discount policy by
                        market. This gives operators a reliable policy view while registry-backed editing
                        is being wired into the same workspace.
                    </p>
                </section>

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

    if (!subscriptionRules) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow={`Subscription Rules - ${selectedMarket?.name}`}
                    title="No subscription rules configured"
                    message="This market does not yet have a registry-backed subscription policy. Once authoring is enabled here, this panel will hold the live activation and renewal rule set."
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
            <section className="flex items-center justify-between rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                <div className="flex-1">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        Subscription Policy
                    </p>
                    <h4 className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{selectedMarket?.name} Subscription Rules</h4>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
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

            {subscriptionRules.activation_method_json && Object.keys(subscriptionRules.activation_method_json).length > 0 && (
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
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

            {subscriptionRules.renewal_method_json && Object.keys(subscriptionRules.renewal_method_json).length > 0 && (
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
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

            {subscriptionRules.free_trial_json && Object.keys(subscriptionRules.free_trial_json).length > 0 && (
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
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

            {subscriptionRules.discount_json && Object.keys(subscriptionRules.discount_json).length > 0 && (
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
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
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
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

            <section className="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                <h4 className="text-sm font-semibold text-slate-900">Editing posture</h4>
                <p className="mt-2 text-sm leading-6 text-slate-600">
                    Subscription rule authoring is being connected to the registry-backed billing model.
                    Use this panel to confirm activation, renewal, and expiry posture before enabling
                    direct edits for operators.
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
