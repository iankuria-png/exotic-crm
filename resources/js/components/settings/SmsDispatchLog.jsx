import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

const STATUS_STYLES = {
    sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    failed: 'bg-rose-50 text-rose-700 ring-rose-600/20',
};

const EMPTY_FILTERS = {
    market_id: '',
    provider: '',
    status: '',
    search: '',
    from: '',
    to: '',
};

function cleanParams(filters, extra = {}) {
    const params = {};
    Object.entries({ ...filters, ...extra }).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) {
            params[key] = value;
        }
    });
    return params;
}

export default function SmsDispatchLog({ platforms = [], providerOptions = [] }) {
    const [filters, setFilters] = useState(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [exporting, setExporting] = useState(false);
    const [exportError, setExportError] = useState('');

    const params = useMemo(() => cleanParams(filters, { page, per_page: 25 }), [filters, page]);

    const logsQuery = useQuery({
        queryKey: ['sms-dispatch-logs', params],
        queryFn: () => api.get('/crm/settings/integrations/sms-logs', { params }).then((response) => response.data),
        keepPreviousData: true,
    });

    const rows = logsQuery.data?.data ?? [];
    const meta = logsQuery.data?.meta ?? { current_page: 1, last_page: 1, total: 0 };
    const hasActiveFilters = Object.values(filters).some((value) => value !== '');

    const updateFilter = (key, value) => {
        setPage(1);
        setFilters((current) => ({ ...current, [key]: value }));
    };

    const resetFilters = () => {
        setPage(1);
        setFilters(EMPTY_FILTERS);
    };

    const handleExport = async () => {
        setExporting(true);
        setExportError('');
        try {
            const response = await api.get('/crm/settings/integrations/sms-logs/export', {
                params: cleanParams(filters),
                responseType: 'blob',
            });
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `sms-dispatches-${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch (error) {
            setExportError('Export failed. Please try again.');
        } finally {
            setExporting(false);
        }
    };

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Recent Dispatches</h3>
                    <p className="crm-panel-subtitle">Every routed SMS with provider, market, and delivery outcome.</p>
                </div>
                <button
                    type="button"
                    onClick={handleExport}
                    disabled={exporting || meta.total === 0}
                    className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {exporting ? 'Exporting…' : 'Export CSV'}
                </button>
            </header>

            <div className="p-4">
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6">
                    <select value={filters.market_id} onChange={(event) => updateFilter('market_id', event.target.value)} className="crm-select" aria-label="Filter by market">
                        <option value="">All markets</option>
                        {(platforms ?? []).map((platform) => (
                            <option key={platform.id} value={platform.id}>{platform.name}</option>
                        ))}
                    </select>
                    <select value={filters.provider} onChange={(event) => updateFilter('provider', event.target.value)} className="crm-select" aria-label="Filter by provider">
                        <option value="">All providers</option>
                        {(providerOptions ?? []).map((provider) => (
                            <option key={provider.id} value={provider.id}>{provider.label}</option>
                        ))}
                    </select>
                    <select value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} className="crm-select" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        <option value="sent">Sent</option>
                        <option value="failed">Failed</option>
                    </select>
                    <input type="date" value={filters.from} onChange={(event) => updateFilter('from', event.target.value)} className="crm-input" aria-label="From date" />
                    <input type="date" value={filters.to} onChange={(event) => updateFilter('to', event.target.value)} className="crm-input" aria-label="To date" />
                    <input
                        type="search"
                        value={filters.search}
                        onChange={(event) => updateFilter('search', event.target.value)}
                        placeholder="Search phone or text"
                        className="crm-input"
                        aria-label="Search phone or message"
                    />
                </div>
                {hasActiveFilters ? (
                    <div className="mt-2">
                        <button type="button" onClick={resetFilters} className="text-xs font-medium text-slate-500 hover:text-slate-700">Clear filters</button>
                    </div>
                ) : null}

                {exportError ? <p className="mt-2 text-xs text-rose-600">{exportError}</p> : null}

                <div className="mt-3 overflow-x-auto rounded-lg border border-slate-200">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-3 py-2">Sent</th>
                                <th className="px-3 py-2">Market</th>
                                <th className="px-3 py-2">Provider</th>
                                <th className="px-3 py-2">Phone</th>
                                <th className="px-3 py-2">Status</th>
                                <th className="px-3 py-2">HTTP</th>
                                <th className="px-3 py-2">Response</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {logsQuery.isLoading ? (
                                <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">Loading dispatches…</td></tr>
                            ) : logsQuery.isError ? (
                                <tr><td colSpan={7} className="px-3 py-8 text-center text-rose-500">Could not load dispatches. <button type="button" onClick={() => logsQuery.refetch()} className="underline">Retry</button></td></tr>
                            ) : rows.length === 0 ? (
                                <tr><td colSpan={7} className="px-3 py-8 text-center text-slate-400">{hasActiveFilters ? 'No dispatches match these filters.' : 'No SMS dispatches recorded yet.'}</td></tr>
                            ) : (
                                rows.map((row) => (
                                    <tr key={row.id} className="align-top">
                                        <td className="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{row.sent_at || '—'}</td>
                                        <td className="px-3 py-2 text-slate-700">{row.market}</td>
                                        <td className="px-3 py-2 text-slate-700">
                                            {row.provider_label}
                                            {row.fallback_used ? <span className="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">fallback</span> : null}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-600">{row.phone}</td>
                                        <td className="px-3 py-2">
                                            <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${STATUS_STYLES[row.status] || 'bg-slate-50 text-slate-600 ring-slate-500/20'}`}>
                                                {row.status}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-slate-500">{row.http_code ?? '—'}</td>
                                        <td className="max-w-xs truncate px-3 py-2 text-xs text-slate-500" title={row.response || ''}>{row.response || '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {meta.total > 0 ? (
                    <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                        <span>{meta.total} dispatch{meta.total === 1 ? '' : 'es'} • page {meta.current_page} of {meta.last_page}</span>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={meta.current_page <= 1} className="crm-btn-secondary px-2 py-1 disabled:cursor-not-allowed disabled:opacity-50">Prev</button>
                            <button type="button" onClick={() => setPage((current) => Math.min(meta.last_page, current + 1))} disabled={meta.current_page >= meta.last_page} className="crm-btn-secondary px-2 py-1 disabled:cursor-not-allowed disabled:opacity-50">Next</button>
                        </div>
                    </div>
                ) : null}
            </div>
        </section>
    );
}
