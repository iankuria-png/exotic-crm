import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';

const QUERY_KEY = ['settings', 'wp-shared-key'];

function statusChip(source) {
    if (source === 'database') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (source === 'env') return 'bg-amber-50 text-amber-700 ring-amber-200';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function sourceLabel(source) {
    if (source === 'database') return 'Database (managed here)';
    if (source === 'env') return '.env fallback';
    return 'Not configured';
}

export default function WordPressSyncKeyCard() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [revealedKey, setRevealedKey] = useState(null);
    const [copied, setCopied] = useState(false);
    const [confirmRotateOpen, setConfirmRotateOpen] = useState(false);
    const [confirmClearOpen, setConfirmClearOpen] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: QUERY_KEY,
        queryFn: () => api.get('/crm/settings/integrations/wp-shared-key').then((res) => res.data),
    });

    const rotateMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/integrations/wp-shared-key/rotate').then((res) => res.data),
        onSuccess: (payload) => {
            setRevealedKey(payload.plain ?? null);
            setCopied(false);
            queryClient.setQueryData(QUERY_KEY, payload.status);
            toast.success('New WordPress sync key generated.');
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Could not rotate the key.');
        },
    });

    const clearMutation = useMutation({
        mutationFn: () => api.delete('/crm/settings/integrations/wp-shared-key').then((res) => res.data),
        onSuccess: (payload) => {
            setRevealedKey(null);
            queryClient.setQueryData(QUERY_KEY, payload.status);
            toast.success('Database key cleared. Reverted to .env fallback.');
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Could not clear the key.');
        },
    });

    useEffect(() => {
        if (!copied) return;
        const t = setTimeout(() => setCopied(false), 2000);
        return () => clearTimeout(t);
    }, [copied]);

    const status = data || {};
    const activeSource = status.active_source ?? 'none';

    const handleCopy = async () => {
        if (!revealedKey) return;
        try {
            await navigator.clipboard.writeText(revealedKey);
            setCopied(true);
        } catch {
            toast.error('Clipboard blocked. Select the key and copy manually.');
        }
    };

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">WordPress Sync Key</h3>
                    <p className="crm-panel-subtitle">
                        Shared secret WordPress sends in <code className="rounded bg-slate-100 px-1 py-0.5 text-[11px]">X-Exotic-CRM-Sync-Key</code> for KYC and CRM-sync requests.
                    </p>
                </div>
                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(activeSource)}`}>
                    {sourceLabel(activeSource)}
                </span>
            </header>

            <div className="space-y-4 p-4">
                {isLoading ? (
                    <p className="text-sm text-slate-500">Loading key status…</p>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <KeyRow
                                label="Database key"
                                preview={status.db_key_preview}
                                isActive={activeSource === 'database'}
                                emptyHint="Generate one to manage the key from this page."
                                meta={status.rotated_at ? `Rotated ${new Date(status.rotated_at).toLocaleString()}` : null}
                            />
                            <KeyRow
                                label=".env fallback"
                                preview={status.env_key_preview}
                                isActive={activeSource === 'env'}
                                emptyHint="Not set in CRM .env."
                                meta="Used only when no database key is set."
                            />
                        </div>

                        {revealedKey ? (
                            <div className="rounded-lg border border-emerald-200 bg-emerald-50/60 p-3">
                                <p className="text-xs font-semibold uppercase tracking-wide text-emerald-700">
                                    Copy this key into WordPress wp-config.php
                                </p>
                                <p className="mt-1 text-[11px] text-emerald-800">
                                    Shown once — it will be masked after you leave this page.
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    <code className="flex-1 break-all rounded bg-white px-2 py-1.5 font-mono text-[12px] text-slate-800 ring-1 ring-emerald-200">
                                        {revealedKey}
                                    </code>
                                    <button
                                        type="button"
                                        onClick={handleCopy}
                                        className="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700"
                                    >
                                        {copied ? 'Copied ✓' : 'Copy'}
                                    </button>
                                </div>
                                <details className="mt-3 text-[12px] text-emerald-900">
                                    <summary className="cursor-pointer font-semibold">wp-config.php snippet</summary>
                                    <pre className="mt-2 overflow-x-auto rounded bg-white p-2 text-[11px] text-slate-800 ring-1 ring-emerald-200">{`if ( ! defined( 'EXOTIC_CRM_SYNC_SHARED_KEY' ) ) {
    define( 'EXOTIC_CRM_SYNC_SHARED_KEY', '${revealedKey}' );
}`}</pre>
                                </details>
                            </div>
                        ) : null}

                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setConfirmRotateOpen(true)}
                                disabled={rotateMutation.isPending}
                                className="inline-flex items-center rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-60"
                            >
                                {rotateMutation.isPending ? 'Generating…' : status.db_key_set ? 'Rotate key' : 'Generate key'}
                            </button>
                            {status.db_key_set ? (
                                <button
                                    type="button"
                                    onClick={() => setConfirmClearOpen(true)}
                                    disabled={clearMutation.isPending}
                                    className="inline-flex items-center rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 hover:bg-rose-50 disabled:opacity-60"
                                >
                                    Clear database key
                                </button>
                            ) : null}
                            <p className="text-[11px] text-slate-500">
                                The database key always wins over .env. WordPress side must use the same value in <code>EXOTIC_CRM_SYNC_SHARED_KEY</code>.
                            </p>
                        </div>
                    </>
                )}
            </div>

            <ConfirmDialog
                open={confirmRotateOpen}
                title={status.db_key_set ? 'Rotate WordPress sync key?' : 'Generate WordPress sync key?'}
                message={
                    status.db_key_set
                        ? 'The current key will be replaced. WordPress sites still using the old key will start getting 401 until you paste the new key into their wp-config.php.'
                        : 'A new 64-character key will be generated and stored. You will be shown the full value once — copy it into WordPress wp-config.php.'
                }
                confirmLabel={status.db_key_set ? 'Rotate' : 'Generate'}
                tone={status.db_key_set ? 'danger' : 'primary'}
                onConfirm={() => {
                    setConfirmRotateOpen(false);
                    rotateMutation.mutate();
                }}
                onCancel={() => setConfirmRotateOpen(false)}
            />

            <ConfirmDialog
                open={confirmClearOpen}
                title="Clear database key?"
                message="This deletes the CRM-managed key. The .env fallback (if set) becomes active. WordPress sites using the deleted key will fail until you align them."
                confirmLabel="Clear"
                tone="danger"
                onConfirm={() => {
                    setConfirmClearOpen(false);
                    clearMutation.mutate();
                }}
                onCancel={() => setConfirmClearOpen(false)}
            />
        </section>
    );
}

function KeyRow({ label, preview, isActive, emptyHint, meta }) {
    return (
        <div className={`rounded-lg border p-3 ${isActive ? 'border-emerald-200 bg-emerald-50/40' : 'border-slate-200 bg-white'}`}>
            <div className="flex items-center justify-between">
                <p className="text-xs font-semibold text-slate-700">{label}</p>
                {isActive ? (
                    <span className="inline-flex items-center rounded-md bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                        Active
                    </span>
                ) : null}
            </div>
            {preview ? (
                <code className="mt-1 block break-all font-mono text-[12px] text-slate-700">{preview}</code>
            ) : (
                <p className="mt-1 text-[12px] italic text-slate-500">{emptyHint}</p>
            )}
            {meta ? <p className="mt-1 text-[11px] text-slate-500">{meta}</p> : null}
        </div>
    );
}
