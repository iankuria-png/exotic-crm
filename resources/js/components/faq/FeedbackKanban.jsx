import React from 'react';
import KindChip from './KindChip';
import StatusChip from './StatusChip';

const columns = ['new', 'triaged', 'planned', 'in_progress', 'shipped', 'resolved', 'wontfix', 'duplicate'];

export default function FeedbackKanban({ items, onOpen }) {
    return (
        <div className="grid gap-3 lg:grid-cols-4 2xl:grid-cols-8">
            {columns.map((status) => (
                <section key={status} className="rounded-2xl border border-slate-200 bg-white p-3">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <StatusChip status={status} />
                        <span className="text-xs font-medium text-slate-500">{items.filter((item) => item.status === status).length}</span>
                    </div>
                    <div className="space-y-3">
                        {items.filter((item) => item.status === status).map((item) => (
                            <button key={item.id} type="button" onClick={() => onOpen?.(item)} className="w-full rounded-xl border border-slate-200 px-3 py-3 text-left transition hover:border-teal-200 hover:bg-teal-50/50">
                                <div className="flex flex-wrap items-center gap-2">
                                    <KindChip kind={item.kind} />
                                </div>
                                <p className="mt-2 text-sm font-semibold text-slate-900">{item.title}</p>
                                <p className="mt-1 text-xs text-slate-500">{item.comments_count} comments · {item.votes_count} votes</p>
                            </button>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
