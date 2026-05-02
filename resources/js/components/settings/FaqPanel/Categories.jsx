import React from 'react';

export default function Categories({ categories = [] }) {
    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div>
                <p className="text-sm font-semibold text-slate-900">Categories</p>
                <p className="text-sm text-slate-500">Reorder or rename categories directly from the FAQ home screen.</p>
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {categories.map((category) => (
                    <div key={category.id} className="rounded-2xl border border-slate-200 px-4 py-4">
                        <p className="text-sm font-semibold text-slate-900">{category.name}</p>
                        <p className="mt-1 text-sm text-slate-500">{category.description}</p>
                        <p className="mt-3 text-xs font-medium uppercase tracking-[0.12em] text-slate-500">{category.crm_page || 'No page map'}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}
