import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';

/**
 * ProvidersTab component displays the complete catalog of billing providers.
 * Each provider card shows key, label, family, capabilities, and market/currency support.
 */
export default function ProvidersTab() {
    /**
     * Fetch provider catalog from API.
     * Query key scoped to this component for cache isolation.
     * staleTime: 5 minutes - provider catalog rarely changes
     */
    const providersQuery = useQuery({
        queryKey: ['billing-providers-catalog'],
        queryFn: () => api.get('/crm/settings/billing/providers-catalog').then(
            (response) => response.data
        ),
        staleTime: 5 * 60 * 1000, // 5 minutes
    });

    const { data = {} } = providersQuery;
    const providers = useMemo(() => data.providers || [], [data.providers]);

    // Handle loading state
    if (providersQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="grid gap-4 xl:grid-cols-3">
                    {[...Array(6)].map((_, i) => (
                        <div
                            key={i}
                            className="h-48 rounded-xl border border-slate-200 bg-white"
                        />
                    ))}
                </div>
            </div>
        );
    }

    // Handle error state
    if (providersQuery.isError) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Providers Catalog"
                    title="Provider catalog unavailable"
                    message="CRM could not load the provider catalog right now. Refresh the page to retry."
                />
            </div>
        );
    }

    // Handle empty state
    if (providers.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Providers Catalog"
                    title="No providers available"
                    message="Provider catalog is empty. Check that the provider registry has been initialized."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            {/* Introduction section */}
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">
                    Billing Providers
                </h4>
                <p className="mt-2 text-sm text-slate-600">
                    Complete catalog of available billing providers. Each provider
                    supports specific execution surfaces, currencies, markets, and
                    operation types.
                </p>
            </section>

            {/* Providers grid */}
            <div className="grid gap-4 xl:grid-cols-3">
                {providers.map((provider) => (
                    <ProviderCard key={provider.key} provider={provider} />
                ))}
            </div>
        </div>
    );
}

/**
 * ProviderCard displays a single provider with key details and capabilities.
 * Renders provider metadata and capability badges in a compact card format.
 */
function ProviderCard({ provider }) {
    const { key, label, family, capabilities = {} } = provider;
    const { flags = [], surfaces = [], rails = [] } = capabilities;

    // Determine status badge color based on provider metadata
    const statusColor = getProviderStatusColor(provider);

    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4 hover:border-slate-300 transition">
            {/* Header with label and family */}
            <div className="flex items-start justify-between gap-2">
                <div className="flex-1">
                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                        {family}
                    </p>
                    <h5 className="mt-1 text-sm font-semibold text-slate-900">
                        {label}
                    </h5>
                    <p className="mt-1 text-xs text-slate-600 font-mono">
                        {key}
                    </p>
                </div>
                <span
                    className={`whitespace-nowrap rounded-full px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.07em] ${statusColor}`}
                >
                    Live
                </span>
            </div>

            {/* Capabilities section */}
            {(flags.length > 0 || surfaces.length > 0 || rails.length > 0) && (
                <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
                    {/* Surfaces */}
                    {surfaces.length > 0 && (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                Surfaces
                            </p>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {surfaces.slice(0, 3).map((surface) => (
                                    <Badge key={surface} label={surface} />
                                ))}
                                {surfaces.length > 3 && (
                                    <Badge
                                        label={`+${surfaces.length - 3}`}
                                        muted
                                    />
                                )}
                            </div>
                        </div>
                    )}

                    {/* Rails */}
                    {rails.length > 0 && (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                Rails
                            </p>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {rails.slice(0, 3).map((rail) => (
                                    <Badge key={rail} label={rail} />
                                ))}
                                {rails.length > 3 && (
                                    <Badge
                                        label={`+${rails.length - 3}`}
                                        muted
                                    />
                                )}
                            </div>
                        </div>
                    )}

                    {/* Flags */}
                    {flags.length > 0 && (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                Features
                            </p>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {flags.slice(0, 3).map((flag) => (
                                    <Badge key={flag} label={flag} />
                                ))}
                                {flags.length > 3 && (
                                    <Badge
                                        label={`+${flags.length - 3}`}
                                        muted
                                    />
                                )}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Market/Currency support hint */}
            {(provider.country_codes?.length > 0 ||
                provider.currency_codes?.length > 0) && (
                <div className="mt-3 border-t border-slate-100 pt-3 text-[11px] text-slate-500">
                    <p>
                        Supports{' '}
                        {provider.country_codes?.length > 0
                            ? `${provider.country_codes.length} market(s)`
                            : 'all markets'}
                        {provider.currency_codes?.length > 0 &&
                            ` • ${provider.currency_codes.length} currency(ies)`}
                    </p>
                </div>
            )}
        </section>
    );
}

/**
 * Badge component for displaying capability tags.
 * Displays capability/feature as a small badge.
 */
function Badge({ label, muted = false }) {
    return (
        <span
            className={`rounded px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.06em] ${
                muted
                    ? 'border border-slate-200 bg-slate-50 text-slate-500'
                    : 'bg-teal-100 text-teal-700'
            }`}
        >
            {label}
        </span>
    );
}

/**
 * Determine provider status badge color.
 * Can be extended to handle beta/deprecated providers if metadata includes status flags.
 */
function getProviderStatusColor(provider) {
    const status = provider.meta?.status || 'active';
    const colors = {
        active: 'bg-emerald-100 text-emerald-700',
        beta: 'bg-amber-100 text-amber-700',
        deprecated: 'bg-slate-100 text-slate-700',
    };
    return colors[status] || colors.active;
}
