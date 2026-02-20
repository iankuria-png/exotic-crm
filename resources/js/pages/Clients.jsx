import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';

export default function Clients() {
    const navigate = useNavigate();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [planFilter, setPlanFilter] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['clients', page, search, statusFilter, planFilter],
        queryFn: () =>
            api.get('/crm/clients', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(planFilter && { plan: planFilter }),
                },
            }).then((r) => r.data),
    });

    const handleSearch = (e) => {
        e.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];

    const stats = useMemo(() => {
        const active = rows.filter((row) => row.profile_status === 'publish').length;
        const premium = rows.filter((row) => row.premium).length;
        const verified = rows.filter((row) => row.verified).length;

        return {
            active,
            premium,
            verified,
        };
    }, [rows]);

    const columns = [
        {
            key: 'name',
            label: 'Client',
            render: (row) => (
                <div className="flex items-center gap-3">
                    {row.main_image_url ? (
                        <img src={row.main_image_url} alt="" className="h-9 w-9 rounded-full object-cover ring-1 ring-slate-200" />
                    ) : (
                        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                            {row.name?.charAt(0) || '?'}
                        </div>
                    )}
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900">{row.name || 'Unnamed'}</p>
                        <p className="truncate text-xs text-slate-500">{row.city || 'No city'}</p>
                    </div>
                </div>
            ),
        },
        {
            key: 'phone_normalized',
            label: 'Phone',
            render: (row) => <span className="crm-mono text-xs text-slate-600">{row.phone_normalized || '—'}</span>,
        },
        {
            key: 'profile_status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.profile_status} />,
        },
        {
            key: 'plan',
            label: 'Plan',
            render: (row) => {
                const tierLabel = row.premium ? 'Premium' : row.featured ? 'Featured' : 'Basic';
                const tierClass = row.premium
                    ? 'bg-teal-50 text-teal-700 ring-teal-200'
                    : row.featured
                        ? 'bg-amber-50 text-amber-700 ring-amber-200'
                        : 'bg-slate-100 text-slate-600 ring-slate-200';

                return (
                    <div className="flex items-center gap-1.5">
                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${tierClass}`}>
                            {tierLabel}
                        </span>
                        {row.verified ? (
                            <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                Verified
                            </span>
                        ) : null}
                    </div>
                );
            },
        },
        {
            key: 'escort_expire',
            label: 'Expires',
            render: (row) => {
                if (!row.escort_expire) return <span className="text-xs text-slate-400">N/A</span>;
                const date = new Date(row.escort_expire * 1000);
                const isExpired = date < new Date();

                return (
                    <span className={`text-xs font-medium ${isExpired ? 'text-rose-700' : 'text-slate-600'}`}>
                        {date.toLocaleDateString()}
                    </span>
                );
            },
        },
        {
            key: 'platform',
            label: 'Market',
            render: (row) => <span className="text-xs text-slate-500">{row.platform?.name || '—'}</span>,
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Clients"
                subtitle={data?.total ? `${data.total.toLocaleString()} profiles in CRM` : 'Manage profile records and subscription status.'}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Clients" value={stats.active.toLocaleString()} meta="on current page" tone="success" />
                <MetricCard label="Premium Profiles" value={stats.premium.toLocaleString()} meta="on current page" tone="accent" />
                <MetricCard label="Verified Profiles" value={stats.verified.toLocaleString()} meta="on current page" tone="default" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(e) => setSearchInput(e.target.value)}
                                placeholder="Search by name, phone, or email..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>

                    <select
                        value={statusFilter}
                        onChange={(e) => {
                            setStatusFilter(e.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Statuses</option>
                        <option value="publish">Active</option>
                        <option value="private">Inactive</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                    </select>

                    <select
                        value={planFilter}
                        onChange={(e) => {
                            setPlanFilter(e.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Plans</option>
                        <option value="premium">Premium</option>
                        <option value="featured">Featured</option>
                        <option value="basic">Basic</option>
                    </select>

                    {(search || statusFilter || planFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setPlanFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => navigate(`/clients/${row.id}`)}
                isLoading={isLoading}
                emptyMessage="No clients found matching your filters."
                compact
            />
        </div>
    );
}
