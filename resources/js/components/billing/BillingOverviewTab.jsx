import React from 'react';
import BillingStateNotice from './BillingStateNotice';

function tone(enabled) {
    return enabled
        ? 'border-emerald-200/90 bg-[linear-gradient(180deg,rgba(236,253,245,0.95)_0%,rgba(255,255,255,1)_100%)] text-emerald-900'
        : 'border-slate-200 bg-[linear-gradient(180deg,rgba(248,250,252,0.95)_0%,rgba(255,255,255,1)_100%)] text-slate-800';
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
            eyebrow: 'Readiness',
        },
        {
            key: 'workspace',
            label: 'Billing Workspace',
            value: features.workspace ? 'Ready' : 'Hidden',
            enabled: features.workspace,
            detail: 'Controls whether the top-level Billing settings workspace is visible.',
            eyebrow: 'Visibility',
        },
        {
            key: 'diagnostics',
            label: 'Diagnostics V2',
            value: features.diagnostics_v2 ? 'Enabled' : 'Deferred',
            enabled: features.diagnostics_v2,
            detail: 'Backs the future Billing Diagnostics health surface.',
            eyebrow: 'Observability',
        },
        {
            key: 'renewal',
            label: 'Wallet Auto-Renew',
            value: features.wallet_auto_renew ? 'Enabled' : 'Deferred',
            enabled: features.wallet_auto_renew,
            detail: `Current billing system mode: ${summary.walletMode}.`,
            eyebrow: 'Continuity',
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
                    <section
                        key={card.key}
                        className={`rounded-2xl border p-5 shadow-sm shadow-slate-950/[0.03] ${tone(card.enabled)}`}
                    >
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] opacity-70">{card.eyebrow}</p>
                        <div className="mt-4 flex items-start justify-between gap-3">
                            <div>
                                <p className="text-sm font-semibold">{card.label}</p>
                                <p className="mt-3 text-3xl font-semibold tracking-tight">{card.value}</p>
                            </div>
                            <span
                                className={`rounded-full border px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] ${
                                    card.enabled
                                        ? 'border-emerald-200 bg-white/80 text-emerald-700'
                                        : 'border-slate-200 bg-white/80 text-slate-500'
                                }`}
                            >
                                {card.enabled ? 'Online' : 'Deferred'}
                            </span>
                        </div>
                        <p className="mt-4 text-sm leading-6 text-slate-600">{card.detail}</p>
                    </section>
                ))}
            </div>

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(260px,0.6fr)]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Workspace posture</p>
                        <h4 className="mt-2 text-lg font-semibold text-slate-950">Registry-backed billing readiness</h4>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Use this view to confirm whether CRM can operate billing from provider profiles and market
                            routing instead of legacy integration payloads. It is the rollout checkpoint before
                            credentials, routing, and diagnostics are opened wider to operators.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Visible markets</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-950">{summary.totalMarkets}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Wallet-ready markets</p>
                            <p className="mt-2 text-2xl font-semibold text-slate-950">{summary.walletEnabledMarkets}</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    );
}
