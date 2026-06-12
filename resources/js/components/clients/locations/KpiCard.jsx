import React from 'react';

export default function KpiCard({ label, value, hint, dotClass = 'bg-slate-300', onClick }) {
    const content = (
        <>
            <div className="flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${dotClass}`} aria-hidden="true" />
                <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            </div>
            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-900">{value}</p>
            {hint ? <p className="mt-1 text-sm text-slate-500">{hint}</p> : null}
        </>
    );

    if (typeof onClick === 'function') {
        return (
            <button
                type="button"
                onClick={onClick}
                className="rounded-xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
            >
                {content}
            </button>
        );
    }

    return <article className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">{content}</article>;
}
