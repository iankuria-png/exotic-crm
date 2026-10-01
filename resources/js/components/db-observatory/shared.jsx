import React, { useEffect, useRef } from 'react';

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

export function fmtDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function fmtTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

export function fmtAgo(value) {
    if (!value) return 'never';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 1000));
    return fmtAge(seconds);
}

export function fmtAge(seconds) {
    if (seconds === null || seconds === undefined) return 'never';
    if (seconds < 60) return `${seconds}s ago`;
    if (seconds < 3600) return `${Math.round(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    return `${Math.round(seconds / 86400)}d ago`;
}

export function fmtNumber(value) {
    const n = Number(value || 0);
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(2)} M`;
    if (n >= 10_000) return `${(n / 1000).toFixed(1)} k`;
    return new Intl.NumberFormat().format(n);
}

export function fmtBytes(value) {
    const n = Number(value || 0);
    if (n >= 1024 ** 3) return `${(n / 1024 ** 3).toFixed(1)} GB`;
    if (n >= 1024 ** 2) return `${(n / 1024 ** 2).toFixed(1)} MB`;
    if (n >= 1024) return `${(n / 1024).toFixed(1)} KB`;
    return `${n} B`;
}

export function humanize(value) {
    return String(value || '').replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function apiError(error, fallback = 'Something went wrong.') {
    const data = error?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
    }
    return data?.message || error?.message || fallback;
}

export const TERMINAL_RUN = ['stopped', 'completed', 'completed_with_gaps', 'partial', 'unreachable', 'skipped_unhealthy', 'skipped_shed', 'failed'];
export const TERMINAL_PASS = ['stopped', 'completed', 'completed_with_gaps', 'completed_with_errors'];

// ---------------------------------------------------------------------------
// Badges
// ---------------------------------------------------------------------------

const SEVERITY = {
    critical: 'bg-rose-600 text-white ring-rose-600',
    warn: 'bg-amber-50 text-amber-800 ring-amber-300',
    info: 'bg-slate-100 text-slate-600 ring-slate-200',
};

export function SeverityBadge({ severity }) {
    if (!severity) return null;
    return (
        <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] ring-1 ring-inset ${SEVERITY[severity] || SEVERITY.info}`}>
            {severity}
        </span>
    );
}

const CONFIDENCE = {
    confirmed: ['Confirmed indicator', 'bg-rose-50 text-rose-800 ring-rose-300'],
    strong: ['Strong behavioral match', 'bg-orange-50 text-orange-800 ring-orange-300'],
    needs_review: ['Needs review', 'bg-sky-50 text-sky-800 ring-sky-200'],
};

export function ConfidenceBadge({ confidence }) {
    if (!confidence || !CONFIDENCE[confidence]) return null;
    const [label, tone] = CONFIDENCE[confidence];
    return <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${tone}`}>{label}</span>;
}

const STATUS_TONE = {
    running: 'bg-teal-50 text-teal-800 ring-teal-300',
    queued: 'bg-sky-50 text-sky-700 ring-sky-200',
    waiting_lock: 'bg-sky-50 text-sky-700 ring-sky-200',
    pausing: 'bg-amber-50 text-amber-800 ring-amber-200',
    paused: 'bg-amber-50 text-amber-800 ring-amber-200',
    stopping: 'bg-rose-50 text-rose-700 ring-rose-200',
    stopped: 'bg-slate-100 text-slate-600 ring-slate-300',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    complete: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    completed_with_gaps: 'bg-amber-50 text-amber-800 ring-amber-200',
    complete_with_gaps: 'bg-amber-50 text-amber-800 ring-amber-200',
    completed_with_errors: 'bg-rose-50 text-rose-700 ring-rose-200',
    partial: 'bg-amber-50 text-amber-800 ring-amber-200',
    incomplete: 'bg-amber-50 text-amber-800 ring-amber-200',
    not_applicable: 'bg-slate-50 text-slate-500 ring-slate-200',
    excluded: 'bg-slate-100 text-slate-600 ring-slate-200',
    unreachable: 'bg-rose-50 text-rose-700 ring-rose-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
    expired: 'bg-slate-100 text-slate-600 ring-slate-300',
    open: 'bg-rose-50 text-rose-700 ring-rose-200',
    acknowledged: 'bg-sky-50 text-sky-700 ring-sky-200',
    snoozed: 'bg-slate-100 text-slate-600 ring-slate-200',
    false_positive: 'bg-slate-100 text-slate-600 ring-slate-200',
    allowlisted: 'bg-slate-100 text-slate-600 ring-slate-200',
    resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    passed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    never: 'bg-slate-100 text-slate-600 ring-slate-200',
    stale: 'bg-amber-50 text-amber-800 ring-amber-200',
    healthy: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    degraded: 'bg-rose-50 text-rose-700 ring-rose-200',
    idle: 'bg-slate-100 text-slate-600 ring-slate-200',
};

const STATUS_LABEL = {
    waiting_lock: 'Waiting for host slot',
    completed_with_gaps: 'Completed with gaps',
    complete_with_gaps: 'Complete with gaps',
    completed_with_errors: 'Completed with errors',
    not_applicable: 'Not applicable',
    false_positive: 'False positive',
};

export function Status({ value, label }) {
    if (!value) return null;
    return (
        <span className={`inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_TONE[value] || 'bg-slate-100 text-slate-600 ring-slate-200'}`}>
            {value === 'running' ? <span className="mr-1.5 h-1.5 w-1.5 animate-pulse rounded-full bg-teal-600" aria-hidden="true" /> : null}
            {label || STATUS_LABEL[value] || humanize(value)}
        </span>
    );
}

// ---------------------------------------------------------------------------
// States
// ---------------------------------------------------------------------------

export function Loading({ label = 'Loading…', rows = 3 }) {
    return (
        <div className="space-y-2 p-4" role="status" aria-label={label}>
            {Array.from({ length: rows }).map((_, i) => (
                <div key={i} className="h-10 animate-pulse rounded-lg bg-slate-100" />
            ))}
        </div>
    );
}

export function ErrorState({ error, onRetry }) {
    const status = error?.response?.status;
    const forbidden = status === 403;
    return (
        <div className="m-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
            <p className="font-semibold">{forbidden ? 'You do not have access to this part of the Observatory.' : 'Could not load this view.'}</p>
            <p className="mt-0.5">{forbidden ? 'Administrators and sub-administrators can view; only administrators can run or configure scans.' : apiError(error)}</p>
            {!forbidden && onRetry ? (
                <button type="button" onClick={onRetry} className="mt-2 text-sm font-semibold underline underline-offset-2">Retry</button>
            ) : null}
        </div>
    );
}

export function Empty({ title, children }) {
    return (
        <div className="m-4 rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-5 py-8 text-center">
            <p className="text-sm font-semibold text-slate-800">{title}</p>
            {children ? <div className="mx-auto mt-1 max-w-xl text-sm text-slate-500">{children}</div> : null}
        </div>
    );
}

export function Panel({ title, subtitle, action, children, className = '', bodyClass = '' }) {
    return (
        <section className={`crm-surface ${className}`}>
            {title ? (
                <header className="crm-panel-header">
                    <div className="min-w-0">
                        <h3 className="crm-panel-title">{title}</h3>
                        {subtitle ? <p className="crm-panel-subtitle">{subtitle}</p> : null}
                    </div>
                    {action}
                </header>
            ) : null}
            <div className={bodyClass}>{children}</div>
        </section>
    );
}

/**
 * Progress bar that can never look "done" unless it is.
 */
export function Meter({ fraction = 0, tone = 'teal' }) {
    const pct = Math.max(0, Math.min(100, Math.round(Number(fraction || 0) * 100)));
    const bar = tone === 'amber' ? 'bg-amber-500' : tone === 'rose' ? 'bg-rose-500' : 'bg-teal-600';
    return (
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100}>
            <div className={`h-1.5 rounded-full transition-all duration-700 ${bar}`} style={{ width: `${pct}%` }} />
        </div>
    );
}

/**
 * Copyable identifier: shown compact, copied in full.
 */
export function CopyId({ value, label }) {
    if (!value) return null;
    const text = String(value);
    return (
        <button
            type="button"
            onClick={() => navigator.clipboard?.writeText(text)}
            className="crm-mono inline-flex max-w-full items-center gap-1 truncate rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[11px] text-slate-600 hover:border-teal-300 hover:text-teal-800"
            title={`Copy ${label || 'value'}: ${text}`}
        >
            <span className="truncate">{text.length > 20 ? `${text.slice(0, 10)}…${text.slice(-6)}` : text}</span>
        </button>
    );
}

// ---------------------------------------------------------------------------
// Drawer — traps and restores focus, closes on Escape, works at 390px.
// ---------------------------------------------------------------------------

export function Drawer({ open, title, subtitle, onClose, children, footer, width = 'max-w-2xl' }) {
    const panelRef = useRef(null);
    const returnFocus = useRef(null);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    useEffect(() => {
        if (!open) return undefined;
        returnFocus.current = document.activeElement;
        const panel = panelRef.current;
        const focusables = () => panel?.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])') || [];
        window.setTimeout(() => (focusables()[0] || panel)?.focus(), 0);

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeRef.current?.();
                return;
            }
            if (event.key !== 'Tab') return;
            const items = Array.from(focusables());
            if (items.length === 0) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('keydown', onKey);
            returnFocus.current?.focus?.();
        };
    }, [open]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[100] flex justify-end bg-slate-900/40" onClick={onClose}>
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-label={typeof title === 'string' ? title : 'Details'}
                tabIndex={-1}
                className={`flex h-full w-full ${width} flex-col bg-white shadow-2xl outline-none`}
                onClick={(event) => event.stopPropagation()}
            >
                <header className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div className="min-w-0">
                        <h3 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h3>
                        {subtitle ? <div className="mt-1 text-sm text-slate-500">{subtitle}</div> : null}
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Close">
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </header>
                <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
                {footer ? <footer className="border-t border-slate-200 px-5 py-3">{footer}</footer> : null}
            </div>
        </div>
    );
}

/**
 * Suspect code is only ever shown as text inside <pre>; React escapes it.
 */
export function InertCode({ children, className = '' }) {
    return (
        <pre className={`crm-mono max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-md border border-slate-800 bg-slate-950 px-3 py-2 text-[11.5px] leading-relaxed text-amber-100 ${className}`}>
            {String(children ?? '')}
        </pre>
    );
}

export const PROFILE_HINT = {
    quick: 'Access, persistence, configuration and high-risk stores · ~30 s per market',
    standard: 'Quick + content, widgets, SEO/builder meta, user meta, terms · 1–3 min',
    deep: 'Standard + revisions, all postmeta, comments, orphans and hygiene · up to 10 min',
};
