import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

export default function ProvidersTab({ registryEnabled = true }) {
    if (!registryEnabled) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Provider Catalog"
                    title="Provider registry is still locked"
                    message="Enable the billing registry rollout before exposing the full provider-family catalog in this workspace."
                />
            </div>
        );
    }

    const providersQuery = useQuery({
        queryKey: ['billing-providers-catalog'],
        queryFn: () => api.get('/crm/settings/billing/providers-catalog').then((response) => response.data),
        staleTime: 5 * 60 * 1000,
    });

    const { data = {} } = providersQuery;
    const providers = useMemo(() => data.providers || [], [data.providers]);
    const statusCounts = useMemo(() => {
        return providers.reduce((carry, provider) => {
            const status = provider?.meta?.status || 'active';
            carry[status] = (carry[status] || 0) + 1;
            return carry;
        }, {});
    }, [providers]);

    if (providersQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-32 rounded-2xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-3">
                    {[...Array(6)].map((_, index) => (
                        <div key={index} className="h-64 rounded-2xl border border-slate-200 bg-white" />
                    ))}
                </div>
            </div>
        );
    }

    if (providersQuery.isError) {
        if (isForbiddenQueryError(providersQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Provider Catalog"
                        title="Provider catalog access is restricted"
                        message="This account can open the Billing workspace, but it is not allowed to inspect provider-family metadata in this environment."
                    />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Provider Catalog"
                    title="Provider catalog unavailable"
                    message="CRM could not load the billing provider catalog right now. Refresh the page to retry."
                />
            </div>
        );
    }

    if (providers.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Provider Catalog"
                    title="No provider families are registered"
                    message="The billing registry is active, but it has not published any provider families yet."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            <section className="rounded-2xl border border-slate-200 bg-white p-5">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                            Provider Catalog
                        </p>
                        <h4 className="mt-2 text-xl font-semibold text-slate-950">
                            Billing providers available to the CRM registry
                        </h4>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                            Review supported rails, execution surfaces, transport modes, and operational posture before
                            attaching real credentials or routing rules to a market.
                        </p>
                    </div>

                    <div className="grid grid-cols-3 gap-3 xl:min-w-[320px]">
                        <MetricCard label="Total" value={providers.length} tone="slate" />
                        <MetricCard label="Active" value={statusCounts.active || 0} tone="emerald" />
                        <MetricCard label="Compatibility" value={statusCounts.compatibility || 0} tone="amber" />
                    </div>
                </div>
            </section>

            <div className="grid gap-4 xl:grid-cols-3">
                {providers.map((provider) => (
                    <ProviderCard key={provider.key} provider={provider} />
                ))}
            </div>
        </div>
    );
}

function ProviderCard({ provider }) {
    const {
        key,
        label,
        family,
        aliases = [],
        capabilities = {},
        country_codes: countryCodes = [],
        currency_codes: currencyCodes = [],
        meta = {},
    } = provider;

    const {
        flags = [],
        surfaces = [],
        rails = [],
        transport_modes: transportModes = [],
    } = capabilities;

    const status = meta.status || 'active';

    return (
        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02] transition hover:border-slate-300">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        {formatKey(family)}
                    </p>
                    <h5 className="mt-2 text-lg font-semibold text-slate-950">{label}</h5>
                    <p className="mt-1 text-xs font-mono text-slate-500">{key}</p>
                </div>

                <span className={`rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] ${providerStatusTone(status)}`}>
                    {status}
                </span>
            </div>

            <div className="mt-4 grid gap-3 md:grid-cols-2">
                <SummaryCell
                    label="Coverage"
                    value={countryCodes.length > 0 ? `${countryCodes.length} market(s)` : 'All configured markets'}
                />
                <SummaryCell
                    label="Currencies"
                    value={currencyCodes.length > 0 ? `${currencyCodes.length} supported` : 'Platform default'}
                />
            </div>

            {(surfaces.length > 0 || rails.length > 0 || transportModes.length > 0 || flags.length > 0) ? (
                <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                    {surfaces.length > 0 ? (
                        <CapabilityRow label="Surfaces" values={surfaces} />
                    ) : null}
                    {rails.length > 0 ? (
                        <CapabilityRow label="Rails" values={rails} />
                    ) : null}
                    {transportModes.length > 0 ? (
                        <CapabilityRow label="Transport" values={transportModes} />
                    ) : null}
                    {flags.length > 0 ? (
                        <CapabilityRow label="Features" values={flags} />
                    ) : null}
                </div>
            ) : null}

            {aliases.length > 0 ? (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Aliases</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {aliases.map((alias) => (
                            <span
                                key={alias}
                                className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-600"
                            >
                                {alias}
                            </span>
                        ))}
                    </div>
                </div>
            ) : null}
        </section>
    );
}

function CapabilityRow({ label, values }) {
    return (
        <div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <div className="mt-2 flex flex-wrap gap-2">
                {values.slice(0, 5).map((value) => (
                    <span
                        key={value}
                        className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-medium text-slate-700"
                    >
                        {formatKey(value)}
                    </span>
                ))}
                {values.length > 5 ? (
                    <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-500">
                        +{values.length - 5}
                    </span>
                ) : null}
            </div>
        </div>
    );
}

function MetricCard({ label, value, tone = 'slate' }) {
    const tones = {
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        amber: 'border-amber-200 bg-amber-50 text-amber-900',
        slate: 'border-slate-200 bg-slate-50 text-slate-900',
    };

    return (
        <div className={`rounded-2xl border px-4 py-3 ${tones[tone] || tones.slate}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] opacity-70">{label}</p>
            <p className="mt-2 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function SummaryCell({ label, value }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-2 text-sm font-semibold text-slate-900">{value}</p>
        </div>
    );
}

function providerStatusTone(status) {
    if (status === 'compatibility') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    if (status === 'deferred' || status === 'legacy' || status === 'inactive') {
        return 'border-slate-200 bg-slate-100 text-slate-600';
    }

    return 'border-emerald-200 bg-emerald-50 text-emerald-700';
}

function formatKey(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}
