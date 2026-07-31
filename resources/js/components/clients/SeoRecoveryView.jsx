import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';
import MetricCard from '../MetricCard';

// ─── Constants ───────────────────────────────────────────────────────────────

const HISTORY_MODES = [
    {
        value: 'paid_history',
        label: 'Paid history only',
        tagline: 'Recommended',
        blurb: 'Profiles that were genuinely live and paid for — a completed payment or an activated deal. Recovers pages that really existed and really ranked.',
        tone: 'recommended',
    },
    {
        value: 'previously_published',
        label: 'Ever been live',
        tagline: 'Wider net',
        blurb: 'Anything with evidence it was once live — activated, has a deal, or churned. Includes lapsed free trials.',
        tone: 'neutral',
    },
    {
        value: 'any_wp_profile',
        label: 'Every offline profile',
        tagline: 'Creates new content',
        blurb: 'Every profile with a WordPress post, including ones that were never live. This publishes pages that never existed before — it is not recovery.',
        tone: 'warning',
    },
];

const SAFETY_TOGGLES = [
    { key: 'exclude_high_risk', label: 'Exclude high-risk profiles', blurb: 'Anyone flagged as high risk stays offline.' },
    { key: 'exclude_duplicates', label: 'Exclude duplicates', blurb: 'Profiles marked as a duplicate of another record.' },
    { key: 'exclude_bad_close_reasons', label: 'Exclude problem closures', blurb: 'Closed as inappropriate, invalid contact, duplicate, or unresolved payment.' },
];

const QUALITY_TOGGLES = [
    { key: 'require_image', label: 'Require at least one image', blurb: 'Skip profiles with no main or display image.' },
    { key: 'require_verified', label: 'Require verified profiles', blurb: 'Most legacy profiles predate KYC — this will shrink the cohort sharply.' },
];

const PACING_MODES = [
    { value: 'manual_capped', label: 'Manual, capped', blurb: 'Nothing runs unless you press Run. Each batch is limited to the cap below.' },
    { value: 'daily_trickle', label: 'Daily trickle', blurb: 'A scheduled quota every night until the backlog clears. Gentlest on the market site and on Google.' },
    { value: 'unrestricted', label: 'Unrestricted', blurb: 'Manual runs with the cap lifted — the whole eligible set in one batch.' },
];

const DEFAULT_FILTERS = {
    history_mode: 'paid_history',
    exclude_high_risk: true,
    exclude_duplicates: true,
    exclude_bad_close_reasons: true,
    require_verified: false,
    require_image: false,
    min_seo_score: null,
    expired_within_months: null,
};

const RUN_STATUS_TONE = {
    queued: 'bg-slate-100 text-slate-700 ring-slate-200',
    running: 'bg-sky-50 text-sky-700 ring-sky-200',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
    reverted: 'bg-amber-50 text-amber-700 ring-amber-200',
};

const STATE_TONE = {
    expired: 'bg-amber-50 text-amber-700 ring-amber-200',
    archived: 'bg-slate-100 text-slate-600 ring-slate-200',
};

const EXPIRY_SOURCE_LABELS = {
    deal: 'Deal expiry',
    payment: 'Payment end date',
    churned_at: 'Churn date',
    updated_at: 'Last updated (estimate)',
};

const POLL_STATUSES = new Set(['queued', 'running']);

// ─── Small presentational pieces ─────────────────────────────────────────────

function Pill({ children, tone = 'bg-slate-100 text-slate-700 ring-slate-200' }) {
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${tone}`}>
            {children}
        </span>
    );
}

function Toggle({ checked, onChange, label, blurb, disabled = false }) {
    return (
        <label className={`flex items-start gap-3 rounded-lg border p-3 transition ${
            disabled
                ? 'cursor-not-allowed border-slate-200 bg-slate-50 opacity-60'
                : 'cursor-pointer border-slate-200 bg-white hover:border-teal-300 hover:bg-teal-50/30'
        }`}>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
            />
            <span className="min-w-0">
                <span className="block text-sm font-medium text-slate-800">{label}</span>
                <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{blurb}</span>
            </span>
        </label>
    );
}

function SectionCard({ step, title, subtitle, children, actions }) {
    return (
        <section className="crm-surface overflow-hidden rounded-xl border border-slate-200">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 bg-slate-50/60 px-5 py-3.5">
                <div className="min-w-0">
                    <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        {step ? (
                            <span className="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-teal-700 text-[11px] font-bold text-white">
                                {step}
                            </span>
                        ) : null}
                        {title}
                    </h3>
                    {subtitle ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{subtitle}</p> : null}
                </div>
                {actions}
            </header>
            <div className="p-5">{children}</div>
        </section>
    );
}

function EmptyState({ icon, title, blurb }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50/50 px-6 py-10 text-center">
            <span className="text-2xl" aria-hidden="true">{icon}</span>
            <p className="mt-2 text-sm font-medium text-slate-700">{title}</p>
            <p className="mt-1 max-w-sm text-xs leading-relaxed text-slate-500">{blurb}</p>
        </div>
    );
}

function ErrorState({ message, onRetry }) {
    return (
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3">
            <p className="text-sm font-medium text-rose-800">Something went wrong</p>
            <p className="mt-1 text-xs leading-relaxed text-rose-700">{message}</p>
            {onRetry ? (
                <button type="button" onClick={onRetry} className="mt-2 text-xs font-semibold text-rose-800 underline underline-offset-2">
                    Try again
                </button>
            ) : null}
        </div>
    );
}

function SkeletonRows({ rows = 4, cols = 5 }) {
    return (
        <div className="space-y-2" aria-hidden="true">
            {Array.from({ length: rows }).map((_, rowIndex) => (
                <div key={rowIndex} className="flex gap-3">
                    {Array.from({ length: cols }).map((__, colIndex) => (
                        <div key={colIndex} className="h-8 flex-1 animate-pulse rounded bg-slate-100" />
                    ))}
                </div>
            ))}
        </div>
    );
}

// ─── Main view ───────────────────────────────────────────────────────────────

export default function SeoRecoveryView({ platformId, platforms = [], marketName }) {
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const [selectedPlatform, setSelectedPlatform] = useState(platformId ? String(platformId) : '');
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [batchLimit, setBatchLimit] = useState(200);
    const [targetState, setTargetState] = useState('');
    const [pacingMode, setPacingMode] = useState('manual_capped');
    const [dailyQuota, setDailyQuota] = useState(50);
    const [previewToken, setPreviewToken] = useState(null);
    const [confirmRun, setConfirmRun] = useState(false);
    const [revertTarget, setRevertTarget] = useState(null);
    const [cohortRunFilter, setCohortRunFilter] = useState('');
    const [activeRunId, setActiveRunId] = useState(null);

    useEffect(() => {
        if (platformId) {
            setSelectedPlatform(String(platformId));
        }
    }, [platformId]);

    // Any configuration change invalidates the dry run — Run must never fire
    // against a preview the admin has not actually seen.
    useEffect(() => {
        setPreviewToken(null);
    }, [selectedPlatform, filters, batchLimit, targetState]);

    const hasMarket = Boolean(selectedPlatform);

    const eligibilityQuery = useQuery({
        queryKey: ['lifecycle-restore', 'eligibility', selectedPlatform, filters, batchLimit, targetState],
        enabled: hasMarket,
        queryFn: async () => {
            const { data } = await api.get('/crm/lifecycle-restore/eligibility', {
                params: {
                    platform_id: selectedPlatform,
                    batch_limit: batchLimit,
                    target_state: targetState || undefined,
                    filters,
                },
            });
            return data;
        },
    });

    const runsQuery = useQuery({
        queryKey: ['lifecycle-restore', 'runs', selectedPlatform],
        enabled: hasMarket,
        queryFn: async () => {
            const { data } = await api.get('/crm/lifecycle-restore/runs', {
                params: { platform_id: selectedPlatform },
            });
            return data.data ?? [];
        },
        // Keep the progress indicator honest while a batch is in flight.
        refetchInterval: (query) => (query.state.data ?? []).some((run) => POLL_STATUSES.has(run.status)) ? 3000 : false,
    });

    const cohortQuery = useQuery({
        queryKey: ['lifecycle-restore', 'cohort', selectedPlatform, cohortRunFilter],
        enabled: hasMarket,
        queryFn: async () => {
            const { data } = await api.get('/crm/lifecycle-restore/cohort', {
                params: {
                    platform_id: selectedPlatform,
                    run_id: cohortRunFilter || undefined,
                    per_page: 25,
                },
            });
            return data;
        },
    });

    const pacing = eligibilityQuery.data?.pacing;

    useEffect(() => {
        if (pacing?.mode) {
            setPacingMode(pacing.mode);
            setDailyQuota(Number(pacing.daily_quota ?? 50));
        }
    }, [pacing?.mode, pacing?.daily_quota]);

    const createRun = useMutation({
        mutationFn: async (mode) => {
            const { data } = await api.post('/crm/lifecycle-restore/runs', {
                platform_id: selectedPlatform,
                mode,
                batch_limit: batchLimit,
                target_state: targetState || undefined,
                filters,
            });
            return data.data;
        },
        onSuccess: (run, mode) => {
            setActiveRunId(run.id);
            queryClient.invalidateQueries({ queryKey: ['lifecycle-restore', 'runs', selectedPlatform] });
            toast?.success?.(mode === 'live'
                ? 'Recovery batch queued — progress updates below.'
                : 'Dry run queued.');
        },
        onError: (error) => {
            toast?.error?.(error?.response?.data?.message ?? 'Could not start the run.');
        },
    });

    const revertRun = useMutation({
        mutationFn: async (runId) => {
            const { data } = await api.post(`/crm/lifecycle-restore/runs/${runId}/revert`);
            return data;
        },
        onSuccess: (data) => {
            setRevertTarget(null);
            queryClient.invalidateQueries({ queryKey: ['lifecycle-restore'] });
            toast?.success?.(data?.message ?? 'Batch reverted.');
        },
        onError: (error) => {
            toast?.error?.(error?.response?.data?.message ?? 'Could not revert the batch.');
        },
    });

    const savePacing = useMutation({
        mutationFn: async () => {
            const { data } = await api.post('/crm/lifecycle-restore/pacing', {
                platform_id: selectedPlatform,
                mode: pacingMode,
                daily_quota: dailyQuota,
                target_state: targetState || undefined,
                filters,
            });
            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['lifecycle-restore', 'eligibility'] });
            toast?.success?.('Pacing policy saved for this market.');
        },
        onError: (error) => {
            toast?.error?.(error?.response?.data?.message ?? 'Could not save pacing.');
        },
    });

    const eligibility = eligibilityQuery.data;
    const lifecycleEnabled = eligibility?.platform?.lifecycle_enabled ?? true;
    const candidateCount = Number(eligibility?.candidate_count ?? 0);
    const willProcess = Number(eligibility?.will_process ?? 0);
    const activeRun = useMemo(
        () => (runsQuery.data ?? []).find((run) => run.id === activeRunId) ?? null,
        [runsQuery.data, activeRunId]
    );
    const isRunInFlight = Boolean(activeRun && POLL_STATUSES.has(activeRun.status));

    const marketOptions = useMemo(() => (platforms ?? []).map((platform) => ({
        value: String(platform.id ?? platform.value),
        label: platform.name ?? platform.label,
    })), [platforms]);

    const selectedMarketLabel = marketOptions.find((option) => option.value === selectedPlatform)?.label
        ?? marketName
        ?? 'this market';

    const setFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));

    const warnAboutNeverLive = filters.history_mode === 'any_wp_profile';
    const canPreview = hasMarket && lifecycleEnabled && !eligibilityQuery.isFetching;
    const canRun = Boolean(previewToken) && candidateCount > 0 && lifecycleEnabled && !isRunInFlight;

    if (!hasMarket) {
        return (
            <div className="space-y-4">
                <MarketPicker
                    options={marketOptions}
                    value={selectedPlatform}
                    onChange={setSelectedPlatform}
                />
                <EmptyState
                    icon="🔍"
                    title="Pick a market to begin"
                    blurb="SEO Recovery works one market at a time — each market is a separate WordPress site with its own backlog of offline profiles."
                />
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {/* 1 — Impact header */}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Eligible now"
                    value={eligibilityQuery.isLoading ? '—' : candidateCount.toLocaleString()}
                    hint="Match the current configuration"
                    tone="accent"
                    isLoading={eligibilityQuery.isLoading}
                />
                <MetricCard
                    label="Already recovered"
                    value={eligibilityQuery.isLoading ? '—' : Number(eligibility?.already_restored ?? 0).toLocaleString()}
                    hint="Republished by a previous batch"
                    tone="success"
                    isLoading={eligibilityQuery.isLoading}
                />
                <MetricCard
                    label="Still offline"
                    value={eligibilityQuery.isLoading ? '—' : Number(eligibility?.still_offline ?? 0).toLocaleString()}
                    hint="Private in WordPress — invisible to Google"
                    tone="warning"
                    isLoading={eligibilityQuery.isLoading}
                />
                <MetricCard
                    label="Pages this batch recovers"
                    value={eligibilityQuery.isLoading ? '—' : willProcess.toLocaleString()}
                    hint={`Capped at ${batchLimit.toLocaleString()}`}
                    tone="accent"
                    isLoading={eligibilityQuery.isLoading}
                />
            </div>

            <MarketPicker options={marketOptions} value={selectedPlatform} onChange={setSelectedPlatform} />

            {!lifecycleEnabled ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm font-semibold text-amber-900">The profile lifecycle is off for this market</p>
                    <p className="mt-1 text-xs leading-relaxed text-amber-800">
                        Recovery republishes profiles as Expired or Archived, which only works when the lifecycle is enabled.
                        Turn it on in Settings → Markets before running a batch.
                    </p>
                </div>
            ) : null}

            {eligibilityQuery.isError ? (
                <ErrorState
                    message={eligibilityQuery.error?.response?.data?.message ?? 'Could not load eligibility for this market.'}
                    onRetry={() => eligibilityQuery.refetch()}
                />
            ) : null}

            {/* 2 — Configure */}
            <SectionCard
                step="1"
                title="Configure the batch"
                subtitle="Nothing here is hardcoded — every option changes which profiles come back and how fast."
            >
                <div className="space-y-6">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Which profiles</p>
                        <div className="mt-2 grid gap-2 lg:grid-cols-3">
                            {HISTORY_MODES.map((mode) => {
                                const isActive = filters.history_mode === mode.value;
                                return (
                                    <button
                                        key={mode.value}
                                        type="button"
                                        onClick={() => setFilter('history_mode', mode.value)}
                                        aria-pressed={isActive}
                                        className={`rounded-lg border p-3.5 text-left transition ${
                                            isActive
                                                ? mode.tone === 'warning'
                                                    ? 'border-amber-400 bg-amber-50 ring-1 ring-amber-300'
                                                    : 'border-teal-500 bg-teal-50/60 ring-1 ring-teal-400'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-semibold text-slate-800">{mode.label}</span>
                                            <Pill tone={
                                                mode.tone === 'warning'
                                                    ? 'bg-amber-100 text-amber-800 ring-amber-200'
                                                    : mode.tone === 'recommended'
                                                        ? 'bg-teal-100 text-teal-800 ring-teal-200'
                                                        : 'bg-slate-100 text-slate-600 ring-slate-200'
                                            }>
                                                {mode.tagline}
                                            </Pill>
                                        </span>
                                        <span className="mt-1.5 block text-xs leading-relaxed text-slate-600">{mode.blurb}</span>
                                    </button>
                                );
                            })}
                        </div>

                        {warnAboutNeverLive ? (
                            <div className="mt-2 flex gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3.5 py-2.5">
                                <span aria-hidden="true">⚠️</span>
                                <p className="text-xs leading-relaxed text-amber-900">
                                    <strong className="font-semibold">This publishes profiles that were never live.</strong>{' '}
                                    That is new content, not recovered content — it will not restore lost rankings, and it puts
                                    real people&apos;s photos on a public page they may never have completed. Confirm the business
                                    decision before running this mode live.
                                </p>
                            </div>
                        ) : null}
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Safety</p>
                            <p className="mt-0.5 text-[11px] text-slate-400">On by default — the safe batch is the default batch.</p>
                            <div className="mt-2 space-y-2">
                                {SAFETY_TOGGLES.map((toggle) => (
                                    <Toggle
                                        key={toggle.key}
                                        checked={Boolean(filters[toggle.key])}
                                        onChange={(value) => setFilter(toggle.key, value)}
                                        label={toggle.label}
                                        blurb={toggle.blurb}
                                    />
                                ))}
                            </div>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Quality</p>
                            <p className="mt-0.5 text-[11px] text-slate-400">Optional — narrows the batch to profiles worth republishing.</p>
                            <div className="mt-2 space-y-2">
                                {QUALITY_TOGGLES.map((toggle) => (
                                    <Toggle
                                        key={toggle.key}
                                        checked={Boolean(filters[toggle.key])}
                                        onChange={(value) => setFilter(toggle.key, value)}
                                        label={toggle.label}
                                        blurb={toggle.blurb}
                                    />
                                ))}
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <NumberField
                                        label="Minimum SEO score"
                                        value={filters.min_seo_score}
                                        placeholder="Any"
                                        min={0}
                                        max={100}
                                        onChange={(value) => setFilter('min_seo_score', value)}
                                    />
                                    <NumberField
                                        label="Expired within (months)"
                                        value={filters.expired_within_months}
                                        placeholder="Any age"
                                        min={1}
                                        max={120}
                                        onChange={(value) => setFilter('expired_within_months', value)}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-4 border-t border-slate-100 pt-5 lg:grid-cols-2">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Landing state</p>
                            <p className="mt-0.5 text-[11px] leading-relaxed text-slate-400">
                                By default the real historical expiry decides: lapsed under 90 days ago returns to city listings as
                                Expired, older than that goes straight to Archived (URL still indexed, out of listings).
                            </p>
                            <select
                                value={targetState}
                                onChange={(event) => setTargetState(event.target.value)}
                                className="mt-2 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                            >
                                <option value="">Automatic — by age (recommended)</option>
                                <option value="expired">Force all to Expired</option>
                                <option value="archived">Force all to Archived</option>
                            </select>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Pacing</p>
                            <p className="mt-0.5 text-[11px] leading-relaxed text-slate-400">
                                How fast this market works through its backlog. Saved per market.
                            </p>
                            <div className="mt-2 space-y-2">
                                <select
                                    value={pacingMode}
                                    onChange={(event) => setPacingMode(event.target.value)}
                                    className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                >
                                    {PACING_MODES.map((mode) => (
                                        <option key={mode.value} value={mode.value}>{mode.label}</option>
                                    ))}
                                </select>
                                <p className="text-[11px] leading-relaxed text-slate-500">
                                    {PACING_MODES.find((mode) => mode.value === pacingMode)?.blurb}
                                </p>

                                <div className="grid gap-2 sm:grid-cols-2">
                                    {pacingMode === 'daily_trickle' ? (
                                        <NumberField
                                            label="Profiles per night"
                                            value={dailyQuota}
                                            min={1}
                                            max={5000}
                                            onChange={(value) => setDailyQuota(value ?? 1)}
                                        />
                                    ) : (
                                        <NumberField
                                            label="Batch cap"
                                            value={batchLimit}
                                            min={1}
                                            max={100000}
                                            onChange={(value) => setBatchLimit(value ?? 1)}
                                        />
                                    )}
                                    <div className="flex items-end">
                                        <button
                                            type="button"
                                            onClick={() => savePacing.mutate()}
                                            disabled={savePacing.isPending}
                                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                        >
                                            {savePacing.isPending ? 'Saving…' : 'Save pacing'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </SectionCard>

            {/* 3 — Preview */}
            <SectionCard
                step="2"
                title="Preview before you run"
                subtitle="A dry run is required — the Run button stays locked until you have seen what this configuration selects."
                actions={(
                    <button
                        type="button"
                        onClick={() => {
                            eligibilityQuery.refetch();
                            setPreviewToken(Date.now());
                            createRun.mutate('dry');
                        }}
                        disabled={!canPreview || createRun.isPending}
                        className="crm-btn-primary shrink-0 disabled:opacity-60"
                    >
                        {createRun.isPending ? 'Previewing…' : 'Run preview'}
                    </button>
                )}
            >
                {eligibilityQuery.isLoading ? (
                    <SkeletonRows rows={4} cols={6} />
                ) : candidateCount === 0 ? (
                    <EmptyState
                        icon="✅"
                        title="Nothing eligible right now"
                        blurb="No offline profiles in this market match the current configuration. Widen the history mode or relax a filter to see more."
                    />
                ) : (
                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3.5 py-2.5">
                            <span className="text-sm text-slate-700">
                                <strong className="font-semibold text-slate-900">{candidateCount.toLocaleString()}</strong> eligible
                                {candidateCount > willProcess ? (
                                    <> — this batch will process <strong className="font-semibold text-slate-900">{willProcess.toLocaleString()}</strong></>
                                ) : null}
                            </span>
                            {previewToken ? (
                                <Pill tone="bg-emerald-50 text-emerald-700 ring-emerald-200">Preview current</Pill>
                            ) : (
                                <Pill tone="bg-amber-50 text-amber-700 ring-amber-200">Configuration changed — preview again</Pill>
                            )}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                                        <th className="px-3 py-2 font-semibold">Profile</th>
                                        <th className="px-3 py-2 font-semibold">City</th>
                                        <th className="px-3 py-2 font-semibold">Recovered expiry</th>
                                        <th className="px-3 py-2 font-semibold">Dated from</th>
                                        <th className="px-3 py-2 font-semibold">Lands as</th>
                                        <th className="px-3 py-2 font-semibold">Quality</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {(eligibility?.sample ?? []).map((row) => (
                                        <tr key={row.id} className="hover:bg-slate-50/60">
                                            <td className="px-3 py-2 font-medium text-slate-800">{row.name || `Profile ${row.id}`}</td>
                                            <td className="px-3 py-2 text-slate-600">{row.city || '—'}</td>
                                            <td className="px-3 py-2 tabular-nums text-slate-600">{row.resolved_expiry}</td>
                                            <td className="px-3 py-2 text-xs text-slate-500">
                                                {EXPIRY_SOURCE_LABELS[row.expiry_source] ?? row.expiry_source}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Pill tone={STATE_TONE[row.landing_state]}>
                                                    {row.landing_state === 'expired' ? 'Expired' : 'Archived'}
                                                </Pill>
                                            </td>
                                            <td className="px-3 py-2 text-xs text-slate-500">
                                                {row.has_image ? '🖼️' : '—'}
                                                {row.seo_score !== null ? <span className="ml-1.5 tabular-nums">SEO {row.seo_score}</span> : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-[11px] text-slate-400">
                            Showing the first {(eligibility?.sample ?? []).length} of {candidateCount.toLocaleString()} eligible profiles.
                        </p>
                    </div>
                )}
            </SectionCard>

            {/* 4 — Run */}
            <SectionCard
                step="3"
                title="Run the recovery"
                subtitle="Republishes each profile in WordPress as Expired or Archived, hides its contact details, and scrubs contact info from its bio."
                actions={(
                    <button
                        type="button"
                        onClick={() => setConfirmRun(true)}
                        disabled={!canRun}
                        className="crm-btn-primary shrink-0 disabled:opacity-50"
                        title={!previewToken ? 'Run a preview first' : undefined}
                    >
                        {isRunInFlight ? 'Running…' : `Recover ${willProcess.toLocaleString()} profiles`}
                    </button>
                )}
            >
                {isRunInFlight ? (
                    <div className="space-y-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3">
                        <div className="flex items-center gap-2">
                            <span className="h-2 w-2 animate-pulse rounded-full bg-sky-500" aria-hidden="true" />
                            <p className="text-sm font-semibold text-sky-900">
                                Batch {activeRun.status === 'queued' ? 'queued' : 'in progress'}
                            </p>
                        </div>
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-sky-200">
                            <div
                                className="h-full rounded-full bg-sky-600 transition-all duration-500"
                                style={{
                                    width: `${activeRun.candidate_count > 0
                                        ? Math.min(100, Math.round((activeRun.restored_count / Math.max(activeRun.candidate_count, 1)) * 100))
                                        : 5}%`,
                                }}
                            />
                        </div>
                        <p className="text-xs text-sky-800">
                            {activeRun.restored_count.toLocaleString()} restored
                            {activeRun.failed_count > 0 ? ` · ${activeRun.failed_count} failed` : ''}
                        </p>
                    </div>
                ) : !previewToken ? (
                    <p className="text-xs leading-relaxed text-slate-500">
                        Run a preview above to unlock this step. The lock resets whenever you change the configuration.
                    </p>
                ) : (
                    <p className="text-xs leading-relaxed text-slate-500">
                        Ready. Every profile in this batch is tagged to the run, so the whole batch can be reverted in one click if it
                        does not look right on the site.
                    </p>
                )}
            </SectionCard>

            {/* 5 — Run history */}
            <SectionCard step="4" title="Run history" subtitle="Every batch, what it selected on, and a one-click undo.">
                {runsQuery.isLoading ? (
                    <SkeletonRows rows={3} cols={6} />
                ) : runsQuery.isError ? (
                    <ErrorState
                        message={runsQuery.error?.response?.data?.message ?? 'Could not load run history.'}
                        onRetry={() => runsQuery.refetch()}
                    />
                ) : (runsQuery.data ?? []).length === 0 ? (
                    <EmptyState
                        icon="📋"
                        title="No runs yet"
                        blurb="Preview a configuration and run your first recovery batch — it will appear here with a revert button."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <th className="px-3 py-2 font-semibold">Run</th>
                                    <th className="px-3 py-2 font-semibold">Mode</th>
                                    <th className="px-3 py-2 font-semibold">Status</th>
                                    <th className="px-3 py-2 font-semibold">Selection</th>
                                    <th className="px-3 py-2 font-semibold">Result</th>
                                    <th className="px-3 py-2 font-semibold">By</th>
                                    <th className="px-3 py-2 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(runsQuery.data ?? []).map((run) => (
                                    <tr key={run.id} className="hover:bg-slate-50/60">
                                        <td className="px-3 py-2">
                                            <span className="font-medium text-slate-800">#{run.id}</span>
                                            <span className="ml-2 text-xs text-slate-400">{run.created_at}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <Pill tone={run.mode === 'live'
                                                ? 'bg-teal-50 text-teal-700 ring-teal-200'
                                                : 'bg-slate-100 text-slate-600 ring-slate-200'}>
                                                {run.mode === 'live' ? 'Live' : 'Dry run'}
                                            </Pill>
                                        </td>
                                        <td className="px-3 py-2">
                                            <Pill tone={RUN_STATUS_TONE[run.status]}>{run.status}</Pill>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-500">
                                            {run.filters?.history_mode
                                                ? HISTORY_MODES.find((mode) => mode.value === run.filters.history_mode)?.label ?? run.filters.history_mode
                                                : 'Defaults'}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums text-slate-600">
                                            {run.restored_count.toLocaleString()} / {run.candidate_count.toLocaleString()}
                                            {run.failed_count > 0 ? (
                                                <span className="ml-1.5 text-xs text-rose-600">{run.failed_count} failed</span>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-500">{run.requested_by ?? 'System'}</td>
                                        <td className="px-3 py-2 text-right">
                                            {run.is_revertible ? (
                                                <button
                                                    type="button"
                                                    onClick={() => setRevertTarget(run)}
                                                    className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700"
                                                >
                                                    Revert
                                                </button>
                                            ) : (
                                                <span className="text-xs text-slate-300">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </SectionCard>

            {/* 6 — Restored cohort */}
            <SectionCard
                step="5"
                title="Recovered profiles"
                subtitle="The cohort this feature created — spot-check a few on the live site to confirm the notice shows and contacts are hidden."
                actions={(
                    <select
                        value={cohortRunFilter}
                        onChange={(event) => setCohortRunFilter(event.target.value)}
                        className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                    >
                        <option value="">All runs</option>
                        {(runsQuery.data ?? [])
                            .filter((run) => run.restored_count > 0)
                            .map((run) => (
                                <option key={run.id} value={run.id}>Run #{run.id}</option>
                            ))}
                    </select>
                )}
            >
                {cohortQuery.isLoading ? (
                    <SkeletonRows rows={4} cols={5} />
                ) : cohortQuery.isError ? (
                    <ErrorState
                        message={cohortQuery.error?.response?.data?.message ?? 'Could not load the recovered cohort.'}
                        onRetry={() => cohortQuery.refetch()}
                    />
                ) : (cohortQuery.data?.data ?? []).length === 0 ? (
                    <EmptyState
                        icon="🌱"
                        title="No recovered profiles yet"
                        blurb="Once a live batch completes, every profile it republished appears here so you can measure the recovery."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead>
                                <tr className="text-left text-[11px] uppercase tracking-wide text-slate-500">
                                    <th className="px-3 py-2 font-semibold">Profile</th>
                                    <th className="px-3 py-2 font-semibold">City</th>
                                    <th className="px-3 py-2 font-semibold">State</th>
                                    <th className="px-3 py-2 font-semibold">Expired</th>
                                    <th className="px-3 py-2 font-semibold">Recovered</th>
                                    <th className="px-3 py-2 font-semibold">Run</th>
                                    <th className="px-3 py-2 text-right font-semibold">Live page</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(cohortQuery.data?.data ?? []).map((row) => (
                                    <tr key={row.id} className="hover:bg-slate-50/60">
                                        <td className="px-3 py-2">
                                            <button
                                                type="button"
                                                onClick={() => navigate(`/clients/${row.id}`)}
                                                className="text-left"
                                                title="Open this client in the CRM"
                                            >
                                                <span className="block text-sm font-medium text-slate-800 hover:text-teal-700">
                                                    {row.name || `Profile ${row.id}`}
                                                </span>
                                                {row.phone_normalized ? (
                                                    <span className="crm-mono block text-xs text-slate-500">{row.phone_normalized}</span>
                                                ) : null}
                                            </button>
                                        </td>
                                        <td className="px-3 py-2 text-slate-600">{row.city || '—'}</td>
                                        <td className="px-3 py-2">
                                            <Pill tone={STATE_TONE[row.lifecycle_state]}>
                                                {row.lifecycle_state === 'expired' ? 'Expired' : 'Archived'}
                                            </Pill>
                                        </td>
                                        <td className="px-3 py-2 tabular-nums text-slate-600">{row.lifecycle_expired_at ?? '—'}</td>
                                        <td className="px-3 py-2 text-xs text-slate-500">{row.lifecycle_restored_at ?? '—'}</td>
                                        <td className="px-3 py-2 text-xs text-slate-500">#{row.lifecycle_restore_run_id}</td>
                                        <td className="px-3 py-2 text-right">
                                            {row.profile_url ? (
                                                <a
                                                    href={row.profile_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-semibold text-slate-700 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700"
                                                    title={row.profile_url}
                                                >
                                                    View ↗
                                                </a>
                                            ) : (
                                                <span className="text-xs text-slate-300" title="No permalink synced from WordPress yet">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="mt-2 text-[11px] text-slate-400">
                            Showing {(cohortQuery.data?.data ?? []).length} of {Number(cohortQuery.data?.total ?? 0).toLocaleString()} recovered profiles.
                        </p>
                    </div>
                )}
            </SectionCard>

            <ConfirmDialog
                open={confirmRun}
                title="Republish these profiles?"
                message={`This will republish ${willProcess.toLocaleString()} profile${willProcess === 1 ? '' : 's'} on ${selectedMarketLabel} as Expired or Archived. Their pages become publicly visible again with contact details hidden.`}
                confirmLabel={`Recover ${willProcess.toLocaleString()} profiles`}
                tone={warnAboutNeverLive ? 'warning' : 'default'}
                isPending={createRun.isPending}
                onCancel={() => setConfirmRun(false)}
                onConfirm={() => {
                    setConfirmRun(false);
                    createRun.mutate('live');
                }}
            >
                {warnAboutNeverLive ? (
                    <p className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                        You are using <strong>Every offline profile</strong>. Some of these were never live — this creates new public
                        pages rather than recovering lost ones.
                    </p>
                ) : null}
                <p className="mt-2 text-xs leading-relaxed text-slate-500">
                    The whole batch can be reverted from Run history.
                </p>
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(revertTarget)}
                title={`Revert run #${revertTarget?.id ?? ''}?`}
                message={`Every profile this run republished (${revertTarget?.restored_count?.toLocaleString() ?? 0}) goes back offline in WordPress. Their pages will stop being indexable again.`}
                confirmLabel="Revert batch"
                tone="danger"
                isPending={revertRun.isPending}
                onCancel={() => setRevertTarget(null)}
                onConfirm={() => revertRun.mutate(revertTarget.id)}
            />
        </div>
    );
}

function MarketPicker({ options, value, onChange }) {
    return (
        <div className="crm-surface flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <label htmlFor="seo-recovery-market" className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Market
            </label>
            <select
                id="seo-recovery-market"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="min-w-[14rem] rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
            >
                <option value="">Select a market…</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                ))}
            </select>
            <p className="text-xs text-slate-400">
                Each market is its own WordPress site — recovery runs one market at a time.
            </p>
        </div>
    );
}

function NumberField({ label, value, onChange, placeholder, min, max }) {
    return (
        <label className="block">
            <span className="block text-[11px] font-medium text-slate-600">{label}</span>
            <input
                type="number"
                value={value ?? ''}
                min={min}
                max={max}
                placeholder={placeholder}
                onChange={(event) => {
                    const next = event.target.value;
                    onChange(next === '' ? null : Number(next));
                }}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
            />
        </label>
    );
}
