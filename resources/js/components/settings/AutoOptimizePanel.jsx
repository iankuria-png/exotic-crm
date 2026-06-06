import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { useAutoOptimizeMutations, useAutoOptimizePlans } from '../../hooks/useAutoOptimize';
import AutoOptimizeGenerationCard from './AutoOptimizeGenerationCard';

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ─── Default plan template ────────────────────────────────────────────────

const DEFAULT_TEMPLATE = {
    name: 'Auto Optimize',
    enabled: false,
    autopilot: false,
    criteria: {
        max_score: 60,
        views_below_market_pct: 80,
        contact_rate_below_market_pct: 80,
        require_below: 'any',
        min_market_sample: 10,
        only_published: true,
        eligibility_window_days: 30,
    },
    actions: {
        optimize_bio: true,
        switch_main_image: false,
        generation: {},
    },
    schedule: {
        active_days: [1, 2, 3, 4, 5, 6, 7],
        window_start: '02:00',
        window_end: '06:00',
        daily_limit: 20,
        runway_threshold: 0,
    },
    reliability: {
        exclude_optimized_within_days: 14,
        impact_recheck_days: 7,
        min_score_gain: 3,
        max_writes_per_hour: 60,
        retry_attempts: 3,
        language_confidence: 0.70,
        similarity_lookback_days: 30,
        max_similarity_distance: 6,
    },
};

function Section({ title, description, children }) {
    return (
        <section className="space-y-4">
            <div>
                <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                {description && <p className="mt-0.5 text-[11px] text-slate-500">{description}</p>}
            </div>
            {children}
        </section>
    );
}

function Field({ label, help, children }) {
    return (
        <div className="space-y-1">
            <label className="block text-xs font-semibold text-slate-600">{label}</label>
            {help && <p className="text-[11px] text-slate-400">{help}</p>}
            {children}
        </div>
    );
}

function NumericField({ label, help, min, max, value, onChange, placeholder }) {
    return (
        <Field label={label} help={help}>
            <input type="number" min={min} max={max} value={value ?? ''} placeholder={placeholder}
                onChange={(e) => onChange(e.target.value ? Number(e.target.value) : undefined)}
                className="crm-input w-full text-xs" />
        </Field>
    );
}

export default function AutoOptimizePanel() {
    const toast = useToast();
    const qc = useQueryClient();

    // Load platforms
    const platformsQuery = useQuery({
        queryKey: ['platforms-list'],
        queryFn: () => api.get('/crm/platforms').then((r) => r.data.data ?? r.data ?? []),
    });

    // Load auto-optimize plans
    const plansQuery = useAutoOptimizePlans();
    const { savePlan, togglePlan, autopilotPlan } = useAutoOptimizeMutations();

    const platforms = platformsQuery.data ?? [];
    const plans = plansQuery.data ?? [];

    const [selectedPlatformId, setSelectedPlatformId] = useState(null);
    const [form, setForm] = useState(null);
    const [weightsError, setWeightsError] = useState(null);

    // SEO engine global defaults for inheritance hints
    const seoSettingsQuery = useQuery({
        queryKey: ['seo-engine-settings'],
        queryFn: () => api.get('/crm/settings/seo-engine').then((r) => r.data),
    });
    const globalGeneration = seoSettingsQuery.data?.config?.generation ?? {};

    // When platform is selected, load or scaffold plan form
    useEffect(() => {
        if (!selectedPlatformId) { setForm(null); return; }
        const existing = plans.find((p) => p.platform_id === selectedPlatformId);
        if (existing) {
            setForm({
                id: existing.id,
                ...DEFAULT_TEMPLATE,
                ...existing,
                criteria: { ...DEFAULT_TEMPLATE.criteria, ...(existing.criteria ?? {}) },
                actions: { ...DEFAULT_TEMPLATE.actions, ...(existing.actions ?? {}) },
                schedule: { ...DEFAULT_TEMPLATE.schedule, ...(existing.schedule ?? {}) },
                reliability: { ...DEFAULT_TEMPLATE.reliability, ...(existing.reliability ?? {}) },
            });
        } else {
            setForm({ ...DEFAULT_TEMPLATE, platform_id: selectedPlatformId });
        }
    }, [selectedPlatformId, plans]);

    const handleSave = () => {
        if (!form) return;

        // Validate scorer weights if set
        const weights = form.actions?.generation?.scorer_weights;
        if (weights) {
            const required = ['word_count', 'links', 'completeness', 'media'];
            const missing = required.filter((k) => !(k in weights));
            const total = Object.values(weights).reduce((s, v) => s + Number(v), 0);
            if (missing.length > 0 || total !== 100) {
                setWeightsError(`scorer_weights must contain all four keys and total 100 (got ${total}).`);
                return;
            }
        }
        setWeightsError(null);

        savePlan.mutate({ ...form, platform_id: selectedPlatformId });
    };

    const updateField = (path, value) => {
        setForm((prev) => {
            const parts = path.split('.');
            if (parts.length === 1) return { ...prev, [path]: value };
            if (parts.length === 2) return { ...prev, [parts[0]]: { ...(prev[parts[0]] ?? {}), [parts[1]]: value } };
            if (parts.length === 3) return {
                ...prev,
                [parts[0]]: {
                    ...(prev[parts[0]] ?? {}),
                    [parts[1]]: { ...((prev[parts[0]] ?? {})[parts[1]] ?? {}), [parts[2]]: value },
                },
            };
            return prev;
        });
    };

    return (
        <div className="space-y-6">
            {/* Market selector */}
            <section className="crm-surface p-4">
                <h2 className="mb-3 text-sm font-semibold text-slate-800">Auto Optimize Engine</h2>
                <p className="mb-4 text-xs text-slate-500">
                    Configure per-market optimization plans. Each market has its own eligibility criteria, bio generation settings, and scheduling.
                </p>
                <Field label="Select a market to configure">
                    <select
                        value={selectedPlatformId ?? ''}
                        onChange={(e) => setSelectedPlatformId(e.target.value ? Number(e.target.value) : null)}
                        className="crm-input w-full max-w-xs text-sm"
                    >
                        <option value="">— Choose market —</option>
                        {platforms.map((p) => {
                            const hasPlan = plans.some((pl) => pl.platform_id === p.id);
                            return (
                                <option key={p.id} value={p.id}>
                                    {p.name} {p.country ? `(${p.country})` : ''}{hasPlan ? ' ✓' : ''}
                                </option>
                            );
                        })}
                    </select>
                </Field>
            </section>

            {/* Plan editor */}
            {form && (
                <div className="space-y-6">
                    {/* Master toggles */}
                    <section className="crm-surface p-4 space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-800">Engine</h3>
                                <p className="text-[11px] text-slate-500">Enable to include this market in the daily optimization runs.</p>
                            </div>
                            <label className="relative inline-flex cursor-pointer items-center gap-3">
                                <input type="checkbox" checked={!!form.enabled}
                                    onChange={(e) => updateField('enabled', e.target.checked)}
                                    className="sr-only" />
                                <div className={`h-6 w-11 rounded-full transition ${form.enabled ? 'bg-teal-500' : 'bg-slate-300'}`}>
                                    <div className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform ${form.enabled ? 'translate-x-5' : ''}`} />
                                </div>
                                <span className="text-sm font-medium text-slate-700">{form.enabled ? 'Enabled' : 'Disabled'}</span>
                            </label>
                        </div>

                        <div className="flex items-center justify-between border-t border-slate-100 pt-4">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-800">Autopilot</h3>
                                <p className="text-[11px] text-slate-500">Auto-apply optimizations without human approval. When off, changes queue for review.</p>
                            </div>
                            <label className="relative inline-flex cursor-pointer items-center gap-3">
                                <input type="checkbox" checked={!!form.autopilot}
                                    onChange={(e) => updateField('autopilot', e.target.checked)}
                                    className="sr-only" />
                                <div className={`h-6 w-11 rounded-full transition ${form.autopilot ? 'bg-amber-500' : 'bg-slate-300'}`}>
                                    <div className={`absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform ${form.autopilot ? 'translate-x-5' : ''}`} />
                                </div>
                                <span className="text-sm font-medium text-slate-700">{form.autopilot ? 'Auto-apply' : 'Approval required'}</span>
                            </label>
                        </div>
                    </section>

                    {/* Eligibility criteria */}
                    <section className="crm-surface p-4">
                        <Section title="Eligibility criteria" description="A profile must meet these thresholds to be queued for optimization.">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <NumericField label="Max SEO score" help="Profiles with score ≤ this are eligible." min={0} max={100}
                                    value={form.criteria?.max_score} onChange={(v) => updateField('criteria.max_score', v)} />
                                <NumericField label="Views below market %" help="Views below N% of market average triggers eligibility." min={10} max={100}
                                    value={form.criteria?.views_below_market_pct} onChange={(v) => updateField('criteria.views_below_market_pct', v)} />
                                <NumericField label="Contact rate below %" min={10} max={100}
                                    value={form.criteria?.contact_rate_below_market_pct} onChange={(v) => updateField('criteria.contact_rate_below_market_pct', v)} />
                                <NumericField label="Min market sample" help="Minimum profiles needed for valid market average." min={1} max={1000}
                                    value={form.criteria?.min_market_sample} onChange={(v) => updateField('criteria.min_market_sample', v)} />
                                <NumericField label="Eligibility window (days)" help="Analytics window used to measure performance." min={1} max={90}
                                    value={form.criteria?.eligibility_window_days} onChange={(v) => updateField('criteria.eligibility_window_days', v)} />
                                <Field label="Match mode" help="Whether 'any' or 'all' analytics criteria must match.">
                                    <select value={form.criteria?.require_below ?? 'any'}
                                        onChange={(e) => updateField('criteria.require_below', e.target.value)}
                                        className="crm-input w-full text-xs">
                                        <option value="any">Any threshold</option>
                                        <option value="all">All thresholds</option>
                                    </select>
                                </Field>
                            </div>
                            <label className="flex items-center gap-2 text-xs font-medium text-slate-600 mt-2">
                                <input type="checkbox" checked={!!form.criteria?.only_published}
                                    onChange={(e) => updateField('criteria.only_published', e.target.checked)}
                                    className="h-3.5 w-3.5 rounded text-teal-600" />
                                Only optimize published profiles
                            </label>
                        </Section>
                    </section>

                    {/* Actions */}
                    <section className="crm-surface p-4">
                        <Section title="Actions" description="What the engine does when a profile qualifies.">
                            <div className="space-y-2">
                                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" checked={!!form.actions?.optimize_bio}
                                        onChange={(e) => updateField('actions.optimize_bio', e.target.checked)}
                                        className="h-4 w-4 rounded text-teal-600" />
                                    Optimize bio (generate new SEO bio)
                                </label>
                                <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <input type="checkbox" checked={!!form.actions?.switch_main_image}
                                        onChange={(e) => updateField('actions.switch_main_image', e.target.checked)}
                                        className="h-4 w-4 rounded text-teal-600" />
                                    Switch main image
                                    <span className="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Requires WP plugin P1</span>
                                </label>
                            </div>
                            {form.actions?.optimize_bio && (
                                <div className="mt-4">
                                    <AutoOptimizeGenerationCard
                                        value={form.actions?.generation ?? {}}
                                        globalDefaults={globalGeneration}
                                        onChange={(gen) => updateField('actions.generation', gen)}
                                    />
                                </div>
                            )}
                        </Section>
                    </section>

                    {/* Schedule */}
                    <section className="crm-surface p-4">
                        <Section title="Schedule" description="When the engine is allowed to run for this market.">
                            <div>
                                <p className="mb-2 text-xs font-semibold text-slate-600">Active days</p>
                                <div className="flex flex-wrap gap-2">
                                    {DAYS.map((day, i) => {
                                        const iso = i + 1;
                                        const active = (form.schedule?.active_days ?? []).includes(iso);
                                        return (
                                            <button key={iso} type="button"
                                                onClick={() => {
                                                    const days = form.schedule?.active_days ?? [];
                                                    updateField('schedule.active_days',
                                                        active ? days.filter((d) => d !== iso) : [...days, iso].sort()
                                                    );
                                                }}
                                                className={`rounded-md border px-2.5 py-1 text-xs font-medium transition ${active ? 'border-teal-400 bg-teal-50 text-teal-700' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'}`}
                                            >
                                                {day}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mt-3">
                                <Field label="Window start (market time)">
                                    <input type="time" value={form.schedule?.window_start ?? '02:00'}
                                        onChange={(e) => updateField('schedule.window_start', e.target.value)}
                                        className="crm-input w-full text-xs" />
                                </Field>
                                <Field label="Window end">
                                    <input type="time" value={form.schedule?.window_end ?? '06:00'}
                                        onChange={(e) => updateField('schedule.window_end', e.target.value)}
                                        className="crm-input w-full text-xs" />
                                </Field>
                                <NumericField label="Daily limit" help="Max profiles queued per run." min={1} max={500}
                                    value={form.schedule?.daily_limit} onChange={(v) => updateField('schedule.daily_limit', v)} />
                            </div>
                        </Section>
                    </section>

                    {/* Safety / reliability */}
                    <section className="crm-surface p-4">
                        <Section title="Safety & reliability">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <NumericField label="Min score gain" help="Bio only applies when new score − old score ≥ this." min={0} max={50}
                                    value={form.reliability?.min_score_gain} onChange={(v) => updateField('reliability.min_score_gain', v)} />
                                <NumericField label="Max WP writes / hour" help="Counts bio, score, image writes. Includes compensations." min={1} max={500}
                                    value={form.reliability?.max_writes_per_hour} onChange={(v) => updateField('reliability.max_writes_per_hour', v)} />
                                <NumericField label="Exclude re-optimize (days)" help="Skip clients optimized within N days." min={0} max={90}
                                    value={form.reliability?.exclude_optimized_within_days} onChange={(v) => updateField('reliability.exclude_optimized_within_days', v)} />
                                <NumericField label="Impact recheck (days)" help="Re-read analytics N days after apply for impact measurement." min={1} max={30}
                                    value={form.reliability?.impact_recheck_days} onChange={(v) => updateField('reliability.impact_recheck_days', v)} />
                                <NumericField label="Retry attempts" min={1} max={10}
                                    value={form.reliability?.retry_attempts} onChange={(v) => updateField('reliability.retry_attempts', v)} />
                                <NumericField label="Language confidence" help="Minimum confidence (0–1) to use detected bio language." min={0} max={1}
                                    value={form.reliability?.language_confidence} onChange={(v) => updateField('reliability.language_confidence', v)} />
                            </div>
                        </Section>
                    </section>

                    {/* Validation errors */}
                    {weightsError && (
                        <div className="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            {weightsError}
                        </div>
                    )}

                    {/* Save */}
                    <div className="sticky bottom-4 flex items-center justify-between gap-3">
                        <p className="text-xs text-slate-400">
                            {form.id ? 'Editing existing plan' : 'Creating new plan'} · Platform #{selectedPlatformId}
                        </p>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={savePlan.isPending}
                            className="rounded-md bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            {savePlan.isPending ? 'Saving…' : 'Save Auto Optimize settings'}
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
