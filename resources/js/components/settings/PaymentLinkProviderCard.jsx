import React from 'react';

export default function PaymentLinkProviderCard({
    index,
    paymentLinkReadinessClasses,
    paymentLinkReadinessState,
    paymentLinkModeOptions,
    paymentLinkProxyWalletProviders,
    provider,
    removePaymentLinkProvider,
    selectedPlatform,
    updatePaymentLinkProvider,
    walletEnvironmentOptions,
    walletProviderKeys,
    walletProviderLabel,
    walletSystemConfig,
    isReadOnly,
    providerCount,
}) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
            <div className="grid gap-2 md:grid-cols-2">
                <input
                    value={provider.key}
                    onChange={(event) => updatePaymentLinkProvider(index, 'key', event.target.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''))}
                    className="crm-input"
                    placeholder="Provider key (e.g. pesapal)"
                />
                <input
                    value={provider.label}
                    onChange={(event) => updatePaymentLinkProvider(index, 'label', event.target.value)}
                    className="crm-input"
                    placeholder="Provider label"
                />
                <select
                    value={provider.mode || 'static_url'}
                    onChange={(event) => updatePaymentLinkProvider(index, 'mode', event.target.value)}
                    className="crm-select"
                >
                    {paymentLinkModeOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={Boolean(provider.enabled)}
                        onChange={(event) => updatePaymentLinkProvider(index, 'enabled', event.target.checked)}
                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                    />
                    Enabled for operator use
                </label>
                {provider.mode === 'proxy_hosted_checkout' ? (
                    <>
                        <select
                            value={provider.wallet_provider_key || 'paystack'}
                            onChange={(event) => updatePaymentLinkProvider(index, 'wallet_provider_key', event.target.value)}
                            className="crm-select"
                        >
                            {walletProviderKeys
                                .filter((providerKey) => paymentLinkProxyWalletProviders.includes(providerKey))
                                .map((providerKey) => (
                                    <option key={providerKey} value={providerKey}>
                                        {walletProviderLabel(providerKey)}
                                    </option>
                                ))}
                        </select>
                        <select
                            value={provider.environment || 'sandbox'}
                            onChange={(event) => updatePaymentLinkProvider(index, 'environment', event.target.value)}
                            className="crm-select"
                        >
                            {walletEnvironmentOptions.map((environment) => (
                                <option key={environment} value={environment}>
                                    {environment}
                                </option>
                            ))}
                        </select>
                        <label className="md:col-span-2 flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                checked={Boolean(provider.self_checkout_fx_enabled)}
                                onChange={(event) => updatePaymentLinkProvider(index, 'self_checkout_fx_enabled', event.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            />
                            Enable self-checkout FX override test
                        </label>
                        {provider.self_checkout_fx_enabled ? (
                            <>
                                <input
                                    value={provider.self_checkout_fx_currency || 'KES'}
                                    onChange={(event) => updatePaymentLinkProvider(index, 'self_checkout_fx_currency', event.target.value.toUpperCase())}
                                    className="crm-input"
                                    placeholder="Charge currency (e.g. KES)"
                                    maxLength={3}
                                />
                                <input
                                    value={provider.self_checkout_fx_rate || ''}
                                    onChange={(event) => updatePaymentLinkProvider(index, 'self_checkout_fx_rate', event.target.value)}
                                    className="crm-input"
                                    placeholder={`Charge units per 1 ${selectedPlatform?.currency || selectedPlatform?.currency_code || 'local'} (e.g. 11.25)`}
                                />
                                <p className="md:col-span-2 text-xs text-amber-700">
                                    Temporary self-checkout test only. Public pricing remains in the market currency, but CRM will charge the selected checkout currency using this fixed rate.
                                </p>
                            </>
                        ) : null}
                        {(() => {
                            const readiness = paymentLinkReadinessState(provider, selectedPlatform, walletSystemConfig);

                            return readiness ? (
                                <div className={`md:col-span-2 rounded-md border px-3 py-2 text-xs ${paymentLinkReadinessClasses(readiness.tone)}`}>
                                    <p className="font-semibold">{readiness.label}</p>
                                    <p className="mt-1">{readiness.detail}</p>
                                </div>
                            ) : null;
                        })()}
                        <p className="md:col-span-2 text-xs text-slate-500">
                            CRM proxy checkout will generate a CRM-owned link and hand off to the configured wallet provider in the selected environment.
                        </p>
                    </>
                ) : (
                    <>
                        <input
                            value={provider.url}
                            onChange={(event) => updatePaymentLinkProvider(index, 'url', event.target.value)}
                            className="crm-input md:col-span-2"
                            placeholder="Direct URL (optional)"
                        />
                        <input
                            value={provider.base_url}
                            onChange={(event) => updatePaymentLinkProvider(index, 'base_url', event.target.value)}
                            className="crm-input"
                            placeholder="Base URL"
                        />
                        <input
                            value={provider.path}
                            onChange={(event) => updatePaymentLinkProvider(index, 'path', event.target.value)}
                            className="crm-input"
                            placeholder="Path (e.g. /pay)"
                        />
                        <p className="md:col-span-2 text-xs text-slate-500">
                            Static URL providers send operators directly to the configured market payment page.
                        </p>
                    </>
                )}
            </div>
            <div className="mt-2 flex justify-end">
                <button
                    type="button"
                    onClick={() => removePaymentLinkProvider(index)}
                    disabled={providerCount <= 1 || isReadOnly}
                    className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Remove
                </button>
            </div>
        </div>
    );
}
