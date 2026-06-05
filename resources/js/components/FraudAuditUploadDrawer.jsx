import React, { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';

export default function FraudAuditUploadDrawer({ open, onClose, platformOptions, onPreviewSaved }) {
    const toast = useToast();
    const [platformId, setPlatformId] = useState('');
    const [file, setFile] = useState(null);
    const [pastedText, setPastedText] = useState('');
    const [reason, setReason] = useState('Fraud reconciliation review');
    const [preview, setPreview] = useState(null);

    useEffect(() => {
        if (!open) return;
        setPlatformId('');
        setFile(null);
        setPastedText('');
        setReason('Fraud reconciliation review');
        setPreview(null);
    }, [open]);

    const previewMutation = useMutation({
        mutationFn: async () => {
            const formData = new FormData();
            formData.append('platform_id', platformId);
            formData.append('reason', reason);
            formData.append('has_header', '1');
            if (file) {
                formData.append('file', file);
            } else {
                formData.append('pasted_text', pastedText);
            }

            const response = await api.post('/crm/payments/reconcile/preview', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return response.data;
        },
        onSuccess: (result) => {
            setPreview(result);
            onPreviewSaved?.(result);
            toast.success('Fraud audit batch saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Fraud audit preview failed.');
        },
    });

    if (!open) {
        return null;
    }

    const canSubmit = platformId && reason.trim() && (file || pastedText.trim()) && !previewMutation.isPending;
    const summary = preview?.summary || {};

    return (
        <div className="fixed inset-0 z-[100] flex bg-slate-900/45" onClick={onClose}>
            <aside
                className="ml-auto flex h-full w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header border-b border-slate-100">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h3 className="crm-panel-title">Upload fraud audit sheet</h3>
                            <p className="crm-panel-subtitle">Compare external collection records against CRM payments.</p>
                        </div>
                        <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5 text-xs">
                            Close
                        </button>
                    </div>
                </header>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Market</span>
                        <select
                            value={platformId}
                            onChange={(event) => setPlatformId(event.target.value)}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        >
                            <option value="">Select market</option>
                            {(platformOptions || []).map((platform) => (
                                <option key={platform.platform_id || platform.id} value={platform.platform_id || platform.id}>
                                    {platform.label || platform.name || platform.country}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">CSV / XLSX / XML file</span>
                        <input
                            type="file"
                            accept=".csv,.txt,.xlsx,.xml"
                            onChange={(event) => setFile(event.target.files?.[0] || null)}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </label>

                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Paste rows or a single code</span>
                        <textarea
                            value={pastedText}
                            onChange={(event) => setPastedText(event.target.value)}
                            disabled={Boolean(file)}
                            rows={8}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-400"
                            placeholder={'Client Name\tAmount Paid\tDate Paid\tTransaction ID'}
                        />
                        {file ? <span className="mt-1 block text-xs text-slate-500">File selected, pasted text will be ignored.</span> : null}
                    </label>

                    <label className="block">
                        <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Reason</span>
                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={3}
                            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        />
                    </label>

                    {preview ? (
                        <section className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                            <h4 className="text-sm font-semibold text-emerald-900">Batch #{preview.batch_id} saved</h4>
                            <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-emerald-800 sm:grid-cols-3">
                                <span>Total {summary.total_rows ?? 0}</span>
                                <span>Matched {summary.matched_rows ?? 0}</span>
                                <span>Mismatch {summary.mismatch_rows ?? 0}</span>
                                <span>Missing {summary.missing_rows ?? 0}</span>
                                <span>Unverifiable {summary.unverifiable_rows ?? 0}</span>
                                <span>Duplicate {summary.duplicate_rows ?? 0}</span>
                            </div>
                        </section>
                    ) : null}
                </div>

                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary">
                        {preview ? 'Done' : 'Cancel'}
                    </button>
                    <button
                        type="button"
                        onClick={() => previewMutation.mutate()}
                        disabled={!canSubmit || Boolean(preview)}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {previewMutation.isPending ? 'Saving...' : 'Save to queue'}
                    </button>
                </footer>
            </aside>
        </div>
    );
}
