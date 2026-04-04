import React from 'react';
import BillingStateNotice from './BillingStateNotice';

function tone(enabled) {
    return enabled
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-slate-200 bg-slate-50 text-slate-700';
}

export default function BillingOverviewTab({
    summary,
    features,
    isLoading = false,
    isError = false,
}) {
    if (isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="grid gap-4 xl:grid-cols-4">
                    <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                </div>
            </div>
        );
    }

    if (isError) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Overview"
                    title="Billing summary unavailable"
                    message="CRM could not load the billing overview right now. Refresh the page to retry."
                />
            </div>
        );
    }

    const cards = [
        {
            key: 'billing',
            label: 'Billing Domain',
            value: summary.billingEnabled ? 'Enabled' : 'Disabled',
            enabled: summary.billingEnabled,
            detail: `${summary.walletEnabledMarkets} wallet-enabled markets across ${summary.totalMarkets} visible markets.`,
        },
        {
            key: 'workspace',
            label: 'Billing Workspace',
            value: features.workspace ? 'Ready' : 'Hidden',
            enabled: features.workspace,
            detail: 'Controls whether the top-level Billing settings workspace is visible.',
        },
        {
            key: 'diagnostics',
            label: 'Diagnostics V2',
            value: features.diagnostics_v2 ? 'Enabled' : 'Deferred',
            enabled: features.diagnostics_v2,
            detail: 'Backs the future Billing Diagnostics health surface.',
        },
        {
            key: 'renewal',
            label: 'Wallet Auto-Renew',
            value: features.wallet_auto_renew ? 'Enabled' : 'Deferred',
            enabled: features.wallet_auto_renew,
            detail: `Current billing system mode: ${summary.walletMode}.`,
        },
    ];

    return (
        <div className="space-y-4 p-5">
            {!summary.billingEnabled ? (
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Overview"
                    title="Billing rollout is still disabled"
                    message="The Billing workspace shell is visible for migration planning, but live billing orchestration remains on the legacy compatibility path until the rollout flags are enabled."
                />
            ) : null}

            {!features.wallet_auto_renew ? (
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Wallet Auto-Renew"
                    title="Wallet auto-renew fallback remains on the legacy path"
                    message="Operators can inspect the rollout posture here, but wallet renewal fallback is still governed by the legacy runtime until Billing wallet rules are introduced."
                />
            ) : null}

            {summary.totalMarkets === 0 ? (
                <BillingStateNotice
                    state="empty"
                    eyebrow="Overview"
                    title="No market billing data loaded yet"
                    message="CRM did not return any visible market records for this session, so the Billing workspace cannot summarize market-level wallet readiness."
                />
            ) : null}

            <div className="grid gap-4 xl:grid-cols-4">
                {cards.map((card) => (
                    <section key={card.key} className={`rounded-xl border p-4 ${tone(card.enabled)}`}>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em]">{card.label}</p>
                        <p className="mt-3 text-lg font-semibold">{card.value}</p>
                        <p className="mt-2 text-sm">{card.detail}</p>
                    </section>
                ))}
            </div>

            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">Phase 0B Scope</h4>
                <p className="mt-2 text-sm text-slate-600">
                    This Billing workspace is currently a read-only shell. It is here to establish a clean top-level
                    workspace boundary, separate query keys, and a safe migration path out of the legacy Integrations
                    branch before runtime billing logic moves.
                </p>
            </section>
        </div>
    );
}
