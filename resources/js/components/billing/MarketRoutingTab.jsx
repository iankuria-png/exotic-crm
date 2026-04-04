import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

/**
 * MarketRoutingTab component displays and manages market-level provider routing.
 * Per-market routing determines which provider is used for each billing surface.
 * Phase 3 is read-only; write operations deferred to Phase 4.
 */
export default function MarketRoutingTab({ platforms = [] }) {
    const [selectedMarket, setSelectedMarket] = useState(null);

    const marketId = selectedMarket?.id;

    /**
     * Fetch routing rules for the selected market.
     * Query key includes marketId to refetch when market changes.
     * staleTime: 10 minutes - routing rules change less frequently
     */
    const routingRulesQuery = useQuery({
        queryKey: ['billing-routing-rules', marketId],
        queryFn: () => api.get(`/crm/settings/billing/routing-rules/${marketId}`).then(
            (response) => response.data
        ),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000, // 10 minutes
    });

    const { data = {} } = routingRulesQuery;
    const routingRules = useMemo(() => data.routing_rules || [], [data.routing_rules]);

    // Handle loading state
    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Market Routing"
                    title="No markets available"
                    message="Create or enable markets in Platform settings before configuring routing rules."
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
                        Market Routing Configuration
                    </h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Select a market below to view and configure its payment provider
                        routing rules for each billing surface (wallet, subscription, etc).
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

    if (routingRulesQuery.isLoading) {
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

    if (routingRulesQuery.isError) {
        if (isForbiddenQueryError(routingRulesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Market Routing"
                        title="Routing rules are outside your billing scope"
                        message="This role cannot inspect market-level routing details for the selected market."
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
                    eyebrow="Market Routing"
                    title="Routing rules unavailable"
                    message="CRM could not load routing rules for this market. Refresh the page to retry."
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

    if (routingRules.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow={`Market Routing - ${selectedMarket?.name}`}
                    title="No routing rules configured"
                    message="Create routing rules in Phase 4 to assign default providers for each billing surface."
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
                        {selectedMarket?.name} Market Routing
                    </h4>
                    <p className="mt-2 text-sm text-slate-600">
                        Provider routing rules for each billing surface. Routes payments
                        through the configured primary provider with fallback support.
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

            {/* Routing rules by surface */}
            <div className="space-y-4">
                {routingRules.map((rule) => (
                    <RoutingRuleCard
                        key={rule.id}
                        rule={rule}
                        market={selectedMarket}
                    />
                ))}
            </div>

            {/* Phase 3 Notice */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">
                    Phase 3 Read-Only Mode
                </h4>
                <p className="mt-2 text-sm text-slate-600">
                    Market routing configuration (create, edit, delete) is available in Phase 4.
                    This view displays existing routing rules and their status.
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
                View Routing Rules
            </div>
        </button>
    );
}

/**
 * RoutingRuleCard displays a single routing rule per billing surface.
 */
function RoutingRuleCard({ rule, market }) {
    const statusColor = rule.active
        ? 'bg-emerald-100 text-emerald-700'
        : 'bg-slate-100 text-slate-700';

    const primaryBindingLabel = rule.primary_binding
        ? rule.primary_binding.provider_profile?.profile_name || 'Unknown Profile'
        : 'No Primary Route';

    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-2">
                <div className="flex-1">
                    <div className="flex items-center gap-2">
                        <h6 className="text-sm font-semibold text-slate-900 uppercase tracking-[0.06em]">
                            {rule.billing_surface}
                        </h6>
                        <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${statusColor}`}
                        >
                            {rule.active ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <p className="mt-1 text-xs text-slate-600">
                        Surface: {rule.billing_surface}
                    </p>
                </div>
            </div>

            {/* Primary routing */}
            {rule.primary_binding && (
                <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Primary Route
                    </p>
                    <div className="mt-2 rounded-lg bg-slate-50 p-3">
                        <p className="text-sm font-semibold text-slate-900">
                            {primaryBindingLabel}
                        </p>
                        {rule.primary_binding.provider_profile && (
                            <>
                                <p className="mt-1 text-xs text-slate-600">
                                    Provider: {rule.primary_binding.provider_profile.provider_type_key}
                                </p>
                                {rule.primary_binding.provider_profile.environment && (
                                    <p className="text-xs text-slate-600">
                                        Environment: {rule.primary_binding.provider_profile.environment}
                                    </p>
                                )}
                            </>
                        )}
                        <p className="mt-1 text-xs text-slate-500">
                            Priority: {rule.primary_binding.priority}
                        </p>
                    </div>
                </div>
            )}

            {/* Fallback strategy */}
            {rule.fallback_strategy_json && Object.keys(rule.fallback_strategy_json).length > 0 && (
                <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Fallback Strategy
                    </p>
                    <dl className="mt-2 space-y-1 text-xs text-slate-600">
                        {Object.entries(rule.fallback_strategy_json).map(([key, value], idx) => (
                            <div key={`fallback-${rule.id}-${idx}`} className="flex items-center justify-between rounded bg-slate-50 px-2 py-1">
                                <dt className="font-medium">{key}</dt>
                                <dd className="text-right text-slate-900">
                                    {String(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}

            {/* Risk policy if configured */}
            {rule.risk_policy_json && Object.keys(rule.risk_policy_json).length > 0 && (
                <div className="mt-3 border-t border-slate-100 pt-3">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                        Risk Policy
                    </p>
                    <dl className="mt-2 space-y-1 text-xs text-slate-600">
                        {Object.entries(rule.risk_policy_json).map(([key, value], idx) => (
                            <div key={`risk-${rule.id}-${idx}`} className="flex items-center justify-between rounded bg-slate-50 px-2 py-1">
                                <dt className="font-medium">{key}</dt>
                                <dd className="text-right text-slate-900">
                                    {String(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </section>
    );
}
