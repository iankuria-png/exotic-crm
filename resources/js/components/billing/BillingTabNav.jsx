import React from 'react';

export default function BillingTabNav({ tabs, activeTab, onChange }) {
    return (
        <div className="flex flex-wrap gap-2 border-b border-slate-100 px-5 py-4">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    type="button"
                    onClick={() => onChange(tab.id)}
                    className={`rounded-full px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.08em] transition ${
                        activeTab === tab.id
                            ? 'border border-slate-900 bg-slate-900 text-white shadow-sm shadow-slate-950/10'
                            : 'border border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900'
                    }`}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
