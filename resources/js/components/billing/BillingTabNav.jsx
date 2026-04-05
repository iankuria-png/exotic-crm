import React from 'react';

export default function BillingTabNav({ tabs, activeTab, onChange }) {
    return (
        <div className="border-y border-slate-100 bg-slate-50/35 px-5 py-4">
            <div className="overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <div className="flex min-w-max gap-2 rounded-lg border border-slate-200 bg-white p-1 shadow-sm shadow-slate-950/[0.03]">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => onChange(tab.id)}
                            className={`rounded-md px-3.5 py-2 text-xs font-semibold uppercase tracking-[0.08em] transition ${
                                activeTab === tab.id
                                    ? 'bg-slate-950 text-white shadow-sm shadow-slate-950/10'
                                    : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
