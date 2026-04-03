import React from 'react';

import PaymentLinkProviderCard from './PaymentLinkProviderCard';

export default function PaymentLinkRoutingPanel({
    addPaymentLinkProvider,
    enabledPaymentLinkProviders,
    onRefresh,
    paymentLinkForm,
    paymentLinkModeOptions,
    paymentLinkProviderOptionLabel,
    paymentLinkProxyWalletProviders,
    paymentLinkReadOnly,
    paymentLinkReadinessClasses,
    paymentLinkReadinessState,
    platformRows,
    removePaymentLinkProvider,
    savePaymentLinkProviders,
    savePaymentLinkProvidersMutation,
    selectedPlatform,
    selectedPlatformId,
    setPaymentLinkForm,
    setSelectedPlatformId,
    updatePaymentLinkProvider,
    walletEnvironmentOptions,
    walletProviderKeys,
    walletProviderLabel,
    walletSystemConfig,
}) {
    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Payment Link Provider Routing</h3>
                    <p className="crm-panel-subtitle">Configure provider-level payment URLs used by the Payments queue "Send link" action.</p>
                </div>
                <button
                    type="button"
                    onClick={onRefresh}
                    className="crm-btn-secondary px-3 py-2"
                >
                    Refresh
                </button>
            </header>

            <div className="space-y-4 p-4">
                <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                    <select
                        value={selectedPlatformId || ''}
                        onChange={(event) => setSelectedPlatformId(Number(event.target.value) || null)}
                        className="crm-select max-w-xl"
                    >
                        {platformRows.map((platform) => (
                            <option key={platform.platform_id} value={platform.platform_id}>
                                {platform.platform_name} ({platform.country || '—'})
                            </option>
                        ))}
                    </select>
                    <p className="mt-2 text-xs text-slate-500">Active provider is used by default when operators send payment links from the Payments workspace. Only enabled providers can be selected as active.</p>
                    {paymentLinkReadOnly ? (
                        <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                            Read-only access: only admin and sub-admin roles can update payment link provider settings.
                        </p>
                    ) : null}
                </section>

                {!selectedPlatform ? (
                    <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-sm text-slate-500">
                        Select a market to edit payment link provider routing.
                    </p>
                ) : (
                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Providers</h4>
                        <p className="mt-1 text-xs text-slate-500">Add one or more providers, choose between direct URLs and CRM proxy checkout, and keep an audit reason for every change.</p>

                        <fieldset disabled={paymentLinkReadOnly || savePaymentLinkProvidersMutation.isPending} className={paymentLinkReadOnly ? 'opacity-70' : ''}>
                            <div className="mt-3 space-y-3">
                                {paymentLinkForm.providers.map((provider, index) => (
                                    <PaymentLinkProviderCard
                                        key={`provider-${index}`}
                                        index={index}
                                        isReadOnly={paymentLinkReadOnly}
                                        paymentLinkModeOptions={paymentLinkModeOptions}
                                        paymentLinkProxyWalletProviders={paymentLinkProxyWalletProviders}
                                        paymentLinkReadinessClasses={paymentLinkReadinessClasses}
                                        paymentLinkReadinessState={paymentLinkReadinessState}
                                        provider={provider}
                                        providerCount={paymentLinkForm.providers.length}
                                        removePaymentLinkProvider={removePaymentLinkProvider}
                                        selectedPlatform={selectedPlatform}
                                        updatePaymentLinkProvider={updatePaymentLinkProvider}
                                        walletEnvironmentOptions={walletEnvironmentOptions}
                                        walletProviderKeys={walletProviderKeys}
                                        walletProviderLabel={walletProviderLabel}
                                        walletSystemConfig={walletSystemConfig}
                                    />
                                ))}
                            </div>

                            <div className="mt-3 grid gap-2 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                    <select
                                        value={paymentLinkForm.active_provider}
                                        onChange={(event) => setPaymentLinkForm((current) => ({ ...current, active_provider: event.target.value }))}
                                        className="crm-select"
                                        disabled={enabledPaymentLinkProviders.length === 0}
                                    >
                                        {enabledPaymentLinkProviders.length === 0 ? (
                                            <option value="">No enabled providers</option>
                                        ) : enabledPaymentLinkProviders.map((provider) => (
                                            <option key={provider.key} value={provider.key}>
                                                {paymentLinkProviderOptionLabel(provider)}
                                            </option>
                                        ))}
                                    </select>
                                    {enabledPaymentLinkProviders.length === 0 ? (
                                        <p className="mt-1 text-xs text-amber-700">Enable at least one provider to make payment-link routing available.</p>
                                    ) : null}
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Audit reason</label>
                                    <input
                                        value={paymentLinkForm.reason}
                                        onChange={(event) => setPaymentLinkForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for payment link config update"
                                    />
                                </div>
                            </div>
                        </fieldset>

                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <button
                                type="button"
                                onClick={addPaymentLinkProvider}
                                disabled={paymentLinkReadOnly}
                                className="crm-btn-secondary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Add provider
                            </button>
                            <button
                                type="button"
                                onClick={savePaymentLinkProviders}
                                disabled={paymentLinkReadOnly || savePaymentLinkProvidersMutation.isPending || !paymentLinkForm.reason.trim()}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {savePaymentLinkProvidersMutation.isPending ? 'Saving...' : 'Save payment link providers'}
                            </button>
                        </div>
                    </section>
                )}
            </div>
        </section>
    );
}
