import React from 'react';
import SectionFrame from '../SectionFrame';

function StatRow({ label, value, isLoading }) {
    return (
        <div className="flex items-center justify-between py-2">
            <span className="text-sm text-slate-600">{label}</span>
            <span className="crm-mono text-sm font-semibold text-slate-900">
                {isLoading ? (
                    <span className="inline-block h-4 w-12 animate-pulse rounded bg-slate-200" />
                ) : (
                    value
                )}
            </span>
        </div>
    );
}

export default function CommsBalanceWidget({ stats = {}, isLoading }) {
    const sent = Number(stats.sent_count || 0);
    const delivered = Number(stats.delivered_count || 0);
    const failed = Number(stats.failed_count || 0);
    const successRate = sent > 0 ? Math.round((delivered / sent) * 100) : 0;

    return (
        <SectionFrame title="Comms & Delivery" subtitle="SMS delivery metrics">
            <div className="divide-y divide-slate-100">
                <StatRow label="Messages sent" value={sent.toLocaleString()} isLoading={isLoading} />
                <StatRow label="Delivered" value={delivered.toLocaleString()} isLoading={isLoading} />
                <StatRow label="Failed" value={failed.toLocaleString()} isLoading={isLoading} />
                <StatRow
                    label="Success rate"
                    value={`${successRate}%`}
                    isLoading={isLoading}
                />
            </div>

            {!isLoading && sent === 0 ? (
                <p className="mt-3 rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-2 text-center text-xs text-slate-500">
                    No messages sent in the current window.
                </p>
            ) : null}
        </SectionFrame>
    );
}
