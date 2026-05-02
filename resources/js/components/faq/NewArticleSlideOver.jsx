import React, { useEffect, useState } from 'react';

function makeInitial(categoryId) {
    return {
        category_id: categoryId || '',
        title: '',
        slug: '',
        summary: '',
        body: '# New article\n\nStart documenting the workflow here.',
        status: 'draft',
    };
}

export default function NewArticleSlideOver({ open, categories, initialCategoryId, onClose, onSubmit }) {
    const [form, setForm] = useState(makeInitial(initialCategoryId));

    useEffect(() => {
        setForm(makeInitial(initialCategoryId));
    }, [initialCategoryId, open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[110] bg-slate-950/35" onClick={onClose}>
            <aside className="ml-auto flex h-full w-full max-w-2xl flex-col border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">New FAQ article</h3>
                    <p className="text-sm text-slate-500">Create a draft and land directly on the article page for inline editing.</p>
                </div>
                <div className="grid gap-4 px-5 py-5">
                    <select value={form.category_id} onChange={(event) => setForm((current) => ({ ...current, category_id: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option value="">Select category</option>
                        {(categories || []).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                    <input
                        value={form.title}
                        onChange={(event) => setForm((current) => ({
                            ...current,
                            title: event.target.value,
                            slug: current.slug || event.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
                        }))}
                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        placeholder="Article title"
                    />
                    <input value={form.slug} onChange={(event) => setForm((current) => ({ ...current, slug: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Slug" />
                    <input value={form.summary} onChange={(event) => setForm((current) => ({ ...current, summary: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Summary" />
                    <textarea value={form.body} onChange={(event) => setForm((current) => ({ ...current, body: event.target.value }))} rows={16} className="rounded-2xl border border-slate-200 px-3 py-3 font-mono text-sm" />
                </div>
                <div className="mt-auto flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                    <button type="button" onClick={() => onSubmit?.(form)} className="crm-btn-primary px-3 py-2 text-sm">Save draft</button>
                </div>
            </aside>
        </div>
    );
}
