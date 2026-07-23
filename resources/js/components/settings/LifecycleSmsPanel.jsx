import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';

const FLOW_META = {
    onboarding: { label: 'Onboarding', hint: 'Welcome new Fast Signup / Full Registration clients with a pay link.' },
    recovery: { label: 'Failed-payment recovery', hint: 'Client SMS with a fresh link when an automated payment fails.' },
    renewal: { label: 'Renewal links', hint: 'Let renewal reminder templates embed {{payment_link}}.' },
    reactivation: { label: 'Reactivation', hint: 'Win back clients N days after their subscription lapsed.' },
};

function Badge({ ok, label, title }) {
    return (
        <span
            title={title || ''}
            className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${ok
                ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
                : 'bg-amber-50 text-amber-700 ring-amber-600/20'}`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${ok ? 'bg-emerald-500' : 'bg-amber-500'}`} />
            {label}
        </span>
    );
}

function Toggle({ checked, onChange, label, disabled = false }) {
    return (
        <label className={`flex items-center gap-2 ${disabled ? 'opacity-50' : ''}`}>
            <input
                type="checkbox"
                checked={Boolean(checked)}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
            />
            <span className="text-sm text-slate-700">{label}</span>
        </label>
    );
}

function TemplateSelect({ value, onChange, templates, categories, platformId, id }) {
    const options = (templates || []).filter(
        (tpl) => categories.includes(tpl.category) && (tpl.platform_id === null || tpl.platform_id === platformId),
    );
    return (
        <select id={id} value={value ?? ''} onChange={(event) => onChange(event.target.value ? Number(event.target.value) : null)} className="crm-select text-sm">
            <option value="">Auto (best matching template)</option>
            {options.map((tpl) => (
                <option key={tpl.id} value={tpl.id}>
                    {tpl.title}{tpl.platform_id === null ? ' (global)' : ''}
                </option>
            ))}
        </select>
    );
}

function OfferPicker({ flowConfig, patch, products, platformId, idPrefix }) {
    const marketProducts = (products || []).filter((product) => product.platform_id === platformId);
    const product = marketProducts.find((entry) => entry.id === flowConfig.product_id) || null;
    return (
        <div className="grid gap-2 md:grid-cols-2">
            <div>
                <label htmlFor={`${idPrefix}-product`} className="mb-1 block text-xs font-medium text-slate-600">Offer plan</label>
                <select
                    id={`${idPrefix}-product`}
                    value={flowConfig.product_id ?? ''}
                    onChange={(event) => patch({ product_id: event.target.value ? Number(event.target.value) : null, product_price_id: null })}
                    className="crm-select text-sm"
                >
                    <option value="">Select a plan…</option>
                    {marketProducts.map((entry) => (
                        <option key={entry.id} value={entry.id}>{entry.name}{entry.tier ? ` (${entry.tier})` : ''}</option>
                    ))}
                </select>
            </div>
            <div>
                <label htmlFor={`${idPrefix}-price`} className="mb-1 block text-xs font-medium text-slate-600">Pricing option</label>
                <select
                    id={`${idPrefix}-price`}
                    value={flowConfig.product_price_id ?? ''}
                    onChange={(event) => patch({ product_price_id: event.target.value ? Number(event.target.value) : null })}
                    className="crm-select text-sm"
                    disabled={!product}
                >
                    <option value="">{product ? 'Select pricing…' : 'Pick a plan first'}</option>
                    {(product?.prices || []).map((price) => (
                        <option key={price.id} value={price.id}>
                            {price.label} — {price.currency} {Number(price.price).toLocaleString()}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}

function MarketLifecycleRow({ market, overrides, onChange, templates, products, onPreview }) {
    const [expanded, setExpanded] = useState(false);
    const effective = market.effective || {};
    const caps = market.capabilities || {};
    const platformId = market.platform_id;

    const entry = overrides || {};
    const patch = (updates) => onChange({ ...entry, ...updates });
    const patchFlow = (flow, updates) => patch({ [flow]: { ...(entry[flow] || {}), ...updates } });

    const flowEnabled = (flow) => {
        const stored = entry[flow] || {};
        const effectiveFlow = effective[flow] || {};
        if (flow === 'renewal') {
            return stored.payment_link_enabled ?? effectiveFlow.payment_link_enabled ?? false;
        }
        return stored.enabled ?? effectiveFlow.enabled ?? false;
    };

    const flowValue = (flow, key, fallback = null) => {
        const stored = entry[flow] || {};
        if (Object.prototype.hasOwnProperty.call(stored, key)) return stored[key];
        return (effective[flow] || {})[key] ?? fallback;
    };

    const smsEnabled = entry.sms_enabled ?? effective.sms_enabled ?? false;
    const templatesReady = Object.values(caps.templates || {}).some(Boolean);

    return (
        <div className="py-3">
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-slate-800">{market.platform_name}</span>
                        <Badge ok={caps.sms_ready} label={caps.sms_ready ? 'SMS ready' : 'SMS not ready'} title={caps.sms_provider ? `Provider: ${caps.sms_provider}` : 'No configured SMS provider'} />
                        <Badge ok={caps.psp_ready} label={caps.psp_ready ? 'Payment provider ready' : 'No tokenized PSP'} title={caps.psp_ready ? 'Tokenized hosted-checkout links available' : 'Link-bearing sends will be skipped (market_no_psp)'} />
                        <Badge ok={templatesReady} label={templatesReady ? 'Templates present' : 'No templates'} />
                    </div>
                    {smsEnabled ? (
                        <p className="mt-0.5 text-xs text-slate-500">
                            Active flows: {['onboarding', 'recovery', 'renewal', 'reactivation'].filter(flowEnabled).map((flow) => FLOW_META[flow].label).join(', ') || 'none yet'}
                        </p>
                    ) : (
                        <p className="mt-0.5 text-xs text-slate-400">Lifecycle SMS off for this market.</p>
                    )}
                </div>
                <Toggle checked={smsEnabled} onChange={(checked) => patch({ sms_enabled: checked })} label="Enabled" />
                <button type="button" onClick={() => setExpanded((current) => !current)} className="text-xs text-teal-700 hover:underline whitespace-nowrap" aria-expanded={expanded}>
                    {expanded ? 'Less' : 'Configure'}
                </button>
            </div>

            {expanded ? (
                <div className="mt-3 space-y-4 border-l-2 border-slate-100 ml-2 pl-3">
                    {['onboarding', 'recovery', 'renewal', 'reactivation'].map((flow) => (
                        <div key={flow} className="rounded-lg border border-slate-200 bg-white p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-slate-800">{FLOW_META[flow].label}</p>
                                    <p className="text-xs text-slate-400">{FLOW_META[flow].hint}</p>
                                </div>
                                <div className="flex items-center gap-3">
                                    {flow !== 'renewal' ? (
                                        <button
                                            type="button"
                                            onClick={() => onPreview(flow, platformId)}
                                            className="text-xs text-teal-700 hover:underline"
                                        >
                                            Preview targets
                                        </button>
                                    ) : null}
                                    <Toggle
                                        checked={flowEnabled(flow)}
                                        onChange={(checked) => patchFlow(flow, flow === 'renewal' ? { payment_link_enabled: checked } : { enabled: checked })}
                                        label="On"
                                    />
                                </div>
                            </div>

                            {flowEnabled(flow) ? (
                                <div className="mt-3 space-y-3">
                                    {flow !== 'renewal' ? (
                                        <div>
                                            <label htmlFor={`lc-${platformId}-${flow}-template`} className="mb-1 block text-xs font-medium text-slate-600">Template</label>
                                            <TemplateSelect
                                                id={`lc-${platformId}-${flow}-template`}
                                                value={flowValue(flow, 'template_id')}
                                                onChange={(value) => patchFlow(flow, { template_id: value })}
                                                templates={templates}
                                                platformId={platformId}
                                                categories={flow === 'onboarding' ? ['welcome'] : flow === 'recovery' ? ['payment'] : ['win_back']}
                                            />
                                        </div>
                                    ) : (
                                        <p className="text-xs text-slate-500">
                                            Renewal cadence stays on the Campaigns page — this toggle only allows renewal templates to resolve <code className="rounded bg-slate-100 px-1">{'{{payment_link}}'}</code> for this market.
                                        </p>
                                    )}

                                    {(flow === 'onboarding' || flow === 'reactivation') ? (
                                        <OfferPicker
                                            flowConfig={{
                                                product_id: flowValue(flow, 'product_id'),
                                                product_price_id: flowValue(flow, 'product_price_id'),
                                            }}
                                            patch={(updates) => patchFlow(flow, updates)}
                                            products={products}
                                            platformId={platformId}
                                            idPrefix={`lc-${platformId}-${flow}`}
                                        />
                                    ) : null}

                                    {flow === 'onboarding' ? (
                                        <div className="flex flex-wrap items-end gap-4">
                                            <Toggle
                                                checked={flowValue(flow, 'free_trial_enabled', false)}
                                                onChange={(checked) => patchFlow(flow, { free_trial_enabled: checked })}
                                                label="Bonus days offer"
                                            />
                                            {flowValue(flow, 'free_trial_enabled', false) ? (
                                                <div>
                                                    <label htmlFor={`lc-${platformId}-bonus`} className="mb-1 block text-xs font-medium text-slate-600">
                                                        Add / deduct days (applied after payment)
                                                    </label>
                                                    <input
                                                        id={`lc-${platformId}-bonus`}
                                                        type="number"
                                                        min={-30}
                                                        max={90}
                                                        value={flowValue(flow, 'free_trial_days', 0) ?? 0}
                                                        onChange={(event) => patchFlow(flow, { free_trial_days: Number(event.target.value || 0) })}
                                                        className="crm-input w-28 text-sm"
                                                    />
                                                    <p className="mt-1 text-[11px] text-slate-400">e.g. +2 → pay for 7 days, get 9.</p>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : null}

                                    {flow === 'reactivation' ? (
                                        <div>
                                            <label htmlFor={`lc-${platformId}-windows`} className="mb-1 block text-xs font-medium text-slate-600">
                                                Win-back windows (days after expiry, comma-separated)
                                            </label>
                                            <input
                                                id={`lc-${platformId}-windows`}
                                                value={(flowValue(flow, 'windows_days', [7]) || [7]).join(', ')}
                                                onChange={(event) => patchFlow(flow, {
                                                    windows_days: event.target.value.split(',').map((value) => parseInt(value.trim(), 10)).filter((value) => Number.isFinite(value) && value > 0),
                                                })}
                                                className="crm-input w-48 text-sm"
                                                placeholder="7, 30"
                                            />
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function PreviewDrawer({ preview, loading, onClose }) {
    if (!preview && !loading) return null;
    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/30" role="dialog" aria-modal="true">
            <div className="h-full w-full max-w-2xl overflow-y-auto bg-white shadow-xl">
                <header className="sticky top-0 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
                    <div>
                        <h4 className="text-sm font-semibold text-slate-900">
                            {loading ? 'Loading preview…' : `${FLOW_META[preview.flow]?.label || preview.flow} preview — ${preview.platform_name}`}
                        </h4>
                        {!loading ? (
                            <p className="text-xs text-slate-500">
                                {preview.would_send_count} would send · {preview.skipped_count} skipped · dry run, nothing was sent
                            </p>
                        ) : null}
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary text-xs">Close</button>
                </header>
                <div className="space-y-3 p-4">
                    {loading ? (
                        <div className="py-10 text-center text-sm text-slate-400">Evaluating targets…</div>
                    ) : preview.targets.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-slate-200 py-10 text-center text-sm text-slate-400">
                            No matching targets right now.
                        </div>
                    ) : (
                        preview.targets.map((target) => (
                            <div key={target.client_id} className={`rounded-lg border p-3 ${target.would_send ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-slate-50'}`}>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-slate-800">{target.client_name || `Client #${target.client_id}`}</span>
                                    <span className="text-xs crm-mono text-slate-500">{target.phone}</span>
                                    {target.would_send ? (
                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">would send</span>
                                    ) : (
                                        <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-semibold text-slate-600">skip: {target.skip_reason}</span>
                                    )}
                                </div>
                                {target.sample_body ? (
                                    <p className="mt-2 rounded-md border border-slate-200 bg-white p-2 text-xs text-slate-700">
                                        {target.sample_body}
                                        <span className="mt-1 block text-[10px] text-slate-400">{target.sample_body.length} chars · ~{target.segments || 1} segment{(target.segments || 1) === 1 ? '' : 's'}</span>
                                    </p>
                                ) : null}
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}

export default function LifecycleSmsPanel() {
    const queryClient = useQueryClient();
    const [drafts, setDrafts] = useState({});
    const [globalEnabled, setGlobalEnabled] = useState(null);
    const [reason, setReason] = useState('');
    const [marketSearch, setMarketSearch] = useState('');
    const [showAll, setShowAll] = useState(false);
    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [testForm, setTestForm] = useState({ flow: 'onboarding', platform_id: '', phone: '' });
    const [testResult, setTestResult] = useState(null);
    const [runResult, setRunResult] = useState(null);
    const [feedback, setFeedback] = useState(null);

    const configQuery = useQuery({
        queryKey: ['lifecycle-sms-config'],
        queryFn: () => api.get('/crm/lifecycle-sms/config').then((r) => r.data),
        staleTime: 30_000,
    });

    const activityQuery = useQuery({
        queryKey: ['lifecycle-sms-activity'],
        queryFn: () => api.get('/crm/lifecycle-sms/activity', { params: { per_page: 15 } }).then((r) => r.data),
        refetchInterval: 60_000,
    });

    const saveMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/lifecycle-sms/config', payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['lifecycle-sms-config'] });
            setDrafts({});
            setGlobalEnabled(null);
            setReason('');
            setFeedback({ tone: 'success', text: 'Lifecycle SMS settings saved.' });
        },
        onError: (err) => setFeedback({ tone: 'error', text: err?.response?.data?.message || 'Save failed.' }),
    });

    const testMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/lifecycle-sms/test-send', payload).then((r) => r.data),
        onSuccess: (data) => setTestResult(data),
        onError: (err) => setTestResult({ success: false, provider_response: err?.response?.data?.message || 'Test send failed.' }),
    });

    const runMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/lifecycle-sms/run', payload).then((r) => r.data),
        onSuccess: (data) => {
            setRunResult(data);
            queryClient.invalidateQueries({ queryKey: ['lifecycle-sms-activity'] });
        },
        onError: (err) => setRunResult({ message: err?.response?.data?.message || 'Run failed.', output: '' }),
    });

    const data = configQuery.data;
    const markets = data?.markets || [];
    const enabledNow = globalEnabled ?? data?.enabled ?? false;
    const dirty = globalEnabled !== null || Object.keys(drafts).length > 0;

    const visibleMarkets = useMemo(() => {
        const term = marketSearch.trim().toLowerCase();
        return markets.filter((market) => {
            if (term && !market.platform_name.toLowerCase().includes(term)) return false;
            if (showAll || term) return true;
            const entry = drafts[String(market.platform_id)] ?? market.overrides;
            return Boolean(entry && (entry.sms_enabled ?? market.effective?.sms_enabled));
        });
    }, [markets, marketSearch, showAll, drafts]);

    const openPreview = async (flow, platformId) => {
        setPreviewLoading(true);
        setPreview(null);
        try {
            const response = await api.get('/crm/lifecycle-sms/preview', { params: { flow, platform_id: platformId, limit: 50 } });
            setPreview(response.data);
        } catch (err) {
            setFeedback({ tone: 'error', text: err?.response?.data?.message || 'Preview failed.' });
        } finally {
            setPreviewLoading(false);
        }
    };

    const save = () => {
        const payload = { reason: reason.trim() };
        if (globalEnabled !== null) payload.enabled = globalEnabled;
        if (Object.keys(drafts).length > 0) payload.markets = drafts;
        saveMutation.mutate(payload);
    };

    if (configQuery.isLoading) {
        return <div className="py-16 text-center text-sm text-slate-400">Loading lifecycle configuration…</div>;
    }

    if (configQuery.isError) {
        return (
            <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                Could not load lifecycle SMS settings. {configQuery.error?.response?.data?.message || ''}
                <button type="button" className="ml-2 underline" onClick={() => configQuery.refetch()}>Retry</button>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <div>
                    <p className="text-sm font-semibold text-slate-800">Lifecycle SMS engine</p>
                    <p className="text-xs text-slate-500">
                        Onboarding · failed-payment recovery · renewal links · reactivation. Tokenized payment links only; markets without a PSP skip link sends. Dedup, quiet hours (8pm–8am local) and rate caps are always on.
                    </p>
                </div>
                <Toggle checked={enabledNow} onChange={setGlobalEnabled} label={enabledNow ? 'Master switch: ON' : 'Master switch: OFF'} />
            </div>

            <section>
                <div className="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h4 className="text-sm font-semibold text-slate-900">Markets</h4>
                    <div className="flex items-center gap-3">
                        <input
                            type="search"
                            value={marketSearch}
                            onChange={(event) => setMarketSearch(event.target.value)}
                            placeholder="Search markets"
                            className="crm-input text-sm sm:w-56"
                            aria-label="Search markets"
                        />
                        <label className="flex items-center gap-2 text-xs text-slate-600">
                            <input type="checkbox" checked={showAll} onChange={(event) => setShowAll(event.target.checked)} className="h-4 w-4 rounded border-slate-300" />
                            Show all {markets.length}
                        </label>
                    </div>
                </div>

                {visibleMarkets.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-slate-200 py-10 text-center text-sm text-slate-400">
                        {marketSearch ? 'No markets match your search.' : 'No markets enabled yet — tick "Show all" and enable your first market.'}
                    </div>
                ) : (
                    <div className="divide-y divide-slate-100 rounded-lg border border-slate-200 px-3">
                        {visibleMarkets.map((market) => (
                            <MarketLifecycleRow
                                key={market.platform_id}
                                market={market}
                                overrides={drafts[String(market.platform_id)] ?? market.overrides}
                                templates={data.templates}
                                products={data.products}
                                onPreview={openPreview}
                                onChange={(entry) => setDrafts((current) => ({ ...current, [String(market.platform_id)]: entry }))}
                            />
                        ))}
                    </div>
                )}
            </section>

            <section className="grid gap-4 lg:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <h4 className="text-sm font-semibold text-slate-900">Test send to me</h4>
                    <p className="mt-1 text-xs text-slate-500">Sends the rendered flow template (with a placeholder link) to your phone. Prefixed with [TEST].</p>
                    <div className="mt-3 grid gap-2 md:grid-cols-3">
                        <select value={testForm.flow} onChange={(event) => setTestForm((current) => ({ ...current, flow: event.target.value }))} className="crm-select text-sm" aria-label="Test flow">
                            {Object.entries(FLOW_META).map(([flow, meta]) => (
                                <option key={flow} value={flow}>{meta.label}</option>
                            ))}
                        </select>
                        <select value={testForm.platform_id} onChange={(event) => setTestForm((current) => ({ ...current, platform_id: event.target.value }))} className="crm-select text-sm" aria-label="Test market">
                            <option value="">Select market…</option>
                            {markets.map((market) => (
                                <option key={market.platform_id} value={market.platform_id}>{market.platform_name}</option>
                            ))}
                        </select>
                        <input
                            value={testForm.phone}
                            onChange={(event) => setTestForm((current) => ({ ...current, phone: event.target.value }))}
                            className="crm-input text-sm"
                            placeholder="Your phone e.g. +2547…"
                        />
                    </div>
                    <div className="mt-2 flex justify-end">
                        <button
                            type="button"
                            className="crm-btn-secondary text-xs disabled:opacity-60"
                            disabled={testMutation.isPending || !testForm.platform_id || !testForm.phone.trim()}
                            onClick={() => testMutation.mutate({ flow: testForm.flow, platform_id: Number(testForm.platform_id), phone: testForm.phone.trim() })}
                        >
                            {testMutation.isPending ? 'Sending…' : 'Send test SMS'}
                        </button>
                    </div>
                    {testResult ? (
                        <div className={`mt-2 rounded-md border p-2 text-xs ${testResult.success ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                            {testResult.success ? `Sent via ${testResult.provider || 'SMS'} (${testResult.segments} segment${testResult.segments === 1 ? '' : 's'}).` : (testResult.provider_response || 'Failed.')}
                            {testResult.body ? <p className="mt-1 text-slate-600">{testResult.body}</p> : null}
                        </div>
                    ) : null}
                </div>

                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <h4 className="text-sm font-semibold text-slate-900">Run now</h4>
                    <p className="mt-1 text-xs text-slate-500">Trigger the sweep immediately instead of waiting for the hourly schedule. Dry run shows counts without sending.</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" className="crm-btn-secondary text-xs" disabled={runMutation.isPending} onClick={() => runMutation.mutate({ flow: 'all', dry_run: true })}>
                            {runMutation.isPending ? 'Running…' : 'Dry run (all flows)'}
                        </button>
                        <button
                            type="button"
                            className="crm-btn-primary text-xs"
                            disabled={runMutation.isPending || !data.enabled}
                            onClick={() => {
                                if (window.confirm('Run all lifecycle flows now? Real SMS will be sent to eligible clients in enabled markets.')) {
                                    runMutation.mutate({ flow: 'all' });
                                }
                            }}
                        >
                            Run live now
                        </button>
                    </div>
                    {runResult ? (
                        <pre className="mt-2 max-h-48 overflow-auto rounded-md border border-slate-200 bg-slate-50 p-2 text-[11px] text-slate-700 whitespace-pre-wrap">{runResult.message}{'\n'}{runResult.output}</pre>
                    ) : null}
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white">
                <header className="border-b border-slate-100 px-3 py-2">
                    <h4 className="text-sm font-semibold text-slate-900">Recent lifecycle sends</h4>
                </header>
                {activityQuery.isLoading ? (
                    <div className="py-8 text-center text-xs text-slate-400">Loading activity…</div>
                ) : (activityQuery.data?.data || []).length === 0 ? (
                    <div className="py-8 text-center text-xs text-slate-400">No lifecycle SMS sent yet. Enable a market and run a dry run to see targets.</div>
                ) : (
                    <div className="divide-y divide-slate-100">
                        {(activityQuery.data?.data || []).map((log) => (
                            <div key={log.id} className="flex flex-wrap items-center gap-3 px-3 py-2 text-xs">
                                <span className={`inline-flex rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset ${log.status === 'sent' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-rose-50 text-rose-700 ring-rose-600/20'}`}>
                                    {log.flow || 'lifecycle'} · {log.status}
                                </span>
                                <span className="crm-mono text-slate-500">{log.phone}</span>
                                <span className="flex-1 truncate text-slate-600" title={log.message}>{log.message}</span>
                                <span className="text-slate-400">{log.sent_at ? new Date(log.sent_at).toLocaleString() : ''}</span>
                            </div>
                        ))}
                    </div>
                )}
            </section>

            <footer className="sticky bottom-0 space-y-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                {feedback ? (
                    <p className={`rounded-md border px-2 py-1 text-xs ${feedback.tone === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                        {feedback.text}
                    </p>
                ) : null}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <input
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        className="crm-input flex-1 text-sm"
                        placeholder="Change reason (recorded in the audit log)"
                    />
                    <button
                        type="button"
                        onClick={save}
                        disabled={saveMutation.isPending || !dirty || !reason.trim()}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {saveMutation.isPending ? 'Saving…' : 'Save lifecycle settings'}
                    </button>
                </div>
            </footer>

            <PreviewDrawer preview={preview} loading={previewLoading} onClose={() => { setPreview(null); setPreviewLoading(false); }} />
        </div>
    );
}
