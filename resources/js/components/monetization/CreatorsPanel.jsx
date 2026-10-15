import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../../services/api';

const control = 'min-h-11 rounded-md border border-slate-300 bg-white px-3 text-sm';

const STATES = [
    { key: '', label: 'All' },
    { key: 'active', label: 'Selling' },
    { key: 'expiring', label: 'Ends within 7 days' },
    { key: 'unclaimed', label: 'Free pass not claimed' },
    { key: 'held', label: 'On hold' },
    { key: 'ended', label: 'No current pass' },
];

const STATE_PILL = {
    active: ['Selling', 'border-emerald-200 bg-emerald-50 text-emerald-700'],
    expiring: ['Ends soon', 'border-amber-200 bg-amber-50 text-amber-700'],
    unclaimed: ['Not claimed', 'border-sky-200 bg-sky-50 text-sky-700'],
    held: ['On hold', 'border-rose-200 bg-rose-50 text-rose-700'],
    ended: ['No current pass', 'border-slate-200 bg-slate-50 text-slate-600'],
};

const SORT_OPTIONS = [
    ['expires_at:desc', 'Pass ends — latest first'],
    ['expires_at:asc', 'Pass ends — soonest first'],
    ['pass_paid:desc', 'Paid for passes — highest'],
    ['sales_gross:desc', 'Content sales — highest'],
    ['sales_count:desc', 'Purchases — most'],
    ['last_pass_at:desc', 'Latest pass activity'],
    ['name:asc', 'Name A–Z'],
];

const SORT_LABELS = { expires_at: 'Pass ends', pass_paid: 'Paid for passes', sales_gross: 'Content sales', sales_count: 'Purchases', last_pass_at: 'Pass activity', name: 'Name' };

const SOURCES = {
    wallet: 'Bought from wallet',
    staff_comp: 'Staff complimentary',
    policy_new_subscription: 'Free with new subscription',
    campaign_active_subscription: 'Free for active subscribers',
    campaign_selected: 'Free campaign',
};

const money = (amount, currency) => `${currency} ${Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
const date = (value) => (value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const label = (value = '') => String(value).replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());

function useDebounced(value, delay = 300) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value.trim()), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

function SortHeader({ column, sort, onSort, children, className = '' }) {
    const active = sort.key === column;
    const direction = active ? sort.direction : null;
    return (
        <th className={`px-4 py-3 ${className}`} aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={`inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${active ? 'text-slate-900' : ''}`}
            >
                {children}
                <span aria-hidden="true" className="text-[10px]">{active ? (direction === 'asc' ? '▲' : '▼') : '↕'}</span>
            </button>
        </th>
    );
}

function Pager({ page, lastPage, total, perPage, onPage, onPerPage, noun }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
            <label>Rows <select className={`${control} ml-2`} value={perPage} onChange={(e) => onPerPage(Number(e.target.value))}><option value="15">15</option><option value="30">30</option><option value="50">50</option></select></label>
            <div className="flex items-center gap-3">
                <span>{Number(total || 0).toLocaleString()} {noun} · page {page} of {lastPage}</span>
                <button type="button" disabled={page <= 1} className={control} onClick={() => onPage(page - 1)}>Previous</button>
                <button type="button" disabled={page >= lastPage} className={control} onClick={() => onPage(page + 1)}>Next</button>
            </div>
        </div>
    );
}

function Loading() {
    return <div className="space-y-2" role="status" aria-label="Loading">{[0, 1, 2, 3].map((i) => <div key={i} className="h-14 animate-pulse rounded-md bg-slate-100" />)}</div>;
}

function Failed({ onRetry }) {
    return <div role="alert" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">Could not load this list.<button type="button" className={control} onClick={onRetry}>Try again</button></div>;
}

function Empty({ text }) {
    return <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center text-sm text-slate-500">{text}</div>;
}

async function download(url, params, name) {
    const result = await api.get(url, { params, responseType: 'blob' });
    const href = URL.createObjectURL(result.data);
    const a = document.createElement('a');
    a.href = href;
    a.download = name;
    a.click();
    URL.revokeObjectURL(href);
}

function CreatorsView({ scope, reportingCurrency, canManage, onManagePass, onMessage }) {
    const [state, setState] = useState('');
    const [passType, setPassType] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const search = useDebounced(searchInput);
    const [sort, setSort] = useState({ key: 'expires_at', direction: 'desc' });
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(30);
    useEffect(() => setPage(1), [scope, state, passType, search, sort, perPage]);

    const params = { ...scope, state: state || undefined, pass_type: passType || undefined, search: search || undefined, sort: sort.key, direction: sort.direction, reporting_currency: reportingCurrency, page, per_page: perPage };
    const query = useQuery({ queryKey: ['monetization-creators', params], queryFn: async () => (await api.get('/crm/monetization/creators', { params })).data, placeholderData: (previous) => previous });
    const data = query.data;
    const onSort = (key) => setSort((current) => (current.key === key ? { key, direction: current.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: key === 'name' ? 'asc' : 'desc' }));
    const sortOptions = SORT_OPTIONS.some(([value]) => value === `${sort.key}:${sort.direction}`)
        ? SORT_OPTIONS
        : [...SORT_OPTIONS, [`${sort.key}:${sort.direction}`, `${SORT_LABELS[sort.key]} — ${sort.direction === 'asc' ? 'lowest / earliest' : 'highest / latest'} first`]];
    const moneySort = ['pass_paid', 'sales_gross'].includes(sort.key);
    const mixed = new Set((data?.data || []).map((row) => row.currency)).size > 1;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap gap-2" role="group" aria-label="Pass state">
                {STATES.map((item) => {
                    const count = data?.counts?.[item.key || 'all'];
                    const selected = state === item.key;
                    return (
                        <button key={item.key || 'all'} type="button" aria-pressed={selected} onClick={() => setState(item.key)} className={`inline-flex min-h-9 items-center gap-2 rounded-full border px-3 text-xs font-semibold transition ${selected ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}>
                            {item.label}
                            {count !== undefined ? <span className={`rounded-full px-1.5 tabular-nums ${selected ? 'bg-white/20' : 'bg-slate-100'}`}>{count}</span> : null}
                        </button>
                    );
                })}
            </div>
            <div className="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-4">
                <label className="min-w-64 flex-1 text-xs font-medium text-slate-500">Search<input className={`${control} mt-1 w-full`} value={searchInput} onChange={(e) => setSearchInput(e.target.value)} placeholder="Creator name or ID" /></label>
                <label className="text-xs font-medium text-slate-500">Passes<select aria-label="Pass type" className={`${control} mt-1 block`} value={passType} onChange={(e) => setPassType(e.target.value)}><option value="">All creators</option><option value="paid">Bought a pass</option><option value="free">Free passes only</option></select></label>
                <label className="text-xs font-medium text-slate-500">Sort by<select aria-label="Sort creators" className={`${control} mt-1 block`} value={`${sort.key}:${sort.direction}`} onChange={(e) => { const [key, direction] = e.target.value.split(':'); setSort({ key, direction }); }}>
                    {sortOptions.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
                </select></label>
                <button type="button" className="min-h-11 rounded-md bg-teal-700 px-4 text-sm font-semibold text-white" onClick={() => download('/crm/monetization/creators/export', { ...params, page: undefined, per_page: undefined }, 'monetize-creators.csv').catch(() => onMessage('Export failed. Try again.'))}>Export</button>
            </div>
            {moneySort && mixed ? <p className="text-xs text-slate-500">Money sorts compare markets after converting to {data?.reporting_currency}; amounts show in each market’s own currency. Markets without an FX rate sort last.</p> : null}
            {query.isPending ? <Loading /> : query.isError ? <Failed onRetry={() => query.refetch()} /> : !data.data.length ? (
                <Empty text={state || passType || search ? 'No creators match these filters. Clear the search or choose another state.' : 'No creators have a selling pass in this scope yet.'} />
            ) : (
                <div className={`overflow-x-auto rounded-lg border border-slate-200 bg-white ${query.isFetching ? 'opacity-70' : ''}`}>
                    <table className="w-full min-w-[56rem] text-left text-sm">
                        <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <SortHeader column="name" sort={sort} onSort={onSort}>Creator</SortHeader>
                                <SortHeader column="expires_at" sort={sort} onSort={onSort}>Pass</SortHeader>
                                <SortHeader column="pass_paid" sort={sort} onSort={onSort}>Paid for passes</SortHeader>
                                <SortHeader column="sales_gross" sort={sort} onSort={onSort}>Content sales</SortHeader>
                                <th className="px-4 py-3">Earned credit</th>
                                <th className="px-4 py-3"><span className="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.data.map((row) => {
                                const [pillLabel, pillTone] = STATE_PILL[row.state] || STATE_PILL.ended;
                                return (
                                    <tr key={row.id} className="border-t border-slate-100 align-top">
                                        <td className="px-4 py-3">
                                            <Link className="font-semibold text-slate-950 hover:text-teal-700" to={`/clients/${row.id}?tab=monetization`}>{row.name}</Link>
                                            <p className="mt-0.5 text-xs text-slate-500">{row.platform_name || 'Unassigned market'} · #{row.id}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex rounded-md border px-2 py-0.5 text-xs font-semibold ${pillTone}`}>{pillLabel}</span>
                                            <p className="mt-1 text-xs text-slate-500">
                                                {row.expires_at ? `${row.state === 'held' ? 'Held until' : 'Ends'} ${date(row.expires_at)}` : 'Nothing running'}
                                                {row.current_type ? ` · ${row.current_type === 'paid' ? 'Bought' : 'Free'}` : ''}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-900">{money(row.pass_paid, row.currency)}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">{row.paid_passes} bought · {row.passes - row.paid_passes} free</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-900">{money(row.sales_gross, row.currency)}</p>
                                            <p className="mt-0.5 text-xs text-slate-500">{Number(row.sales_count).toLocaleString()} purchases</p>
                                        </td>
                                        <td className="px-4 py-3 text-xs text-slate-600">
                                            {row.stats ? <><p><span className="font-semibold text-slate-900">{money(row.stats.earned_outstanding, row.stats.currency)}</span> available</p><p className="mt-0.5">{money(row.stats.earned_spent, row.stats.currency)} spent on Exotic</p></> : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {canManage ? <button type="button" className={`${control} whitespace-nowrap`} onClick={() => onManagePass(row)}>Manage pass</button> : null}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            {data?.data?.length ? <Pager page={data.page} lastPage={data.last_page} total={data.total} perPage={perPage} onPage={setPage} onPerPage={setPerPage} noun="creators" /> : null}
        </div>
    );
}

function PassesView({ scope, onMessage }) {
    const [passType, setPassType] = useState('paid');
    const [status, setStatus] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const search = useDebounced(searchInput);
    const [sort, setSort] = useState({ key: 'created_at', direction: 'desc' });
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(30);
    useEffect(() => setPage(1), [scope, passType, status, search, sort, perPage]);

    const params = { ...scope, pass_type: passType, status: status || undefined, search: search || undefined, sort: sort.key, direction: sort.direction, page, per_page: perPage };
    const query = useQuery({ queryKey: ['monetization-passes', params], queryFn: async () => (await api.get('/crm/monetization/passes', { params })).data, placeholderData: (previous) => previous });
    const data = query.data;
    const rows = data?.passes?.data || [];
    const onSort = (key) => setSort((current) => (current.key === key ? { key, direction: current.direction === 'asc' ? 'desc' : 'asc' } : { key, direction: 'desc' }));

    return (
        <div className="space-y-4">
            {(data?.totals || []).length ? (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {data.totals.map((total) => (
                        <div key={total.currency} className="rounded-lg border border-slate-200 bg-white p-4">
                            <p className="text-xs font-semibold text-slate-500">{passType === 'free' ? 'Free passes' : passType === 'all' ? 'All passes' : 'Pass revenue'} · {total.currency}</p>
                            <p className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">{money(total.paid, total.currency)}</p>
                            <p className="mt-1 text-xs text-slate-500">{total.passes.toLocaleString()} passes · {total.creators.toLocaleString()} creators{total.subsidy ? ` · ${money(total.subsidy, total.currency)} subsidised` : ''}</p>
                        </div>
                    ))}
                </div>
            ) : null}
            <div className="flex flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-4">
                <label className="min-w-64 flex-1 text-xs font-medium text-slate-500">Search<input className={`${control} mt-1 w-full`} value={searchInput} onChange={(e) => setSearchInput(e.target.value)} placeholder="Creator name, pass ID or MP- reference" /></label>
                <label className="text-xs font-medium text-slate-500">Type<select aria-label="Pass type" className={`${control} mt-1 block`} value={passType} onChange={(e) => setPassType(e.target.value)}><option value="paid">Bought</option><option value="free">Free</option><option value="all">All passes</option></select></label>
                <label className="text-xs font-medium text-slate-500">Status<select aria-label="Pass status" className={`${control} mt-1 block`} value={status} onChange={(e) => setStatus(e.target.value)}><option value="">All statuses</option>{['active', 'queued', 'granted', 'held', 'expired', 'refunded', 'reversed', 'retired'].map((s) => <option key={s} value={s}>{s === 'granted' ? 'Not claimed' : label(s)}</option>)}</select></label>
                <button type="button" className="min-h-11 rounded-md bg-teal-700 px-4 text-sm font-semibold text-white" onClick={() => download('/crm/monetization/passes/export', { ...params, page: undefined, per_page: undefined }, 'monetize-passes.csv').catch(() => onMessage('Export failed. Try again.'))}>Export</button>
            </div>
            {query.isPending ? <Loading /> : query.isError ? <Failed onRetry={() => query.refetch()} /> : !rows.length ? (
                <Empty text={passType === 'paid' && !search && !status ? 'No creator has bought a pass in this window. Free passes are under Type → Free.' : 'No passes match these filters.'} />
            ) : (
                <div className={`overflow-x-auto rounded-lg border border-slate-200 bg-white ${query.isFetching ? 'opacity-70' : ''}`}>
                    <table className="w-full min-w-[56rem] text-left text-sm">
                        <thead className="bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <SortHeader column="created_at" sort={sort} onSort={onSort}>Bought</SortHeader>
                                <th className="px-4 py-3">Creator</th>
                                <SortHeader column="expires_at" sort={sort} onSort={onSort}>Pass</SortHeader>
                                <th className="px-4 py-3">Price</th>
                                <th className="px-4 py-3">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((pass) => (
                                <tr key={pass.id} className="border-t border-slate-100 align-top">
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-950">{date(pass.created_at)}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">Pass #{pass.id}{pass.is_sandbox ? ' · Sandbox' : ''}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        {pass.client ? <Link className="font-semibold text-slate-950 hover:text-teal-700" to={`/clients/${pass.client.id}?tab=monetization`}>{pass.client.name}</Link> : <span className="font-semibold text-slate-500">Archived creator</span>}
                                        <p className="mt-0.5 text-xs text-slate-500">{pass.platform_name || 'Unassigned market'}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="inline-flex rounded-md border border-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600">{pass.status === 'granted' ? 'Not claimed' : label(pass.status)}</span>
                                        <p className="mt-1 text-xs text-slate-500">{label(pass.duration_key)} · {date(pass.starts_at)} → {date(pass.expires_at)}</p>
                                        {pass.hold_reason ? <p className="mt-1 max-w-xs text-xs text-rose-700">Held: {pass.hold_reason}</p> : null}
                                    </td>
                                    <td className="px-4 py-3">
                                        <p className="font-semibold text-slate-950">{pass.type === 'paid' ? money(pass.paid_amount, pass.currency) : 'Free'}</p>
                                        <p className="mt-0.5 text-xs text-slate-500">List {money(pass.list_amount, pass.currency)}{pass.subsidy_amount ? ` · ${money(pass.subsidy_amount, pass.currency)} subsidy` : ''}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <p className="text-slate-700">{SOURCES[pass.grant_source] || label(pass.grant_source)}</p>
                                        {pass.payment_reference ? <p className="mt-0.5 font-mono text-xs text-slate-500" title="Wallet payment reference">{pass.payment_reference}</p> : null}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {rows.length ? <Pager page={data.passes.current_page} lastPage={data.passes.last_page} total={data.passes.total} perPage={perPage} onPage={setPage} onPerPage={setPerPage} noun="passes" /> : null}
        </div>
    );
}

export default function CreatorsPanel({ view, onViewChange, scope, reportingCurrency, canManage, onManagePass, onMessage }) {
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Creators view">
                    {[['creators', 'Creators'], ['passes', 'Pass purchases']].map(([key, text]) => (
                        <button key={key} type="button" aria-pressed={view === key} onClick={() => onViewChange(key)} className={`rounded px-3 py-1.5 text-sm font-semibold transition ${view === key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}>{text}</button>
                    ))}
                </div>
                <p className="text-xs text-slate-500">{view === 'passes' ? 'Every selling pass in this market and date range, with who holds it and how it was paid.' : 'Creators with a selling pass in this market and date range. Pass state and earned credit are current.'}</p>
            </div>
            {view === 'passes'
                ? <PassesView scope={scope} onMessage={onMessage} />
                : <CreatorsView scope={scope} reportingCurrency={reportingCurrency} canManage={canManage} onManagePass={onManagePass} onMessage={onMessage} />}
        </div>
    );
}
