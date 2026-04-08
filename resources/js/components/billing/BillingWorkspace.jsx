import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingDiagnosticsTab from './BillingDiagnosticsTab';
import BillingOverviewTab from './BillingOverviewTab';
import ProvidersTab from './ProvidersTab';
import ProviderProfilesTab from './ProviderProfilesTab';
import MarketRoutingTab from './MarketRoutingTab';
import WalletRulesTab from './WalletRulesTab';
import SubscriptionRulesTab from './SubscriptionRulesTab';
import ManualPaymentsTab from './ManualPaymentsTab';
import BillingSystemTab from './BillingSystemTab';
import BillingTabNav from './BillingTabNav';

const billingTabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'providers', label: 'Providers' },
    { id: 'profiles', label: 'Profiles' },
    { id: 'market_routing', label: 'Market Routing' },
    { id: 'wallet_rules', label: 'Wallet Rules' },
    { id: 'subscription_rules', label: 'Subscription Rules' },
    { id: 'manual_payments', label: 'Manual Payments' },
    { id: 'billing_system', label: 'Billing System' },
    { id: 'diagnostics', label: 'Diagnostics' },
];

export default function BillingWorkspace() {
    const [activeTab, setActiveTab] = useState('overview');
    const [diagnosticsFilters, setDiagnosticsFilters] = useState({
        marketId: '',
        providerKey: '',
    });

    const overviewQuery = useQuery({
        queryKey: ['billing-workspace-overview'],
        queryFn: () => api.get('/crm/settings/billing/overview').then((response) => response.data),
        staleTime: 60_000,
    });

    const data = overviewQuery.data || {};
    const billing = data.billing || {};
    const features = billing.features || {};
    const markets = data.markets || [];
    const summary = data.summary || {
        billingEnabled: false,
        walletMode: 'disabled',
        totalMarkets: 0,
        walletEnabledMarkets: 0,
    };

    const billingSystemQuery = useQuery({
        queryKey: ['billing-system-settings'],
        queryFn: () => api.get('/crm/settings/billing/system').then((response) => response.data),
        enabled: activeTab === 'billing_system',
        staleTime: 60_000,
    });

    const diagnosticsQuery = useQuery({
        queryKey: ['billing-diagnostics-summary', diagnosticsFilters.marketId, diagnosticsFilters.providerKey],
        queryFn: () => api.get('/crm/settings/billing/diagnostics-summary', {
            params: {
                ...(diagnosticsFilters.marketId ? { market_id: Number(diagnosticsFilters.marketId) } : {}),
                ...(diagnosticsFilters.providerKey ? { provider_key: diagnosticsFilters.providerKey } : {}),
            },
        }).then((response) => response.data),
        enabled: activeTab === 'diagnostics' && Boolean(features.diagnostics_v2),
        staleTime: 30_000,
    });

    const diagnosticsProviderOptions = diagnosticsQuery.data?.diagnostics?.meta?.provider_options || [];

    const headerChips = useMemo(
        () => [
            {
                key: 'registry',
                label: 'Registry',
                value: features.registry ? 'Active' : 'Deferred',
                enabled: Boolean(features.registry),
            },
            {
                key: 'workspace',
                label: 'Workspace',
                value: features.workspace ? 'Visible' : 'Hidden',
                enabled: Boolean(features.workspace),
            },
            {
                key: 'diagnostics',
                label: 'Diagnostics',
                value: features.diagnostics_v2 ? 'Online' : 'Deferred',
                enabled: Boolean(features.diagnostics_v2),
            },
            {
                key: 'renewal',
                label: 'Auto-renew',
                value: features.wallet_auto_renew ? 'Policy ready' : 'Deferred',
                enabled: Boolean(features.wallet_auto_renew),
            },
        ],
        [features.diagnostics_v2, features.registry, features.wallet_auto_renew, features.workspace]
    );

    if (overviewQuery.isLoading) {
        return (
            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Billing</h3>
                        <p className="crm-panel-subtitle">Loading Billing workspace…</p>
                    </div>
                </header>
                <div className="animate-pulse space-y-4 p-5">
                    <div className="h-12 rounded-xl border border-slate-200 bg-white" />
                    <div className="grid gap-4 xl:grid-cols-4">
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                    </div>
                </div>
            </section>
        );
    }

    if (overviewQuery.isError) {
        return (
            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Billing</h3>
                        <p className="crm-panel-subtitle">Billing workspace foundation</p>
                    </div>
                </header>
                <div className="p-5">
                    <section className="rounded-xl border border-rose-200 bg-rose-50 p-4">
                        <h4 className="text-sm font-semibold text-rose-900">Billing workspace unavailable</h4>
                        <p className="mt-2 text-sm text-rose-700">
                            CRM could not load the Billing workspace payload right now. Retry from Settings after the
                            integrations payload is available again.
                        </p>
                    </section>
                </div>
            </section>
        );
    }

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Billing</h3>
                    <p className="crm-panel-subtitle">
                        Operate provider families, credential profiles, routing rules, wallet controls, and billing
                        health from one CRM workspace.
                    </p>
                </div>
            </header>
            {headerChips.length > 0 ? (
                <div className="border-b border-slate-100 bg-slate-50/30 px-5 py-4">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        {headerChips.map((chip) => (
                            <div
                                key={chip.key}
                                className="min-w-0 rounded-lg border border-slate-200 bg-white px-5 py-4 shadow-sm shadow-slate-950/[0.02]"
                            >
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-[9px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        {chip.label}
                                    </p>
                                    <span
                                        className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-50"
                                        title={chip.value}
                                        aria-label={chip.value}
                                    >
                                        <span
                                            className={`h-2 w-2 rounded-full ${
                                                chip.enabled ? 'bg-emerald-500' : 'bg-slate-300'
                                            }`}
                                        />
                                    </span>
                                </div>
                                <p className="mt-3 truncate text-[1.05rem] font-semibold tracking-tight text-slate-950">
                                    {chip.value}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}

            <BillingTabNav tabs={billingTabs} activeTab={activeTab} onChange={setActiveTab} />

            {activeTab === 'overview' ? (
                <BillingOverviewTab
                    summary={summary}
                    features={features}
                    isLoading={overviewQuery.isLoading && !overviewQuery.data}
                    isError={overviewQuery.isError && !overviewQuery.data}
                />
            ) : null}

            {activeTab === 'providers' ? (
                <ProvidersTab registryEnabled={Boolean(features.registry)} />
            ) : null}

            {activeTab === 'profiles' ? (
                <ProviderProfilesTab registryEnabled={Boolean(features.registry)} markets={markets} />
            ) : null}

            {activeTab === 'market_routing' ? (
                <MarketRoutingTab platforms={markets} />
            ) : null}

            {activeTab === 'wallet_rules' ? (
                <WalletRulesTab platforms={markets} />
            ) : null}

            {activeTab === 'subscription_rules' ? (
                <SubscriptionRulesTab platforms={markets} />
            ) : null}

            {activeTab === 'manual_payments' ? (
                <ManualPaymentsTab platforms={markets} />
            ) : null}

            {activeTab === 'billing_system' ? (
                <BillingSystemTab
                    system={billingSystemQuery.data?.system || {}}
                    source={billingSystemQuery.data?.source || {}}
                    features={features}
                    markets={markets}
                    isLoading={billingSystemQuery.isLoading}
                    isError={billingSystemQuery.isError}
                    error={billingSystemQuery.error}
                />
            ) : null}

            {activeTab === 'diagnostics' ? (
                <BillingDiagnosticsTab
                    isLoading={diagnosticsQuery.isLoading}
                    isError={diagnosticsQuery.isError}
                    diagnosticsEnabled={Boolean(features.diagnostics_v2)}
                    services={diagnosticsQuery.data?.services || {}}
                    diagnostics={diagnosticsQuery.data?.diagnostics || null}
                    markets={markets}
                    providerOptions={diagnosticsProviderOptions}
                    filters={diagnosticsFilters}
                    onFiltersChange={setDiagnosticsFilters}
                    error={diagnosticsQuery.error}
                />
            ) : null}
        </section>
    );
}
