import React from 'react';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const SERVICE_META = {
    wallet_system: { label: 'Wallet System', type: 'Dependency' },
    kopokopo: { label: 'KopoKopo', type: 'Provider' },
    payment_service: { label: 'Payment Service', type: 'Dependency' },
    sendgrid: { label: 'SendGrid', type: 'Background' },
};

function normalizeServices(services = {}) {
    const orderedKeys = Array.from(new Set([...Object.keys(SERVICE_META), ...Object.keys(services || {})]));

    return orderedKeys.map((key) => {
        const source = services?.[key] || {};
        const meta = SERVICE_META[key] || { label: key.replace(/_/g, ' '), type: 'Dependency' };

        let detail = source.note || 'No diagnostic summary available';
        if (source.base_url) {
            detail = source.mode ? `${source.base_url} • Mode: ${source.mode}` : source.base_url;
        } else if (!source.base_url && source.mode) {
            detail = `Mode: ${source.mode}`;
        }

        return {
            key,
            label: meta.label,
            type: meta.type,
            status: source.status || 'unknown',
            detail,
        };
    });
}

function formatStatus(status) {
    return String(status || 'unknown').replace(/_/g, ' ');
}

function statusDescriptor(status) {
    if (['connected', 'healthy', 'success'].includes(status)) {
        return { tone: 'online', label: 'Connected' };
    }

    if (['configured_disabled', 'partial', 'degraded', 'pending', 'queued', 'running'].includes(status)) {
        return { tone: 'attention', label: formatStatus(status) };
    }

    if (['deferred', 'unknown'].includes(status)) {
        return { tone: 'neutral', label: formatStatus(status) };
    }

    return { tone: 'critical', label: formatStatus(status) };
}

export default function BillingDiagnosticsTab({ isLoading, isError, diagnosticsEnabled = false, services, error = null }) {
    const cards = normalizeServices(services);

    if (isLoading) {
        return (
            <div className="space-y-4 p-5">
                <div className="animate-pulse space-y-4">
                    <div className="h-28 rounded-xl border border-slate-200 bg-white" />
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                        <div className="h-64 rounded-xl border border-slate-200 bg-white" />
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

    const observedSignals = cards.filter((card) => !['unknown', 'deferred'].includes(card.status));
    const attentionSignals = cards.filter((card) => ['configured_disabled', 'pending', 'partial', 'degraded', 'failed', 'error'].includes(card.status));
    const healthySignals = cards.filter((card) => ['connected', 'healthy', 'success'].includes(card.status));
    const deferredSignals = cards.filter((card) => ['deferred', 'unknown'].includes(card.status));
    const matrixBuckets = [
        {
            key: 'attention',
            eyebrow: 'Needs attention',
            title: 'Priority services and providers',
            cards: attentionSignals,
            empty: 'No degraded or pending billing services are currently published.',
        },
        {
            key: 'connected',
            eyebrow: 'Connected',
            title: 'Healthy runtime dependencies',
            cards: healthySignals,
            empty: 'No fully connected services are currently published.',
        },
        {
            key: 'deferred',
            eyebrow: 'Deferred',
            title: 'Observed but not yet active',
            cards: deferredSignals,
            empty: 'No deferred or passive dependencies are currently published.',
        },
    ];

    if (observedSignals.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Diagnostics"
                    title="No diagnostics health signals have been published yet"
                    message="The Billing diagnostics shell loaded, but the current integrations payload does not expose provider or wallet-system health signals for this session."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4 p-5">
            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.45fr)_minmax(360px,420px)]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Diagnostics posture</p>
                        <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">Billing health signals available to operators</h4>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            This surface summarizes whether the billing runtime has enough telemetry to support provider
                            verification, wallet posture checks, and escalation from Settings without dropping back into
                            the Payments drawer.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <DiagnosticsMetricCard label="Healthy" value={healthySignals.length} status="online" />
                        <DiagnosticsMetricCard label="Attention" value={attentionSignals.length} status="attention" />
                        <DiagnosticsMetricCard label="Observed" value={observedSignals.length} status="neutral" />
                    </div>
                </div>
            </section>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)]">
                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Priority watchlist</p>
                            <h5 className="mt-2 text-lg font-semibold text-slate-950">Signals that need intervention or confirmation</h5>
                        </div>
                        <CountBadge value={attentionSignals.length} label="flagged" />
                    </div>

                    <div className="mt-5 grid gap-3">
                        {(attentionSignals.length > 0 ? attentionSignals : cards).map((card) => (
                            <DiagnosticsServiceRow key={card.key} card={card} emphasis />
                        ))}
                    </div>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Service matrix</p>
                            <h5 className="mt-2 text-lg font-semibold text-slate-950">Connected, deferred, and background dependencies</h5>
                        </div>
                        <CountBadge value={cards.length} label="services" />
                    </div>

                    <div className="mt-5 space-y-5">
                        {matrixBuckets.map((bucket) => (
                            <div key={bucket.key}>
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{bucket.eyebrow}</p>
                                        <h6 className="mt-1 text-sm font-semibold text-slate-900">{bucket.title}</h6>
                                    </div>
                                    <CountBadge value={bucket.cards.length} label="signals" />
                                </div>

                                {bucket.cards.length > 0 ? (
                                    <div className="space-y-3">
                                        {bucket.cards.map((card) => (
                                            <DiagnosticsServiceRow key={card.key} card={card} />
                                        ))}
                                    </div>
                                ) : (
                                    <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                        {bucket.empty}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </div>
    );
}

function DiagnosticsMetricCard({ label, value, status = 'neutral' }) {
    return (
        <div className="min-w-0 rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm shadow-slate-950/[0.02]">
            <div className="flex min-h-[104px] flex-col justify-between">
                <div className="flex items-start justify-between gap-3">
                    <p className="text-[8px] font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
                    <span className={`mt-0.5 h-2 w-2 shrink-0 rounded-full ${metricStatusDot(status)}`} />
                </div>
                <div className="pt-5">
                    <p className="tabular-nums text-[1.55rem] font-semibold leading-none tracking-[-0.04em] text-slate-950">{value}</p>
                </div>
            </div>
        </div>
    );
}

function metricStatusDot(status) {
    const tones = {
        online: 'bg-emerald-500',
        attention: 'bg-amber-500',
        critical: 'bg-rose-500',
        neutral: 'bg-slate-300',
    };

    return tones[status] || tones.neutral;
}

function DiagnosticsServiceRow({ card, emphasis = false }) {
    const descriptor = statusDescriptor(card.status);

    return (
        <section className={`rounded-lg border border-slate-200 bg-white p-4 ${emphasis ? 'shadow-sm shadow-slate-950/[0.02]' : ''}`}>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{card.type}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <h6 className="text-base font-semibold text-slate-950">{card.label}</h6>
                        <StatusPill status={descriptor.tone} label={descriptor.label} />
                    </div>
                    <p className="mt-2 break-words text-sm leading-6 text-slate-600">{card.detail}</p>
                </div>

                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 lg:min-w-[138px] lg:text-right">
                    <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">State</p>
                    <p className="mt-1 text-sm font-semibold text-slate-900">{descriptor.label}</p>
                </div>
            </div>
        </section>
    );
}

function StatusPill({ status, label, compact = false }) {
    const resolvedLabel =
        label ||
        (status === 'online'
            ? 'Healthy'
            : status === 'attention'
              ? 'Attention'
              : status === 'critical'
                ? 'Error'
                : 'Observed');

    const tones = {
        online: { dot: 'bg-emerald-500', text: 'text-emerald-700', border: 'border-emerald-200' },
        attention: { dot: 'bg-amber-500', text: 'text-amber-700', border: 'border-amber-200' },
        critical: { dot: 'bg-rose-500', text: 'text-rose-700', border: 'border-rose-200' },
        neutral: { dot: 'bg-slate-300', text: 'text-slate-500', border: 'border-slate-200' },
    };

    const tone = tones[status] || tones.neutral;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border bg-white ${compact ? 'px-2.5' : 'px-3'} py-1 text-[10px] font-semibold uppercase tracking-[0.08em] ${tone.border} ${tone.text}`}>
            <span className={`h-2 w-2 rounded-full ${tone.dot}`} />
            {resolvedLabel}
        </span>
    );
}

function CountBadge({ value, label }) {
    return (
        <span className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
            <span className="text-slate-900">{value}</span>
            {label}
        </span>
    );
}
