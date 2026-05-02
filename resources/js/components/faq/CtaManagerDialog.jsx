import React, { useEffect, useState } from 'react';

function buildRows(article) {
    return article?.ctas?.map((cta) => ({ ...cta })) || [];
}

export default function CtaManagerDialog({ open, article, walkthroughs, onClose, onSave }) {
    const [rows, setRows] = useState([]);

    useEffect(() => {
        setRows(buildRows(article));
    }, [article, open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4" onClick={onClose}>
            <div className="w-full max-w-4xl rounded-2xl border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Manage CTAs</h3>
                    <p className="text-sm text-slate-500">Define deep links, prefill actions, or walkthrough launchers for this article.</p>
                </div>
                <div className="space-y-3 px-5 py-5">
                    {rows.map((row, index) => (
                        <div key={row.id || index} className="grid gap-3 rounded-2xl border border-slate-200 px-4 py-4 lg:grid-cols-5">
                            <select value={row.kind} onChange={(event) => setRows((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, kind: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <option value="deep_link">Deep link</option>
                                <option value="prefill">Prefill</option>
                                <option value="walkthrough">Walkthrough</option>
                            </select>
                            <input value={row.label || ''} onChange={(event) => setRows((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, label: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm lg:col-span-2" placeholder="Button label" />
                            <input value={row.target_path || ''} onChange={(event) => setRows((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, target_path: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm lg:col-span-2" placeholder="Target path, e.g. /clients?status=manual_review" />
                            {row.kind === 'walkthrough' ? (
                                <select value={row.walkthrough_id || ''} onChange={(event) => setRows((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, walkthrough_id: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm lg:col-span-2">
                                    <option value="">Select walkthrough</option>
                                    {(walkthroughs || []).map((walkthrough) => <option key={walkthrough.slug} value={walkthrough.slug}>{walkthrough.name}</option>)}
                                </select>
                            ) : null}
                            <button type="button" onClick={() => setRows((current) => current.filter((_, itemIndex) => itemIndex !== index))} className="crm-btn-danger px-3 py-2 text-sm">Remove</button>
                        </div>
                    ))}
                    <button type="button" onClick={() => setRows((current) => [...current, { kind: 'deep_link', label: '', target_path: window.location.pathname + window.location.search, position: current.length + 1 }])} className="crm-btn-secondary px-3 py-2 text-sm">
                        Add CTA
                    </button>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                    <button type="button" onClick={() => onSave?.(rows.map((row, index) => ({ ...row, position: index + 1 })))} className="crm-btn-primary px-3 py-2 text-sm">Save CTAs</button>
                </div>
            </div>
        </div>
    );
}
