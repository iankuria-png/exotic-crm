import React from 'react';

export default function BillingTabNav({ tabs, activeTab, onChange }) {
    return (
        <div className="border-y border-slate-100 bg-slate-50/70 px-5 py-4">
            <div className="flex flex-wrap gap-2 rounded-2xl border border-slate-200/80 bg-white p-2 shadow-sm shadow-slate-950/[0.03]">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    onClick={() => onChange(tab.id)}
                    className={`rounded-xl px-3.5 py-2 text-xs font-semibold uppercase tracking-[0.08em] transition ${
                        activeTab === tab.id
                            ? 'border border-slate-950 bg-slate-950 text-white shadow-sm shadow-slate-950/15'
                            : 'border border-transparent bg-transparent text-slate-500 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900'
                    }`}
                >
                    {tab.label}
                </button>
            ))}
            </div>
        </div>
    );
}
