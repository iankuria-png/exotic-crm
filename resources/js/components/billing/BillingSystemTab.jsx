import React from 'react';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const environments = ['sandbox', 'production'];

export default function BillingSystemTab({
    system,
    source = {},
    isLoading = false,
    isError = false,
    error = null,
}) {
    if (isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-24 rounded-xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-48 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-48 rounded-xl border border-slate-200 bg-white" />
                </div>
            </div>
        );
    }

    if (isError) {
        if (isForbiddenQueryError(error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Billing System"
                        title="Billing system posture is restricted"
                        message="This role cannot inspect billing domains, branding, and system-level wallet funding posture in the new Billing workspace."
                    />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Billing System"
                    title="Billing system metadata unavailable"
                    message="CRM could not load the billing system configuration right now. Retry later."
                />
            </div>
        );
    }

    const mode = system?.mode || 'disabled';
    const domains = system?.billing_domains || {};
    const branding = system?.billing_branding || {};
    const timing = system?.timing || {};
    const smtp = system?.smtp || {};
    const liveReadEnabled = Boolean(source?.live_read_enabled);
    const hasConfiguredData = environments.some((environment) => {
        return Boolean(domains?.[environment] || branding?.[environment]?.business_name || branding?.[environment]?.description);
    });

    const summaryCards = [
        { label: 'Source of truth', value: source?.source_of_truth || 'wallet_system_config' },
        { label: 'Default currency', value: system?.default_currency || 'KES' },
        { label: 'Single wallet cap', value: system?.max_single_topup_default || 'Not configured' },
        { label: 'Wallet balance cap', value: system?.max_wallet_balance_default || 'Not configured' },
    ];

    const timingRows = [
        { label: 'Redirect delay', value: timing?.redirect_delay_seconds ?? 'Not configured', suffix: 's' },
        { label: 'Wallet refresh rate limit', value: timing?.wallet_refresh_rate_limit_seconds ?? 'Not configured', suffix: 's' },
        { label: 'Wallet refresh timeout', value: timing?.wallet_refresh_timeout_seconds ?? 'Not configured', suffix: 's' },
        { label: 'Funding poll interval', value: timing?.topup_poll_interval_seconds ?? 'Not configured', suffix: 's' },
    ];

    const deliveryRows = [
        { label: 'SMTP enabled', value: smtp?.enabled ? 'Enabled' : 'Disabled' },
        { label: 'SMTP host', value: smtp?.host || 'Not configured' },
        { label: 'SMTP from', value: smtp?.from_address || 'Not configured' },
        { label: 'Password state', value: smtp?.password_configured ? 'Configured' : 'Not configured' },
    ];

    return (
        <div className="space-y-5 p-5">
            {!liveReadEnabled ? (
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Billing System"
                    title="Runtime still reads the legacy billing-system contract"
                    message="This operational view reflects the live system settings that still govern wallet limits, billing domains, branding, and SMTP posture while the registry model takes over provider execution."
                />
            ) : null}

            {!hasConfiguredData ? (
                <BillingStateNotice
                    state="empty"
                    eyebrow="Billing System"
                    title="No billing system metadata is configured yet"
                    message="Billing domains and branding are empty for both sandbox and production in the current wallet system payload."
                />
            ) : null}

            <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,420px)] xl:items-end">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Billing system posture</p>
                        <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                            Global billing controls still shaping runtime behavior
                        </h4>
                        <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                            Use this surface to confirm which global wallet limits, domains, timing controls, and
                            outbound delivery settings are still active while registry-backed profiles take over
                            provider and market execution.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:ml-auto xl:w-full">
                        {summaryCards.map((card) => (
                            <MetricCell key={card.label} label={card.label} value={card.value} />
                        ))}
                    </div>
                </div>
                <div className="mt-5 flex flex-wrap items-center gap-3">
                    <StatusTag label={mode === 'disabled' ? 'System disabled' : formatMode(mode)} tone={mode === 'disabled' ? 'attention' : 'online'} />
                    <StatusTag
                        label={liveReadEnabled ? 'Registry live read enabled' : 'Legacy read path active'}
                        tone={liveReadEnabled ? 'online' : 'neutral'}
                    />
                </div>
            </section>

            <div className="grid gap-4 xl:grid-cols-2">
                {environments.map((environment) => (
                    <section key={environment} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{environment}</p>
                                <h5 className="mt-2 text-lg font-semibold text-slate-950">
                                    {environment === 'sandbox' ? 'Sandbox billing presentation' : 'Production billing presentation'}
                                </h5>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    {environment === 'sandbox'
                                        ? 'Preview-facing domain and brand copy used for sandbox journeys and QA confirmation.'
                                        : 'Live-facing domain and operator-visible brand copy used when production billing is active.'}
                                </p>
                            </div>
                            <StatusTag
                                label={domains?.[environment] ? 'Configured' : 'Incomplete'}
                                tone={domains?.[environment] ? 'online' : 'attention'}
                            />
                        </div>
                        <dl className="mt-5 grid gap-3">
                            <DataStrip label="Billing domain" value={domains?.[environment] || 'Not configured'} breakAll />
                            <DataStrip label="Business name" value={branding?.[environment]?.business_name || 'Not configured'} />
                            <DataStrip label="Description" value={branding?.[environment]?.description || 'Not configured'} />
                        </dl>
                    </section>
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Runtime timing</p>
                        <h5 className="mt-2 text-lg font-semibold text-slate-950">Refresh and redirect controls</h5>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            These limits shape wallet polling, redirects, and refresh cadence across billing surfaces.
                        </p>
                    </div>
                    <dl className="mt-5 space-y-3">
                        {timingRows.map((row) => (
                            <KeyValueRow
                                key={row.label}
                                label={row.label}
                                value={`${row.value}${typeof row.value === 'number' ? row.suffix || '' : row.value === 'Not configured' ? '' : row.suffix || ''}`}
                            />
                        ))}
                    </dl>
                </section>

                <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Delivery posture</p>
                        <h5 className="mt-2 text-lg font-semibold text-slate-950">SMTP and outbound billing email</h5>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            Shows whether billing email delivery can support receipts, notifications, and operator follow-up.
                        </p>
                    </div>
                    <dl className="mt-5 space-y-3">
                        {deliveryRows.map((row) => (
                            <KeyValueRow key={row.label} label={row.label} value={row.value} />
                        ))}
                    </dl>
                </section>
            </div>

            <section className="rounded-lg border border-slate-200 bg-slate-50 px-5 py-5">
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_240px] xl:items-start">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Transition note</p>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            Billing System is still the operational source of truth for global posture, while provider
                            profiles and market routing now live in the Billing workspace. That split is intentional so
                            runtime cutover stays controlled, reviewable, and reversible.
                        </p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white px-4 py-4">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Transition posture</p>
                        <p className="mt-2 text-base font-semibold text-slate-950">{liveReadEnabled ? 'Registry live-read enabled' : 'Legacy live-read active'}</p>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            Global defaults stay here until registry-backed system settings formally take ownership.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    );
}

function formatMode(mode) {
    return String(mode || 'disabled').replace(/_/g, ' ');
}

function MetricCell({ label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
            <p className="text-[8px] font-semibold uppercase tracking-[0.16em] text-slate-400">{label}</p>
            <p className="mt-2 break-words text-sm font-semibold leading-6 text-slate-950">{value}</p>
        </div>
    );
}

function StatusTag({ label, tone = 'neutral' }) {
    const tones = {
        online: { dot: 'bg-emerald-500', border: 'border-emerald-200', text: 'text-emerald-700' },
        attention: { dot: 'bg-amber-500', border: 'border-amber-200', text: 'text-amber-700' },
        neutral: { dot: 'bg-slate-300', border: 'border-slate-200', text: 'text-slate-600' },
    };

    const resolved = tones[tone] || tones.neutral;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border bg-white px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.08em] ${resolved.border} ${resolved.text}`}>
            <span className={`h-2 w-2 rounded-full ${resolved.dot}`} />
            {label}
        </span>
    );
}

function DataStrip({ label, value, breakAll = false }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
            <dt className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className={`mt-2 text-sm font-medium text-slate-900 ${breakAll ? 'break-all' : 'break-words'}`}>{value}</dd>
        </div>
    );
}

function KeyValueRow({ label, value }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
            <dt className="text-sm font-medium text-slate-700">{label}</dt>
            <dd className="text-sm font-semibold text-slate-950">{value}</dd>
        </div>
    );
}
