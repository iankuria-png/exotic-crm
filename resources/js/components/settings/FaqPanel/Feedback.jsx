import React from 'react';
import KindChip from '../../faq/KindChip';
import SeverityChip from '../../faq/SeverityChip';

export default function Feedback({ items = [] }) {
    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div>
                <p className="text-sm font-semibold text-slate-900">Article feedback</p>
                <p className="text-sm text-slate-500">Helpful signals, article suggestions, and product notes in one queue.</p>
            </div>
            <div className="space-y-3">
                {items.map((item) => (
                    <div key={item.id} className="rounded-2xl border border-slate-200 px-4 py-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <KindChip kind={item.kind} />
                            <SeverityChip severity={item.severity} />
                        </div>
                        <p className="mt-2 text-sm font-semibold text-slate-900">{item.title}</p>
                        <p className="mt-1 text-sm text-slate-500">{item.comment}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}
