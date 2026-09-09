import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import { copyToClipboard } from '../../utils/clipboard';
import exportRowsToCsv from '../../utils/csvExport';

// Every dial was a constant picked by measuring a handful of real photos. The
// copy explains what moving each one trades away, because a number without that
// is just a number.
const DIALS = [
    {
        key: 'invert_below_blend',
        label: 'Invert below blend',
        min: 0.1, max: 0.99, step: 0.01, format: (v) => Number(v).toFixed(2),
        help: 'Below this the maths recovers the true pixels. Above it the pixel is filled from its surroundings, because dividing by (1 − blend) amplifies compression noise — at 0.9 by tenfold, which over a bright background turns a white mark black.',
    },
    {
        key: 'max_implausible_ratio',
        label: 'Reject above implausible share',
        min: 0.01, max: 0.9, step: 0.01, format: (v) => `${Math.round(v * 100)}%`,
        help: 'Share of the strongest logo pixels allowed to be darker than the logo alone would make them. Correct market pairings measure under 5%; the wrong logo reads over 70%. Lower is stricter.',
    },
    {
        key: 'evidence_tolerance',
        label: 'Codec slack',
        min: 0, max: 80, step: 1, format: (v) => `${v} levels`,
        help: 'How far an observed pixel may fall below the logo’s own contribution before it counts against the match. Raise it for heavily recompressed markets.',
    },
    {
        key: 'max_out_of_gamut_ratio',
        label: 'Reject above out-of-gamut share',
        min: 0.01, max: 0.9, step: 0.01, format: (v) => `${Math.round(v * 100)}%`,
        help: 'Secondary check, on the inverted pixels only. Kept because it catches geometry problems the evidence check can miss.',
    },
    {
        key: 'chroma_repair_blend',
        label: 'Repair colour above blend',
        min: 0.05, max: 0.95, step: 0.01, format: (v) => Number(v).toFixed(2),
        help: 'Recovered pixels above this keep their brightness but take their colour from the photo around them. JPEG subsamples colour, so amplified error shows up as red and cyan speckle rather than grain.',
    },
    {
        key: 'chroma_repair_passes',
        label: 'Colour repair passes',
        min: 1, max: 20, step: 1, format: (v) => `${v}`,
        help: 'How far the colour repair reaches into a solid region. The figure inside the O needs several passes to be reached from its edges.',
    },
    {
        key: 'fill_passes',
        label: 'Fill passes',
        min: 1, max: 20, step: 1, format: (v) => `${v}`,
        help: 'The same, for pixels too strongly blended to invert.',
    },
    {
        key: 'min_landed_px',
        label: 'Minimum landed pixels',
        min: 8, max: 5000, step: 8, format: (v) => `${v} px`,
        help: 'Below this the stamp barely touches the photo, so there is nothing to gain and nothing to judge the match on.',
    },
];

const OUTCOME_TONE = {
    applied: 'border-teal-200 bg-teal-50 text-teal-800',
    not_this_watermark: 'border-amber-200 bg-amber-50 text-amber-800',
    not_configured: 'border-rose-200 bg-rose-50 text-rose-800',
    barely_lands: 'border-slate-200 bg-slate-50 text-slate-700',
    unreadable: 'border-rose-200 bg-rose-50 text-rose-800',
    write_failed: 'border-rose-200 bg-rose-50 text-rose-800',
    error: 'border-rose-200 bg-rose-50 text-rose-800',
};

function percent(value) {
    return value === null || value === undefined ? '—' : `${Math.round(Number(value) * 100)}%`;
}

function Stat({ label, value, hint, tone = 'text-slate-900' }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone}`}>{value}</p>
            {hint ? <p className="mt-0.5 text-[11px] text-slate-500">{hint}</p> : null}
        </div>
    );
}

export default function WatermarkControlPanel({ platforms = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [days, setDays] = useState(30);
    const [draft, setDraft] = useState(null);
    const [enabled, setEnabled] = useState(true);
    const [testPlatform, setTestPlatform] = useState('');
    const [testUrl, setTestUrl] = useState('');

    const overviewQuery = useQuery({
        queryKey: ['pbn-watermark', days],
        queryFn: () => api.get('/crm/pbn/watermark', { params: { days } }).then((r) => r.data),
    });

    const data = overviewQuery.data;

    useEffect(() => {
        if (!data) return;
        setDraft(data.tuning);
        setEnabled(Boolean(data.enabled));
    }, [data]);

    useEffect(() => {
        if (!testPlatform && platforms.length) setTestPlatform(String(platforms[0].platform_id));
    }, [platforms, testPlatform]);

    const saveMutation = useMutation({
        mutationFn: () => api.patch('/crm/pbn/watermark/settings', { enabled, tuning: draft }).then((r) => r.data),
        onSuccess: () => {
            toast.success('Watermark settings saved.');
            queryClient.invalidateQueries({ queryKey: ['pbn-watermark'] });
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not save watermark settings.'),
    });

    const testMutation = useMutation({
        mutationFn: () => api.post('/crm/pbn/watermark/test', { platform_id: Number(testPlatform), url: testUrl.trim() }).then((r) => r.data),
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not run the test.'),
    });

    const dirty = useMemo(() => {
        if (!data || !draft) return false;
        if (Boolean(data.enabled) !== enabled) return true;
        return DIALS.some((dial) => Number(draft[dial.key]) !== Number(data.tuning[dial.key]));
    }, [data, draft, enabled]);

    const totals = data?.totals;
    const test = testMutation.data;

    // One block of text carrying the whole picture — settings, totals, market
    // breakdown and the latest test — so a question about this feature can be
    // asked without screenshots.
    const diagnosticsText = useMemo(() => {
        if (!data) return '';
        const lines = [
            `Watermark removal — last ${data.window_days} days`,
            `enabled: ${data.enabled}`,
            `tuning: ${JSON.stringify(data.tuning)}`,
            `defaults: ${JSON.stringify(data.defaults)}`,
            '',
            `attempted ${totals.attempted} · removed ${totals.removed} · declined ${totals.declined} · rate ${percent(totals.removal_rate)}`,
            '',
            'outcomes:',
            ...data.outcomes.map((o) => `  ${o.outcome} (${o.label}): ${o.attempts}${o.avg_implausible !== null ? ` · avg implausible ${percent(o.avg_implausible)}` : ''}`),
            '',
            'markets:',
            ...data.markets.map((m) => `  ${m.platform_name} [${m.platform_id}]: ${m.removed}/${m.attempted} removed (${percent(m.removal_rate)})${m.top_decline ? ` · top decline ${m.top_decline.outcome} x${m.top_decline.attempts}` : ''}`),
        ];

        if (test) {
            lines.push('', 'last test:', `  url: ${testUrl}`, `  outcome: ${test.outcome} — ${test.reason}`,
                `  watermark: ${test.watermark ? `${test.watermark.size} @ ${test.watermark.position} ${test.watermark.opacity}%` : 'n/a'}`,
                `  stats: ${JSON.stringify(test.stats || {})}`);
        }

        return lines.join('\n');
    }, [data, totals, test, testUrl]);

    if (overviewQuery.isLoading) {
        return (
            <div className="space-y-3" aria-busy="true">
                {[0, 1, 2].map((row) => <div key={row} className="h-24 animate-pulse rounded-lg bg-slate-100" />)}
            </div>
        );
    }

    if (overviewQuery.isError) {
        return (
            <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p className="font-semibold">Could not load the watermark control centre.</p>
                <button type="button" className="crm-btn-secondary mt-3 min-h-11 px-3 py-2" onClick={() => overviewQuery.refetch()}>Retry</button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <section className="rounded-lg border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Watermark removal</p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Reverses the source market’s logo using that market’s own watermark settings. It declines rather than
                            guessing, so every attempt is recorded with its reason.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select value={days} onChange={(event) => setDays(Number(event.target.value))} className="crm-input w-auto" aria-label="Reporting window">
                            {[7, 30, 90].map((d) => <option key={d} value={d}>Last {d} days</option>)}
                        </select>
                        <button
                            type="button"
                            className="crm-btn-secondary min-h-11 px-3 py-2 text-xs"
                            onClick={async () => {
                                await copyToClipboard(diagnosticsText);
                                toast.success('Diagnostics copied.');
                            }}
                        >
                            Copy diagnostics
                        </button>
                    </div>
                </div>

                <div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat label="Images attempted" value={totals.attempted} />
                    <Stat
                        label="Watermarks removed"
                        value={totals.removed}
                        tone="text-teal-700"
                        hint={totals.removal_rate !== null ? `${percent(totals.removal_rate)} of attempts` : null}
                    />
                    <Stat label="Left on" value={totals.declined} tone={totals.declined > 0 ? 'text-amber-700' : 'text-slate-400'} />
                    <Stat
                        label="Misconfigured"
                        value={totals.misconfigured}
                        tone={totals.misconfigured > 0 ? 'text-rose-700' : 'text-slate-400'}
                        hint="Market settings unreadable"
                    />
                </div>

                {totals.attempted === 0 ? (
                    <p className="mt-3 rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                        No images have been through the remover in this window. Seed a batch with the watermark policy set to
                        “Remove before copying”, or run a single image through the test below.
                    </p>
                ) : null}
            </section>

            {data.outcomes.length > 0 ? (
                <section className="rounded-lg border border-slate-200 bg-white p-4">
                    <p className="text-sm font-semibold text-slate-900">What happened, and why</p>
                    <div className="mt-3 space-y-2">
                        {data.outcomes.map((outcome) => {
                            const share = totals.attempted > 0 ? outcome.attempts / totals.attempted : 0;

                            return (
                                <div key={outcome.outcome}>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="font-medium text-slate-800">{outcome.label}</span>
                                        <span className="text-xs text-slate-500">
                                            {outcome.attempts} · {percent(share)}
                                            {outcome.avg_implausible !== null ? ` · avg implausible ${percent(outcome.avg_implausible)}` : ''}
                                        </span>
                                    </div>
                                    <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className={`h-full rounded-full ${outcome.outcome === 'applied' ? 'bg-teal-500' : outcome.outcome === 'not_configured' ? 'bg-rose-400' : 'bg-amber-400'}`}
                                            style={{ width: `${Math.max(2, share * 100)}%` }}
                                        />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            {data.markets.length > 0 ? (
                <section className="rounded-lg border border-slate-200 bg-white">
                    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">By source market</p>
                            <p className="mt-0.5 text-xs text-slate-500">Each site has its own logo file, so the answer differs per market.</p>
                        </div>
                        <button
                            type="button"
                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                            onClick={() => exportRowsToCsv('watermark-by-market', [
                                { label: 'Market', value: (r) => r.platform_name },
                                { label: 'Attempted', value: (r) => r.attempted },
                                { label: 'Removed', value: (r) => r.removed },
                                { label: 'Declined', value: (r) => r.declined },
                                { label: 'Rate', value: (r) => percent(r.removal_rate) },
                                { label: 'Top decline', value: (r) => r.top_decline?.label },
                            ], data.markets)}
                        >
                            Export CSV
                        </button>
                    </div>
                    <div className="divide-y divide-slate-100">
                        {data.markets.map((market) => (
                            <div key={market.platform_id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-[180px]">
                                    <p className="text-sm font-medium text-slate-900">{market.platform_name}</p>
                                    <p className="text-xs text-slate-500">{market.removed} of {market.attempted} cleaned</p>
                                </div>
                                <div className="flex min-w-[160px] flex-1 items-center gap-3">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                                        <div className="h-full rounded-full bg-teal-500" style={{ width: `${Math.max(2, (market.removal_rate || 0) * 100)}%` }} />
                                    </div>
                                    <span className="w-12 text-right text-xs font-semibold text-slate-700">{percent(market.removal_rate)}</span>
                                </div>
                                {market.top_decline ? (
                                    <span className={`rounded-md border px-2 py-0.5 text-[11px] font-semibold ${OUTCOME_TONE[market.top_decline.outcome] || OUTCOME_TONE.barely_lands}`}>
                                        {market.top_decline.label} · {market.top_decline.attempts}
                                    </span>
                                ) : (
                                    <span className="rounded-md border border-teal-200 bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-800">All clean</span>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            ) : null}

            <section className="rounded-lg border border-slate-200 bg-white p-4">
                <p className="text-sm font-semibold text-slate-900">Try one image</p>
                <p className="mt-0.5 text-xs text-slate-500">
                    Runs the exact path a seed batch takes, and changes nothing. The quickest way to tell a misconfigured market
                    from a photo that carries a different mark.
                </p>
                <div className="mt-3 grid gap-2 lg:grid-cols-[220px_1fr_auto]">
                    <select value={testPlatform} onChange={(event) => setTestPlatform(event.target.value)} className="crm-input" aria-label="Source market">
                        {platforms.map((platform) => (
                            <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                        ))}
                    </select>
                    <input
                        value={testUrl}
                        onChange={(event) => setTestUrl(event.target.value)}
                        className="crm-input"
                        placeholder="https://market.com/wp-content/uploads/…/photo.webp"
                        aria-label="Image URL"
                    />
                    <button
                        type="button"
                        className="crm-btn-primary min-h-11 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={!testPlatform || testUrl.trim().length < 8 || testMutation.isPending}
                        onClick={() => testMutation.mutate()}
                    >
                        {testMutation.isPending ? 'Running...' : 'Run test'}
                    </button>
                </div>

                {test ? (
                    <div className="mt-4 space-y-3">
                        <div className={`rounded-md border px-3 py-2 text-sm ${OUTCOME_TONE[test.outcome] || OUTCOME_TONE.barely_lands}`}>
                            <p className="font-semibold">{test.applied ? 'Watermark removed' : `Declined — ${test.outcome}`}</p>
                            <p className="mt-0.5 text-xs">{test.reason}</p>
                        </div>
                        {test.watermark ? (
                            <p className="text-xs text-slate-500">
                                Market watermark: {test.watermark.size} at {test.watermark.position}, {test.watermark.opacity}% opacity
                            </p>
                        ) : null}
                        {Object.keys(test.stats || {}).length > 0 ? (
                            <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
                                {Object.entries(test.stats).map(([key, value]) => (
                                    <div key={key} className="rounded-md border border-slate-200 px-2 py-1.5">
                                        <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">{key.replaceAll('_', ' ')}</p>
                                        <p className="text-sm font-semibold text-slate-800">{String(value)}</p>
                                    </div>
                                ))}
                            </div>
                        ) : null}
                        {test.before ? (
                            <div className="grid gap-3 md:grid-cols-2">
                                <figure>
                                    <figcaption className="mb-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Before</figcaption>
                                    <img src={test.before} alt="Source image before removal" className="w-full rounded-md border border-slate-200" />
                                </figure>
                                {test.after ? (
                                    <figure>
                                        <figcaption className="mb-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">After</figcaption>
                                        <img src={test.after} alt="Image after removal" className="w-full rounded-md border border-slate-200" />
                                    </figure>
                                ) : (
                                    <div className="flex items-center justify-center rounded-md border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-xs text-slate-500">
                                        Nothing was changed, so there is no “after” to compare.
                                    </div>
                                )}
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Thresholds</p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Chosen by measuring real photos. Move them against the numbers above rather than by feel.
                        </p>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={enabled}
                            disabled={!data.can_configure}
                            onChange={(event) => setEnabled(event.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                        />
                        Removal enabled
                    </label>
                </div>

                {!data.can_configure ? (
                    <p className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        Only admin and sub-admin users can change these.
                    </p>
                ) : null}

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    {draft ? DIALS.map((dial) => {
                        const value = Number(draft[dial.key]);
                        const isDefault = Number(data.defaults[dial.key]) === value;

                        return (
                            <div key={dial.key}>
                                <div className="flex items-center justify-between gap-2">
                                    <label className="text-sm font-medium text-slate-800" htmlFor={`dial-${dial.key}`}>{dial.label}</label>
                                    <span className="flex items-center gap-2">
                                        <span className="text-xs font-semibold text-slate-700">{dial.format(value)}</span>
                                        {!isDefault ? (
                                            <button
                                                type="button"
                                                className="text-[11px] font-medium text-teal-700 hover:underline"
                                                onClick={() => setDraft((current) => ({ ...current, [dial.key]: data.defaults[dial.key] }))}
                                            >
                                                reset
                                            </button>
                                        ) : null}
                                    </span>
                                </div>
                                <input
                                    id={`dial-${dial.key}`}
                                    type="range"
                                    min={dial.min}
                                    max={dial.max}
                                    step={dial.step}
                                    value={value}
                                    disabled={!data.can_configure}
                                    onChange={(event) => setDraft((current) => ({ ...current, [dial.key]: Number(event.target.value) }))}
                                    className="mt-1 w-full accent-teal-700 disabled:opacity-50"
                                />
                                <p className="mt-1 text-[11px] leading-relaxed text-slate-500">{dial.help}</p>
                            </div>
                        );
                    }) : null}
                </div>

                {data.can_configure ? (
                    <div className="mt-4 flex flex-wrap items-center justify-end gap-2">
                        {dirty ? <span className="mr-auto text-xs text-amber-700">Unsaved changes</span> : null}
                        <button
                            type="button"
                            className="crm-btn-secondary min-h-11 px-3 py-2"
                            disabled={!dirty || saveMutation.isPending}
                            onClick={() => { setDraft(data.tuning); setEnabled(Boolean(data.enabled)); }}
                        >
                            Discard
                        </button>
                        <button
                            type="button"
                            className="crm-btn-primary min-h-11 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                            disabled={!dirty || saveMutation.isPending}
                            onClick={() => saveMutation.mutate()}
                        >
                            {saveMutation.isPending ? 'Saving...' : 'Save thresholds'}
                        </button>
                    </div>
                ) : null}
            </section>

            <section className="rounded-lg border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <p className="text-sm font-semibold text-slate-900">Recent attempts</p>
                    <button
                        type="button"
                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                        disabled={data.recent.length === 0}
                        onClick={() => exportRowsToCsv('watermark-attempts', [
                            { label: 'When', value: (r) => r.created_at },
                            { label: 'Market', value: (r) => r.platform_name },
                            { label: 'Batch', value: (r) => r.batch_id },
                            { label: 'Outcome', value: (r) => r.outcome },
                            { label: 'Reason', value: (r) => r.reason },
                            { label: 'Image', value: (r) => r.image_size },
                            { label: 'Stamp', value: (r) => r.stamp_size },
                            { label: 'Implausible', value: (r) => r.implausible_ratio },
                            { label: 'Landed px', value: (r) => r.landed_px },
                            { label: 'URL', value: (r) => r.image_url },
                        ], data.recent)}
                    >
                        Export CSV
                    </button>
                </div>
                {data.recent.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm text-slate-500">Nothing recorded in this window.</p>
                ) : (
                    <div className="max-h-96 overflow-auto">
                        <table className="min-w-full divide-y divide-slate-100">
                            <thead className="bg-slate-50">
                                <tr>
                                    {['When', 'Market', 'Outcome', 'Detail', 'Image'].map((heading) => (
                                        <th key={heading} className="px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{heading}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.recent.map((row) => (
                                    <tr key={row.id}>
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{row.created_at}</td>
                                        <td className="px-3 py-2 text-sm text-slate-800">{row.platform_name || '—'}</td>
                                        <td className="px-3 py-2">
                                            <span className={`rounded-md border px-2 py-0.5 text-[11px] font-semibold ${OUTCOME_TONE[row.outcome] || OUTCOME_TONE.barely_lands}`}>
                                                {row.label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-600">
                                            <p className="max-w-[320px] truncate" title={row.reason || ''}>{row.reason || '—'}</p>
                                            {row.implausible_ratio !== null ? (
                                                <p className="text-[11px] text-slate-400">implausible {percent(row.implausible_ratio)}</p>
                                            ) : null}
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-500">
                                            {row.image_size || '—'}{row.stamp_size ? ` · stamp ${row.stamp_size}` : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </div>
    );
}
