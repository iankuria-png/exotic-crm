import React, { useMemo, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import FaqFlyoutPanel from './FaqFlyoutPanel';

const options = [
    { kind: 'bug', label: 'Report bug', description: 'Capture a broken flow, misleading state, or regression.', tone: 'rose' },
    { kind: 'feature_request', label: 'Request feature', description: 'Log a missing shortcut, admin action, or workflow gap.', tone: 'sky' },
    { kind: 'general', label: 'General suggestion', description: 'Share product, process, or documentation feedback.', tone: 'slate' },
];

function locationLabel(pathname) {
    if (pathname.startsWith('/clients/')) return 'Client detail';
    if (pathname.startsWith('/clients')) return 'Clients';
    if (pathname.startsWith('/payments')) return 'Payments';
    if (pathname.startsWith('/team')) return 'Team';
    if (pathname.startsWith('/leads')) return 'Leads';
    return 'Current page';
}

function toneClasses(tone) {
    switch (tone) {
        case 'rose':
            return 'border-rose-200 bg-rose-50/80 text-rose-700';
        case 'sky':
            return 'border-sky-200 bg-sky-50/80 text-sky-700';
        case 'slate':
        default:
            return 'border-slate-200 bg-slate-50/80 text-slate-700';
    }
}

export default function FeedbackButton() {
    const navigate = useNavigate();
    const location = useLocation();
    const [open, setOpen] = useState(false);
    const contextPath = useMemo(() => `${location.pathname}${location.search}`, [location.pathname, location.search]);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900"
            >
                <svg className="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7.5 8.25h9m-9 3h6m-7.5 8.25 1.843-3.072a1.5 1.5 0 0 1 1.286-.728h8.121A2.25 2.25 0 0 0 19.5 13.5v-6A2.25 2.25 0 0 0 17.25 5.25h-10.5A2.25 2.25 0 0 0 4.5 7.5v9.75a.75.75 0 0 0 1.5.75Z" />
                </svg>
                Feedback
            </button>
            <FaqFlyoutPanel
                open={open}
                onClose={() => setOpen(false)}
                title="Feedback Hub"
                subtitle="Keep the current page context and route product feedback without leaving your work."
                widthClassName="max-w-lg"
                footer={(
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm text-slate-500">The full feedback queue lives in the hub.</p>
                        <Link to="/faq/feedback" onClick={() => setOpen(false)} className="crm-btn-secondary inline-flex rounded-lg px-3 py-2 text-sm">
                            Open Feedback Hub
                        </Link>
                    </div>
                )}
            >
                <div className="space-y-4">
                    <div className="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-4">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Page context</p>
                        <p className="mt-2 text-sm font-semibold text-slate-900">{locationLabel(location.pathname)}</p>
                        <p className="mt-1 break-all text-sm text-slate-500">{contextPath}</p>
                    </div>
                    <div className="space-y-3">
                        {options.map((option) => (
                            <button
                                key={option.kind}
                                type="button"
                                onClick={() => {
                                    navigate(`/faq/feedback/new?kind=${encodeURIComponent(option.kind)}&context_path=${encodeURIComponent(contextPath)}`);
                                    setOpen(false);
                                }}
                                className="w-full rounded-xl border border-slate-200 px-4 py-4 text-left transition hover:border-teal-200 hover:bg-teal-50/50"
                            >
                                <div className="flex items-start gap-3">
                                    <span className={`inline-flex shrink-0 rounded-lg border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ${toneClasses(option.tone)}`}>
                                        {option.label}
                                    </span>
                                </div>
                                <p className="mt-3 text-sm leading-6 text-slate-600">{option.description}</p>
                            </button>
                        ))}
                    </div>
                </div>
            </FaqFlyoutPanel>
        </>
    );
}
