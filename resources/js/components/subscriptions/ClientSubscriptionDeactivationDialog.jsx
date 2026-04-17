import React from 'react';
import ConfirmDialog from '../ConfirmDialog';
import { DEAL_DEACTIVATION_REASON_OPTIONS } from '../../utils/deactivationOptions';

export default function ClientSubscriptionDeactivationDialog({
    open,
    title = 'Deactivate Profile Subscription',
    message,
    confirmLabel = 'Deactivate',
    reasonCode,
    onReasonCodeChange,
    reasonNotes,
    onReasonNotesChange,
    notifyClient,
    onNotifyClientChange,
    selectionSummary = null,
    onCancel,
    onConfirm,
    confirmDisabled = false,
    isPending = false,
}) {
    const eligibleCount = Number(selectionSummary?.eligibleCount || 0);
    const skippedCount = Number(selectionSummary?.skippedCount || 0);

    return (
        <ConfirmDialog
            open={open}
            title={title}
            message={message}
            confirmLabel={confirmLabel}
            tone="danger"
            onCancel={onCancel}
            onConfirm={onConfirm}
            confirmDisabled={confirmDisabled}
            isPending={isPending}
        >
            {selectionSummary ? (
                <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                    <p>
                        {eligibleCount} eligible profile{eligibleCount === 1 ? '' : 's'} will be deactivated.
                    </p>
                    {skippedCount > 0 ? (
                        <p className="mt-1 text-amber-700">
                            {skippedCount} selected row{skippedCount === 1 ? '' : 's'} will be skipped.
                        </p>
                    ) : null}
                </div>
            ) : null}

            <label className="mb-1 block text-sm font-medium text-slate-700">Reason code</label>
            <select
                value={reasonCode}
                onChange={(event) => onReasonCodeChange?.(event.target.value)}
                className="crm-select"
            >
                {DEAL_DEACTIVATION_REASON_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>

            <label className="mb-1 mt-3 block text-sm font-medium text-slate-700">Notes</label>
            <textarea
                rows={selectionSummary ? 2 : 3}
                value={reasonNotes}
                onChange={(event) => onReasonNotesChange?.(event.target.value)}
                className="crm-input"
                placeholder="Explain why this profile should be deactivated."
            />

            <label className="mt-3 flex items-center gap-2 text-sm text-slate-700">
                <input
                    type="checkbox"
                    checked={notifyClient}
                    onChange={(event) => onNotifyClientChange?.(event.target.checked)}
                />
                Notify clients via SMS
            </label>
        </ConfirmDialog>
    );
}
