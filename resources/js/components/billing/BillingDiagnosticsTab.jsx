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

    const hasKnownSignals = cards.some((card) => !['unknown', 'deferred'].includes(card.status));

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
            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">Billing diagnostics posture</h4>
                <p className="mt-2 text-sm text-slate-600">
                    This surface summarizes whether the billing runtime has enough health signals to support provider
                    verification, wallet posture checks, and operator escalation from the Settings workspace without
                    dropping back into the Payments drawer.
                </p>
            </section>

            <div className="grid gap-4 md:grid-cols-2">
                {cards.map((card) => (
                    <section key={card.key} className={`rounded-xl border p-4 ${statusTone(card.status)}`}>
                        <div className="flex items-center justify-between gap-3">
                            <h5 className="text-sm font-semibold">{card.label}</h5>
                            <span className="rounded-full bg-white/70 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em]">
                                {card.status}
                            </span>
                        </div>
                        <p className="mt-3 text-sm">{card.detail}</p>
                    </section>
                ))}
            </div>
        </div>
    );
}
