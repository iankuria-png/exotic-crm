import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BioPreviewModal from './BioPreviewModal';

/**
 * Bulk bio generation flow.
 *
 *   Step 1 (compose):  pick market, language, paste rows, set save mode, dry-run preview
 *   Step 2 (running):  show live progress (polled every 3s)
 *   Step 3 (review):   per-row results, select rows, Accept → save back to WP
 *
 * Mirrors the UX pattern from PushCampaigns/UploadModal but lighter:
 * no file upload, paste-only; bios stream in as the queue runs.
 */
export default function BulkBioModal({ open, onClose, platforms = [], defaultPlatformId = null, supportedLanguages = [] }) {
    const toast = useToast();
    const [step, setStep] = useState('compose');           // compose | running | review
    const [platformId, setPlatformId] = useState(defaultPlatformId || '');
    const [language, setLanguage] = useState('en');
    const [autoSave, setAutoSave] = useState(false);
    const [content, setContent] = useState('');
    const [previewRows, setPreviewRows] = useState(null);
    const [previewSummary, setPreviewSummary] = useState(null);
    const [batchId, setBatchId] = useState(null);
    const [batch, setBatch] = useState(null);
    const [rows, setRows] = useState([]);
    const [selectedRowIds, setSelectedRowIds] = useState({});
    const [drillIntoRow, setDrillIntoRow] = useState(null);
    const pollTimerRef = useRef(null);

    useEffect(() => {
        if (!open) {
            // Reset
            setStep('compose');
            setContent('');
            setPreviewRows(null);
            setPreviewSummary(null);
            setBatchId(null);
            setBatch(null);
            setRows([]);
            setSelectedRowIds({});
            setDrillIntoRow(null);
            stopPolling();
        }
    }, [open]);

    useEffect(() => {
        if (!platformId && platforms.length > 0) {
            setPlatformId(platforms[0]?.id || '');
        }
    }, [platforms, platformId]);

    // ── Mutations ────────────────────────────────────────────────────
    const previewMutation = useMutation({
        mutationFn: () => api.post('/crm/seo/bulk/preview', {
            platform_id: Number(platformId),
            content,
        }).then((r) => r.data),
        onSuccess: (data) => {
            setPreviewRows(data.rows || []);
            setPreviewSummary(data.summary || null);
            if ((data.summary?.unresolved || 0) > 0) {
                toast.warning?.(`${data.summary.unresolved} row(s) could not be matched to a client.`);
            }
        },
        onError: (err) => {
            const msg = err?.response?.data?.message || err?.message || 'Preview failed.';
            toast.error(msg);
        },
    });

    const createMutation = useMutation({
        mutationFn: () => api.post('/crm/seo/bulk', {
            platform_id: Number(platformId),
            content,
            language,
            auto_save_to_wp: autoSave,
        }).then((r) => r.data),
        onSuccess: (data) => {
            setBatchId(data.batch?.id || null);
            setBatch(data.batch || null);
            setStep('running');
            startPolling(data.batch?.id);
            toast.success(`Queued ${data.batch?.total_rows || 0} row(s). Generation will run in the background.`);
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Could not start batch.');
        },
    });

    const acceptMutation = useMutation({
        mutationFn: (rowIds) => api.post(`/crm/seo/bulk/${batchId}/accept`, { row_ids: rowIds }).then((r) => r.data),
        onSuccess: (data) => {
            setBatch(data.batch || batch);
            toast.success(`Accepted ${data.accepted_count} row(s). ${data.failed_count} failed.`);
            // Refresh row list to reflect new statuses
            refreshStatus();
            setSelectedRowIds({});
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Accept failed.');
        },
    });

    const cancelMutation = useMutation({
        mutationFn: () => api.post(`/crm/seo/bulk/${batchId}/cancel`).then((r) => r.data),
        onSuccess: (data) => {
            setBatch(data.batch || batch);
            toast.info?.('Batch cancelled.');
            stopPolling();
        },
    });

    // ── Polling ──────────────────────────────────────────────────────
    const refreshStatus = async () => {
        if (!batchId) return;
        try {
            const { data } = await api.get(`/crm/seo/bulk/${batchId}`);
            setBatch(data.batch);
            setRows(data.rows || []);
            if (data.batch?.status === 'ready' || data.batch?.status === 'completed' || data.batch?.status === 'failed' || data.batch?.status === 'cancelled') {
                if (data.batch.status === 'ready' || data.batch.status === 'completed') {
                    setStep('review');
                }
                stopPolling();
            }
        } catch (_e) { /* swallow — UI shows last known state */ }
    };

    const startPolling = (id) => {
        stopPolling();
        if (!id) return;
        refreshStatus();
        pollTimerRef.current = window.setInterval(refreshStatus, 3000);
    };

    const stopPolling = () => {
        if (pollTimerRef.current) {
            window.clearInterval(pollTimerRef.current);
            pollTimerRef.current = null;
        }
    };

    useEffect(() => () => stopPolling(), []);

    if (!open) return null;

    const generatedRows = rows.filter((r) => r.status === 'generated');
    const allGeneratedIds = generatedRows.map((r) => r.id);
    const selectedCount = Object.values(selectedRowIds).filter(Boolean).length;
    const toggleRow = (id) => setSelectedRowIds((s) => ({ ...s, [id]: !s[id] }));
    const toggleAllGenerated = () => {
        if (selectedCount === allGeneratedIds.length && allGeneratedIds.length > 0) {
            setSelectedRowIds({});
        } else {
            const next = {};
            for (const id of allGeneratedIds) next[id] = true;
            setSelectedRowIds(next);
        }
    };

    const progressPct = batch?.total_rows
        ? Math.round((batch.processed_rows / batch.total_rows) * 100)
        : 0;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="dialog" aria-modal="true">
            <div className="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                {/* ── Header ── */}
                <header className="border-b border-slate-100 bg-gradient-to-r from-teal-50 via-white to-white px-6 py-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">SEO tools</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-950">Bulk generate bios</h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Paste profile URLs from your Excel sheet. We'll resolve each to a client, queue a bio per row, and let you review the results.
                            </p>
                        </div>
                        <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
                            ×
                        </button>
                    </div>
                    <StepIndicator step={step} />
                </header>

                {/* ── Body ── */}
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {step === 'compose' ? (
                        <ComposeStep
                            platforms={platforms}
                            platformId={platformId}
                            setPlatformId={setPlatformId}
                            language={language}
                            setLanguage={setLanguage}
                            supportedLanguages={supportedLanguages}
                            autoSave={autoSave}
                            setAutoSave={setAutoSave}
                            content={content}
                            setContent={setContent}
                            previewRows={previewRows}
                            previewSummary={previewSummary}
                            previewBusy={previewMutation.isPending}
                            onPreview={() => previewMutation.mutate()}
                        />
                    ) : null}

                    {step === 'running' ? (
                        <RunningStep batch={batch} rows={rows} progressPct={progressPct} onCancel={() => cancelMutation.mutate()} cancelling={cancelMutation.isPending} />
                    ) : null}

                    {step === 'review' ? (
                        <ReviewStep
                            batch={batch}
                            rows={rows}
                            selectedRowIds={selectedRowIds}
                            toggleRow={toggleRow}
                            toggleAllGenerated={toggleAllGenerated}
                            allGeneratedIds={allGeneratedIds}
                            onDrillInto={(row) => setDrillIntoRow(row)}
                        />
                    ) : null}
                </div>

                {/* ── Footer ── */}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
                    <div className="text-xs text-slate-500">
                        {step === 'compose' && previewSummary ? (
                            <>Resolved <strong>{previewSummary.resolved}</strong> of {previewSummary.total} rows. Unresolved rows will be skipped.</>
                        ) : null}
                        {step === 'running' && batch ? (
                            <>Processing — {batch.processed_rows}/{batch.total_rows} done · ✓ {batch.succeeded_rows} · ✗ {batch.failed_rows}</>
                        ) : null}
                        {step === 'review' && batch ? (
                            <>Done — {batch.succeeded_rows} succeeded · {batch.failed_rows} failed · {batch.accepted_rows} accepted</>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onClose}>Close</button>
                        {step === 'compose' ? (
                            <button
                                type="button"
                                className="crm-btn-primary"
                                disabled={!platformId || !content.trim() || createMutation.isPending || (previewSummary && previewSummary.resolved === 0)}
                                onClick={() => createMutation.mutate()}
                            >
                                {createMutation.isPending ? 'Queuing…' : 'Start generating'}
                            </button>
                        ) : null}
                        {step === 'review' ? (
                            <button
                                type="button"
                                className="crm-btn-primary"
                                disabled={selectedCount === 0 || acceptMutation.isPending}
                                onClick={() => acceptMutation.mutate(Object.keys(selectedRowIds).filter((id) => selectedRowIds[id]).map(Number))}
                            >
                                {acceptMutation.isPending ? 'Saving…' : `Save ${selectedCount} bio${selectedCount === 1 ? '' : 's'} to WP`}
                            </button>
                        ) : null}
                    </div>
                </footer>
            </div>

            {/* Per-row drill-into using the existing single-bio preview modal */}
            {drillIntoRow ? (
                <BioPreviewModal
                    open
                    bioHtml={drillIntoRow.bio_html}
                    score={drillIntoRow.score}
                    breakdown={drillIntoRow.breakdown}
                    providerUsed={drillIntoRow.provider_used}
                    usage={null}
                    onAccept={() => {
                        acceptMutation.mutate([drillIntoRow.id]);
                        setDrillIntoRow(null);
                    }}
                    onDiscard={() => setDrillIntoRow(null)}
                />
            ) : null}
        </div>
    );
}

function StepIndicator({ step }) {
    const steps = [
        { id: 'compose', label: 'Compose' },
        { id: 'running', label: 'Generate' },
        { id: 'review', label: 'Review' },
    ];
    const activeIdx = steps.findIndex((s) => s.id === step);
    return (
        <ol className="mt-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.08em]">
            {steps.map((s, i) => {
                const active = i === activeIdx;
                const done = i < activeIdx;
                return (
                    <li key={s.id} className={`flex items-center gap-2 rounded-full px-3 py-1 ${
                        active ? 'bg-teal-600 text-white' : done ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'
                    }`}>
                        <span>{i + 1}. {s.label}</span>
                    </li>
                );
            })}
        </ol>
    );
}

function ComposeStep({ platforms, platformId, setPlatformId, language, setLanguage, supportedLanguages, autoSave, setAutoSave, content, setContent, previewRows, previewSummary, previewBusy, onPreview }) {
    const langs = supportedLanguages.length
        ? supportedLanguages
        : [{ code: 'en', label: 'English' }, { code: 'fr', label: 'French' }, { code: 'pt', label: 'Portuguese' }, { code: 'sw', label: 'Swahili' }];

    return (
        <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Market</label>
                    <select
                        value={platformId}
                        onChange={(e) => setPlatformId(Number(e.target.value) || '')}
                        className="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    >
                        <option value="">Select a market…</option>
                        {platforms.map((p) => (
                            <option key={p.id} value={p.id}>{p.name} (#{p.id})</option>
                        ))}
                    </select>
                </div>
                <div>
                    <label className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Output language</label>
                    <select
                        value={language}
                        onChange={(e) => setLanguage(e.target.value)}
                        className="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500"
                    >
                        {langs.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
                    </select>
                </div>
            </div>

            <div>
                <label className="block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Profile URLs</label>
                <textarea
                    rows={9}
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    placeholder={`Paste one URL per line, or copy whole rows from Excel.\n\nhttps://exotickenya.com/escort/jane-doe/\nhttps://exotickenya.com/escort/another-profile/`}
                    className="mt-1 w-full rounded-lg border-slate-300 font-mono text-xs focus:border-teal-500 focus:ring-teal-500"
                />
                <div className="mt-1 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-500">
                    <span>Accepts URLs, slugs, or bare post IDs. Max 250 rows per batch.</span>
                    <button
                        type="button"
                        onClick={onPreview}
                        disabled={previewBusy || !platformId || !content.trim()}
                        className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    >
                        {previewBusy ? 'Resolving…' : 'Resolve & preview'}
                    </button>
                </div>
            </div>

            <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                <input
                    type="checkbox"
                    checked={autoSave}
                    onChange={(e) => setAutoSave(e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
                />
                <div>
                    <div className="text-sm font-semibold text-slate-900">Auto-save successful bios to WordPress</div>
                    <div className="text-xs text-slate-500">If off, you'll review each generated bio before pushing it back to the live site.</div>
                </div>
            </label>

            {previewRows ? (
                <PreviewTable rows={previewRows} summary={previewSummary} />
            ) : null}
        </div>
    );
}

function PreviewTable({ rows, summary }) {
    return (
        <div className="rounded-xl border border-slate-200">
            <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs">
                <div>
                    <strong className="text-slate-900">{summary?.total ?? rows.length}</strong>
                    <span className="text-slate-500"> rows parsed · </span>
                    <strong className="text-emerald-700">{summary?.resolved ?? 0}</strong>
                    <span className="text-slate-500"> resolved · </span>
                    <strong className="text-rose-700">{summary?.unresolved ?? 0}</strong>
                    <span className="text-slate-500"> unresolved</span>
                </div>
            </div>
            <div className="max-h-64 overflow-y-auto">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-[10px] uppercase tracking-[0.08em] text-slate-500">
                        <tr>
                            <th className="px-3 py-2 text-left">#</th>
                            <th className="px-3 py-2 text-left">Input</th>
                            <th className="px-3 py-2 text-left">Resolved profile</th>
                            <th className="px-3 py-2 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.row_index} className="border-t border-slate-100">
                                <td className="px-3 py-1.5 text-xs text-slate-500">{r.row_index}</td>
                                <td className="px-3 py-1.5 font-mono text-[11px] text-slate-700 truncate max-w-[280px]" title={r.input_text}>{r.input_text}</td>
                                <td className="px-3 py-1.5 text-xs text-slate-700">
                                    {r.profile_name || (r.wp_post_id ? `#${r.wp_post_id}` : '—')}
                                </td>
                                <td className="px-3 py-1.5 text-xs">
                                    {r.status === 'queued' ? (
                                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">ready</span>
                                    ) : (
                                        <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700" title={r.error}>unresolved</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function RunningStep({ batch, rows, progressPct, onCancel, cancelling }) {
    return (
        <div className="space-y-5">
            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div className="flex items-baseline justify-between text-sm">
                    <span className="font-semibold text-slate-900">Generating bios…</span>
                    <span className="text-slate-600">{batch?.processed_rows || 0} / {batch?.total_rows || 0}</span>
                </div>
                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                    <div className="h-full bg-teal-500 transition-all" style={{ width: `${progressPct}%` }} />
                </div>
                <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-600">
                    <span>✓ {batch?.succeeded_rows || 0} done</span>
                    <span>✗ {batch?.failed_rows || 0} failed</span>
                    {batch?.auto_save_to_wp ? <span>· auto-save on</span> : null}
                    <button type="button" onClick={onCancel} disabled={cancelling} className="ml-auto text-xs font-medium text-rose-700 underline hover:text-rose-900 disabled:opacity-50">
                        {cancelling ? 'Cancelling…' : 'Cancel'}
                    </button>
                </div>
            </div>
            <RowProgressList rows={rows} />
        </div>
    );
}

function ReviewStep({ batch, rows, selectedRowIds, toggleRow, toggleAllGenerated, allGeneratedIds, onDrillInto }) {
    const generatedRows = rows.filter((r) => r.status === 'generated');
    const failedRows = rows.filter((r) => r.status === 'failed');
    const acceptedRows = rows.filter((r) => r.status === 'accepted');
    const unresolvedRows = rows.filter((r) => r.status === 'unresolved');

    const allSelected = allGeneratedIds.length > 0 && allGeneratedIds.every((id) => selectedRowIds[id]);

    return (
        <div className="space-y-5">
            <div className="grid gap-2 sm:grid-cols-4">
                <Stat label="Generated" value={generatedRows.length} tone="teal" />
                <Stat label="Accepted" value={acceptedRows.length} tone="emerald" />
                <Stat label="Failed" value={failedRows.length} tone="rose" />
                <Stat label="Unresolved" value={unresolvedRows.length} tone="amber" />
            </div>

            {generatedRows.length > 0 ? (
                <div className="rounded-xl border border-slate-200">
                    <div className="flex items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-4 py-2">
                        <label className="inline-flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input
                                type="checkbox"
                                checked={allSelected}
                                onChange={toggleAllGenerated}
                                className="h-4 w-4 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
                            />
                            Select all generated
                        </label>
                        <span className="text-xs text-slate-500">{generatedRows.length} ready to review</span>
                    </div>
                    <div className="max-h-[40vh] overflow-y-auto divide-y divide-slate-100">
                        {generatedRows.map((r) => (
                            <RowCard
                                key={r.id}
                                row={r}
                                selected={!!selectedRowIds[r.id]}
                                onToggle={() => toggleRow(r.id)}
                                onDrillInto={() => onDrillInto(r)}
                            />
                        ))}
                    </div>
                </div>
            ) : null}

            {failedRows.length > 0 ? <FailedSection rows={failedRows} /> : null}
            {unresolvedRows.length > 0 ? <UnresolvedSection rows={unresolvedRows} /> : null}
        </div>
    );
}

function RowCard({ row, selected, onToggle, onDrillInto }) {
    const preview = row.bio_html ? row.bio_html.replace(/<[^>]+>/g, ' ').slice(0, 180) : '';
    return (
        <div className={`flex items-start gap-3 px-4 py-3 ${selected ? 'bg-teal-50/40' : ''}`}>
            <input
                type="checkbox"
                checked={selected}
                onChange={onToggle}
                className="mt-1 h-4 w-4 rounded text-teal-600 focus:ring-teal-500 border-slate-300"
            />
            <div className="flex-1 min-w-0">
                <div className="flex flex-wrap items-baseline gap-2">
                    <span className="text-sm font-semibold text-slate-900">{row.profile_name || `#${row.wp_post_id}`}</span>
                    <span className="text-[11px] text-slate-400">row {row.row_index}</span>
                    {row.score != null ? (
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                            row.score >= 70 ? 'bg-emerald-50 text-emerald-700' :
                            row.score >= 40 ? 'bg-amber-50 text-amber-700' :
                                              'bg-rose-50 text-rose-700'
                        }`}>{row.score}/100</span>
                    ) : null}
                    {row.provider_used ? <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">{row.provider_used}</span> : null}
                </div>
                <p className="mt-1 line-clamp-2 text-xs text-slate-600">{preview}…</p>
            </div>
            <button
                type="button"
                onClick={onDrillInto}
                className="text-xs font-medium text-teal-700 underline decoration-dotted underline-offset-4 hover:text-teal-900"
            >
                Preview
            </button>
        </div>
    );
}

function FailedSection({ rows }) {
    return (
        <details className="rounded-xl border border-rose-200 bg-rose-50/40 p-3">
            <summary className="cursor-pointer text-sm font-semibold text-rose-800">Failed rows ({rows.length})</summary>
            <ul className="mt-2 space-y-1 text-xs text-rose-900">
                {rows.map((r) => (
                    <li key={r.id}>
                        <span className="font-mono">{r.input_text}</span> — {r.error}
                    </li>
                ))}
            </ul>
        </details>
    );
}

function UnresolvedSection({ rows }) {
    return (
        <details className="rounded-xl border border-amber-200 bg-amber-50/40 p-3">
            <summary className="cursor-pointer text-sm font-semibold text-amber-800">Unresolved rows ({rows.length})</summary>
            <p className="mt-1 text-xs text-amber-900">These URLs didn't match any client on the selected market. Verify the URL or sync the profile to the CRM first.</p>
            <ul className="mt-2 space-y-1 text-xs text-amber-900">
                {rows.map((r) => (
                    <li key={r.id}>
                        <span className="font-mono">{r.input_text}</span>
                    </li>
                ))}
            </ul>
        </details>
    );
}

function RowProgressList({ rows }) {
    if (rows.length === 0) return null;
    const head = rows.slice(0, 8);
    return (
        <ul className="space-y-1 rounded-xl border border-slate-200 bg-white p-2 text-xs">
            {head.map((r) => (
                <li key={r.id} className="flex items-center justify-between gap-2 rounded px-2 py-1">
                    <span className="truncate text-slate-700">{r.profile_name || r.input_text}</span>
                    <StatusPill status={r.status} />
                </li>
            ))}
            {rows.length > head.length ? (
                <li className="px-2 py-1 text-[11px] text-slate-400">+ {rows.length - head.length} more rows…</li>
            ) : null}
        </ul>
    );
}

function StatusPill({ status }) {
    const map = {
        queued:     ['bg-slate-100 text-slate-600', 'queued'],
        processing: ['bg-teal-50 text-teal-700', 'processing'],
        generated:  ['bg-emerald-50 text-emerald-700', 'generated'],
        accepted:   ['bg-emerald-100 text-emerald-800', 'accepted'],
        failed:     ['bg-rose-50 text-rose-700', 'failed'],
        skipped:    ['bg-slate-100 text-slate-500', 'skipped'],
        unresolved: ['bg-amber-50 text-amber-700', 'unresolved'],
    };
    const [cls, label] = map[status] || ['bg-slate-100 text-slate-500', status];
    return <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${cls}`}>{label}</span>;
}

function Stat({ label, value, tone }) {
    const tones = {
        teal:    'border-teal-200 bg-teal-50 text-teal-800',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        rose:    'border-rose-200 bg-rose-50 text-rose-800',
        amber:   'border-amber-200 bg-amber-50 text-amber-800',
    };
    return (
        <div className={`rounded-lg border p-3 ${tones[tone] || 'border-slate-200 bg-slate-50 text-slate-700'}`}>
            <div className="text-[10px] font-semibold uppercase tracking-[0.08em] opacity-75">{label}</div>
            <div className="mt-1 text-xl font-bold">{value}</div>
        </div>
    );
}
