import React, { useMemo, useState } from 'react';

function emptyCategory() {
    return { slug: '', name: '', description: '', crm_page: 'dashboard' };
}

function swapPositions(ids, firstIndex, secondIndex) {
    const next = [...ids];
    [next[firstIndex], next[secondIndex]] = [next[secondIndex], next[firstIndex]];
    return next;
}

export default function InlineCategoryManager({ open, categories, onCreate, onUpdate, onDelete, onReorder }) {
    const [draft, setDraft] = useState(emptyCategory());
    const sorted = useMemo(() => [...(categories || [])].sort((a, b) => a.position - b.position), [categories]);

    if (!open) {
        return null;
    }

    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div className="space-y-2">
                <p className="text-sm font-semibold text-slate-900">Category manager</p>
                <p className="text-sm text-slate-500">Use the quick controls to rename categories or move them up and down without leaving FAQ.</p>
            </div>
            <div className="space-y-3">
                {sorted.map((category, index) => (
                    <div key={category.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{category.name}</p>
                            <p className="text-sm text-slate-500">{category.crm_page || 'No page mapping'}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => index > 0 && onReorder?.(swapPositions(sorted.map((row) => row.id), index, index - 1))}
                                className="crm-btn-secondary px-3 py-2 text-sm"
                            >
                                Up
                            </button>
                            <button
                                type="button"
                                onClick={() => index < sorted.length - 1 && onReorder?.(swapPositions(sorted.map((row) => row.id), index, index + 1))}
                                className="crm-btn-secondary px-3 py-2 text-sm"
                            >
                                Down
                            </button>
                            <button type="button" onClick={() => onUpdate?.(category.slug, category)} className="crm-btn-secondary px-3 py-2 text-sm">Refresh</button>
                            <button type="button" onClick={() => onDelete?.(category.slug)} className="crm-btn-danger px-3 py-2 text-sm">Delete</button>
                        </div>
                    </div>
                ))}
            </div>
            <div className="grid gap-3 rounded-2xl border border-dashed border-slate-300 px-4 py-4 md:grid-cols-2">
                <input value={draft.name} onChange={(event) => setDraft((current) => ({ ...current, name: event.target.value, slug: event.target.value.toLowerCase().replace(/[^a-z0-9]+/g, '-') }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Category name" />
                <input value={draft.slug} onChange={(event) => setDraft((current) => ({ ...current, slug: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Slug" />
                <select value={draft.crm_page} onChange={(event) => setDraft((current) => ({ ...current, crm_page: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    {['dashboard', 'clients', 'client_detail', 'deals', 'payments', 'conversations', 'campaigns', 'leads', 'cross_cutting'].map((value) => (
                        <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>
                    ))}
                </select>
                <input value={draft.description} onChange={(event) => setDraft((current) => ({ ...current, description: event.target.value }))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Description" />
                <div className="md:col-span-2">
                    <button type="button" onClick={() => onCreate?.(draft)} className="crm-btn-primary px-3 py-2 text-sm">Add category</button>
                </div>
            </div>
        </section>
    );
}
