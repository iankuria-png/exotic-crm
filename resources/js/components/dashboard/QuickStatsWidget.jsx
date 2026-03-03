import React from 'react';
import SectionFrame from '../SectionFrame';

function MiniStat({ label, value, isLoading }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-3">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-1 text-xl font-semibold tracking-tight text-slate-900">
                {isLoading ? (
                    <span className="inline-block h-6 w-14 animate-pulse rounded bg-slate-200" />
                ) : (
                    Number(value || 0).toLocaleString()
                )}
            </p>
        </div>
    );
}

export default function QuickStatsWidget({ kpis = {}, activeCampaigns = 0, isLoading }) {
    const stats = [
        { key: 'new_leads', label: 'New Leads (7d)', value: kpis.pending_leads },
        { key: 'follow_ups', label: 'Pending Follow-ups', value: kpis.upcoming_follow_ups_count ?? 0 },
        { key: 'campaigns', label: 'Active Campaigns', value: activeCampaigns },
    ];

    return (
        <SectionFrame title="Quick Stats" subtitle="Key numbers at a glance">
            <div className="grid gap-2 sm:grid-cols-3">
                {stats.map((stat) => (
                    <MiniStat key={stat.key} label={stat.label} value={stat.value} isLoading={isLoading} />
                ))}
            </div>
        </SectionFrame>
    );
}
