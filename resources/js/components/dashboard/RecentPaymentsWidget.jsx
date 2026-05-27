import React from 'react';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import { relativeTime } from './ceoFormatters';

const LIMITS = [10, 20, 30];
const CHANNELS = [
    { key: 'all', label: 'All methods' },
    { key: 'self_service', label: 'Self-service' },
    { key: 'manual', label: 'Manual' },
    { key: 'other', label: 'Other' },
];

function SkeletonRows() {
    return (
        <div className="space-y-2">
            {Array.from({ length: 6 }).map((_, index) => (
                <div key={index} className="h-14 animate-pulse rounded-lg bg-slate-100" />
            ))}
        </div>
    );
}

export default function RecentPaymentsWidget({
    data,
    isLoading,
    errorMessage,
    onOpenPayment,
    limit = 10,
    onLimitChange,
    channel = 'all',
    onChannelChange,
}) {
    const payments = data?.payments || [];

    return (
        <SectionFrame
            title={`Last ${limit} Payments`}
            subtitle="Completed cash events only, refreshed while the tab is active."
            className="overflow-hidden"
            contentClassName="min-h-[360px]"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Recent payments count">
                        {LIMITS.map((value) => (
                            <button
                                key={value}
                                type="button"
                                onClick={() => onLimitChange(value)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${limit === value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {value}
                            </button>
                        ))}
                    </div>
                    <select
                        value={channel}
                        onChange={(event) => onChannelChange(event.target.value)}
                        className="h-8 rounded-md border border-slate-300 bg-white px-2 text-xs font-semibold text-slate-700 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                        aria-label="Payment channel"
                    >
                        {CHANNELS.map((item) => (
                            <option key={item.key} value={item.key}>{item.label}</option>
                        ))}
                    </select>
                </div>
            )}
        >
            {isLoading ? (
                <SkeletonRows />
            ) : errorMessage ? (
                <div className="rounded-lg border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">{errorMessage}</div>
            ) : payments.length === 0 ? (
                <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No completed payments yet.</div>
            ) : (
                <div className="space-y-2">
                    {payments.map((payment) => (
                        <button
                            key={payment.id}
                            type="button"
                            onClick={() => onOpenPayment(payment.id)}
                            className="grid w-full grid-cols-[1fr_auto] gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            <span className="min-w-0">
                                <span className="flex items-center gap-2">
                                    <span className="truncate text-sm font-semibold text-slate-900">{payment.client?.name || 'Unknown client'}</span>
                                    <span className="shrink-0 text-xs text-slate-500">
                                        {getCountryFlag(payment.market?.country)} {payment.market?.name || 'Market'}
                                    </span>
                                </span>
                                <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                                    <span>{relativeTime(payment.occurred_at)}</span>
                                    <span aria-hidden="true">|</span>
                                    <span>{payment.product || 'Subscription'}</span>
                                    <span aria-hidden="true">|</span>
                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{payment.channel?.label || 'Other'}</span>
                                    <span>{payment.method?.label || 'Unknown method'}</span>
                                    {payment.method?.subtitle ? <span>{payment.method.subtitle}</span> : null}
                                    {payment.agent?.name ? <span>Agent: {payment.agent.name}</span> : null}
                                </span>
                            </span>
                            <span className="text-right">
                                <span className="block text-sm font-semibold text-slate-900">{formatCurrency(payment.amount, payment.currency)}</span>
                                {payment.normalized_total !== null && payment.normalized_total !== undefined ? (
                                    <span className="block text-[11px] text-slate-500" title={`${formatCurrency(payment.amount, payment.currency)} native`}>
                                        {formatCurrency(payment.normalized_total, payment.normalized_currency)}
                                    </span>
                                ) : null}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </SectionFrame>
    );
}
