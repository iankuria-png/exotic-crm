import React, { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';

const options = [
    { kind: 'bug', label: 'Report bug', description: 'Capture a broken flow or a confusing state.' },
    { kind: 'feature_request', label: 'Request feature', description: 'Log a workflow gap or missing shortcut.' },
    { kind: 'general', label: 'General suggestion', description: 'Share product or process feedback.' },
];

export default function FeedbackButton() {
    const navigate = useNavigate();
    const [open, setOpen] = useState(false);
    const contextPath = useMemo(() => window.location.pathname + window.location.search, [open]);

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:border-teal-200 hover:text-teal-700"
            >
                Feedback
            </button>
            {open ? (
                <div className="fixed inset-0 z-[110] bg-slate-950/35" onClick={() => setOpen(false)}>
                    <aside className="ml-auto flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <div className="border-b border-slate-100 px-5 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Feedback Hub</h3>
                            <p className="text-sm text-slate-500">Keep the page context and file feedback in two clicks.</p>
                        </div>
                        <div className="space-y-3 px-5 py-5">
                            {options.map((option) => (
                                <button
                                    key={option.kind}
                                    type="button"
                                    onClick={() => {
                                        navigate(`/faq/feedback/new?kind=${encodeURIComponent(option.kind)}&context_path=${encodeURIComponent(contextPath)}`);
                                        setOpen(false);
                                    }}
                                    className="w-full rounded-2xl border border-slate-200 px-4 py-4 text-left transition hover:border-teal-200 hover:bg-teal-50/50"
                                >
                                    <p className="text-sm font-semibold text-slate-900">{option.label}</p>
                                    <p className="mt-1 text-sm text-slate-500">{option.description}</p>
                                </button>
                            ))}
                        </div>
                    </aside>
                </div>
            ) : null}
        </>
    );
}
