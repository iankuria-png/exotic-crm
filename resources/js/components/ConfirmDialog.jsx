import React from 'react';

function confirmButtonClass(tone) {
    if (tone === 'danger') {
        return 'bg-rose-700 hover:bg-rose-800 focus-visible:ring-rose-500';
    }
    if (tone === 'warning') {
        return 'bg-amber-600 hover:bg-amber-700 focus-visible:ring-amber-500';
    }

    return 'bg-teal-700 hover:bg-teal-800 focus-visible:ring-teal-600';
}

export default function ConfirmDialog({
    open,
    title,
    message,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    tone = 'default',
    onCancel,
    onConfirm,
    confirmDisabled = false,
    isPending = false,
    children,
}) {
    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4" onClick={onCancel}>
            <div
                role="dialog"
                aria-modal="true"
                className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">{title}</h3>
                        {message ? <p className="crm-panel-subtitle">{message}</p> : null}
                    </div>
                </header>

                {children ? (
                    <div className="space-y-3 p-4">
                        {children}
                    </div>
                ) : null}

                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onCancel} className="crm-btn-secondary">
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={confirmDisabled || isPending}
                        className={`rounded-md px-4 py-2 text-sm font-semibold text-white transition focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50 ${confirmButtonClass(tone)}`}
                    >
                        {isPending ? 'Working...' : confirmLabel}
                    </button>
                </footer>
            </div>
        </div>
    );
}

