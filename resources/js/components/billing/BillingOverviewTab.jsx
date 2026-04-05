import React from 'react';
import BillingStateNotice from './BillingStateNotice';

export default function BillingOverviewTab({
    summary,
    features,
    isLoading = false,
    isError = false,
}) {
    if (isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
                    {[...Array(4)].map((_, index) => (
                        <div key={index} className="h-36 rounded-xl border border-slate-200 bg-white" />
                    ))}
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
            eyebrow: 'Readiness',
            label: 'Billing Domain',
            value: summary.billingEnabled ? 'Enabled' : 'Disabled',
            enabled: summary.billingEnabled,
            detail: `${summary.walletEnabledMarkets} wallet-enabled markets across ${summary.totalMarkets} visible markets.`,
        },
        {
            key: 'workspace',
            eyebrow: 'Visibility',
            label: 'Billing Workspace',
            value: features.workspace ? 'Ready' : 'Hidden',
            enabled: features.workspace,
            detail: 'Controls whether the Billing workspace is visible to settings owners.',
        },
        {
            key: 'diagnostics',
            eyebrow: 'Observability',
            label: 'Diagnostics V2',
            value: features.diagnostics_v2 ? 'Enabled' : 'Deferred',
            enabled: features.diagnostics_v2,
            detail: 'Controls whether the diagnostics surface can load billing health signals.',
        },
        {
            key: 'renewal',
            eyebrow: 'Continuity',
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
                    message="The Billing workspace is visible for migration planning, but live billing orchestration remains on the legacy compatibility path until rollout flags are enabled."
                />
            ) : null}

            {!features.wallet_auto_renew ? (
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Wallet Auto-Renew"
                    title="Wallet auto-renew fallback remains on the legacy path"
                    message="Operators can inspect rollout posture here, but wallet renewal fallback is still governed by the legacy runtime until Billing wallet rules are introduced."
                />
            ) : null}

            {summary.totalMarkets === 0 ? (
                <BillingStateNotice
                    state="empty"
                    eyebrow="Overview"
                    title="No market billing data loaded yet"
                    message="CRM did not return visible market records for this session, so the Billing workspace cannot summarize market-level wallet readiness."
                />
            ) : null}

            <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
                {cards.map((card) => (
                    <section key={card.key} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{card.eyebrow}</p>
                            <StatusDot enabled={card.enabled} />
                        </div>
                        <div className="mt-4 min-w-0">
                            <p className="text-sm font-semibold text-slate-700">{card.label}</p>
                            <p className="mt-3 break-words text-[2rem] font-semibold leading-none tracking-tight text-slate-950">
                                {card.value}
                            </p>
                        </div>
                        <p className="mt-4 text-sm leading-6 text-slate-600">{card.detail}</p>
                    </section>
                ))}
            </div>

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(260px,0.6fr)]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Workspace posture</p>
                        <h4 className="mt-2 text-lg font-semibold text-slate-950">Registry-backed billing readiness</h4>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Use this view to confirm whether CRM can operate billing from provider profiles and market routing
                            instead of legacy integration payloads. It is the rollout checkpoint before credentials, routing,
                            and diagnostics are opened wider to operators.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                        <MetricTile label="Visible markets" value={summary.totalMarkets} />
                        <MetricTile label="Wallet-ready markets" value={summary.walletEnabledMarkets} />
                    </div>
                </div>
            </section>
        </div>
    );
}

function StatusDot({ enabled }) {
    return (
        <span className={`inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.08em] ${
            enabled ? 'text-emerald-700' : 'text-slate-500'
        }`}>
            <span className={`h-2 w-2 rounded-full ${enabled ? 'bg-emerald-500' : 'bg-slate-300'}`} />
            {enabled ? 'Online' : 'Deferred'}
        </span>
    );
}

function MetricTile({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
            <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-slate-950">{value}</p>
        </div>
    );
}
