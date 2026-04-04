import React from 'react';

const environments = ['sandbox', 'production'];

export default function BillingSystemTab({ walletSystem }) {
    const mode = walletSystem?.mode || 'disabled';
    const domains = walletSystem?.billing_domains || {};
    const branding = walletSystem?.billing_branding || {};

    return (
        <div className="space-y-4 p-5">
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
