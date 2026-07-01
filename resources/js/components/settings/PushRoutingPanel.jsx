import React from 'react';

export default function PushRoutingPanel({
    canManagePushProviders,
    latestPushTestResult,
    platformRows,
    pushFallbackInvalid,
    pushFallbackOptions,
    pushPlatformId,
    pushProviderForm,
    pushProviderLabel,
    pushProviderOptions,
    pushTestForm,
    savePushProviderConfig,
    savePushProviderMutation,
    selectedPushConfig,
    selectedPushPlatform,
    selectedPushProvider,
    selectedPushReady,
    setPushPlatformId,
    setPushTestConfirmOpen,
    setPushTestForm,
    statusChip,
    testPushProviderMutation,
    updatePushPlatformField,
    updatePushProviderCredentialField,
    updatePushProviderField,
}) {
    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Push Provider Routing</h3>
                    <p className="crm-panel-subtitle">Configure provider credentials per market and validate notification delivery using a real push test.</p>
                </div>
            </header>

            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-7">
                    {!canManagePushProviders ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Read-only access: only admin and sub-admin roles can update push routing settings.
                        </p>
                    ) : null}

                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Routing Controls</h4>
                        <p className="mt-1 text-xs text-slate-500">Define the global default provider and per-market provider/fallback behavior.</p>

                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(pushProviderForm.enabled)}
                                    onChange={(event) => updatePushProviderField('enabled', event.target.checked)}
                                    disabled={!canManagePushProviders}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Enable push dispatch for campaigns
                            </label>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Default provider</label>
                                <select
                                    value={pushProviderForm.default_provider}
                                    onChange={(event) => updatePushProviderField('default_provider', event.target.value)}
                                    disabled={!canManagePushProviders}
                                    className="crm-select w-full"
                                >
                                    {pushProviderOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    value={pushPlatformId || ''}
                                    onChange={(event) => setPushPlatformId(event.target.value)}
                                    className="crm-select w-full"
                                >
                                    {(platformRows || []).map((platform) => (
                                        <option key={platform.platform_id} value={String(platform.platform_id)}>
                                            {platform.platform_name} ({platform.country || '—'})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                <select
                                    value={selectedPushConfig?.active_provider || pushProviderForm.default_provider}
                                    onChange={(event) => updatePushPlatformField(pushPlatformId, 'active_provider', event.target.value)}
                                    disabled={!canManagePushProviders || !pushPlatformId}
                                    className="crm-select w-full"
                                >
                                    {pushProviderOptions.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Fallback provider</label>
                                <select
                                    value={selectedPushConfig?.fallback_provider || 'none'}
                                    onChange={(event) => updatePushPlatformField(pushPlatformId, 'fallback_provider', event.target.value)}
                                    disabled={!canManagePushProviders || !pushPlatformId}
                                    className="crm-select w-full"
                                >
                                    {pushFallbackOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                            disabled={option.value !== 'none' && option.value === (selectedPushConfig?.active_provider || '')}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <label className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                <textarea
                                    rows={2}
                                    value={pushProviderForm.reason}
                                    onChange={(event) => updatePushProviderField('reason', event.target.value)}
                                    disabled={!canManagePushProviders}
                                    className="crm-input"
                                    placeholder="Reason for updating push routing"
                                />
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">WebPushr Credentials</h4>
                        <p className="mt-1 text-xs text-slate-500">Required when WebPushr is selected as active or fallback provider.</p>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                type="password"
                                value={selectedPushConfig?.webpushr?.api_key || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'webpushr', 'api_key', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="API key (leave blank to keep current)"
                            />
                            <input
                                type="password"
                                value={selectedPushConfig?.webpushr?.auth_token || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'webpushr', 'auth_token', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="Auth token (leave blank to keep current)"
                            />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Stored keys:
                            {' '}
                            {selectedPushConfig?.webpushr?.api_key_configured ? 'API key configured' : 'API key missing'}
                            {' • '}
                            {selectedPushConfig?.webpushr?.auth_token_configured ? 'Auth token configured' : 'Auth token missing'}
                        </p>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">WonderPush Credentials</h4>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                type="password"
                                value={selectedPushConfig?.wonderpush?.access_token || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'wonderpush', 'access_token', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="Access token (leave blank to keep current)"
                            />
                            <input
                                value={selectedPushConfig?.wonderpush?.project_id || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'wonderpush', 'project_id', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="Project ID"
                            />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Stored token:
                            {' '}
                            {selectedPushConfig?.wonderpush?.access_token_configured ? 'configured' : 'missing'}
                        </p>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">iZooto Credentials</h4>
                        <div className="mt-3">
                            <input
                                type="password"
                                value={selectedPushConfig?.izooto?.api_token || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'izooto', 'api_token', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="API token (leave blank to keep current)"
                            />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Stored token:
                            {' '}
                            {selectedPushConfig?.izooto?.api_token_configured ? 'configured' : 'missing'}
                        </p>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Exotic Push Engine Credentials</h4>
                        <div className="mt-3 grid gap-3 md:grid-cols-3">
                            <input
                                value={selectedPushConfig?.exoticpush?.site_id || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'exoticpush', 'site_id', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="Site ID"
                            />
                            <input
                                type="password"
                                value={selectedPushConfig?.exoticpush?.api_key || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'exoticpush', 'api_key', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="API key (leave blank to keep current)"
                            />
                            <input
                                type="password"
                                value={selectedPushConfig?.exoticpush?.auth_token || ''}
                                onChange={(event) => updatePushProviderCredentialField(pushPlatformId, 'exoticpush', 'auth_token', event.target.value)}
                                disabled={!canManagePushProviders || !pushPlatformId}
                                className="crm-input"
                                placeholder="Auth token (leave blank to keep current)"
                            />
                        </div>
                        <p className="mt-2 text-xs text-slate-500">
                            Site:
                            {' '}
                            {selectedPushConfig?.exoticpush?.site_id ? selectedPushConfig.exoticpush.site_id : 'missing'}
                            {' • '}
                            {selectedPushConfig?.exoticpush?.api_key_configured ? 'API key configured' : 'API key missing'}
                            {' • '}
                            {selectedPushConfig?.exoticpush?.auth_token_configured ? 'Auth token configured' : 'Auth token missing'}
                        </p>
                    </section>

                    {pushFallbackInvalid ? (
                        <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                            Fallback provider must be different from the active provider.
                        </p>
                    ) : null}

                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={savePushProviderConfig}
                            disabled={!canManagePushProviders || savePushProviderMutation.isPending || !pushProviderForm.reason.trim() || pushFallbackInvalid}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {savePushProviderMutation.isPending ? 'Saving...' : 'Save push settings'}
                        </button>
                    </div>
                </div>

                <div className="space-y-4 xl:col-span-5">
                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Test Notification</h4>
                        <p className="mt-1 text-xs text-slate-500">Sends a real push notification to all subscribers for the selected market.</p>
                        <div className="mt-3 space-y-3">
                            <input
                                value={pushTestForm.title}
                                onChange={(event) => setPushTestForm((current) => ({ ...current, title: event.target.value }))}
                                className="crm-input"
                                placeholder="Notification title"
                            />
                            <textarea
                                rows={3}
                                value={pushTestForm.message}
                                onChange={(event) => setPushTestForm((current) => ({ ...current, message: event.target.value }))}
                                className="crm-input"
                                placeholder="Notification message"
                            />
                            <input
                                value={pushTestForm.target_url}
                                onChange={(event) => setPushTestForm((current) => ({ ...current, target_url: event.target.value }))}
                                className="crm-input"
                                placeholder="Target URL"
                            />
                            <input
                                value={pushTestForm.icon_url}
                                onChange={(event) => setPushTestForm((current) => ({ ...current, icon_url: event.target.value }))}
                                className="crm-input"
                                placeholder="Icon URL (optional)"
                            />
                            <input
                                value={pushTestForm.reason}
                                onChange={(event) => setPushTestForm((current) => ({ ...current, reason: event.target.value }))}
                                className="crm-input"
                                placeholder="Reason for push test"
                            />
                        </div>

                        <div className="mt-3 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setPushTestConfirmOpen(true)}
                                disabled={
                                    testPushProviderMutation.isPending
                                    || !canManagePushProviders
                                    || !pushProviderForm.enabled
                                    || !pushPlatformId
                                    || !selectedPushReady
                                    || !pushTestForm.title.trim()
                                    || !pushTestForm.message.trim()
                                    || !pushTestForm.target_url.trim()
                                    || !pushTestForm.reason.trim()
                                }
                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {testPushProviderMutation.isPending ? 'Sending...' : 'Send test push'}
                            </button>
                        </div>

                        {!pushProviderForm.enabled ? (
                            <p className="mt-2 text-xs text-amber-700">Enable push dispatch before sending a test notification.</p>
                        ) : null}
                        {pushProviderForm.enabled && !selectedPushReady ? (
                            <p className="mt-2 text-xs text-amber-700">Selected provider credentials are incomplete for this market.</p>
                        ) : null}
                    </section>

                    {latestPushTestResult ? (
                        <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Latest Push Test Result</h4>
                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestPushTestResult.success ? 'success' : 'failed')}`}>
                                    {latestPushTestResult.success ? 'success' : 'failed'}
                                </span>
                            </div>
                            <div className="mt-2 space-y-1 text-xs text-slate-600">
                                <p><span className="font-semibold text-slate-800">Provider:</span> {pushProviderLabel(latestPushTestResult.provider)}</p>
                                <p><span className="font-semibold text-slate-800">Notification ID:</span> {latestPushTestResult.provider_notification_id || 'n/a'}</p>
                                <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {JSON.stringify(latestPushTestResult.provider_response || {})}</p>
                                {latestPushTestResult.fallback_attempted ? (
                                    <p><span className="font-semibold text-slate-800">Fallback:</span> Attempted</p>
                                ) : null}
                            </div>
                        </section>
                    ) : null}

                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Selected Market</h4>
                        <p className="mt-1 text-xs text-slate-600">
                            {selectedPushPlatform
                                ? `${selectedPushPlatform.platform_name} (${selectedPushPlatform.country || '—'})`
                                : 'No market selected.'}
                        </p>
                        <p className="mt-1 text-xs text-slate-600">Active provider: {pushProviderLabel(selectedPushProvider)}</p>
                        <p className="mt-1 text-xs text-slate-600">Fallback: {selectedPushConfig?.fallback_provider === 'none' ? 'No fallback' : pushProviderLabel(selectedPushConfig?.fallback_provider)}</p>
                    </section>
                </div>
            </div>
        </section>
    );
}
