import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import BillingDiagnosticsTab from './BillingDiagnosticsTab';
import BillingOverviewTab from './BillingOverviewTab';
import ProvidersTab from './ProvidersTab';
import ProviderProfilesTab from './ProviderProfilesTab';
import MarketRoutingTab from './MarketRoutingTab';
import WalletRulesTab from './WalletRulesTab';
import SubscriptionRulesTab from './SubscriptionRulesTab';
import BillingSystemTab from './BillingSystemTab';
import BillingTabNav from './BillingTabNav';

const billingTabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'providers', label: 'Providers' },
    { id: 'profiles', label: 'Profiles' },
    { id: 'market_routing', label: 'Market Routing' },
    { id: 'wallet_rules', label: 'Wallet Rules' },
    { id: 'subscription_rules', label: 'Subscription Rules' },
    { id: 'billing_system', label: 'Billing System' },
    { id: 'diagnostics', label: 'Diagnostics' },
];

export default function BillingWorkspace() {
    const [activeTab, setActiveTab] = useState('overview');

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
        queryKey: ['billing-diagnostics-summary'],
        queryFn: () => api.get('/crm/settings/billing/diagnostics-summary').then((response) => response.data),
        enabled: activeTab === 'diagnostics' && Boolean(features.diagnostics_v2),
        staleTime: 30_000,
    });

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
                        Read-only Phase 0B shell for the future billing registry, routing, and diagnostics workspace.
                    </p>
                </div>
            </header>

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
                <ProviderProfilesTab registryEnabled={Boolean(features.registry)} />
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

            {activeTab === 'billing_system' ? (
                <BillingSystemTab
                    system={billingSystemQuery.data?.system || {}}
                    source={billingSystemQuery.data?.source || {}}
                    isLoading={billingSystemQuery.isLoading}
                    isError={billingSystemQuery.isError}
                />
            ) : null}

            {activeTab === 'diagnostics' ? (
                <BillingDiagnosticsTab
                    isLoading={diagnosticsQuery.isLoading}
                    isError={diagnosticsQuery.isError}
                    diagnosticsEnabled={Boolean(features.diagnostics_v2)}
                    services={diagnosticsQuery.data?.services || {}}
                />
            ) : null}
        </section>
    );
}
