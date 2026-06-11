import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../services/api';

const NOTE_PLACEHOLDERS = {
    not_serious: 'Optional — anything notable about the conversation?',
    no_response: 'Optional — last attempted contact channel/date.',
    declined: 'Optional — what reason did they give for not proceeding?',
    invalid_contact: 'Optional — wrong number, bounced email, etc.',
    inappropriate: 'Optional — short description for the audit trail.',
    payment_issue: 'Optional — what was attempted (STK, link, manual)?',
    duplicate: 'Optional — id or phone of the primary contact.',
    other: 'Required — what happened?',
};

function useCloseReasons() {
    return useQuery({
        queryKey: ['client-close-reasons'],
        queryFn: () => api.get('/crm/clients/close-reasons').then((r) => r.data),
        staleTime: 60 * 60 * 1000, // 1 hour — taxonomy rarely changes
    });
}

export default function CloseCaseDialog({
    open,
    onCancel,
    onConfirm,
    mode = 'single', // 'single' | 'bulk'
    clientName = '',
    clientNames = [], // for bulk
    openPaymentsCount = null, // null = unknown; integer = preview count
    activeDeal = null, // { id, plan_label } if blocking
    deactivateUrl = null, // deep-link target when blocked
    isPending = false,
    error = null,
    initialReasonCode = '', // optional pre-selected reason (e.g. from churn queue)
}) {
    const reasonsQuery = useCloseReasons();
    const [reasonCode, setReasonCode] = useState(initialReasonCode || '');
    const [note, setNote] = useState('');
    const firstFieldRef = useRef(null);

    const reasons = reasonsQuery.data?.reasons ?? [];
    const softCloseDays = reasonsQuery.data?.soft_close_days ?? 30;
    const selectedReason = reasons.find((r) => r.code === reasonCode);
    const noteRequired = selectedReason?.requires_note === true;
    const noteValid = !noteRequired || note.trim().length > 0;
    const canSubmit = reasonCode && noteValid && !isPending && !activeDeal;

    useEffect(() => {
        if (!open) {
            setReasonCode(initialReasonCode || '');
            setNote('');
            return undefined;
        }
        // Pre-fill from initialReasonCode when dialog opens
        if (initialReasonCode) {
            setReasonCode(initialReasonCode);
        }

        // Focus the reason dropdown on mount.
        const timer = setTimeout(() => firstFieldRef.current?.focus(), 30);

        const handleEscape = (event) => {
            if (event.key === 'Escape' && !isPending) {
                event.preventDefault();
                onCancel?.();
            }
        };

        window.addEventListener('keydown', handleEscape);
        return () => {
            clearTimeout(timer);
            window.removeEventListener('keydown', handleEscape);
        };
    }, [open, isPending, onCancel]);

    const purgeDate = useMemo(() => {
        const date = new Date();
        date.setDate(date.getDate() + softCloseDays);
        return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }, [softCloseDays]);

    if (!open) return null;

    const title = mode === 'bulk'
        ? `Close ${clientNames.length} case${clientNames.length === 1 ? '' : 's'}`
        : `Close case for ${clientName || 'this client'}`;

    return (
        <div
            className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4"
            onClick={isPending ? undefined : onCancel}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="close-case-dialog-title"
                className="w-full max-w-lg overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 id="close-case-dialog-title" className="crm-panel-title">{title}</h3>
                        <p className="crm-panel-subtitle">Hides from active queues immediately. Auto-deletes on {purgeDate} unless reopened.</p>
                    </div>
                </header>

                {activeDeal ? (
                    <div className="space-y-3 border-l-4 border-amber-400 bg-amber-50 p-4">
                        <p className="text-sm text-amber-900">
                            <strong>{clientName || 'This client'}</strong> has an active{' '}
                            <strong>{activeDeal.plan_label || 'subscription'}</strong>. Close-case can&rsquo;t run while
                            a subscription is live.
                        </p>
                        {deactivateUrl ? (
                            <a
                                href={deactivateUrl}
                                className="inline-flex items-center gap-1 rounded-md border border-amber-500 bg-white px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                            >
                                Deactivate subscription →
                            </a>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-4 p-4">
                        {mode === 'bulk' && clientNames.length > 0 ? (
                            <div className="rounded-md bg-slate-50 p-3 text-xs text-slate-600">
                                <p className="font-semibold text-slate-700">Selected ({clientNames.length}):</p>
                                <p className="mt-1">
                                    {clientNames.slice(0, 5).join(', ')}
                                    {clientNames.length > 5 ? ` + ${clientNames.length - 5} more` : ''}
                                </p>
                            </div>
                        ) : null}

                        <div>
                            <label htmlFor="close-case-reason" className="block text-xs font-semibold uppercase tracking-wide text-slate-600">
                                Reason
                            </label>
                            <select
                                ref={firstFieldRef}
                                id="close-case-reason"
                                value={reasonCode}
                                onChange={(e) => setReasonCode(e.target.value)}
                                disabled={isPending || reasonsQuery.isLoading}
                                className="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                            >
                                <option value="">Select a reason…</option>
                                {reasons.map((r) => (
                                    <option key={r.code} value={r.code}>
                                        {r.label}
                                    </option>
                                ))}
                            </select>
                            {reasonsQuery.isError ? (
                                <p className="mt-1 text-xs text-rose-600">Failed to load reasons. Try again.</p>
                            ) : null}
                        </div>

                        <div>
                            <label htmlFor="close-case-note" className="block text-xs font-semibold uppercase tracking-wide text-slate-600">
                                Note {noteRequired ? <span className="text-rose-600">*</span> : <span className="font-normal normal-case text-slate-400">(optional)</span>}
                            </label>
                            <textarea
                                id="close-case-note"
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                disabled={isPending}
                                rows={3}
                                placeholder={NOTE_PLACEHOLDERS[reasonCode] || 'Add any context worth keeping in the audit trail.'}
                                maxLength={1000}
                                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                            />
                        </div>

                        <div className="rounded-md bg-slate-50 p-3 text-xs text-slate-600">
                            <p className="font-semibold text-slate-700">What happens next</p>
                            <ul className="mt-1 list-inside list-disc space-y-0.5">
                                <li>Hides from active queues right away.</li>
                                {openPaymentsCount !== null && openPaymentsCount > 0 ? (
                                    <li>{openPaymentsCount} open failed payment{openPaymentsCount === 1 ? '' : 's'} will be marked resolved with the same reason.</li>
                                ) : null}
                                <li>Auto-deletes from CRM and WordPress on {purgeDate} ({softCloseDays} days from today).</li>
                                <li>Reopenable any time within the {softCloseDays}-day window.</li>
                            </ul>
                        </div>

                        {error ? (
                            <p className="rounded-md bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>
                        ) : null}
                    </div>
                )}

                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button
                        type="button"
                        onClick={onCancel}
                        disabled={isPending}
                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    {!activeDeal ? (
                        <button
                            type="button"
                            onClick={() => onConfirm?.({ reason_code: reasonCode, reason_note: note.trim() || null })}
                            disabled={!canSubmit}
                            className="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {isPending ? 'Closing…' : (mode === 'bulk' ? `Close ${clientNames.length} cases` : 'Close case')}
                        </button>
                    ) : null}
                </footer>
            </div>
        </div>
    );
}
