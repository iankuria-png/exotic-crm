import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useToast } from '../ToastProvider';
import { useAuth } from '../../hooks/useAuth';
import kyc from '../../services/kyc';
import KycRejectDialog from './KycRejectDialog';
import KycRequestInfoDialog from './KycRequestInfoDialog';
import KycDocumentViewer from './KycDocumentViewer';

function statusPresentation(status) {
    const value = String(status || 'unverified');
    if (value === 'approved') return { label: 'Approved', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' };
    if (value === 'in_review') return { label: 'In review', className: 'bg-sky-50 text-sky-700 ring-sky-200' };
    if (value === 'info_requested') return { label: 'Info requested', className: 'bg-amber-50 text-amber-700 ring-amber-200' };
    if (value === 'rejected') return { label: 'Rejected', className: 'bg-rose-50 text-rose-700 ring-rose-200' };
    if (value === 'expired') return { label: 'Reverification due', className: 'bg-violet-50 text-violet-700 ring-violet-200' };
    return { label: 'Unverified', className: 'bg-slate-100 text-slate-700 ring-slate-200' };
}

function sourcePresentation(source) {
    if (source === 'kyc') return { label: 'Verified via KYC', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200' };
    if (source === 'manual_wp') return { label: 'Verified in WordPress', className: 'bg-amber-50 text-amber-700 ring-amber-200' };
    if (source === 'manual_crm_emergency') return { label: 'Emergency CRM verify', className: 'bg-rose-50 text-rose-700 ring-rose-200' };
    return null;
}

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function titleize(value) {
    return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

export default function KycPanel({ client, canReview = true }) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const { user } = useAuth();
    const [showRejectDialog, setShowRejectDialog] = useState(false);
    const [showRequestInfoDialog, setShowRequestInfoDialog] = useState(false);
    const [showEmergencyDialog, setShowEmergencyDialog] = useState(false);
    const [emergencyReason, setEmergencyReason] = useState('');
    const [showRemoveDialog, setShowRemoveDialog] = useState(false);
    const [viewerIndex, setViewerIndex] = useState(null);

    const subjectSummary = client?.kyc_subject || client?.kycSubject || null;
    const subjectId = Number(subjectSummary?.id || 0) || null;
    const kycRequired = client?.kyc_required !== false;

    const subjectQuery = useQuery({
        queryKey: ['kyc-subject', subjectId],
        queryFn: () => kyc.getSubject(subjectId),
        enabled: Boolean(subjectId),
    });

    const subject = subjectQuery.data?.subject || subjectSummary;
    const documents = subjectQuery.data?.documents || [];
    const statusPayload = subjectQuery.data?.status_payload || {};
    const status = statusPresentation(subject?.status || statusPayload.status);
    const source = sourcePresentation(client?.verified_source || statusPayload.verified_source);

    const refreshAll = () => {
        if (subjectId) {
            queryClient.invalidateQueries({ queryKey: ['kyc-subject', subjectId] });
        }
        queryClient.invalidateQueries({ queryKey: ['client', String(client?.id || '')] });
        queryClient.invalidateQueries({ queryKey: ['client', client?.id] });
        queryClient.invalidateQueries({ queryKey: ['client-timeline', String(client?.id || '')] });
        queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
        queryClient.invalidateQueries({ queryKey: ['kyc-queue'] });
        queryClient.invalidateQueries({ queryKey: ['kyc-queue-count'] });
    };

    const approveMutation = useMutation({
        mutationFn: () => kyc.approveSubject(subjectId, { reason: 'Approved via KYC review' }),
        onSuccess: () => {
            refreshAll();
            toast.success('KYC approved and verified badge synced.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not approve this subject.'),
    });

    const rejectMutation = useMutation({
        mutationFn: (payload) => kyc.rejectSubject(subjectId, payload),
        onSuccess: () => {
            setShowRejectDialog(false);
            refreshAll();
            toast.success('Subject rejected.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not reject this subject.'),
    });

    const requestInfoMutation = useMutation({
        mutationFn: (payload) => kyc.requestInfo(subjectId, payload),
        onSuccess: () => {
            setShowRequestInfoDialog(false);
            refreshAll();
            toast.success('Advertiser has been asked for more information.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not request more information.'),
    });

    const reRequestMutation = useMutation({
        mutationFn: () => kyc.reRequest(subjectId, { reason: 'Manual re-verification requested from client detail' }),
        onSuccess: () => {
            refreshAll();
            toast.success('Re-verification requested.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not start re-verification.'),
    });

    const removeBadgeMutation = useMutation({
        mutationFn: () => kyc.setClientVerified(client.id, { verified: false }),
        onSuccess: () => {
            setShowRemoveDialog(false);
            refreshAll();
            toast.success('Public verified badge removed.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not remove the verified badge.'),
    });

    const emergencyVerifyMutation = useMutation({
        mutationFn: () => kyc.setClientVerified(client.id, {
            verified: true,
            source: 'manual_crm_emergency',
            reason: emergencyReason.trim(),
        }),
        onSuccess: () => {
            setShowEmergencyDialog(false);
            setEmergencyReason('');
            refreshAll();
            toast.success('Emergency verified badge applied.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not apply emergency verification.'),
    });

    const canEmergencyVerify = useMemo(() => (user?.role || '') === 'admin' && !client?.verified, [user?.role, client?.verified]);
    const canManagePublicBadge = ['admin', 'sub_admin', 'sales'].includes(String(user?.role || ''));
    const canActOnSubject = canReview && Boolean(subjectId);
    const lastReviewer = subject?.reviewer?.name;

    return (
        <section className="crm-surface px-5 py-5">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-lg font-semibold text-slate-900">KYC review</h3>
                        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${status.className}`}>{status.label}</span>
                        {source ? <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${source.className}`}>{source.label}</span> : null}
                        {!kycRequired ? <span className="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200">Exempt from queue</span> : null}
                    </div>
                    <p className="mt-2 max-w-3xl text-sm text-slate-500">This is the primary reviewer surface. Approvals set <span className="font-medium text-slate-700">verified_source=kyc</span>, while manual fallbacks remain explicit and auditable.</p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {canActOnSubject ? (
                        <>
                            <button
                                type="button"
                                onClick={() => approveMutation.mutate()}
                                disabled={approveMutation.isPending || documents.length === 0}
                                className="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {approveMutation.isPending ? 'Approving…' : 'Approve'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowRequestInfoDialog(true)}
                                disabled={requestInfoMutation.isPending}
                                className="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Request info
                            </button>
                            <button
                                type="button"
                                onClick={() => setShowRejectDialog(true)}
                                disabled={rejectMutation.isPending}
                                className="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Reject
                            </button>
                        </>
                    ) : null}

                    {canActOnSubject && ['approved', 'rejected', 'expired'].includes(String(subject?.status || '')) ? (
                        <button
                            type="button"
                            onClick={() => reRequestMutation.mutate()}
                            disabled={reRequestMutation.isPending}
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {reRequestMutation.isPending ? 'Updating…' : 'Re-request verification'}
                        </button>
                    ) : null}

                    {client?.verified && canManagePublicBadge ? (
                        <button
                            type="button"
                            onClick={() => setShowRemoveDialog(true)}
                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            Remove public badge
                        </button>
                    ) : null}

                    {canEmergencyVerify ? (
                        <button
                            type="button"
                            onClick={() => setShowEmergencyDialog(true)}
                            className="inline-flex items-center rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-50"
                        >
                            Emergency verify
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="mt-5 grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
                <div className="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Verified at</p>
                            <p className="mt-1 text-sm font-medium text-slate-900">{formatDate(subject?.verified_at || statusPayload.verified_at)}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Expires</p>
                            <p className="mt-1 text-sm font-medium text-slate-900">{formatDate(subject?.expires_at || statusPayload.expires_at)}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Last reviewer</p>
                            <p className="mt-1 text-sm font-medium text-slate-900">{lastReviewer || '—'}</p>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Advertiser-facing note</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{subject?.last_reason_user || statusPayload.last_reason_user || '—'}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Internal note</p>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{subject?.last_reason_internal || '—'}</p>
                        </div>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-4">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <h4 className="text-sm font-semibold text-slate-900">Documents</h4>
                            <p className="mt-1 text-xs text-slate-500">Every document view is audited. DB mode streams decrypted content; S3 mode stays signed and temporary.</p>
                        </div>
                        {subjectQuery.isLoading ? <span className="text-xs text-slate-400">Loading…</span> : null}
                    </div>

                    {documents.length === 0 ? (
                        <div className="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                            No documents uploaded yet. The subject will enter review automatically after the required files arrive.
                        </div>
                    ) : (
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            {documents.map((document, index) => (
                                <div key={document.id} className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{titleize(document.kind)}</p>
                                            <p className="mt-1 text-xs text-slate-500">{document.mime} • {(Number(document.byte_size || 0) / 1024 / 1024).toFixed(2)} MB</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setViewerIndex(index)}
                                            className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            View
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {showEmergencyDialog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="border-b border-slate-200 px-6 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Emergency verify</h3>
                            <p className="mt-1 text-sm text-slate-500">This is visually distinct on purpose. Use it only when KYC cannot be completed normally and the reason must be recoverable later.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5">
                            <textarea
                                value={emergencyReason}
                                onChange={(event) => setEmergencyReason(event.target.value)}
                                rows={4}
                                className="crm-textarea min-h-[120px] w-full"
                                placeholder="Explain why this admin-only emergency verification is necessary."
                            />
                            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    onClick={() => setShowEmergencyDialog(false)}
                                    className="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    disabled={emergencyVerifyMutation.isPending || !emergencyReason.trim()}
                                    onClick={() => emergencyVerifyMutation.mutate()}
                                    className="inline-flex items-center justify-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {emergencyVerifyMutation.isPending ? 'Saving…' : 'Apply emergency verify'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}

            {showRemoveDialog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl">
                        <div className="border-b border-slate-200 px-6 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Remove public verified badge?</h3>
                            <p className="mt-1 text-sm text-slate-500">This clears the public verified signal in WordPress. It does not silently rewrite the KYC audit trail.</p>
                        </div>
                        <div className="flex flex-col-reverse gap-2 px-6 py-5 sm:flex-row sm:justify-end">
                            <button type="button" onClick={() => setShowRemoveDialog(false)} className="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">Cancel</button>
                            <button type="button" onClick={() => removeBadgeMutation.mutate()} disabled={removeBadgeMutation.isPending} className="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">{removeBadgeMutation.isPending ? 'Updating…' : 'Remove badge'}</button>
                        </div>
                    </div>
                </div>
            ) : null}

            <KycRejectDialog
                open={showRejectDialog}
                onClose={() => setShowRejectDialog(false)}
                onSubmit={(payload) => rejectMutation.mutate(payload)}
                isPending={rejectMutation.isPending}
            />
            <KycRequestInfoDialog
                open={showRequestInfoDialog}
                onClose={() => setShowRequestInfoDialog(false)}
                onSubmit={(payload) => requestInfoMutation.mutate(payload)}
                isPending={requestInfoMutation.isPending}
            />
            <KycDocumentViewer
                open={viewerIndex !== null}
                documents={documents}
                initialIndex={viewerIndex || 0}
                onClose={() => setViewerIndex(null)}
            />
        </section>
    );
}
