import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

const PROVIDER_DISPLAY = {
    claude:   { label: 'Anthropic Claude', help: 'Best quality. Get a key at console.anthropic.com.' },
    openai:   { label: 'OpenAI',           help: 'GPT-4o-mini is cost-effective for bios.' },
    gemini:   { label: 'Google Gemini',    help: 'Free tier available via Google AI Studio (aistudio.google.com).' },
    deepseek: { label: 'DeepSeek',         help: 'Lowest cost. Get a key at platform.deepseek.com.' },
};

const SENTINEL = '__keep__';


function hydrateProvider(provider = {}) {
    const incomingKey = provider?.api_key || '';
    return {
        apiKey: incomingKey === SENTINEL ? '' : incomingKey,
        model: provider?.model || '',
        hasKey: !!provider?.has_key,
        preview: provider?.key_preview || '',
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

    // Hydrate form when settings load
    useEffect(() => {
        if (settingsQuery.data?.config && !form) {
            const cfg = settingsQuery.data.config;
            setForm({
                enabled: !!cfg.enabled,
                platformAllowlist: cfg.platform_allowlist || [],
                providersOrder: cfg.providers_order || ['gemini', 'claude', 'openai', 'deepseek'],
                providers: {
                    claude:   hydrateProvider(cfg.providers?.claude),
                    openai:   hydrateProvider(cfg.providers?.openai),
                    gemini:   hydrateProvider(cfg.providers?.gemini),
                    deepseek: hydrateProvider(cfg.providers?.deepseek),
                },
            });
        }
    }, [settingsQuery.data, form]);

    const saveMutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/seo-engine', payload).then((r) => r.data),
        onSuccess: () => {
            toast.success('SEO Engine settings saved.');
            queryClient.invalidateQueries({ queryKey: ['seo-engine-settings'] });
            // Reset hasKey state on next render so the placeholder updates
            setForm(null);
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
                claude:   { api_key: form.providers.claude.apiKey   || SENTINEL, model: form.providers.claude.model },
                openai:   { api_key: form.providers.openai.apiKey   || SENTINEL, model: form.providers.openai.model },
                gemini:   { api_key: form.providers.gemini.apiKey   || SENTINEL, model: form.providers.gemini.model },
                deepseek: { api_key: form.providers.deepseek.apiKey || SENTINEL, model: form.providers.deepseek.model },
            },
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
                                    <div>
                                        <label className="block text-xs font-medium text-slate-700 mb-1">Model</label>
                                        <input
                                            type="text"
                                            value={p.model}
                                            onChange={(e) => updateProvider(provider, 'model', e.target.value)}
                                            className="w-full text-sm rounded-md border-slate-300 focus:border-teal-500 focus:ring-teal-500"
                                        />
                                    </div>
                                </div>
                                {testResult && (
                                    <div className={`mt-3 text-xs rounded-md p-2 ${testResult.success ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'}`}>
                                        {testResult.success
                                            ? <>✓ Reply: <span className="font-mono">{testResult.text}</span> ({testResult.input_tokens}→{testResult.output_tokens} tokens)</>
                                            : <>✗ {testResult.error}</>
                                        }
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </section>

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
