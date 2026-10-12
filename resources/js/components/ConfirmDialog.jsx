import React, { useEffect, useMemo, useRef } from 'react';

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
    const dialogId = useMemo(() => `confirm-dialog-${Math.random().toString(36).slice(2, 10)}`, []);
    const titleId = `${dialogId}-title`;
    const messageId = message ? `${dialogId}-message` : undefined;
    const panelRef = useRef(null);
    const latestRef = useRef({ isPending, onCancel });
    latestRef.current = { isPending, onCancel };

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const previousFocus = document.activeElement;
        const controls = () => Array.from(panelRef.current?.querySelectorAll('button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled), a[href], [tabindex="0"]') || [])
            .filter((element) => element.getClientRects().length > 0);
        const focusFirst = () => (controls()[0] || panelRef.current)?.focus();
        const frame = requestAnimationFrame(focusFirst);
        const isTopDialog = () => Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]')).at(-1) === panelRef.current;
        const handleKey = (event) => {
            if (!isTopDialog()) {
                return;
            }
            if (event.key === 'Escape') {
                if (!latestRef.current.isPending) {
                    event.preventDefault();
                    latestRef.current.onCancel?.();
                }
            } else if (event.key === 'Tab') {
                const elements = controls();
                const first = elements[0];
                const last = elements.at(-1);
                if (!first || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)
                    || !panelRef.current?.contains(document.activeElement)) {
                    event.preventDefault();
                    (event.shiftKey ? last : first)?.focus();
                    if (!first) panelRef.current?.focus();
                }
            }
        };
        const containFocus = (event) => {
            if (isTopDialog() && !panelRef.current?.contains(event.target)) focusFirst();
        };
        window.addEventListener('keydown', handleKey);
        document.addEventListener('focusin', containFocus);
        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('keydown', handleKey);
            document.removeEventListener('focusin', containFocus);
            if (previousFocus?.isConnected) {
                previousFocus.focus();
            } else {
                // A completed action may remove its own trigger button.
                const heading = document.querySelector('main h1, h1');
                if (heading) {
                    heading.setAttribute('tabindex', '-1');
                    heading.focus();
                }
            }
        };
    }, [open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4" onClick={isPending ? undefined : onCancel}>
            <div
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={messageId}
                className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 id={titleId} className="crm-panel-title">{title}</h3>
                        {message ? <p id={messageId} className="crm-panel-subtitle">{message}</p> : null}
                    </div>
                </header>

                {children ? (
                    <div className="space-y-3 p-4">
                        {children}
                    </div>
                ) : null}

                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onCancel} disabled={isPending} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50">
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
