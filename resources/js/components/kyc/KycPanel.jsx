import React, { useMemo, useRef, useState } from 'react';
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

function formatBytes(bytes) {
    const size = Number(bytes || 0);
    if (!size) return '0 MB';
    return `${(size / 1024 / 1024).toFixed(2)} MB`;
}

function channelPresentation(channel) {
    if (channel === 'whatsapp') return 'WhatsApp';
    if (channel === 'support_chat') return 'Support chat';
    if (channel === 'manual_assisted') return 'Manual assisted';
    if (channel === 'email') return 'Email';
    return titleize(channel);
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
    const [uploadKind, setUploadKind] = useState('id_front');
    const [uploadSourceChannel, setUploadSourceChannel] = useState('whatsapp');
    const [uploadNote, setUploadNote] = useState('');
    const [uploadFile, setUploadFile] = useState(null);
    const [pendingDeleteDocumentId, setPendingDeleteDocumentId] = useState(null);
    const [replaceContext, setReplaceContext] = useState(null);
    const uploaderCardRef = useRef(null);

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
    const reviewCapabilities = subjectQuery.data?.review_capabilities || {};
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

    const uploadMutation = useMutation({
        mutationFn: () => kyc.uploadDocument(subjectId, {
            kind: uploadKind,
            upload_source_channel: uploadSourceChannel,
            upload_note: uploadNote.trim(),
            file: uploadFile,
        }),
        onSuccess: () => {
            const replacedLabel = replaceContext ? titleize(replaceContext.kind) : null;
            setUploadFile(null);
            setUploadNote('');
            setReplaceContext(null);
            const input = document.getElementById(`kyc-staff-upload-${subjectId}`);
            if (input) input.value = '';
            refreshAll();
            toast.success(replacedLabel ? `${replacedLabel} replaced from CRM.` : 'KYC document uploaded from CRM.');
        },
        onError: (error) => {
            const message = error?.response?.data?.message || error?.response?.data?.errors?.file?.[0] || 'Could not upload this KYC document.';
            toast.error(message);
        },
    });

    const deleteDocumentMutation = useMutation({
        mutationFn: (documentId) => kyc.deleteDocument(subjectId, documentId),
        onSuccess: () => {
            setPendingDeleteDocumentId(null);
            refreshAll();
            toast.success('KYC document removed.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not delete this KYC document.'),
    });

    const canEmergencyVerify = useMemo(() => (user?.role || '') === 'admin' && !client?.verified, [user?.role, client?.verified]);
    const canManagePublicBadge = ['admin', 'sub_admin', 'sales'].includes(String(user?.role || ''));
    const canActOnSubject = canReview && Boolean(subjectId);
    const canStaffUpload = ['admin', 'sub_admin', 'sales'].includes(String(user?.role || '')) && Boolean(subjectId);
    const lastReviewer = subject?.reviewer?.name;
    const documentKindOptions = reviewCapabilities.allowed_document_kinds || ['id_front', 'id_back', 'selfie'];
    const sourceChannelOptions = reviewCapabilities.allowed_staff_upload_channels || ['whatsapp', 'support_chat', 'email', 'manual_assisted'];
    const handlePrepareReplace = (document) => {
        setUploadKind(document.kind || 'id_front');
        setUploadSourceChannel(document.upload_source_channel || 'manual_assisted');
        setUploadNote(document.upload_note || `Replacing previous ${titleize(document.kind)} from ${channelPresentation(document.upload_source_channel || 'manual_assisted').toLowerCase()}.`);
        setReplaceContext({ id: document.id, kind: document.kind });
        setPendingDeleteDocumentId(null);
        const input = document.getElementById(`kyc-staff-upload-${subjectId}`);
        if (input) input.value = '';
        setUploadFile(null);
        window.requestAnimationFrame(() => {
            uploaderCardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            input?.focus();
        });
    };

    const handleDeleteDocument = (documentId) => {
        deleteDocumentMutation.mutate(documentId);
    };

    const uploadDisabled = uploadMutation.isPending || !uploadFile || !uploadNote.trim() || !uploadKind || !uploadSourceChannel;

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
                    <p className="mt-2 max-w-3xl text-sm text-slate-500">This is the primary reviewer surface. Approvals set <span className="font-medium text-slate-700">verified_source=kyc</span>, manual fallbacks remain explicit, and staff-assisted uploads preserve source-channel provenance.</p>
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

                <div className="space-y-4">
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
                            <div className="mt-4 grid gap-3">
                                {documents.map((document, index) => (
                                    <div key={document.id} className="rounded-xl border border-slate-200 bg-slate-50/70 p-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{titleize(document.kind)}</p>
                                                <p className="mt-1 text-xs text-slate-500">{document.mime} • {formatBytes(document.byte_size)}</p>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {document.upload_origin === 'crm_staff' ? (
                                                        <span className="inline-flex items-center rounded-md bg-sky-50 px-2 py-1 text-[11px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                                                            Staff upload via {channelPresentation(document.upload_source_channel)}
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">
                                                            Advertiser upload
                                                        </span>
                                                    )}
                                                    {document.uploaded_by_name ? (
                                                        <span className="inline-flex items-center rounded-md bg-white px-2 py-1 text-[11px] font-medium text-slate-600 ring-1 ring-inset ring-slate-200">
                                                            Added by {document.uploaded_by_name}
                                                        </span>
                                                    ) : null}
                                                </div>
                                                {document.upload_note ? <p className="mt-2 text-xs leading-5 text-slate-600">{document.upload_note}</p> : null}
                                                <p className="mt-2 text-[11px] text-slate-400">Uploaded {formatDate(document.uploaded_at)}</p>
                                            </div>
                                            <div className="flex flex-col items-end gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setViewerIndex(index)}
                                                    className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                                >
                                                    View
                                                </button>
                                                {canStaffUpload && document.upload_origin === 'crm_staff' ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => handlePrepareReplace(document)}
                                                        className="inline-flex items-center rounded-lg border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-100"
                                                    >
                                                        Replace
                                                    </button>
                                                ) : null}
                                                {canStaffUpload && document.upload_origin === 'crm_staff' ? (
                                                    pendingDeleteDocumentId === document.id ? (
                                                        <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-right">
                                                            <p className="text-[11px] font-medium text-rose-700">Delete this staff-uploaded file?</p>
                                                            <div className="mt-2 flex justify-end gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setPendingDeleteDocumentId(null)}
                                                                    className="inline-flex items-center rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600 transition hover:bg-slate-50"
                                                                >
                                                                    Cancel
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleDeleteDocument(document.id)}
                                                                    disabled={deleteDocumentMutation.isPending}
                                                                    className="inline-flex items-center rounded-md bg-rose-600 px-2.5 py-1 text-[11px] font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                                >
                                                                    {deleteDocumentMutation.isPending ? 'Deleting…' : 'Confirm'}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() => setPendingDeleteDocumentId(document.id)}
                                                            className="inline-flex items-center rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                                        >
                                                            Delete
                                                        </button>
                                                    )
                                                ) : null}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {canStaffUpload ? (
                        <div ref={uploaderCardRef} className="rounded-2xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h4 className="text-sm font-semibold text-slate-900">Upload on behalf of client</h4>
                                    <p className="mt-1 text-xs text-slate-500">Use this when a client sends KYC through WhatsApp, support chat, or email. The source channel and reviewer note are kept with the document.</p>
                                </div>
                                <span className="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Admin / sub-admin / sales</span>
                            </div>

                            {replaceContext ? (
                                <div className="mt-4 flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50 px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-sky-700">Replace mode</p>
                                        <p className="mt-1 text-sm text-sky-900">You are replacing the current {titleize(replaceContext.kind)} file. Uploading here will swap it in-place and keep the audit trail intact.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setReplaceContext(null)}
                                        className="inline-flex items-center justify-center rounded-lg border border-sky-200 bg-white px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100"
                                    >
                                        Cancel replace
                                    </button>
                                </div>
                            ) : null}

                            {uploadSourceChannel === 'whatsapp' ? (
                                <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-800">
                                    WhatsApp often compresses images. If the ID is blurry, ask the client to resend it as a document/file or request a clearer replacement.
                                </div>
                            ) : null}

                            <div className="mt-4 grid gap-4 md:grid-cols-2">
                                <label className="block">
                                    <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Document type</span>
                                    <select value={uploadKind} onChange={(event) => setUploadKind(event.target.value)} className="crm-select mt-2 w-full">
                                        {documentKindOptions.map((kind) => <option key={kind} value={kind}>{titleize(kind)}</option>)}
                                    </select>
                                </label>

                                <label className="block">
                                    <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Source channel</span>
                                    <select value={uploadSourceChannel} onChange={(event) => setUploadSourceChannel(event.target.value)} className="crm-select mt-2 w-full">
                                        {sourceChannelOptions.map((channel) => <option key={channel} value={channel}>{channelPresentation(channel)}</option>)}
                                    </select>
                                </label>
                            </div>

                            <label className="mt-4 block">
                                <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">Reviewer note</span>
                                <textarea
                                    value={uploadNote}
                                    onChange={(event) => setUploadNote(event.target.value)}
                                    rows={3}
                                    className="crm-textarea mt-2 min-h-[96px] w-full"
                                    placeholder="Example: Received via WhatsApp from the client’s registered number."
                                />
                            </label>

                            <label className="mt-4 block">
                                <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">File</span>
                                <input
                                    id={`kyc-staff-upload-${subjectId}`}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    onChange={(event) => setUploadFile(event.target.files?.[0] || null)}
                                    className="mt-2 block w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-3 text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-800"
                                />
                                <p className="mt-2 text-xs text-slate-500">Accepted: JPG, PNG, WEBP, PDF. Uploads go straight into the same KYC subject and can replace an older file of the same type.</p>
                            </label>

                            <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-xs text-slate-500">This does not masquerade as advertiser self-upload. Provenance stays visible to reviewers and in audit logs.</p>
                                <button
                                    type="button"
                                    onClick={() => uploadMutation.mutate()}
                                    disabled={uploadDisabled}
                                    className="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {uploadMutation.isPending ? 'Uploading…' : (replaceContext ? `Replace ${titleize(replaceContext.kind)}` : 'Upload document')}
                                </button>
                            </div>
                        </div>
                    ) : null}
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
