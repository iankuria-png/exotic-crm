import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { registerToast } from '../services/toastBridge';

const ToastContext = createContext(null);

function toneClasses(tone) {
    if (tone === 'success') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    }
    if (tone === 'error') {
        return 'border-rose-200 bg-rose-50 text-rose-800';
    }
    if (tone === 'warning') {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    return 'border-slate-200 bg-white text-slate-800';
}

export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const idRef = useRef(0);

    const dismissToast = useCallback((id) => {
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const pushToast = useCallback((message, options = {}) => {
        const id = ++idRef.current;
        const duration = Number.isFinite(options.duration) ? options.duration : 4200;

        setToasts((current) => [
            ...current,
            {
                id,
                tone: options.tone || 'info',
                title: options.title || null,
                message,
            },
        ]);

        if (duration > 0) {
            window.setTimeout(() => dismissToast(id), duration);
        }

        return id;
    }, [dismissToast]);

    const api = useMemo(() => ({
        push: (message, options) => pushToast(message, options),
        success: (message, options) => pushToast(message, { ...(options || {}), tone: 'success' }),
        error: (message, options) => pushToast(message, { ...(options || {}), tone: 'error' }),
        warning: (message, options) => pushToast(message, { ...(options || {}), tone: 'warning' }),
        info: (message, options) => pushToast(message, { ...(options || {}), tone: 'info' }),
        dismiss: dismissToast,
    }), [dismissToast, pushToast]);

    // Expose the imperative toast api to non-React callers (the global query
    // error bridge lives above this provider in the tree).
    useEffect(() => {
        registerToast(api);
        return () => registerToast(null);
    }, [api]);

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div className="pointer-events-none fixed right-4 top-4 z-[120] flex w-full max-w-sm flex-col gap-2">
                {toasts.map((toast) => (
                    <div
                        key={toast.id}
                        role="status"
                        aria-live={toast.tone === 'error' ? 'assertive' : 'polite'}
                        className={`pointer-events-auto rounded-md border px-3 py-2 shadow-lg ${toneClasses(toast.tone)}`}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                {toast.title ? <p className="text-sm font-semibold">{toast.title}</p> : null}
                                <p className="text-sm">{toast.message}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => dismissToast(toast.id)}
                                className="shrink-0 rounded p-1 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                                aria-label="Dismiss notification"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const context = useContext(ToastContext);
    if (!context) {
        throw new Error('useToast must be used within ToastProvider');
    }

    return context;
}

