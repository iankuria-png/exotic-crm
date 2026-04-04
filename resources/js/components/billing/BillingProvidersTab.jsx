import React from 'react';
import BillingStateNotice from './BillingStateNotice';

function providerCardTone(enabled) {
    return enabled
        ? 'border-emerald-200 bg-emerald-50'
        : 'border-slate-200 bg-slate-50';
}

export default function BillingProvidersTab({ providerFamilies, walletProviderKeys = [], registryEnabled = false }) {
    const entries = Object.entries(providerFamilies || {});

    return (
        <div className="space-y-4 p-5">
            {!registryEnabled ? (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Providers"
                    title="Provider registry is still locked"
                    message="The Billing shell can list legacy provider identifiers, but the new provider-family registry stays read-only until the registry rollout flag is enabled."
                />
            ) : null}

            {registryEnabled && entries.length === 0 && walletProviderKeys.length === 0 ? (
                <BillingStateNotice
                    state="empty"
                    eyebrow="Providers"
                    title="No provider families are configured"
                    message="No provider-family flags or legacy wallet provider identifiers were returned for this session."
                />
            ) : null}

            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">Provider Families</h4>
                <p className="mt-2 text-sm text-slate-600">
                    These flags represent the provider families planned for the decoupled billing registry. A disabled
                    family here means the shell is aware of it, but the runtime rollout is still gated.
                </p>
                <div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {entries.map(([key, meta]) => {
                        const enabled = Boolean(meta?.enabled);
                        return (
                            <section key={key} className={`rounded-lg border p-4 ${providerCardTone(enabled)}`}>
                                <div className="flex items-center justify-between gap-3">
                                    <p className="text-sm font-semibold capitalize text-slate-900">{key}</p>
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] ${enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>
                                        {enabled ? 'enabled' : 'disabled'}
                                    </span>
                                </div>
                            </section>
                        );
                    })}
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white p-4">
                <h4 className="text-sm font-semibold text-slate-900">Current Wallet Provider Keys</h4>
                <p className="mt-2 text-sm text-slate-600">
                    These are the legacy provider identifiers currently exposed through the wallet settings payload and
                    still power the existing runtime until the registry cutover.
                </p>
                {walletProviderKeys.length > 0 ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {walletProviderKeys.map((providerKey) => (
                            <span
                                key={providerKey}
                                className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.08em] text-slate-700"
                            >
                                {providerKey}
                            </span>
                        ))}
                    </div>
                ) : (
                    <p className="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-500">No legacy wallet provider keys were returned.</p>
                )}
            </section>
        </div>
    );
}
