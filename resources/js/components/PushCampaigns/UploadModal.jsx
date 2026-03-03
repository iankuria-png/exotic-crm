import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import PresetWizard from './PresetWizard';

function extractDomain(url) {
    try {
        const value = new URL(url.startsWith('http') ? url : `https://${url}`);
        return value.hostname.replace(/^www\./i, '').toLowerCase();
    } catch (_) {
        return null;
    }
}

function statusLabel(status, dryRun = false) {
    if (status === 'queued') return 'Upload queued';
    if (status === 'processing') return 'Parsing sheets';
    if (status === 'extracting') return 'Extracting profiles';
    if (status === 'ready' && dryRun) return 'Dry run complete';
    if (status === 'ready') return 'Ready for confirmation';
    if (status === 'failed') return 'Upload failed';
    return 'Processing';
}

function formatBytes(bytes) {
    if (!Number.isFinite(Number(bytes)) || Number(bytes) <= 0) {
        return 'n/a';
    }

    const value = Number(bytes);
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = value;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

export default function UploadModal({ open, onClose, onCreated }) {
    const toast = useToast();
    const [file, setFile] = useState(null);
    const [dryRun, setDryRun] = useState(false);
    const [batchId, setBatchId] = useState(null);
    const [statusPayload, setStatusPayload] = useState(null);
    const [selectedDomain, setSelectedDomain] = useState(null);

    const limitsQuery = useQuery({
        enabled: open,
        queryKey: ['push-upload-limits'],
        queryFn: () => api.get('/crm/push-campaigns/upload/limits').then((response) => response.data),
        staleTime: 60_000,
    });

    const uploadMutation = useMutation({
        mutationFn: (selectedFile) => {
            const uploadMaxBytes = Number(limitsQuery.data?.upload_max_bytes || 0);
            const postMaxBytes = Number(limitsQuery.data?.post_max_bytes || 0);
            const effectiveLimit = [uploadMaxBytes, postMaxBytes]
                .filter((value) => Number.isFinite(value) && value > 0)
                .reduce((min, value) => Math.min(min, value), Number.POSITIVE_INFINITY);

            if (Number.isFinite(effectiveLimit) && selectedFile.size > effectiveLimit) {
                throw new Error(
                    `Selected file is ${formatBytes(selectedFile.size)} but server request limit is ${formatBytes(effectiveLimit)}. Increase PHP upload/post limits to continue.`
                );
            }

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('dry_run', dryRun ? '1' : '0');
            return api.post('/crm/push-campaigns/upload', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            }).then((response) => response.data);
        },
        onSuccess: (response) => {
            setBatchId(response?.batch_id || null);
            setStatusPayload(response || null);
            if (response?.dry_run) {
                toast.success('Workbook uploaded for dry run. Parsing has started.');
            } else {
                toast.success('Workbook uploaded. Parsing has started.');
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || error?.message || 'Upload failed.');
        },
    });

    const confirmMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/push-campaigns', payload).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(`Confirmed ${response?.confirmed_count || 0} campaign(s).`);
            onCreated?.(response);
            onClose?.();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to confirm campaigns.');
        },
    });

    const refreshStatus = async (id) => {
        if (!id) return;

        try {
            const response = await api.get(`/crm/push-campaigns/upload/${id}/status`);
            setStatusPayload(response.data || null);
        } catch (error) {
            if (error?.response?.status === 404) {
                return;
            }

            toast.error(error?.response?.data?.message || 'Failed to fetch upload progress.');
        }
    };

    useEffect(() => {
        if (!open) {
            setFile(null);
            setDryRun(false);
            setBatchId(null);
            setStatusPayload(null);
            setSelectedDomain(null);
        }
    }, [open]);

    useEffect(() => {
        if (!open || !batchId) {
            return undefined;
        }

        refreshStatus(batchId);

        const interval = window.setInterval(() => {
            refreshStatus(batchId);
        }, 3000);

        return () => window.clearInterval(interval);
    }, [batchId, open]);

    const needsPresetDomains = useMemo(() => {
        const domains = new Set();

        (statusPayload?.campaigns || []).forEach((campaign) => {
            (campaign?.sample_items || []).forEach((item) => {
                if (item?.status !== 'needs_preset') {
                    return;
                }

                const domain = extractDomain(item?.profile_url || '');
                if (domain) {
                    domains.add(domain);
                }
            });
        });

        return Array.from(domains.values());
    }, [statusPayload]);

    useEffect(() => {
        if (needsPresetDomains.length === 0) {
            setSelectedDomain(null);
            return;
        }

        if (!selectedDomain || !needsPresetDomains.includes(selectedDomain)) {
            setSelectedDomain(needsPresetDomains[0]);
        }
    }, [needsPresetDomains, selectedDomain]);

    if (!open) {
        return null;
    }

    const status = statusPayload?.status || null;
    const canConfirm = Boolean(batchId) && status === 'ready' && !Boolean(statusPayload?.dry_run);
    const fileSizeText = file ? formatBytes(file.size) : 'n/a';
    const uploadLimitText = formatBytes(limitsQuery.data?.upload_max_bytes);
    const postLimitText = formatBytes(limitsQuery.data?.post_max_bytes);

    return (
        <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/60 p-4">
            <div className="w-full max-w-5xl rounded-xl bg-white shadow-xl">
                <header className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-900">Upload Push Workbook</h3>
                        <p className="text-xs text-slate-500">Upload `.xlsx` workbook and monitor parsing + extraction progress.</p>
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5">Close</button>
                </header>

                <div className="space-y-4 p-4">
                    <section className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div className="grid gap-2 md:grid-cols-[1fr_auto]">
                            <input
                                type="file"
                                accept=".xlsx,.xls"
                                onChange={(event) => setFile(event.target.files?.[0] || null)}
                                className="crm-input"
                                disabled={uploadMutation.isPending || Boolean(batchId)}
                            />
                            <button
                                type="button"
                                onClick={() => file && uploadMutation.mutate(file)}
                                disabled={!file || uploadMutation.isPending || Boolean(batchId)}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {uploadMutation.isPending ? 'Uploading...' : 'Upload workbook'}
                            </button>
                        </div>
                        <label className="mt-2 flex items-center gap-2 text-xs text-slate-700">
                            <input
                                type="checkbox"
                                checked={dryRun}
                                onChange={(event) => setDryRun(event.target.checked)}
                                disabled={uploadMutation.isPending || Boolean(batchId)}
                            />
                            Dry run only (validate parsing without creating campaigns)
                        </label>
                        <p className="mt-2 text-xs text-slate-600">
                            File size: <span className="font-semibold">{fileSizeText}</span> • server limits: upload <span className="font-semibold">{uploadLimitText}</span>, post <span className="font-semibold">{postLimitText}</span>
                        </p>
                        <p className="mt-1 text-xs text-amber-700">
                            For large workbook imports, increase PHP limits (example: `upload_max_filesize=64M`, `post_max_size=64M`) then restart the app server.
                        </p>
                        {batchId ? (
                            <p className="mt-2 text-xs text-slate-600">Batch: <span className="font-mono">{batchId}</span></p>
                        ) : null}
                    </section>

                    {statusPayload ? (
                        <section className="rounded-lg border border-slate-200 bg-white p-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h4 className="text-sm font-semibold text-slate-900">Processing Status</h4>
                                <div className="flex items-center gap-2">
                                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                        {statusLabel(status, Boolean(statusPayload?.dry_run))}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => refreshStatus(batchId)}
                                        className="crm-btn-secondary px-2 py-1 text-xs"
                                    >
                                        Refresh
                                    </button>
                                </div>
                            </div>

                            <div className="mt-2 grid gap-2 text-xs text-slate-600 md:grid-cols-4">
                                <p><span className="font-semibold text-slate-800">Sheets:</span> {statusPayload?.sheets_parsed || 0}</p>
                                <p><span className="font-semibold text-slate-800">Items:</span> {(statusPayload?.total_items || 0).toLocaleString()}</p>
                                <p><span className="font-semibold text-slate-800">Profiles:</span> {(statusPayload?.profiles_processed || 0).toLocaleString()}</p>
                                <p><span className="font-semibold text-slate-800">Year:</span> {statusPayload?.year || 'n/a'}</p>
                            </div>

                            {(statusPayload?.message || '').trim() ? (
                                <p className="mt-2 rounded-md border border-blue-200 bg-blue-50 px-2 py-1 text-xs text-blue-800">
                                    {statusPayload.message}
                                </p>
                            ) : null}

                            {(statusPayload?.error || '').trim() ? (
                                <p className="mt-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-800">
                                    {statusPayload.error}
                                </p>
                            ) : null}

                            {(statusPayload?.unmapped_sheets || []).length > 0 ? (
                                <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                                    Unmapped sheets: {(statusPayload.unmapped_sheets || []).join(', ')}
                                </p>
                            ) : null}
                        </section>
                    ) : null}

                    {(statusPayload?.campaigns || []).length > 0 ? (
                        <section className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <h4 className="text-sm font-semibold text-slate-900">Campaign Preview</h4>
                            {(statusPayload.campaigns || []).map((campaign) => (
                                <article key={campaign.id} className="rounded-md border border-slate-200 bg-white p-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm font-semibold text-slate-900">{campaign.name}</p>
                                        <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                            {campaign.platform?.name || 'Market'} • {campaign.total_items || 0} items
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">
                                        pending: {campaign.pending_count || 0} • needs preset: {campaign.needs_preset_count || 0} • failed: {campaign.failed_count || 0}
                                    </p>

                                    {(campaign.sample_items || []).length > 0 ? (
                                        <div className="mt-2 overflow-auto">
                                            <table className="min-w-full text-xs">
                                                <thead>
                                                    <tr className="text-left text-slate-500">
                                                        <th className="px-2 py-1 font-medium">Date</th>
                                                        <th className="px-2 py-1 font-medium">URL</th>
                                                        <th className="px-2 py-1 font-medium">Name</th>
                                                        <th className="px-2 py-1 font-medium">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {(campaign.sample_items || []).map((item) => (
                                                        <tr key={item.id} className="border-t border-slate-100">
                                                            <td className="px-2 py-1">{item.date_label || '--'}</td>
                                                            <td className="max-w-[220px] truncate px-2 py-1">{item.profile_url}</td>
                                                            <td className="px-2 py-1">{item.profile_name || '--'}</td>
                                                            <td className="px-2 py-1">{item.status}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : null}
                                </article>
                            ))}
                        </section>
                    ) : null}

                    {selectedDomain ? (
                        <PresetWizard
                            domain={selectedDomain}
                            onSaved={() => refreshStatus(batchId)}
                            onCancel={() => setSelectedDomain(null)}
                        />
                    ) : null}
                </div>

                <footer className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                    <p className="text-xs text-slate-500">
                        {statusPayload?.dry_run
                            ? 'Dry run complete. Upload again with dry-run disabled to create campaigns.'
                            : (canConfirm
                                ? 'Processing complete. Confirm campaigns to unlock execute/schedule actions.'
                                : 'Waiting for processing to finish before confirmation.')}
                    </p>
                    <button
                        type="button"
                        onClick={() => confirmMutation.mutate({ batch_id: batchId })}
                        disabled={!canConfirm || confirmMutation.isPending}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {confirmMutation.isPending ? 'Confirming...' : 'Confirm & create'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
