import React, { useMemo, useState } from 'react';
import DataTable from '../DataTable';
import ExportModal from '../ExportModal';
import FilterSelect from '../FilterSelect';
import StatusBadge from '../StatusBadge';
import { copyText, PAYMENT_STATUS_OPTIONS, providerLabel, SCOPE_OPTIONS, shortDate, titleize, UNLOCK_STATUS_OPTIONS, visitorContextParts } from './visitorFormat';
import { formatCurrency } from '../../utils/currency';

const PER_PAGE_OPTIONS = [10, 25, 50, 100];

function SortHeader({ label, column, sort, direction, onSort }) {
    const active = sort === column;
    return (
        <button
            type="button"
            className="flex items-center gap-2 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500 hover:text-slate-900"
            onClick={() => onSort(column)}
        >
            <span>{label}</span>
            <span className={`rounded border px-1.5 py-0.5 text-[9px] normal-case tracking-normal ${active ? 'border-teal-200 bg-teal-50 text-teal-700' : 'border-slate-200 bg-white text-slate-500'}`}>
                {active ? (direction === 'asc' ? 'Asc' : 'Desc') : 'Sort'}
            </span>
        </button>
    );
}

function Chip({ value, toast, mono = false }) {
    if (!value) return <span className="text-slate-400">-</span>;
    return (
        <button
            type="button"
            onClick={() => copyText(value, toast)}
            className={`rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 transition hover:border-teal-300 hover:text-teal-700 ${mono ? 'font-mono' : ''}`}
            title="Copy"
        >
            {value}
        </button>
    );
}

export default function VisitorUnlockTrail({
    markets = [],
    unlocks = [],
    meta = {},
    filters,
    setFilter,
    setPage,
    setPerPage,
    toggleSort,
    isLoading,
    isFetching,
    onReset,
    onExport,
    toast,
}) {
    const [exportOpen, setExportOpen] = useState(false);
    const [isExporting, setIsExporting] = useState(false);
    const total = Number(meta.total || 0);
    const isTruncated = total > 5000;

    const columns = useMemo(() => [
        {
            key: 'id',
            label: 'Reference',
            renderHeader: () => <SortHeader label="Reference" column="id" sort={filters.sort} direction={filters.direction} onSort={toggleSort} />,
            render: (unlock) => (
                <div>
                    <p className="font-semibold text-slate-950">#{unlock.id}</p>
                    <p className="mt-1 text-xs text-slate-500">{unlock.market || 'Market'}</p>
                    <p className="mt-1 text-xs text-slate-400">{shortDate(unlock.created_at)}</p>
                </div>
            ),
        },
        {
            key: 'profile',
            label: 'Profile',
            renderHeader: () => <SortHeader label="Profile" column="profile" sort={filters.sort} direction={filters.direction} onSort={toggleSort} />,
            render: (unlock) => (
                <div className="min-w-0">
                    {unlock.profile?.url ? (
                        <a className="font-semibold text-slate-950 underline-offset-4 hover:text-teal-700 hover:underline" href={unlock.profile.url} target="_blank" rel="noreferrer">
                            {unlock.profile?.name || unlock.profile?.wp_post_id || 'Profile'}
                        </a>
                    ) : (
                        <p className="font-semibold text-slate-950">{unlock.profile?.name || unlock.profile?.wp_post_id || '-'}</p>
                    )}
                    <p className="mt-1 text-xs text-slate-500">{unlock.scope === 'market_inactive_profiles' ? 'All inactive contacts' : 'Single profile'}</p>
                </div>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            renderHeader: () => <SortHeader label="Status" column="status" sort={filters.sort} direction={filters.direction} onSort={toggleSort} />,
            render: (unlock) => (
                <div>
                    <div className="flex flex-wrap gap-1.5">
                        <StatusBadge status={unlock.status || 'unknown'} />
                        {unlock.payment?.status ? <StatusBadge status={unlock.payment.status} label={`Payment ${titleize(unlock.payment.status)}`} /> : null}
                    </div>
                    {unlock.claim_review?.claimed ? (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            <span className="rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-700">Claimed</span>
                            {Number(unlock.claim_review?.pending_reachability_reviews || 0) > 0 ? (
                                <span className="rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">
                                    {Number(unlock.claim_review.pending_reachability_reviews).toLocaleString()} reachability review
                                </span>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'amount',
            label: 'Payment',
            renderHeader: () => <SortHeader label="Payment" column="amount" sort={filters.sort} direction={filters.direction} onSort={toggleSort} />,
            render: (unlock) => {
                const payment = unlock.payment || {};
                const pricing = unlock.pricing || {};
                const reference = payment.reference || (payment.id ? `#${payment.id}` : '');
                return (
                    <div className="min-w-[13rem]">
                        <p className="font-semibold text-slate-950">
                            {payment.currency ? formatCurrency(payment.amount, payment.currency) : '-'}
                        </p>
                        {Number(pricing.credit_amount || 0) > 0 ? (
                            <p className="mt-1 text-xs font-semibold text-emerald-700">
                                {formatCurrency(pricing.credit_amount, payment.currency || 'USD')} credited
                            </p>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {payment.provider_key ? <span className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600">{providerLabel(payment.provider_key)}</span> : null}
                            {payment.provider_environment ? <span className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600">{titleize(payment.provider_environment)}</span> : null}
                            <Chip value={reference} toast={toast} mono />
                        </div>
                        {payment.failure_reason ? (
                            <p className="mt-2 max-w-xs rounded-md border border-rose-100 bg-rose-50 px-2 py-1.5 text-xs leading-5 text-rose-700">{payment.failure_reason}</p>
                        ) : null}
                    </div>
                );
            },
        },
        {
            key: 'visitor',
            label: 'Visitor',
            renderHeader: () => <SortHeader label="Visitor" column="visitor" sort={filters.sort} direction={filters.direction} onSort={toggleSort} />,
            render: (unlock) => {
                const parts = visitorContextParts(unlock.visitor_context || {});
                return (
                    <div className="min-w-[14rem]">
                        <div className="flex flex-wrap gap-1.5">
                            <Chip value={unlock.visitor_phone_masked || unlock.visitor_email_masked} toast={toast} />
                            {unlock.visitor_email_masked ? <Chip value={unlock.visitor_email_masked} toast={toast} /> : null}
                        </div>
                        {parts.length ? (
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {parts.slice(0, 5).map((part) => (
                                    <span key={part} className="rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-600">{part}</span>
                                ))}
                            </div>
                        ) : null}
                        {unlock.claim_review?.claimed ? (
                            <div className="mt-2 rounded-md border border-teal-100 bg-teal-50 px-2 py-1.5 text-xs leading-5 text-teal-800">
                                <p className="font-semibold">{unlock.claim_review.latest_customer?.name || 'Member account'}</p>
                                {unlock.claim_review.latest_customer?.email ? <p>{unlock.claim_review.latest_customer.email}</p> : null}
                                {unlock.claim_review.latest_reachability_outcome ? (
                                    <p className="mt-1 text-teal-700">Latest feedback: {titleize(unlock.claim_review.latest_reachability_outcome)}</p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                );
            },
        },
    ], [filters.direction, filters.sort, toast, toggleSort]);

    const runExport = async () => {
        setIsExporting(true);
        try {
            const result = await onExport();
            if (result?.truncated) {
                toast.info(`Exported the first ${result.rowLimit.toLocaleString()} of ${result.rowTotal.toLocaleString()} rows.`);
            } else {
                toast.success('Contact unlock export downloaded.');
            }
            setExportOpen(false);
        } finally {
            setIsExporting(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="crm-filter-row space-y-3">
                <div className="flex flex-wrap items-end gap-3">
                    <label className="min-w-[220px] flex-1">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                        <input
                            className="crm-input mt-1"
                            value={filters.search}
                            onChange={(event) => setFilter('search', event.target.value)}
                            placeholder="Reference, visitor, profile, WP post ID"
                        />
                    </label>
                    <FilterSelect label="Market" value={filters.platform_id} onChange={(event) => setFilter('platform_id', event.target.value)} options={[{ value: 'all', label: 'All markets' }, ...markets.map((market) => ({ value: String(market.id), label: market.name }))]} />
                    <FilterSelect label="Unlock" value={filters.status} onChange={(event) => setFilter('status', event.target.value)} options={[{ value: 'all', label: 'All unlocks' }, ...UNLOCK_STATUS_OPTIONS.map((status) => ({ value: status, label: titleize(status) }))]} />
                    <FilterSelect label="Payment" value={filters.payment_status} onChange={(event) => setFilter('payment_status', event.target.value)} options={[{ value: 'all', label: 'All payments' }, ...PAYMENT_STATUS_OPTIONS.map((status) => ({ value: status, label: titleize(status) }))]} />
                    <FilterSelect label="Scope" value={filters.scope} onChange={(event) => setFilter('scope', event.target.value)} options={[{ value: 'all', label: 'All scopes' }, ...SCOPE_OPTIONS]} />
                    <button type="button" className="crm-btn-secondary mb-0.5" onClick={onReset}>Reset</button>
                    <button type="button" className="crm-btn-primary mb-0.5" onClick={() => setExportOpen(true)}>Export</button>
                </div>
            </div>

            <div className={isFetching ? 'opacity-70' : ''}>
                <DataTable
                    columns={columns}
                    data={unlocks}
                    pagination={meta}
                    onPageChange={setPage}
                    perPage={Number(filters.per_page || meta.per_page || 10)}
                    onPerPageChange={setPerPage}
                    perPageOptions={PER_PAGE_OPTIONS}
                    isLoading={isLoading}
                    emptyMessage="No visitor unlocks match these filters."
                    compact
                />
            </div>

            <ExportModal
                open={exportOpen}
                title="Export Contact Unlocks"
                subtitle="The export uses the same market, date, search, status and sort filters as this table."
                exportLabel="Export .xlsx"
                onClose={() => setExportOpen(false)}
                onExport={runExport}
                isExporting={isExporting}
                exportDisabled={total < 1}
                footerContent={`${total.toLocaleString()} matching rows`}
            >
                {isTruncated ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                        Exporting the first 5,000 of {total.toLocaleString()} rows. Narrow the date range or market to get everything.
                    </div>
                ) : (
                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">
                        {total.toLocaleString()} row{total === 1 ? '' : 's'} will be written, plus an Export Meta worksheet with the exact filters.
                    </div>
                )}
            </ExportModal>
        </div>
    );
}
