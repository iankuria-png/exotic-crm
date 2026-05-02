import React, { useEffect, useState } from 'react';
import MarkdownRenderer from './MarkdownRenderer';

export default function InlineTiptapEditor({ article, open, onCancel, onSaveDraft, onPublish }) {
    const [draft, setDraft] = useState('');
    const [title, setTitle] = useState('');
    const [summary, setSummary] = useState('');

    useEffect(() => {
        setDraft(article?.body_draft || article?.body || '');
        setTitle(article?.title || '');
        setSummary(article?.summary || '');
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
                <button type="button" onClick={() => onSaveDraft?.({ title, summary, body_draft: draft })} className="crm-btn-secondary px-3 py-2 text-sm">Save draft</button>
                <button type="button" onClick={() => onPublish?.({ title, summary, body_draft: draft })} className="crm-btn-primary px-3 py-2 text-sm">Publish</button>
            </div>
        </section>
    );
}
