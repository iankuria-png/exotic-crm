import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import compliance from '../../services/compliance';
import { useToast } from '../ToastProvider';

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function titleize(value) {
    return String(value || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusBadge(status) {
    const normalized = String(status || 'missing');
    if (['accepted', 'approved', 'ok', 'valid'].includes(normalized)) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (['release_required', 'blocked_pending_release', 'info_requested', 'retired_version'].includes(normalized)) {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    if (['rejected', 'missing'].includes(normalized)) {
        return 'bg-rose-50 text-rose-700 ring-rose-200';
    }
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function EvidenceCard({ label, status, meta }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p>
            <div className="mt-3 flex items-center justify-between gap-3">
                <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusBadge(status)}`}>
                    {titleize(status || 'missing')}
                </span>
            </div>
            <p className="mt-3 text-sm leading-5 text-slate-600">{meta || 'No evidence recorded yet.'}</p>
        </div>
    );
}

export default function CompliancePanel({ client, canExport = false }) {
    const toast = useToast();
    const [showExportDialog, setShowExportDialog] = useState(false);
    const [exportReason, setExportReason] = useState('Segpay record request');
    const clientId = client?.id;

    const complianceQuery = useQuery({
        queryKey: ['client-compliance', clientId],
        queryFn: () => compliance.getClientCompliance(clientId),
        enabled: Boolean(clientId),
    });

    const exportMutation = useMutation({
        mutationFn: () => compliance.exportEvidencePack(clientId, { reason: exportReason }),
        onSuccess: (payload) => {
            setShowExportDialog(false);
            toast.success('Evidence pack generated.');
            if (payload?.download_url) {
                window.open(payload.download_url, '_blank', 'noopener,noreferrer');
            }
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not generate evidence pack.'),
    });

    const payload = complianceQuery.data || {};
    const agreement = payload.creator_agreement || {};
    const latestAgreement = agreement.latest || null;
    const kyc = payload.kyc || {};
    const content = payload.content_compliance || {};
    const declarations = Array.isArray(content.items) ? content.items : [];
    const exportDisabled = exportMutation.isPending || !exportReason.trim();

    const contentMeta = useMemo(() => {
        if (!declarations.length) return 'No upload declarations recorded yet.';
        const releaseCount = Number(content.pending_release_count || 0);
        return releaseCount > 0
            ? `${releaseCount} declaration${releaseCount === 1 ? '' : 's'} waiting for model-release handling.`
            : `${declarations.length} declaration${declarations.length === 1 ? '' : 's'} recorded.`;
    }, [content.pending_release_count, declarations.length]);

    return (
        <section className="crm-surface px-5 py-5">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-semibold text-slate-900">Compliance evidence</h3>
                        {complianceQuery.isFetching ? <span className="text-xs text-slate-400">Refreshing…</span> : null}
                    </div>
                    <p className="mt-2 max-w-3xl text-sm text-slate-500">
                        Agreement acceptance, KYC state and upload declarations for {client?.name || 'this client'}.
                    </p>
                </div>

                {canExport ? (
                    <button
                        type="button"
                        onClick={() => setShowExportDialog(true)}
                        className="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
                    >
                        Export evidence pack
                    </button>
                ) : null}
            </div>

            <div className="mt-5 grid gap-4 lg:grid-cols-3">
                <EvidenceCard
                    label="Creator agreement"
                    status={agreement.status}
                    meta={latestAgreement ? `${latestAgreement.version_key || 'Version'} accepted ${formatDate(latestAgreement.accepted_at)}` : null}
                />
                <EvidenceCard
                    label="KYC"
                    status={kyc.status}
                    meta={kyc.subject ? `Subject #${kyc.subject.id} · verified ${formatDate(kyc.subject.verified_at)}` : null}
                />
                <EvidenceCard
                    label="Content releases"
                    status={content.status}
                    meta={contentMeta}
                />
            </div>

            <div className="mt-5 grid gap-5 xl:grid-cols-[0.95fr_1.05fr]">
                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <h4 className="text-sm font-semibold text-slate-900">Agreement record</h4>
                    {latestAgreement ? (
                        <dl className="mt-4 grid gap-3 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Version</dt>
                                <dd className="font-medium text-slate-900">{latestAgreement.version_key || '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Context</dt>
                                <dd className="font-medium text-slate-900">{titleize(latestAgreement.source_context)}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">Accepted</dt>
                                <dd className="font-medium text-slate-900">{formatDate(latestAgreement.accepted_at)}</dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-slate-500">WP actor</dt>
                                <dd className="font-medium text-slate-900">{latestAgreement.actor_wp_user_id || latestAgreement.wp_user_id || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Body hash</dt>
                                <dd className="mt-1 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-xs text-slate-700">{latestAgreement.body_sha256 || '—'}</dd>
                            </div>
                        </dl>
                    ) : (
                        <div className="mt-4 rounded-xl border border-dashed border-rose-200 bg-rose-50 px-4 py-5 text-sm text-rose-700">
                            No creator agreement acceptance has been mirrored into the CRM.
                        </div>
                    )}
                </div>

                <div className="rounded-xl border border-slate-200 bg-white p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h4 className="text-sm font-semibold text-slate-900">Upload declarations</h4>
                            <p className="mt-1 text-xs text-slate-500">New uploads should be solo-declared or blocked for release handling.</p>
                        </div>
                        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${statusBadge(content.status)}`}>
                            {titleize(content.status || 'missing')}
                        </span>
                    </div>

                    {declarations.length ? (
                        <div className="mt-4 overflow-hidden rounded-xl border border-slate-200">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-400">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold">Content</th>
                                        <th className="px-3 py-2 text-left font-semibold">People</th>
                                        <th className="px-3 py-2 text-left font-semibold">Status</th>
                                        <th className="px-3 py-2 text-left font-semibold">Declared</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {declarations.slice(0, 30).map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-3 py-2 text-slate-700">
                                                <div className="font-medium text-slate-900">{titleize(item.content_kind)}</div>
                                                <div className="text-xs text-slate-500">Post {item.wp_post_id}{item.wp_attachment_id ? ` · media ${item.wp_attachment_id}` : ''}</div>
                                            </td>
                                            <td className="px-3 py-2 text-slate-600">{titleize(item.participant_status)}</td>
                                            <td className="px-3 py-2">
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset ${statusBadge(item.status)}`}>
                                                    {titleize(item.status)}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2 text-slate-600">{formatDate(item.declared_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                            No content declarations have been recorded for this profile yet.
                        </div>
                    )}
                </div>
            </div>

            {showExportDialog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="border-b border-slate-200 px-6 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Export compliance evidence</h3>
                            <p className="mt-1 text-sm text-slate-500">The export reason is stored with the evidence-pack audit trail.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5">
                            <label className="block">
                                <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Reason</span>
                                <textarea
                                    value={exportReason}
                                    onChange={(event) => setExportReason(event.target.value)}
                                    rows={4}
                                    className="crm-textarea mt-2 min-h-[112px] w-full"
                                />
                            </label>
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button type="button" onClick={() => setShowExportDialog(false)} className="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={() => exportMutation.mutate()}
                                    disabled={exportDisabled}
                                    className="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {exportMutation.isPending ? 'Generating…' : 'Generate export'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
