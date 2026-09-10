import React from 'react';
import SectionFrame from '../SectionFrame';
import { BarList, InsightEmptyState } from '../shared/InsightStates';
import MetricCard from '../MetricCard';
import { formatCurrency } from '../../utils/currency';
import { compactNumber, copyText, percentLabel } from './visitorFormat';

function DemandList({ title, subtitle, rows, toast }) {
    const mapped = (rows || []).map((row) => ({
        label: row.label || 'Unknown',
        value: Number(row.count || 0),
        formattedValue: compactNumber(row.count),
    }));

    return (
        <SectionFrame
            title={title}
            subtitle={subtitle}
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

/**
 * Top profiles carries two numbers per row — paid unlocks and the revenue they produced.
 * They are labelled and stacked rather than joined with a pipe, because "15 | 17,710" reads
 * as one figure and the second half was previously a raw sum across mixed currencies.
 */
function TopProfilesList({ rows, currency, toast }) {
    const list = rows || [];
    const maxCount = list.reduce((max, row) => Math.max(max, Number(row.count || 0)), 0) || 1;
    const revenueLabel = (row) => {
        if (row.amount_display) return row.amount_display;
        if (row.amount_normalized !== null && row.amount_normalized !== undefined) {
            return formatCurrency(row.amount_normalized, row.normalized_currency || currency);
        }
        const native = Object.entries(row.source_breakdown || {});
        if (!native.length) return null;
        return `${native.map(([code, amount]) => formatCurrency(amount, code)).join(' + ')}*`;
    };

    return (
        <SectionFrame
            title="Top profiles"
            subtitle={`Paid unlocks and the revenue they produced, in ${currency}.`}
            action={list.length ? (
                <button
                    type="button"
                    className="crm-btn-secondary px-3 py-1.5 text-xs"
                    onClick={() => copyText(list.map((row) => `${row.label}: ${compactNumber(row.count)} unlocks, ${revenueLabel(row) || 'no revenue'}`).join('\n'), toast)}
                >
                    Copy
                </button>
            ) : null}
        >
            {list.length ? (
                <div className="space-y-3">
                    {list.map((row, index) => (
                        <div key={`${row.client_id || 'none'}-${index}`} className="space-y-2">
                            <div className="flex items-start justify-between gap-3">
                                <p className="min-w-0 flex-1 truncate text-sm font-medium text-slate-700">{row.label || 'Unknown'}</p>
                                <div className="shrink-0 text-right">
                                    <p className="whitespace-nowrap text-sm font-semibold text-slate-800">
                                        {compactNumber(row.count)} <span className="font-normal text-slate-500">unlocks</span>
                                    </p>
                                    <p className="whitespace-nowrap text-[11px] text-slate-500">{revenueLabel(row) || 'no revenue'}</p>
                                </div>
                            </div>
                            <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className="h-full rounded-full bg-teal-600"
                                    style={{ width: `${Math.max(6, Math.round((Number(row.count || 0) / maxCount) * 100))}%` }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <InsightEmptyState title="No demand yet" message="This market has no visitor unlock demand in the selected range." />
            )}
        </SectionFrame>
    );
}

export default function VisitorDemandPanel({ pulse = {}, reportingCurrency, isLoading, onOpenMetric, toast }) {
    const kpis = pulse.kpis || {};
    const currency = kpis.normalized_currency || reportingCurrency?.targetCurrency || 'USD';

    // Three of these five cards have rows behind them worth reading. Clicking opens the
    // drill-down rather than sending the reader to an unfiltered list somewhere else.
    const open = (key, label, value) => (typeof onOpenMetric === 'function'
        ? () => onOpenMetric({ key, label, value })
        : undefined);

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <MetricCard label="Unlock conversion" value={percentLabel(kpis.unlock_conversion_percent)} isLoading={isLoading} />
                <MetricCard
                    label="Pending payments"
                    value={compactNumber(kpis.pending_payments)}
                    tone={Number(kpis.pending_payments || 0) > 0 ? 'warning' : 'neutral'}
                    isLoading={isLoading}
                />
                <MetricCard
                    label="Single-profile purchases"
                    value={compactNumber(kpis.single_profile_purchases)}
                    hint="Who bought whom"
                    isLoading={isLoading}
                    onClick={open('single_profile_purchases', 'Single-profile purchases', compactNumber(kpis.single_profile_purchases))}
                />
                <MetricCard
                    label="Full-access purchases"
                    value={compactNumber(kpis.full_access_purchases)}
                    hint="Visitor and profiles revealed"
                    isLoading={isLoading}
                    onClick={open('full_access_purchases', 'Full-access purchases', compactNumber(kpis.full_access_purchases))}
                />
                <MetricCard
                    label="Renewed after demand"
                    value={compactNumber(kpis.renewed_after_paid_demand)}
                    hint="Client list and expiry movement"
                    tone="success"
                    isLoading={isLoading}
                    onClick={open('renewed_after_demand', 'Renewed after demand', compactNumber(kpis.renewed_after_paid_demand))}
                />
            </div>
            <div className="grid gap-4 xl:grid-cols-4">
                <DemandList title="Top cities" subtitle="Unlock interest by profile city." rows={pulse.top_cities || []} toast={toast} />
                <TopProfilesList rows={pulse.top_profiles || []} currency={currency} toast={toast} />
                <DemandList title="Traffic sources" subtitle="Where checkouts started." rows={pulse.top_sources || []} toast={toast} />
                <DemandList title="Top hours" subtitle="Local hour of checkout start." rows={pulse.top_hours || []} toast={toast} />
            </div>
        </div>
    );
}
