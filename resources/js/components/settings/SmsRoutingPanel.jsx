import React from 'react';

export default function SmsRoutingPanel({
    fallbackInvalid,
    fallbackOptions,
    latestSmsTestResult,
    saveSmsProviderConfig,
    saveSmsProviderMutation,
    setSmsProviderForm,
    setSmsTestConfirmOpen,
    setSmsTestForm,
    smsProviderApiKeyConfigured,
    smsProviderForm,
    smsProviderLabel,
    smsReady,
    smsTestForm,
    statusChip,
    testSmsProviderMutation,
    updateSmsProviderField,
}) {
    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">SMS Provider Routing</h3>
                    <p className="crm-panel-subtitle">Choose an active SMS provider, set fallback behavior, and validate delivery from settings.</p>
                </div>
            </header>

            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-7">
                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Routing Controls</h4>
                        <p className="mt-1 text-xs text-slate-500">These settings define which provider is used first and what happens if dispatch fails.</p>

                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={Boolean(smsProviderForm.enabled)}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, enabled: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                Enable SMS dispatch for operational events
                            </label>

                            <div>
                                <label htmlFor="sms-active-provider" className="mb-1 block text-sm font-medium text-slate-700">Active provider</label>
                                <select
                                    id="sms-active-provider"
                                    value={smsProviderForm.active_provider}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, active_provider: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="legacy_gateway">Legacy Gateway</option>
                                    <option value="africastalking">Africa&apos;s Talking</option>
                                </select>
                            </div>

                            <div>
                                <label htmlFor="sms-fallback-provider" className="mb-1 block text-sm font-medium text-slate-700">Fallback provider</label>
                                <select
                                    id="sms-fallback-provider"
                                    value={smsProviderForm.fallback_provider}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, fallback_provider: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    {fallbackOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                            disabled={option.value !== 'none' && option.value === smsProviderForm.active_provider}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label htmlFor="sms-default-prefix" className="mb-1 block text-sm font-medium text-slate-700">Default phone prefix</label>
                                <input
                                    id="sms-default-prefix"
                                    value={smsProviderForm.default_prefix}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, default_prefix: event.target.value }))}
                                    className="crm-input"
                                    placeholder="254"
                                />
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="sms-config-reason" className="mb-1 block text-sm font-medium text-slate-700">Change reason</label>
                                <textarea
                                    id="sms-config-reason"
                                    rows={2}
                                    value={smsProviderForm.reason}
                                    onChange={(event) => setSmsProviderForm((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Reason for updating SMS routing"
                                />
                            </div>
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Legacy Gateway</h4>
                        <p className="mt-1 text-xs text-slate-500">Existing SMS connector used in current operations.</p>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                value={smsProviderForm.legacy_gateway.gateway_url}
                                onChange={(event) => updateSmsProviderField('legacy_gateway', 'gateway_url', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="Gateway URL"
                            />
                            <input
                                value={smsProviderForm.legacy_gateway.org_code}
                                onChange={(event) => updateSmsProviderField('legacy_gateway', 'org_code', event.target.value)}
                                className="crm-input"
                                placeholder="Org code"
                            />
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Africa&apos;s Talking</h4>
                        <p className="mt-1 text-xs text-slate-500">Use this provider for managed delivery with API-key authentication.</p>
                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                            <input
                                value={smsProviderForm.africastalking.endpoint}
                                onChange={(event) => updateSmsProviderField('africastalking', 'endpoint', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="API endpoint"
                            />
                            <input
                                value={smsProviderForm.africastalking.username}
                                onChange={(event) => updateSmsProviderField('africastalking', 'username', event.target.value)}
                                className="crm-input"
                                placeholder="Username"
                            />
                            <input
                                value={smsProviderForm.africastalking.sender_id}
                                onChange={(event) => updateSmsProviderField('africastalking', 'sender_id', event.target.value)}
                                className="crm-input"
                                placeholder="Sender ID (optional)"
                            />
                            <input
                                type="password"
                                value={smsProviderForm.africastalking.api_key}
                                onChange={(event) => updateSmsProviderField('africastalking', 'api_key', event.target.value)}
                                className="crm-input md:col-span-2"
                                placeholder="API key (leave blank to keep current key)"
                            />
                        </div>
                        <p className={`mt-2 text-xs ${smsProviderApiKeyConfigured ? 'text-emerald-700' : 'text-amber-700'}`}>
                            {smsProviderApiKeyConfigured
                                ? 'API key is already stored. Add a new value only when rotating credentials.'
                                : 'No API key is currently configured for Africa\'s Talking.'}
                        </p>
                    </section>

                    {fallbackInvalid ? (
                        <p className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700">
                            Fallback provider must be different from the active provider.
                        </p>
                    ) : null}

                    {smsProviderForm.enabled && !smsReady ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                            Active provider credentials are incomplete. Complete required fields before saving or sending tests.
                        </p>
                    ) : null}

                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={saveSmsProviderConfig}
                            disabled={saveSmsProviderMutation.isPending || !smsProviderForm.reason.trim() || fallbackInvalid || !smsProviderForm.default_prefix.trim() || (smsProviderForm.enabled && !smsReady)}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveSmsProviderMutation.isPending ? 'Saving...' : 'Save SMS settings'}
                        </button>
                    </div>
                </div>

                <div className="space-y-4 xl:col-span-5">
                    <section className="rounded-lg border border-slate-200 bg-white p-3">
                        <h4 className="text-sm font-semibold text-slate-900">Test Dispatch</h4>
                        <p className="mt-1 text-xs text-slate-500">Send a controlled SMS to verify routing and provider response in real time.</p>
                        <div className="mt-3 space-y-3">
                            <input
                                value={smsTestForm.phone}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, phone: event.target.value }))}
                                className="crm-input"
                                placeholder="Phone (example: +254712000000)"
                            />
                            <textarea
                                rows={4}
                                value={smsTestForm.message}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, message: event.target.value }))}
                                className="crm-input"
                                placeholder="Test message content"
                            />
                            <input
                                value={smsTestForm.reason}
                                onChange={(event) => setSmsTestForm((current) => ({ ...current, reason: event.target.value }))}
                                className="crm-input"
                                placeholder="Reason for test dispatch"
                            />
                        </div>
                        <div className="mt-3 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSmsTestConfirmOpen(true)}
                                disabled={testSmsProviderMutation.isPending || !smsReady || !smsProviderForm.enabled || !smsTestForm.phone.trim() || !smsTestForm.message.trim() || !smsTestForm.reason.trim()}
                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {testSmsProviderMutation.isPending ? 'Sending...' : 'Send test SMS'}
                            </button>
                        </div>
                        {!smsProviderForm.enabled ? (
                            <p className="mt-2 text-xs text-amber-700">Enable SMS dispatch before sending a provider test message.</p>
                        ) : null}
                    </section>

                    {latestSmsTestResult ? (
                        <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Latest SMS Test Result</h4>
                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(latestSmsTestResult.success ? 'success' : 'failed')}`}>
                                    {latestSmsTestResult.success ? 'success' : 'failed'}
                                </span>
                            </div>
                            <div className="mt-2 space-y-1 text-xs text-slate-600">
                                <p><span className="font-semibold text-slate-800">Provider:</span> {smsProviderLabel(latestSmsTestResult.provider)}</p>
                                <p><span className="font-semibold text-slate-800">Status:</span> {latestSmsTestResult.status || 'unknown'}</p>
                                <p><span className="font-semibold text-slate-800">Phone:</span> {latestSmsTestResult.phone || '--'}</p>
                                <p className="break-all"><span className="font-semibold text-slate-800">Response:</span> {latestSmsTestResult.provider_response || 'No provider response message.'}</p>
                                {latestSmsTestResult.fallback_attempted ? (
                                    <p><span className="font-semibold text-slate-800">Fallback:</span> Attempted from {smsProviderLabel(latestSmsTestResult.fallback_from || smsProviderForm.active_provider)}</p>
                                ) : null}
                            </div>
                        </section>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
