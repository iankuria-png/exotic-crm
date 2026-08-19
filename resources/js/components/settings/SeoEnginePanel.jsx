import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BioGenerationDefaultsCard from './BioGenerationDefaultsCard';

const PROVIDER_DISPLAY = {
    claude:   { label: 'Anthropic Claude', help: 'Best quality. Get a key at console.anthropic.com.' },
    openai:   { label: 'OpenAI',           help: 'GPT-4o-mini is cost-effective for bios.' },
    gemini:   { label: 'Google Gemini',    help: 'Free tier available via Google AI Studio (aistudio.google.com).' },
    deepseek: { label: 'DeepSeek',         help: 'Lowest cost. Get a key at platform.deepseek.com.' },
};

const MODEL_PRESETS = {
    deepseek: [
        { value: 'deepseek-v4-pro', label: 'V4 Pro', hint: 'Primary quality model' },
        { value: 'deepseek-v4-flash', label: 'V4 Flash', hint: 'Fast fallback model' },
        { value: 'deepseek-chat', label: 'DeepSeek Chat', hint: 'Official chat-compatible model ID' },
    ],
};

const SENTINEL = '__keep__';

const DEFAULT_GENERATION = {
    tone: 'seductive, unique, sexy, witty, flirty, suggestive, human-written profile copy',
    temperament: 'confident, playful, raw, and magnetic without sounding scripted',
    min_words: 75,
    max_words: 115,
    max_characters: 900,
    max_services: 5,
    include_location: true,
    include_services: true,
    include_contact: true,
    contact_channel: 'whatsapp',
    custom_prompt: '',
    language: 'en',
    bio_format: 'auto',
    creativity: 0.85,
    overuse_sensitivity: 'medium',
    ignored_overuse_terms: [],
    overuse_lookback_days: 60,
};


function hydrateProvider(provider = {}) {
    const incomingKey = provider?.api_key || '';
    return {
        apiKey: incomingKey === SENTINEL ? '' : incomingKey,
        model: provider?.model || '',
        fallbackModels: Array.isArray(provider?.fallback_models) ? provider.fallback_models : [],
        hasKey: !!provider?.has_key,
        preview: provider?.key_preview || '',
    };
}

function formFromConfig(config = {}) {
    return {
        enabled: !!config.enabled,
        platformAllowlist: config.platform_allowlist || [],
        providersOrder: config.providers_order || ['gemini', 'claude', 'openai', 'deepseek'],
        providers: {
            claude:   hydrateProvider(config.providers?.claude),
            openai:   hydrateProvider(config.providers?.openai),
            gemini:   hydrateProvider(config.providers?.gemini),
            deepseek: hydrateProvider(config.providers?.deepseek),
        },
        generation: { ...DEFAULT_GENERATION, ...(config.generation || {}) },
    };
}

/**
 * SEO Engine settings panel.
 * Lives in Settings → SEO Engine tab. Admin-only writes.
 */
export default function SeoEnginePanel() {
    const toast = useToast();
    const queryClient = useQueryClient();

    const settingsQuery = useQuery({
        queryKey: ['seo-engine-settings'],
        queryFn: () => api.get('/crm/settings/seo-engine').then((r) => r.data),
    });

    const [form, setForm] = useState(null);
    const [testingProvider, setTestingProvider] = useState(null);
    const [testResults, setTestResults] = useState({});
    const [balances, setBalances] = useState({});
    const [refreshingBalance, setRefreshingBalance] = useState(null);
    const [auditPlatformId, setAuditPlatformId] = useState('');
    const [auditSource, setAuditSource] = useState('all');

    // Hydrate form when settings load
    useEffect(() => {
        if (settingsQuery.data?.config && !form) {
            setForm(formFromConfig(settingsQuery.data.config));
        }
    }, [settingsQuery.data, form]);

    const qualityAuditQuery = useQuery({
        queryKey: ['seo-quality-audit', auditPlatformId, auditSource],
        queryFn: () => api.get('/crm/seo/quality-audit', {
            params: {
                source: auditSource,
                limit: 300,
                ...(auditPlatformId ? { platform_id: auditPlatformId } : {}),
            },
        }).then((r) => r.data),
        enabled: !!form,
        staleTime: 60_000,
    });

    const qualityRecoveryMutation = useMutation({
        mutationFn: () => api.post('/crm/auto-optimize/quality-audit/run', {
            platform_id: Number(auditPlatformId),
            limit: 20,
        }).then((r) => r.data),
        onSuccess: (data) => {
            toast.success(data?.message || 'Bio quality recovery run staged.');
            queryClient.invalidateQueries({ queryKey: ['auto-optimize-plans'] });
            queryClient.invalidateQueries({ queryKey: ['auto-optimize-items'] });
            queryClient.invalidateQueries({ queryKey: ['auto-optimize-metrics'] });
            qualityAuditQuery.refetch();
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Could not stage bio quality recovery.');
        },
    });

    const saveMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/seo-engine', payload).then((r) => r.data),
        onSuccess: (data) => {
            toast.success('SEO Engine settings saved.');
            if (data?.config) {
                setForm(formFromConfig(data.config));
                queryClient.setQueryData(['seo-engine-settings'], (previous) => ({
                    ...(previous || {}),
                    config: data.config,
                }));
            }
            queryClient.invalidateQueries({ queryKey: ['seo-engine-settings'] });
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Could not save SEO settings.');
        },
    });

    const testMutation = useMutation({
        mutationFn: (provider) => api.post('/crm/settings/seo-engine/test', { provider }).then((r) => r.data),
        onMutate: (provider) => setTestingProvider(provider),
        onSettled: () => setTestingProvider(null),
        onSuccess: (data, provider) => {
            setTestResults((prev) => ({ ...prev, [provider]: data }));
            if (data.success) {
                toast.success(`${PROVIDER_DISPLAY[provider]?.label} responded OK.`);
            } else {
                toast.error(`Test failed: ${data.error}`);
            }
        },
        onError: (err, provider) => {
            const msg = err?.response?.data?.message || 'Test failed.';
            setTestResults((prev) => ({ ...prev, [provider]: { success: false, error: msg } }));
            toast.error(msg);
        },
    });

    const refreshBalance = async (provider) => {
        setRefreshingBalance(provider);
        try {
            const { data } = await api.get('/crm/settings/seo-engine/balance', { params: { provider } });
            setBalances((prev) => ({ ...prev, [provider]: data }));
        } catch (err) {
            setBalances((prev) => ({
                ...prev,
                [provider]: { supported: true, error: err?.response?.data?.message || 'Could not fetch balance.' },
            }));
        } finally {
            setRefreshingBalance(null);
        }
    };

    if (settingsQuery.isLoading || !form) {
        return <div className="crm-surface p-6 text-sm text-slate-500">Loading SEO Engine settings…</div>;
    }

    if (settingsQuery.isError) {
        return <div className="crm-surface p-6 text-sm text-rose-700">Could not load settings. Make sure you're an admin.</div>;
    }

    const envKeys = settingsQuery.data?.env_keys_detected || {};
    const platforms = settingsQuery.data?.platforms || [];

    const handleSave = () => {
        const payload = {
            enabled: form.enabled,
            platform_allowlist: form.platformAllowlist,
            providers_order: form.providersOrder,
            providers: {
                claude:   providerPayload(form.providers.claude),
                openai:   providerPayload(form.providers.openai),
                gemini:   providerPayload(form.providers.gemini),
                deepseek: providerPayload(form.providers.deepseek),
            },
            generation: form.generation,
        };
        // If user typed a key, send it raw; otherwise send sentinel so backend keeps existing.
        Object.keys(payload.providers).forEach((p) => {
            if (payload.providers[p].api_key === '') payload.providers[p].api_key = SENTINEL;
        });
        saveMutation.mutate(payload);
    };

    const togglePlatform = (id) => {
        setForm((f) => {
            const has = f.platformAllowlist.includes(id);
            return {
                ...f,
                platformAllowlist: has ? f.platformAllowlist.filter((x) => x !== id) : [...f.platformAllowlist, id],
            };
        });
    };

    const moveProvider = (provider, direction) => {
        setForm((f) => {
            const arr = [...f.providersOrder];
            const idx = arr.indexOf(provider);
            if (idx < 0) return f;
            const swap = direction === 'up' ? idx - 1 : idx + 1;
            if (swap < 0 || swap >= arr.length) return f;
            [arr[idx], arr[swap]] = [arr[swap], arr[idx]];
            return { ...f, providersOrder: arr };
        });
    };

    const updateProvider = (provider, field, value) => {
        setForm((f) => ({
            ...f,
            providers: {
                ...f.providers,
                [provider]: { ...f.providers[provider], [field]: value },
            },
        }));
    };

    const updateProviderModelChain = (provider, models) => {
        setForm((f) => {
            const chain = normalizeModelChain(models);

            return {
                ...f,
                providers: {
                    ...f.providers,
                    [provider]: {
                        ...f.providers[provider],
                        model: chain[0] || '',
                        fallbackModels: chain.slice(1),
                    },
                },
            };
        });
    };

    const updateGeneration = (field, value) => {
        setForm((f) => ({
            ...f,
            generation: { ...f.generation, [field]: value },
        }));
    };

    return (
        <div className="space-y-4">
            {/* === Master toggle === */}
            <section className="crm-surface p-6">
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-lg font-semibold text-slate-900">SEO Engine</h2>
                        <p className="mt-1 text-sm text-slate-600">
                            Generate SEO-optimised profile bios automatically using an LLM waterfall (Claude → OpenAI → Gemini → DeepSeek)
                            with a deterministic template fallback. Scoring runs on every save. Turn the engine off to disable bio generation
                            and the “Generate Bio” buttons everywhere.
                        </p>
                    </div>
                    <label className="inline-flex items-center gap-2 select-none cursor-pointer">
                        <input
                            type="checkbox"
                            checked={form.enabled}
                            onChange={(e) => setForm((f) => ({ ...f, enabled: e.target.checked }))}
                            className="h-5 w-5 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
                        />
                        <span className="text-sm font-medium text-slate-800">
                            {form.enabled ? 'Enabled' : 'Disabled'}
                        </span>
                    </label>
                </div>
            </section>

            {/* === Platform allowlist === */}
            <section className="crm-surface p-6">
                <h3 className="text-base font-semibold text-slate-900">Platform allowlist</h3>
                <p className="mt-1 text-sm text-slate-600">
                    Which markets are allowed to call the SEO Engine. Leave all unchecked to allow all platforms.
                </p>
                <div className="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    {platforms.length === 0 ? (
                        <p className="text-sm text-slate-500 col-span-full">No platforms found.</p>
                    ) : platforms.map((p) => (
                        <label key={p.id} className="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 hover:bg-slate-50 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={form.platformAllowlist.includes(p.id)}
                                onChange={() => togglePlatform(p.id)}
                                className="h-4 w-4 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
                            />
                            <span className="text-sm text-slate-800">{p.name}</span>
                            <span className="text-xs text-slate-400">#{p.id}</span>
                        </label>
                    ))}
                </div>
            </section>

            {/* === Provider order === */}
            <section className="crm-surface p-6">
                <h3 className="text-base font-semibold text-slate-900">Provider order</h3>
                <p className="mt-1 text-sm text-slate-600">
                    The waterfall tries providers in this order. Providers without an API key (or marked unavailable) are skipped automatically.
                </p>
                <ol className="mt-4 space-y-2">
                    {form.providersOrder.map((p, i) => {
                        const display = PROVIDER_DISPLAY[p];
                        const configured = !!form.providers[p].apiKey || form.providers[p].hasKey || envKeys[p];
                        return (
                            <li key={p} className="flex items-center gap-3 rounded-md border border-slate-200 px-3 py-2">
                                <span className="text-xs font-mono bg-slate-100 rounded px-1.5 py-0.5">{i + 1}</span>
                                <span className="flex-1 text-sm text-slate-800">{display?.label || p}</span>
                                {configured ? (
                                    <span className="text-xs text-emerald-600">configured</span>
                                ) : (
                                    <span className="text-xs text-slate-400">no key</span>
                                )}
                                <button type="button" onClick={() => moveProvider(p, 'up')} disabled={i === 0}
                                    className="text-xs text-slate-500 hover:text-slate-800 disabled:opacity-30">↑</button>
                                <button type="button" onClick={() => moveProvider(p, 'down')} disabled={i === form.providersOrder.length - 1}
                                    className="text-xs text-slate-500 hover:text-slate-800 disabled:opacity-30">↓</button>
                            </li>
                        );
                    })}
                </ol>
            </section>

            {/* === API keys per provider === */}
            <section className="crm-surface p-6">
                <h3 className="text-base font-semibold text-slate-900">Provider API keys</h3>
                <p className="mt-1 text-sm text-slate-600">
                    Keys are encrypted at rest and never returned by the API. Leave a field blank to keep the stored value.
                </p>
                <div className="mt-4 space-y-5">
                    {Object.entries(PROVIDER_DISPLAY).map(([provider, display]) => {
                        const p = form.providers[provider];
                        const envFallback = envKeys[provider];
                        const testResult = testResults[provider];
                        return (
                            <div key={provider} className="rounded-md border border-slate-200 p-4">
                                <div className="flex items-center justify-between flex-wrap gap-2">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">{display.label}</h4>
                                        <p className="text-xs text-slate-500 mt-0.5">{display.help}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => testMutation.mutate(provider)}
                                        disabled={testingProvider === provider || (!p.hasKey && !p.apiKey && !envFallback)}
                                        className="text-xs px-3 py-1.5 rounded-md border border-slate-300 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
                                    >
                                        {testingProvider === provider ? 'Testing…' : 'Test'}
                                    </button>
                                </div>
                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700 mb-1">API key</label>
                                        <input
                                            type="password"
                                            value={p.apiKey}
                                            onChange={(e) => updateProvider(provider, 'apiKey', e.target.value)}
                                            placeholder={p.hasKey ? p.preview : (envFallback ? 'Using .env value' : 'Paste key…')}
                                            className="w-full text-sm rounded-md border-slate-300 focus:border-teal-500 focus:ring-teal-500"
                                        />
                                    </div>
                                    <ProviderModelField
                                        provider={provider}
                                        value={p.model}
                                        fallbackModels={p.fallbackModels || []}
                                        onChange={(value) => updateProvider(provider, 'model', value)}
                                        onModelChainChange={(models) => updateProviderModelChain(provider, models)}
                                    />
                                </div>
                                {testResult && (
                                    <div className={`mt-3 text-xs rounded-md p-2 ${testResult.success ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'}`}>
                                        {testResult.success
                                            ? <>✓ Reply: <span className="font-mono">{testResult.text}</span> ({testResult.input_tokens}→{testResult.output_tokens} tokens)</>
                                            : <>✗ {testResult.error}</>
                                        }
                                    </div>
                                )}

                                {/* === Credit balance row === */}
                                <ProviderBalanceRow
                                    provider={provider}
                                    balance={balances[provider]}
                                    refreshing={refreshingBalance === provider}
                                    onRefresh={() => refreshBalance(provider)}
                                    canFetch={p.hasKey || !!p.apiKey || envFallback}
                                />
                            </div>
                        );
                    })}
                </div>
            </section>


            {/* === Editorial defaults (new card) === */}
            <BioGenerationDefaultsCard
                value={form.generation}
                onChange={(next) => setForm((f) => ({ ...f, generation: next }))}
            />

            <BioQualityAuditCard
                platforms={platforms}
                selectedPlatformId={auditPlatformId}
                onPlatformChange={setAuditPlatformId}
                source={auditSource}
                onSourceChange={setAuditSource}
                data={qualityAuditQuery.data}
                loading={qualityAuditQuery.isFetching}
                error={qualityAuditQuery.error}
                onRefresh={() => qualityAuditQuery.refetch()}
                onRunRecovery={() => qualityRecoveryMutation.mutate()}
                runningRecovery={qualityRecoveryMutation.isPending}
            />

            {/* === Save bar === */}
            <div className="sticky bottom-4 flex justify-end">
                <button
                    type="button"
                    onClick={handleSave}
                    disabled={saveMutation.isPending}
                    className="px-5 py-2 rounded-md bg-teal-600 text-white text-sm font-medium hover:bg-teal-700 shadow-sm disabled:opacity-50"
                >
                    {saveMutation.isPending ? 'Saving…' : 'Save SEO Engine settings'}
                </button>
            </div>
        </div>
    );
}

function BioQualityAuditCard({
    platforms,
    selectedPlatformId,
    onPlatformChange,
    source,
    onSourceChange,
    data,
    loading,
    error,
    onRefresh,
    onRunRecovery,
    runningRecovery,
}) {
    const summary = data?.summary || {};
    const platformRows = data?.platforms || [];
    const detailRows = selectedPlatformId ? [summary].filter((item) => item?.sample_size) : platformRows.slice(0, 8);
    const issueSummary = selectedPlatformId
        ? summary
        : platformRows.find((row) => (row?.top_slop_flags || []).length || (row?.top_repetition_flags || []).length);

    return (
        <section className="crm-surface p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-slate-900">Bio quality audit</h3>
                    <p className="mt-1 text-sm text-slate-600">
                        Scan country-level bio quality for repeated openings, AI-ish phrasing, thin copy, ethnicity leakage, and punctuation artifacts.
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={onRefresh}
                        disabled={loading}
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    >
                        {loading ? 'Scanning...' : 'Refresh scan'}
                    </button>
                    <button
                        type="button"
                        onClick={onRunRecovery}
                        disabled={!selectedPlatformId || runningRecovery}
                        className="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-teal-700 disabled:cursor-not-allowed disabled:opacity-40"
                        title={!selectedPlatformId ? 'Select one market before staging optimizer work.' : 'Queue the worst bio-quality profiles into Optimizer approval mode.'}
                    >
                        {runningRecovery ? 'Staging...' : 'Stage optimizer run'}
                    </button>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-[1fr_220px_150px]">
                <label className="block">
                    <span className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Market</span>
                    <select
                        value={selectedPlatformId}
                        onChange={(e) => onPlatformChange(e.target.value)}
                        className="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    >
                        <option value="">All markets</option>
                        {platforms.map((platform) => (
                            <option key={platform.id} value={platform.id}>
                                {platform.name} {platform.country ? `· ${platform.country}` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block">
                    <span className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Source</span>
                    <select
                        value={source}
                        onChange={(e) => onSourceChange(e.target.value)}
                        className="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    >
                        <option value="all">Live + generated</option>
                        <option value="accepted">Accepted generated</option>
                        <option value="generated">All generated</option>
                        <option value="live">Live client bios</option>
                    </select>
                </label>
                <div>
                    <span className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Sample</span>
                    <div className="mt-1 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-800">
                        {summary.sample_size || 0} bios
                    </div>
                </div>
            </div>

            {error ? (
                <div className="mt-4 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                    Could not run the quality audit.
                </div>
            ) : null}

            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                <MetricTile label="Quality" value={summary.quality_score ?? 0} suffix="/100" band={summary.quality_band} />
                <MetricTile label="AI-likeness" value={summary.ai_likeness_score ?? 0} suffix="/100" dangerHigh />
                <MetricTile label="Repetition" value={summary.repetition_score ?? 0} suffix="/100" dangerHigh />
                <MetricTile label="Slop patterns" value={summary.slop_score ?? 0} suffix="/100" dangerHigh />
            </div>

            {detailRows.length > 0 ? (
                <div className="mt-4 overflow-hidden rounded-md border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
                            <tr>
                                <th className="px-3 py-2 text-left font-semibold">Market</th>
                                <th className="px-3 py-2 text-left font-semibold">Score</th>
                                <th className="px-3 py-2 text-left font-semibold">AI-like</th>
                                <th className="px-3 py-2 text-left font-semibold">Name/Age</th>
                                <th className="px-3 py-2 text-left font-semibold">Top issue</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {detailRows.map((row) => {
                                const topIssue = row.top_slop_flags?.[0]?.label || row.top_repetition_flags?.[0]?.label || 'No major pattern';
                                return (
                                    <tr key={row.platform_id || 'summary'}>
                                        <td className="px-3 py-2 font-medium text-slate-800">{row.platform_name || row.country || 'All markets'}</td>
                                        <td className="px-3 py-2"><QualityPill score={row.quality_score} band={row.quality_band} /></td>
                                        <td className="px-3 py-2 text-slate-700">{row.ai_likeness_score}/100</td>
                                        <td className="px-3 py-2 text-slate-700">
                                            {formatPercent(row.metrics?.name_intro_rate)} / {formatPercent(row.metrics?.age_mention_rate)}
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">{topIssue}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {issueSummary ? (
                <div className="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-3">
                    <CorpusOveruseList title="Overused words" items={issueSummary.corpus_overuse?.words || []} />
                    <CorpusOveruseList title="Overused phrases" items={issueSummary.corpus_overuse?.phrases || []} />
                    <CorpusOveruseList title="Repeated openings" items={issueSummary.corpus_overuse?.openings || []} />
                </div>
            ) : null}

            {issueSummary ? (
                <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <IssueList title="Repeated structures" items={issueSummary.top_repetition_flags || []} />
                    <IssueList title="AI-ish patterns" items={issueSummary.top_slop_flags || []} />
                </div>
            ) : null}

            {issueSummary?.examples?.length ? (
                <div className="mt-3 rounded-md border border-slate-200 bg-white p-3">
                    <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Example snippets</h4>
                    <ul className="mt-2 space-y-2">
                        {issueSummary.examples.slice(0, 4).map((example, index) => (
                            <li key={`${example.client_id || example.feedback_id || 'example'}-${index}`} className="text-sm text-slate-600">
                                <span className="font-semibold text-slate-700">{example.issue}:</span> {example.snippet}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {!selectedPlatformId ? (
                <p className="mt-3 text-xs text-slate-500">
                    Select a single market to stage quality-recovery profiles into the Optimizer approval queue.
                </p>
            ) : null}
        </section>
    );
}

function MetricTile({ label, value, suffix = '', band, dangerHigh = false }) {
    const numeric = Number(value || 0);
    const color = dangerHigh
        ? (numeric >= 65 ? 'text-rose-700' : numeric >= 35 ? 'text-amber-700' : 'text-emerald-700')
        : (numeric >= 80 ? 'text-emerald-700' : numeric >= 60 ? 'text-amber-700' : 'text-rose-700');

    return (
        <div className="rounded-md border border-slate-200 bg-white p-3">
            <div className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-bold ${color}`}>{numeric}{suffix}</div>
            {band ? <div className="mt-1 text-xs text-slate-500">{band}</div> : null}
        </div>
    );
}

function QualityPill({ score = 0, band = 'none' }) {
    const numeric = Number(score || 0);
    const color = numeric >= 80
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
        : numeric >= 60
            ? 'bg-amber-50 text-amber-700 ring-amber-200'
            : 'bg-rose-50 text-rose-700 ring-rose-200';

    return (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ${color}`}>
            {numeric}/100 {band}
        </span>
    );
}

function IssueList({ title, items }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
            <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{title}</h4>
            {items.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">No strong pattern detected.</p>
            ) : (
                <ul className="mt-2 space-y-1.5">
                    {items.slice(0, 8).map((item, index) => (
                        <li key={`${item.label || item.term}-${index}`} className="flex items-center justify-between gap-3 text-sm">
                            <span className="min-w-0 truncate text-slate-700">{item.label || item.term}</span>
                            <span className="shrink-0 text-xs font-semibold text-slate-500">
                                {item.rate != null ? formatPercent(item.rate) : formatPercent(item.corpus_rate)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function CorpusOveruseList({ title, items }) {
    return (
        <div className="rounded-md border border-slate-200 bg-white p-3">
            <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{title}</h4>
            {items.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">No repeated term crossed the threshold.</p>
            ) : (
                <ul className="mt-2 space-y-1.5">
                    {items.slice(0, 10).map((item) => (
                        <li key={`${item.type}-${item.term}`} className="grid grid-cols-[1fr_auto_auto] items-center gap-2 text-sm">
                            <span className="min-w-0 truncate font-medium text-slate-700">{item.term}</span>
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                                {item.corpus_hits} bios
                            </span>
                            <span className="text-xs font-semibold text-slate-500">{formatPercent(item.corpus_rate)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function formatPercent(value) {
    const numeric = Number(value || 0);
    return `${Math.round(numeric * 100)}%`;
}

function providerPayload(provider = {}) {
    const chain = normalizeModelChain([provider.model, ...(provider.fallbackModels || [])]);

    return {
        api_key: provider.apiKey || SENTINEL,
        model: chain[0] || provider.model,
        fallback_models: chain.slice(1),
    };
}

function normalizeModelChain(models = []) {
    const seen = new Set();
    const chain = [];

    models.forEach((model) => {
        const trimmed = String(model || '').trim();
        if (!trimmed || seen.has(trimmed)) return;
        seen.add(trimmed);
        chain.push(trimmed);
    });

    return chain;
}

function ProviderModelField({ provider, value, fallbackModels = [], onChange, onModelChainChange }) {
    const presets = MODEL_PRESETS[provider] || [];
    const [customModel, setCustomModel] = useState('');

    if (presets.length === 0) {
        return (
            <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">Model</label>
                <input
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="w-full text-sm rounded-md border-slate-300 focus:border-teal-500 focus:ring-teal-500"
                />
            </div>
        );
    }

    const chain = normalizeModelChain([value, ...fallbackModels]);
    const displayChain = chain.length > 0 ? chain : [presets[0].value];

    const applyChain = (models) => {
        onModelChainChange(normalizeModelChain(models));
    };

    const addModel = (model) => {
        const trimmed = String(model || '').trim();
        if (!trimmed) return;
        applyChain([...displayChain, trimmed]);
        setCustomModel('');
    };

    const moveModel = (index, direction) => {
        const next = [...displayChain];
        const swap = direction === 'up' ? index - 1 : index + 1;
        if (swap < 0 || swap >= next.length) return;
        [next[index], next[swap]] = [next[swap], next[index]];
        applyChain(next);
    };

    const removeModel = (model) => {
        const next = displayChain.filter((item) => item !== model);
        applyChain(next.length > 0 ? next : [presets[0].value]);
    };

    return (
        <div>
            <label className="block text-xs font-medium text-slate-700 mb-1">Model priority</label>
            <div className="rounded-md border border-slate-200 bg-slate-50 p-2">
                <div className="flex flex-wrap gap-1.5">
                    {presets.map((preset) => {
                        const active = displayChain.includes(preset.value);
                        return (
                            <button
                                key={preset.value}
                                type="button"
                                onClick={() => addModel(preset.value)}
                                disabled={active}
                                title={preset.hint}
                                className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold transition ${
                                    active
                                        ? 'border-teal-300 bg-teal-50 text-teal-800'
                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                }`}
                            >
                                {active ? 'Added: ' : 'Add '}
                                {preset.label}
                            </button>
                        );
                    })}
                </div>

                <ol className="mt-2 space-y-1.5">
                    {displayChain.map((model, index) => (
                        <li key={model} className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-2 py-1.5">
                            <span className="w-16 shrink-0 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                {index === 0 ? 'Primary' : `Fallback ${index}`}
                            </span>
                            <span className="min-w-0 flex-1 truncate font-mono text-xs text-slate-800">{model}</span>
                            <button
                                type="button"
                                onClick={() => moveModel(index, 'up')}
                                disabled={index === 0}
                                className="text-xs text-slate-500 hover:text-slate-800 disabled:opacity-30"
                                aria-label={`Move ${model} up`}
                            >
                                ↑
                            </button>
                            <button
                                type="button"
                                onClick={() => moveModel(index, 'down')}
                                disabled={index === displayChain.length - 1}
                                className="text-xs text-slate-500 hover:text-slate-800 disabled:opacity-30"
                                aria-label={`Move ${model} down`}
                            >
                                ↓
                            </button>
                            <button
                                type="button"
                                onClick={() => removeModel(model)}
                                disabled={displayChain.length === 1}
                                className="text-[11px] font-semibold text-rose-600 hover:text-rose-700 disabled:opacity-30"
                            >
                                Remove
                            </button>
                        </li>
                    ))}
                </ol>

                <div className="mt-2 flex gap-2">
                    <input
                        type="text"
                        value={customModel}
                        onChange={(e) => setCustomModel(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                addModel(customModel);
                            }
                        }}
                        placeholder="Custom model ID, e.g. deepseek-chat"
                        className="min-w-0 flex-1 text-xs rounded-md border-slate-300 focus:border-teal-500 focus:ring-teal-500"
                    />
                    <button
                        type="button"
                        onClick={() => addModel(customModel)}
                        disabled={!customModel.trim() || displayChain.includes(customModel.trim())}
                        className="rounded-md border border-slate-300 bg-white px-3 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-40"
                    >
                        Add model
                    </button>
                </div>
                <p className="mt-2 text-[11px] text-slate-500">
                    The first model is tried first. If it errors or returns empty text, DeepSeek tries each fallback in order before template fallback.
                </p>
            </div>
        </div>
    );
}

/**
 * Inline balance row for a provider card. Shows credit balance for providers
 * that expose one (DeepSeek), and a neutral "Not available" state otherwise.
 */
function ProviderBalanceRow({ provider, balance, refreshing, onRefresh, canFetch }) {
    if (!canFetch) {
        return null;
    }

    const renderBody = () => {
        if (!balance) {
            return <span className="text-slate-500">Click "Check" to fetch credit balance.</span>;
        }
        if (balance.supported === false) {
            return <span className="text-slate-500">{balance.error || 'Not exposed by this provider.'}</span>;
        }
        if (balance.error) {
            return <span className="text-rose-700">{balance.error}</span>;
        }
        if (balance.balance != null) {
            const value = Number(balance.balance);
            const formatted = Number.isFinite(value) ? value.toFixed(2) : balance.balance;
            const low = Number.isFinite(value) && value < 1;
            return (
                <span className="flex flex-wrap items-baseline gap-2">
                    <span className={`text-base font-bold ${low ? 'text-rose-700' : 'text-emerald-700'}`}>
                        {balance.currency || 'USD'} {formatted}
                    </span>
                    {balance.granted ? (
                        <span className="text-slate-500">granted {balance.granted}</span>
                    ) : null}
                    {balance.topped_up ? (
                        <span className="text-slate-500">topped-up {balance.topped_up}</span>
                    ) : null}
                    {balance.is_available === false ? (
                        <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-rose-700">unavailable</span>
                    ) : null}
                </span>
            );
        }
        return <span className="text-slate-500">No balance returned.</span>;
    };

    return (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <div>
                <span className="font-semibold text-slate-700">Balance: </span>
                {renderBody()}
            </div>
            <button
                type="button"
                onClick={onRefresh}
                disabled={refreshing}
                className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50"
            >
                {refreshing ? 'Checking…' : (balance ? 'Refresh' : 'Check')}
            </button>
        </div>
    );
}
