import React from 'react';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

function statusTone(status) {
    if (['connected', 'healthy', 'success'].includes(status)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }

    if (['configured_disabled', 'partial', 'degraded', 'pending', 'queued', 'running'].includes(status)) {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    if (['deferred', 'unknown'].includes(status)) {
        return 'border-slate-200 bg-slate-50 text-slate-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-800';
}

export default function BillingDiagnosticsTab({ isLoading, isError, diagnosticsEnabled = false, services, error = null }) {
    const cards = [
        {
            key: 'wallet_system',
            label: 'Wallet System',
            status: services?.wallet_system?.status || 'unknown',
            detail: services?.wallet_system?.mode ? `Mode: ${services.wallet_system.mode}` : 'Status unavailable',
        },
        {
            key: 'kopokopo',
            label: 'KopoKopo',
            status: services?.kopokopo?.status || 'unknown',
            detail: services?.kopokopo?.base_url || services?.kopokopo?.note || 'No provider endpoint configured',
        },
        {
            key: 'payment_service',
            label: 'Payment Service',
            status: services?.payment_service?.status || 'unknown',
            detail: services?.payment_service?.base_url || services?.payment_service?.note || 'No payment service configured',
        },
        {
            key: 'sendgrid',
            label: 'SendGrid',
            status: services?.sendgrid?.status || 'unknown',
            detail: services?.sendgrid?.note || 'No email health summary available',
        },
    ];

    if (isLoading) {
        return (
            <div className="space-y-4 p-5">
                <div className="animate-pulse space-y-4">
                    <div className="h-28 rounded-xl border border-slate-200 bg-white" />
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                        <div className="h-32 rounded-xl border border-slate-200 bg-white" />
                    </div>
                </div>
            </div>
        );
    }

    if (!diagnosticsEnabled) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Diagnostics"
                    title="Billing diagnostics is still gated"
                    message="The Billing workspace can render its diagnostics shell, but the new diagnostics surface remains behind the `diagnostics_v2` rollout flag."
                />
            </div>
        );
    }

    if (isError) {
        if (isForbiddenQueryError(error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Diagnostics"
                        title="Billing diagnostics access is restricted"
                        message="This role can use payment-level diagnostics elsewhere in CRM, but it cannot inspect the Billing diagnostics health surface."
                    />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Diagnostics"
                    title="Billing diagnostics data is unavailable"
                    message="CRM could not refresh the Billing diagnostics summary right now. Retry after the integrations payload is available again."
                />
            </div>
        );
    }

    const activeSignals = cards.filter((card) => !['unknown', 'deferred'].includes(card.status));
    const attentionSignals = cards.filter((card) => ['configured_disabled', 'pending', 'partial', 'degraded', 'failed', 'error'].includes(card.status));
    const healthySignals = cards.filter((card) => ['connected', 'healthy', 'success'].includes(card.status));
    const hasKnownSignals = activeSignals.length > 0;

    if (!hasKnownSignals) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Diagnostics"
                    title="No diagnostics health signals have been published yet"
                    message="The Billing diagnostics shell loaded, but the current integrations payload does not expose any provider or wallet-system health signals for this session."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(300px,0.55fr)]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Diagnostics posture</p>
                        <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">Billing health signals available to operators</h4>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            This surface summarizes whether the billing runtime has enough telemetry to support
                            provider verification, wallet posture checks, and escalation from Settings without sending
                            operators back into the Payments drawer.
                        </p>
                    </div>
                    <div className="grid grid-cols-3 gap-3">
                        <DiagnosticsMetricCard label="Healthy" value={healthySignals.length} tone="emerald" />
                        <DiagnosticsMetricCard label="Attention" value={attentionSignals.length} tone="amber" />
                        <DiagnosticsMetricCard label="Observed" value={activeSignals.length} tone="slate" />
                    </div>
                </div>
            </section>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Priority watchlist</p>
                            <h5 className="mt-2 text-lg font-semibold text-slate-950">Signals that need intervention or confirmation</h5>
                        </div>
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {attentionSignals.length} flagged
                        </span>
                    </div>

                    <div className="mt-5 grid gap-4">
                        {(attentionSignals.length > 0 ? attentionSignals : cards).map((card) => (
                            <DiagnosticsServiceCard key={card.key} card={card} compact={false} />
                        ))}
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Service matrix</p>
                            <h5 className="mt-2 text-lg font-semibold text-slate-950">Connected, deferred, and background dependencies</h5>
                        </div>
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {cards.length} services
                        </span>
                    </div>

                    <div className="mt-5 space-y-3">
                        {cards.map((card) => (
                            <DiagnosticsServiceCard key={card.key} card={card} compact />
                        ))}
                    </div>
                </section>
            </div>
        </div>
    );
}

function DiagnosticsMetricCard({ label, value, tone = 'slate' }) {
    const tones = {
        emerald: 'border-emerald-200 bg-[linear-gradient(180deg,rgba(236,253,245,0.95)_0%,rgba(255,255,255,1)_100%)] text-emerald-950',
        amber: 'border-amber-200 bg-[linear-gradient(180deg,rgba(255,251,235,0.95)_0%,rgba(255,255,255,1)_100%)] text-amber-950',
        slate: 'border-slate-200 bg-[linear-gradient(180deg,rgba(248,250,252,0.95)_0%,rgba(255,255,255,1)_100%)] text-slate-950',
    };

    return (
        <div className={`rounded-2xl border px-4 py-4 ${tones[tone] || tones.slate}`}>
            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] opacity-65">{label}</p>
            <p className="mt-3 text-3xl font-semibold tracking-tight">{value}</p>
        </div>
    );
}

function DiagnosticsServiceCard({ card, compact = false }) {
    return (
        <section
            className={`rounded-2xl border p-4 ${statusTone(card.status)} ${
                compact ? 'shadow-none' : 'shadow-sm shadow-slate-950/[0.03]'
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] opacity-70">
                        {compact ? 'Dependency' : 'Service signal'}
                    </p>
                    <h6 className="mt-2 text-base font-semibold">{card.label}</h6>
                </div>
                <span className="rounded-full bg-white/80 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.08em]">
                    {card.status}
                </span>
            </div>
            <p className="mt-3 text-sm leading-6">{card.detail}</p>
        </section>
    );
}
