import React, { useEffect, useState } from 'react';

function KycDialogShell({ open, title, description, children, onClose }) {
    useEffect(() => {
        if (!open) return undefined;

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4">
            <div className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div className="border-b border-slate-200 px-6 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
                    {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}
                </div>
                <div className="space-y-4 px-6 py-5">{children}</div>
            </div>
        </div>
    );
}

export default function KycRejectDialog({
    open,
    onClose,
    onSubmit,
    isPending = false,
    title = 'Reject verification',
    description = 'Tell the advertiser what needs to change, then save any internal note for reviewers.',
}) {
    const [reasonUser, setReasonUser] = useState('');
    const [reasonInternal, setReasonInternal] = useState('');

    useEffect(() => {
        if (open) {
            setReasonUser('');
            setReasonInternal('');
        }
    }, [open]);

    return (
        <KycDialogShell open={open} onClose={onClose} title={title} description={description}>
            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Message shown to advertiser</label>
                <textarea
                    value={reasonUser}
                    onChange={(event) => setReasonUser(event.target.value)}
                    rows={4}
                    className="crm-textarea min-h-[120px] w-full"
                    placeholder="Explain what was wrong and what they should upload next."
                />
            </div>
            <div>
                <label className="mb-1 block text-sm font-medium text-slate-700">Internal note</label>
                <textarea
                    value={reasonInternal}
                    onChange={(event) => setReasonInternal(event.target.value)}
                    rows={3}
                    className="crm-textarea min-h-[100px] w-full"
                    placeholder="Optional context for reviewers only."
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
                    className="inline-flex items-center justify-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {isPending ? 'Saving…' : 'Reject subject'}
                </button>
            </div>
        </KycDialogShell>
    );
}
