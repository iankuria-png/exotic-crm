import React from 'react';

/**
 * Reusable loading / empty / error state for AI surfaces (briefings, insights chat,
 * project intelligence). Keeps a consistent look across the AI workspace.
 *
 * Usage:
 *   <AiStateBlock variant="loading" />
 *   <AiStateBlock variant="empty" title="No briefings yet"
 *                 message="Briefings appear after the first weekly run." />
 *   <AiStateBlock variant="error" message={error.message} onRetry={refetch} />
 */
export function AiStateBlock({
    variant = 'empty',
    title,
    message,
    onRetry,
    retryLabel = 'Try again',
    className = '',
}) {
    const palette = {
        loading: 'border-slate-200 bg-slate-50 text-slate-500',
        empty: 'border-slate-200 bg-slate-50 text-slate-500',
        error: 'border-rose-200 bg-rose-50 text-rose-700',
    }[variant] || 'border-slate-200 bg-slate-50 text-slate-500';

    const defaultTitle = {
        loading: 'Working on it…',
        empty: 'Nothing to show yet',
        error: 'Something went wrong',
    }[variant];

    return (
        <div
            role={variant === 'error' ? 'alert' : 'status'}
            aria-live="polite"
            className={`flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center ${palette} ${className}`}
        >
            {variant === 'loading' ? (
                <span
                    aria-hidden="true"
                    className="h-5 w-5 animate-spin rounded-full border-2 border-slate-300 border-t-slate-500"
                />
            ) : null}

            <p className="text-sm font-semibold">{title || defaultTitle}</p>

            {message ? (
                <p className="max-w-sm text-sm text-slate-500">{message}</p>
            ) : null}

            {variant === 'error' && typeof onRetry === 'function' ? (
                <button
                    type="button"
                    onClick={onRetry}
                    className="mt-2 rounded-md border border-rose-300 bg-white px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100"
                >
                    {retryLabel}
                </button>
            ) : null}
        </div>
    );
}

export default AiStateBlock;
