import React from 'react';
import BillingStateNotice from './BillingStateNotice';

const environments = ['sandbox', 'production'];

export default function BillingSystemTab({ walletSystem, liveReadEnabled = false }) {
    const mode = walletSystem?.mode || 'disabled';
    const domains = walletSystem?.billing_domains || {};
    const branding = walletSystem?.billing_branding || {};
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
        </div>
    );
}
