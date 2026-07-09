import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHeader from '../components/PageHeader';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import MetricCard from '../components/MetricCard';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';
import { retentionBandClasses } from '../utils/retention';
import api from '../services/api';

function money(value, currency = 'KES') {
    return new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
}

function statusClasses(status) {
    if (status === 'paid') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'void') return 'bg-slate-100 text-slate-600 ring-slate-200';
    if (status === 'pending') return 'bg-sky-50 text-sky-700 ring-sky-200';
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).slice(0, 2);
    return parts.map((p) => p[0]?.toUpperCase() || '').join('') || '?';
}

const DATE_PRESETS = [
    { key: 'this_month', label: 'This month' },
    { key: 'last_month', label: 'Last month' },
    { key: 'last_30', label: 'Last 30 days' },
    { key: 'last_90', label: 'Last 90 days' },
    { key: 'ytd', label: 'Year to date' },
    { key: 'all', label: 'All time' },
];

function toYmd(date) {
    if (!date) return '';
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function resolveDateRange(preset) {
    const now = new Date();
    if (preset === 'all') return { dateFrom: '', dateTo: '' };
    if (preset === 'this_month') {
        return { dateFrom: toYmd(new Date(now.getFullYear(), now.getMonth(), 1)), dateTo: toYmd(now) };
    }
    if (preset === 'last_month') {
        const first = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        const last = new Date(now.getFullYear(), now.getMonth(), 0);
        return { dateFrom: toYmd(first), dateTo: toYmd(last) };
    }
    if (preset === 'ytd') {
        return { dateFrom: toYmd(new Date(now.getFullYear(), 0, 1)), dateTo: toYmd(now) };
    }
    if (preset === 'last_90') {
        const from = new Date(now); from.setDate(from.getDate() - 90);
        return { dateFrom: toYmd(from), dateTo: toYmd(now) };
    }
    const from = new Date(now); from.setDate(from.getDate() - 30);
    return { dateFrom: toYmd(from), dateTo: toYmd(now) };
}

function ClientAvatar({ client }) {
    const src = client?.display_image_url || client?.main_image_url || '';
    if (src) {
        return (
            <img
                src={src}
                alt=""
                className="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
                onError={(event) => {
                    event.currentTarget.style.display = 'none';
                    event.currentTarget.nextElementSibling?.style.setProperty('display', 'flex');
                }}
            />
        );
    }
    return (
        <span
            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-500 ring-1 ring-slate-200"
            aria-hidden="true"
        >
            {initials(client?.name)}
        </span>
    );
}

function CurrencyList({ items, emptyLabel }) {
    if (!items?.length) return <span className="text-slate-400">{emptyLabel || '—'}</span>;
    return (
        <span className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
            {items.map((item, index) => (
                <span key={`${item.currency}-${index}`} className="whitespace-nowrap">
                    {money(item.total, item.currency)}
                </span>
            ))}
        </span>
    );
}

function PayoutDrawer({ payout, onClose }) {
    if (!payout) return null;
    return (
        <div className="fixed inset-0 z-[120] flex justify-end bg-slate-900/45" onClick={onClose}>
            <aside
                className="flex h-full w-full max-w-md flex-col bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header flex items-start justify-between">
                    <div>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Payout batch</p>
                        <h3 className="crm-panel-title">{payout.external_reference || 'Payout #' + payout.id}</h3>
                        {payout.paid_at ? <p className="crm-panel-subtitle">Paid {new Date(payout.paid_at).toLocaleString()}</p> : null}
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-2 py-1 text-xs">Close</button>
                </header>
                <div className="space-y-3 overflow-auto p-4 text-sm text-slate-700">
                    <dl className="grid grid-cols-2 gap-3">
                        <div>
                            <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Paid by</dt>
                            <dd className="mt-1 text-slate-900">{payout.paid_by?.name || payout.paid_by?.email || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">External ref</dt>
                            <dd className="mt-1 text-slate-900">{payout.external_reference || '—'}</dd>
                        </div>
                    </dl>
                    {payout.notes ? (
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Notes</p>
                            <p className="mt-1 whitespace-pre-wrap">{payout.notes}</p>
                        </div>
                    ) : null}
                </div>
            </aside>
        </div>
    );
}

export default function AdminCommissions() {
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('earned');
    const [agentId, setAgentId] = useState('');
    const [marketId, setMarketId] = useState('');
    const [datePreset, setDatePreset] = useState('all');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [externalReference, setExternalReference] = useState('');
    const [selectedRows, setSelectedRows] = useState([]);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [confirmPaidOpen, setConfirmPaidOpen] = useState(false);
    const [drawerPayout, setDrawerPayout] = useState(null);

    const isRangeInvalid = dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo);

    useEffect(() => {
        if (datePreset === 'custom') return;
        const range = resolveDateRange(datePreset);
        setDateFrom(range.dateFrom);
        setDateTo(range.dateTo);
    }, [datePreset]);

    const query = useQuery({
        queryKey: ['admin-commissions', page, status, agentId, marketId, dateFrom, dateTo],
        queryFn: () => api.get('/crm/admin/commissions', {
            params: {
                page,
                per_page: 50,
                ...(status && { status }),
                ...(agentId && { agent_user_id: Number(agentId) }),
                ...(marketId && { market_id: Number(marketId) }),
                ...(dateFrom && !isRangeInvalid && { date_from: dateFrom }),
                ...(dateTo && !isRangeInvalid && { date_to: dateTo }),
            },
        }).then((response) => response.data),
        keepPreviousData: true,
    });

    const paginator = query.data?.commissions || query.data?.data || {};
    const rows = paginator.data || [];
    const meta = paginator.meta || paginator;
    const agents = query.data?.agents || [];
    const markets = query.data?.markets || [];
    const summary = query.data?.summary || {};

    const selectedTotal = useMemo(() => {
        return selectedRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }, [selectedRows]);

    const selectedCurrency = selectedRows[0]?.currency || rows[0]?.currency || 'KES';
    const selectedAgentId = selectedRows[0]?.agent_user_id ?? selectedRows[0]?.agent_id;
    const selectedAgentName = selectedRows[0]?.agent?.name || selectedRows[0]?.agent?.email || 'agent';

    const canMarkPaid = selectedRows.length > 0
        && selectedRows.every((row) => row.status === 'earned')
        && selectedRows.every((row) => Number(row.agent_user_id ?? row.agent_id) === Number(selectedAgentId))
        && selectedRows.every((row) => String(row.currency || 'KES') === String(selectedCurrency));

    const markPaidMutation = useMutation({
        mutationFn: () => api.post('/crm/admin/commissions/mark-paid', {
            commission_ids: selectedRows.map((row) => row.id),
            payment_method: 'manual',
            external_reference: externalReference.trim() || null,
            notes: 'Marked paid from CRM commissions workspace',
        }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin-commissions'] });
            queryClient.invalidateQueries({ queryKey: ['field-commissions'] });
            setSelectedRows([]);
            setExternalReference('');
            setClearSelectionKey((value) => value + 1);
            setConfirmPaidOpen(false);
            toast.success('Commission payout recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not mark commissions as paid.');
            setConfirmPaidOpen(false);
        },
    });

    const handleExport = () => {
        const params = new URLSearchParams();
        if (status) params.set('status', status);
        if (agentId) params.set('agent_user_id', String(Number(agentId)));
        if (marketId) params.set('market_id', String(Number(marketId)));
        if (dateFrom && !isRangeInvalid) params.set('date_from', dateFrom);
        if (dateTo && !isRangeInvalid) params.set('date_to', dateTo);
        const url = `/api/crm/admin/commissions/export?${params.toString()}`;
        window.open(url, '_blank');
    };

    const resetFilters = () => {
        setStatus('earned');
        setAgentId('');
        setMarketId('');
        setDatePreset('all');
        setDateFrom('');
        setDateTo('');
        setPage(1);
    };

    const filtersActive = status !== 'earned' || agentId || marketId || datePreset !== 'all';

    const columns = [
        {
            key: 'agent',
            label: 'Agent',
            render: (row) => (
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-teal-50 text-[11px] font-semibold text-teal-700 ring-1 ring-teal-100">
                        {initials(row.agent?.name || row.agent?.email)}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{row.agent?.name || 'Agent'}</p>
                        <p className="truncate text-xs text-slate-500">{row.agent?.email || '—'}</p>
                    </div>
                </div>
            ),
        },
        {
            key: 'client',
            label: 'Client',
            render: (row) => {
                const band = row.client?.retention_insight?.band || row.client?.retentionInsight?.band;
                const marketName = row.client?.platform?.name;
                return (
                    <div className="flex items-center gap-2.5">
                        <ClientAvatar client={row.client} />
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium text-slate-900">{row.client?.name || row.client?.phone_normalized || 'Client'}</p>
                            <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                                {row.client?.phone_normalized ? <span>{row.client.phone_normalized}</span> : null}
                                {marketName ? (
                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-700 ring-1 ring-slate-200">
                                        {marketName}
                                    </span>
                                ) : null}
                                {band ? (
                                    <span className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${retentionBandClasses(band)}`}>
                                        {band}
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    </div>
                );
            },
        },
        {
            key: 'type',
            label: 'Type',
            render: (row) => {
                const plan = row.deal?.product?.display_name || row.deal?.product?.name;
                return (
                    <div>
                        <p className="text-sm capitalize text-slate-800">{String(row.type || '').replace('_', ' ')}</p>
                        {plan ? <p className="text-[11px] text-slate-500">{plan}</p> : null}
                    </div>
                );
            },
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => {
                const basis = row.basis_amount ? money(row.basis_amount, row.currency || 'KES') : null;
                const ratePct = row.rate ? `${(Number(row.rate) * 100).toFixed(1).replace(/\.0$/, '')}%` : null;
                const tooltip = basis && ratePct ? `${ratePct} of ${basis}` : undefined;
                return (
                    <span
                        title={tooltip}
                        className="text-sm font-semibold text-slate-900"
                    >
                        {money(row.amount, row.currency || 'KES')}
                    </span>
                );
            },
        },
        {
            key: 'reference',
            label: 'Reference',
            render: (row) => {
                const ref = row.deal?.payment_reference;
                if (!ref) return <span className="text-xs text-slate-400">—</span>;
                return (
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            navigator.clipboard?.writeText(ref);
                            toast.success('Reference copied.');
                        }}
                        className="max-w-[10rem] truncate text-left text-xs text-slate-600 hover:text-teal-700 hover:underline"
                        title={ref}
                    >
                        {ref}
                    </button>
                );
            },
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => {
                const chip = (
                    <span className={`inline-flex rounded-md px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${statusClasses(row.status)}`}>
                        {row.status || 'earned'}
                    </span>
                );
                if (row.status === 'paid' && row.payout) {
                    return (
                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                setDrawerPayout(row.payout);
                            }}
                            className="rounded-md focus:outline-none focus:ring-2 focus:ring-teal-500"
                        >
                            {chip}
                        </button>
                    );
                }
                return chip;
            },
        },
        {
            key: 'earned_at',
            label: 'Earned',
            render: (row) => <span className="text-xs text-slate-500">{row.earned_at ? new Date(row.earned_at).toLocaleString() : '—'}</span>,
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader title="Field Commissions" subtitle="Review and settle field-sales commission payouts." />

            <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    label="Earned & unpaid"
                    value={<CurrencyList items={summary.earned_by_currency} emptyLabel="0" />}
                    hint="ready for settlement"
                    tone="warning"
                    isLoading={query.isLoading}
                />
                <MetricCard
                    label="Paid this month"
                    value={<CurrencyList items={summary.paid_this_month_by_currency} emptyLabel="0" />}
                    hint="already settled"
                    tone="success"
                    isLoading={query.isLoading}
                />
                <MetricCard
                    label="Commissions this month"
                    value={(summary.this_month_count ?? 0).toLocaleString()}
                    hint="rows earned"
                    tone="accent"
                    isLoading={query.isLoading}
                />
                <MetricCard
                    label="Active field agents"
                    value={(summary.active_agents_30d ?? 0).toLocaleString()}
                    hint="earning in last 30 days"
                    tone="slate"
                    isLoading={query.isLoading}
                />
            </section>

            <section>
                <div className="mb-2 flex items-baseline justify-between">
                    <h3 className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Field-sales funnel</h3>
                    <p className="text-[11px] text-slate-400">clients where <code className="rounded bg-slate-100 px-1 text-[10px]">created_by</code> is a field agent · respects filters</p>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <MetricCard
                        label="Acquired"
                        value={(summary.funnel?.acquired ?? 0).toLocaleString()}
                        hint="clients brought in by field sales"
                        tone="accent"
                        isLoading={query.isLoading}
                    />
                    <MetricCard
                        label="Trialed"
                        value={(summary.funnel?.trialed ?? 0).toLocaleString()}
                        hint={(() => {
                            const a = Number(summary.funnel?.acquired || 0);
                            const t = Number(summary.funnel?.trialed || 0);
                            return a > 0 ? `${((t / a) * 100).toFixed(0)}% of acquired started a trial` : 'none yet';
                        })()}
                        tone="warning"
                        isLoading={query.isLoading}
                    />
                    <MetricCard
                        label="Converted to paid"
                        value={(summary.funnel?.converted ?? 0).toLocaleString()}
                        hint={(() => {
                            const a = Number(summary.funnel?.acquired || 0);
                            const c = Number(summary.funnel?.converted || 0);
                            return a > 0 ? `${((c / a) * 100).toFixed(0)}% conversion rate` : 'none yet';
                        })()}
                        tone="success"
                        isLoading={query.isLoading}
                    />
                </div>
            </section>

            <section className="crm-filter-row flex flex-wrap items-end gap-3">
                <FilterSelect
                    label="Status"
                    value={status}
                    onChange={(event) => { setStatus(event.target.value); setPage(1); }}
                    options={[
                        { value: '', label: 'All statuses' },
                        { value: 'earned', label: 'Earned' },
                        { value: 'paid', label: 'Paid' },
                        { value: 'void', label: 'Void' },
                    ]}
                />
                <FilterSelect
                    label="Agent"
                    value={agentId}
                    onChange={(event) => { setAgentId(event.target.value); setPage(1); }}
                    options={[
                        { value: '', label: 'All agents' },
                        ...agents.map((agent) => ({ value: String(agent.id), label: agent.name || agent.email })),
                    ]}
                />
                <FilterSelect
                    label="Market"
                    value={marketId}
                    onChange={(event) => { setMarketId(event.target.value); setPage(1); }}
                    options={[
                        { value: '', label: 'All markets' },
                        ...markets.map((m) => ({ value: String(m.id), label: m.name })),
                    ]}
                />
                <FilterSelect
                    label="Date range"
                    value={datePreset}
                    onChange={(event) => { setDatePreset(event.target.value); setPage(1); }}
                    options={[
                        ...DATE_PRESETS.map((p) => ({ value: p.key, label: p.label })),
                        { value: 'custom', label: 'Custom' },
                    ]}
                />
                {datePreset === 'custom' ? (
                    <>
                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="commissions-from">From</label>
                            <input
                                id="commissions-from"
                                type="date"
                                value={dateFrom}
                                onChange={(event) => { setDateFrom(event.target.value); setPage(1); }}
                                className="crm-input w-auto min-w-[140px]"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="commissions-to">To</label>
                            <input
                                id="commissions-to"
                                type="date"
                                value={dateTo}
                                onChange={(event) => { setDateTo(event.target.value); setPage(1); }}
                                className="crm-input w-auto min-w-[140px]"
                            />
                        </div>
                        {isRangeInvalid ? (
                            <span className="self-end pb-2 text-xs text-rose-500">From must be before To</span>
                        ) : null}
                    </>
                ) : null}
                <label className="flex min-w-[14rem] flex-col gap-1">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Payment reference</span>
                    <input
                        value={externalReference}
                        onChange={(event) => setExternalReference(event.target.value)}
                        className="crm-input"
                        placeholder="Optional receipt or note"
                    />
                </label>
                <div className="ml-auto flex items-end gap-2">
                    {filtersActive ? (
                        <button type="button" onClick={resetFilters} className="crm-btn-secondary">
                            Reset
                        </button>
                    ) : null}
                    <button
                        type="button"
                        onClick={handleExport}
                        disabled={query.isLoading || (meta?.total ?? 0) === 0}
                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Export CSV
                    </button>
                    <button
                        type="button"
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                        disabled={!canMarkPaid || markPaidMutation.isPending}
                        onClick={() => setConfirmPaidOpen(true)}
                        title={selectedRows.length > 0 && !canMarkPaid ? 'Select earned commissions for one agent and one currency.' : undefined}
                    >
                        Mark paid ({money(selectedTotal, selectedCurrency)})
                    </button>
                </div>
            </section>

            {query.isError ? (
                <div className="crm-surface flex flex-col items-center gap-3 p-8 text-center">
                    <p className="text-sm text-rose-700">Could not load commissions.</p>
                    <button type="button" onClick={() => query.refetch()} className="crm-btn-secondary">Retry</button>
                </div>
            ) : (
                <DataTable
                    columns={columns}
                    data={rows}
                    isLoading={query.isLoading}
                    emptyMessage={
                        filtersActive
                            ? 'No commissions match these filters. Try a wider date range, different market, or clear filters.'
                            : 'No commissions yet. Field-sales activations will appear here as they earn.'
                    }
                    pagination={meta}
                    onPageChange={setPage}
                    onRowClick={(row) => row.client?.id && navigate(`/clients/${row.client.id}`)}
                    selectable
                    onSelectionChange={setSelectedRows}
                    clearSelectionKey={clearSelectionKey}
                />
            )}

            <ConfirmDialog
                open={confirmPaidOpen}
                title="Record commission payout?"
                message={`This will mark ${selectedRows.length} commission${selectedRows.length === 1 ? '' : 's'} as paid to ${selectedAgentName}.`}
                confirmLabel="Mark paid"
                onCancel={() => setConfirmPaidOpen(false)}
                onConfirm={() => markPaidMutation.mutate()}
                isPending={markPaidMutation.isPending}
            >
                <dl className="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Agent</dt>
                        <dd className="mt-1 text-slate-900">{selectedAgentName}</dd>
                    </div>
                    <div>
                        <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Total</dt>
                        <dd className="mt-1 font-semibold text-slate-900">{money(selectedTotal, selectedCurrency)}</dd>
                    </div>
                    <div>
                        <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Rows</dt>
                        <dd className="mt-1 text-slate-900">{selectedRows.length}</dd>
                    </div>
                    <div>
                        <dt className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Reference</dt>
                        <dd className="mt-1 text-slate-900">{externalReference.trim() || <span className="text-slate-400">—</span>}</dd>
                    </div>
                </dl>
            </ConfirmDialog>

            <PayoutDrawer payout={drawerPayout} onClose={() => setDrawerPayout(null)} />
        </div>
    );
}
