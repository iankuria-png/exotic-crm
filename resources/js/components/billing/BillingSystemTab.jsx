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

    return (
        <div className="space-y-4 p-5">
            {!liveReadEnabled ? (
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Billing System"
                    title="Live reads are still pinned to the legacy wallet settings path"
                    message="This compatibility view reflects the current wallet system source of truth while the new Billing System settings model remains behind rollout flags."
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

            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h4 className="text-sm font-semibold text-slate-900">Billing System Compatibility View</h4>
                        <p className="mt-2 text-sm text-slate-600">
                            During Phase 0B and Phase 1, the live billing system configuration still comes from the
                            existing wallet system settings. This tab exposes that source of truth without reusing the
                            Integrations UI tree.
                        </p>
                    </div>
                    <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] text-slate-700">
                        {mode}
                    </span>
                </div>
                <dl className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-lg bg-slate-50 px-3 py-2">
                        <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Default Currency</dt>
                        <dd className="mt-1 text-sm font-semibold text-slate-900">{system?.default_currency || 'KES'}</dd>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-3 py-2">
                        <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Max Single Wallet Funding</dt>
                        <dd className="mt-1 text-sm font-semibold text-slate-900">{system?.max_single_topup_default || 'Not configured'}</dd>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-3 py-2">
                        <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Max Wallet Balance</dt>
                        <dd className="mt-1 text-sm font-semibold text-slate-900">{system?.max_wallet_balance_default || 'Not configured'}</dd>
                    </div>
                    <div className="rounded-lg bg-slate-50 px-3 py-2">
                        <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Source of Truth</dt>
                        <dd className="mt-1 text-sm font-semibold text-slate-900">{source?.source_of_truth || 'wallet_system_config'}</dd>
                    </div>
                </dl>
            </section>

            <div className="grid gap-4 xl:grid-cols-2">
                {environments.map((environment) => (
                    <section key={environment} className="rounded-xl border border-slate-200 bg-white p-4">
                        <h5 className="text-sm font-semibold uppercase tracking-[0.08em] text-slate-700">{environment}</h5>
                        <dl className="mt-4 space-y-3 text-sm text-slate-600">
                            <div>
                                <dt className="font-medium text-slate-900">Billing domain</dt>
                                <dd className="mt-1 break-all rounded-lg bg-slate-50 px-3 py-2">
                                    {domains?.[environment] || 'Not configured'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-slate-900">Business name</dt>
                                <dd className="mt-1 rounded-lg bg-slate-50 px-3 py-2">
                                    {branding?.[environment]?.business_name || 'Not configured'}
                                </dd>
                            </div>
                            <div>
                                <dt className="font-medium text-slate-900">Description</dt>
                                <dd className="mt-1 rounded-lg bg-slate-50 px-3 py-2">
                                    {branding?.[environment]?.description || 'Not configured'}
                                </dd>
                            </div>
                        </dl>
                    </section>
                ))}
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">Timing Controls</h5>
                    <dl className="mt-4 space-y-3 text-sm text-slate-600">
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">Redirect delay</dt>
                            <dd>{timing?.redirect_delay_seconds ?? 'Not configured'}s</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">Wallet refresh rate limit</dt>
                            <dd>{timing?.wallet_refresh_rate_limit_seconds ?? 'Not configured'}s</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">Wallet refresh timeout</dt>
                            <dd>{timing?.wallet_refresh_timeout_seconds ?? 'Not configured'}s</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">Wallet funding poll interval</dt>
                            <dd>{timing?.topup_poll_interval_seconds ?? 'Not configured'}s</dd>
                        </div>
                    </dl>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-4">
                    <h5 className="text-sm font-semibold text-slate-900">SMTP & Billing Posture</h5>
                    <dl className="mt-4 space-y-3 text-sm text-slate-600">
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">SMTP enabled</dt>
                            <dd>{smtp?.enabled ? 'Enabled' : 'Disabled'}</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">SMTP host</dt>
                            <dd>{smtp?.host || 'Not configured'}</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">SMTP from</dt>
                            <dd>{smtp?.from_address || 'Not configured'}</dd>
                        </div>
                        <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
                            <dt className="font-medium text-slate-900">SMTP password</dt>
                            <dd>{smtp?.password_configured ? 'Configured' : 'Not configured'}</dd>
                        </div>
                    </dl>
                </section>
            </div>
        </div>
    );
}
