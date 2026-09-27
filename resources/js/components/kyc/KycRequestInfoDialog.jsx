import React, { useEffect, useState } from 'react';
import useKycDialog from './useKycDialog';

export default function KycRequestInfoDialog({
    open,
    onClose,
    onSubmit,
    isPending = false,
    initialReason = '',
}) {
    const dialogRef = useKycDialog(open, onClose);
    const [reasonUser, setReasonUser] = useState('');
    const [reasonInternal, setReasonInternal] = useState('');

    useEffect(() => {
        if (open) {
            setReasonUser(initialReason);
            setReasonInternal('');
        }
    }, [open, initialReason]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div ref={dialogRef} role="dialog" aria-modal="true" aria-label="Request more information" className="max-h-[90vh] overflow-y-auto w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div className="border-b border-slate-200 px-6 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Request more information</h3>
                    <p className="mt-1 text-sm text-slate-500">Keep the request crisp for the advertiser. Internal notes stay in the CRM only.</p>
                </div>
                <div className="space-y-4 px-6 py-5">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Message shown to advertiser</label>
                        <textarea
                            aria-label="Message shown to advertiser"
                            value={reasonUser}
                            onChange={(event) => setReasonUser(event.target.value)}
                            rows={4}
                            className="crm-textarea min-h-[120px] w-full"
                            placeholder="Example: Please upload a clearer photo of the front of your ID in better lighting."
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Internal note</label>
                        <textarea
                            aria-label="Internal note"
                            value={reasonInternal}
                            onChange={(event) => setReasonInternal(event.target.value)}
                            rows={3}
                            className="crm-textarea min-h-[100px] w-full"
                            placeholder="Optional reviewer note."
                        />
                    </div>
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={isPending || !reasonUser.trim()}
                            onClick={() => onSubmit?.({ reason_user: reasonUser.trim(), reason_internal: reasonInternal.trim() || null })}
                            className="inline-flex items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isPending ? 'Saving…' : 'Request info'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
