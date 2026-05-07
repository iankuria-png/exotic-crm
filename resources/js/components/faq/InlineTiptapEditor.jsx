import React, { useEffect, useState } from 'react';
import MarkdownRenderer from './MarkdownRenderer';

const CRM_PAGE_OPTIONS = ['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'conversations', 'campaigns', 'leads', 'cross_cutting', 'team'];
const CONTEXT_KIND_OPTIONS = ['script', 'runbook'];

export default function InlineTiptapEditor({ article, open, onCancel, onSaveDraft, onPublish }) {
    const [draft, setDraft] = useState('');
    const [title, setTitle] = useState('');
    const [summary, setSummary] = useState('');
    const [contexts, setContexts] = useState([]);

    useEffect(() => {
        setDraft(article?.body_draft || article?.body || '');
        setTitle(article?.title || '');
        setSummary(article?.summary || '');
        setContexts(article?.contexts || []);
    }, [article]);

    if (!open) {
        return null;
    }

    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div className="grid gap-4 lg:grid-cols-2">
                <label className="space-y-1.5 text-sm text-slate-600">
                    <span className="font-medium text-slate-800">Title</span>
                    <input value={title} onChange={(event) => setTitle(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2" />
                </label>
                <label className="space-y-1.5 text-sm text-slate-600">
                    <span className="font-medium text-slate-800">Summary</span>
                    <input value={summary} onChange={(event) => setSummary(event.target.value)} className="w-full rounded-xl border border-slate-200 px-3 py-2" />
                </label>
            </div>
            <div className="space-y-3 rounded-2xl border border-slate-200 px-4 py-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-medium text-slate-800">Contextual reveal</p>
                        <p className="text-sm text-slate-500">Map this article into the help drawer for specific CRM screens.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setContexts((current) => [...current, { crm_page: 'payments', surface: 'help_drawer', context_kind: 'script', priority: current.length + 1 }])}
                        className="crm-btn-secondary px-3 py-2 text-sm"
                    >
                        Add mapping
                    </button>
                </div>
                {contexts.length ? (
                    <div className="space-y-3">
                        {contexts.map((context, index) => (
                            <div key={`${context.id || 'new'}-${index}`} className="grid gap-3 rounded-xl border border-slate-200 px-3 py-3 md:grid-cols-[minmax(0,1fr)_160px_140px_72px]">
                                <select
                                    value={context.crm_page || 'payments'}
                                    onChange={(event) => setContexts((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, crm_page: event.target.value } : row))}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                >
                                    {CRM_PAGE_OPTIONS.map((value) => (
                                        <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>
                                    ))}
                                </select>
                                <select
                                    value={context.context_kind || 'script'}
                                    onChange={(event) => setContexts((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, context_kind: event.target.value } : row))}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                >
                                    {CONTEXT_KIND_OPTIONS.map((value) => (
                                        <option key={value} value={value}>{value}</option>
                                    ))}
                                </select>
                                <input
                                    type="number"
                                    min="1"
                                    value={context.priority || index + 1}
                                    onChange={(event) => setContexts((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, priority: Number(event.target.value) || 1 } : row))}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm"
                                />
                                <button
                                    type="button"
                                    onClick={() => setContexts((current) => current.filter((_, rowIndex) => rowIndex !== index))}
                                    className="crm-btn-danger px-3 py-2 text-sm"
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-sm text-slate-500">No help-drawer mappings yet. Add one if this article should show contextually on a CRM screen.</p>
                )}
            </div>
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                <label className="space-y-1.5 text-sm text-slate-600">
                    <span className="font-medium text-slate-800">Article body</span>
                    <textarea value={draft} onChange={(event) => setDraft(event.target.value)} rows={18} className="min-h-[420px] w-full rounded-2xl border border-slate-200 px-3 py-3 font-mono text-sm" />
                </label>
                <div className="space-y-1.5">
                    <p className="text-sm font-medium text-slate-800">Preview</p>
                    <div className="min-h-[420px] rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <MarkdownRenderer>{draft}</MarkdownRenderer>
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap justify-end gap-2">
                <button type="button" onClick={onCancel} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                <button type="button" onClick={() => onSaveDraft?.({ title, summary, body_draft: draft, contexts })} className="crm-btn-secondary px-3 py-2 text-sm">Save draft</button>
                <button type="button" onClick={() => onPublish?.({ title, summary, body_draft: draft, contexts })} className="crm-btn-primary px-3 py-2 text-sm">Publish</button>
            </div>
        </section>
    );
}
