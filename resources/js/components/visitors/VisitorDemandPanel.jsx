import React from 'react';
import SectionFrame from '../SectionFrame';
import { BarList, InsightEmptyState } from '../shared/InsightStates';
import MetricCard from '../MetricCard';
import { compactNumber, copyText, percentLabel } from './visitorFormat';

function DemandList({ title, rows, amountLabel, toast }) {
    const mapped = (rows || []).map((row) => ({
        label: row.label || 'Unknown',
        value: Number(row.count || 0),
        formattedValue: amountLabel && row.amount ? `${compactNumber(row.count)} | ${compactNumber(row.amount)}` : compactNumber(row.count),
    }));

    return (
        <SectionFrame
            title={title}
            action={mapped.length ? (
                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => copyText(mapped.map((row) => `${row.label}: ${row.formattedValue}`).join('\n'), toast)}>
                    Copy
                </button>
            ) : null}
        >
            {mapped.length ? <BarList rows={mapped} colorClass="bg-teal-600" /> : (
                <InsightEmptyState title="No demand yet" message="This market has no visitor unlock demand in the selected range." />
            )}
        </SectionFrame>
    );
}

export default function VisitorDemandPanel({ pulse = {}, toast }) {
    const kpis = pulse.kpis || {};

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="Unlock conversion" value={percentLabel(kpis.unlock_conversion_percent)} />
                <MetricCard label="Pending payments" value={compactNumber(kpis.pending_payments)} tone={Number(kpis.pending_payments || 0) > 0 ? 'warning' : 'neutral'} />
                <MetricCard label="Single-profile purchases" value={compactNumber(kpis.single_profile_purchases)} />
                <MetricCard label="Full-access purchases" value={compactNumber(kpis.full_access_purchases)} />
                <MetricCard label="Renewed after demand" value={compactNumber(kpis.renewed_after_paid_demand)} tone="success" />
            </div>
            <div className="grid gap-4 xl:grid-cols-4">
                <DemandList title="Top cities" rows={pulse.top_cities || []} toast={toast} />
                <DemandList title="Top profiles" rows={pulse.top_profiles || []} amountLabel toast={toast} />
                <DemandList title="Traffic sources" rows={pulse.top_sources || []} toast={toast} />
                <DemandList title="Top hours" rows={pulse.top_hours || []} toast={toast} />
            </div>
        </div>
    );
}
