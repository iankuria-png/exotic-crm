import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import StatusBadge from '../components/StatusBadge';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';

const baseTabs = [
    { id: 'integrations', label: 'Integrations' },
    { id: 'templates', label: 'Templates' },
    { id: 'logs', label: 'Webhook Logs' },
    { id: 'roles', label: 'Roles & Permissions' },
];

function statusChip(status) {
    if (status === 'connected') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'configured_disabled') return 'bg-amber-50 text-amber-700 ring-amber-200';
    if (status === 'deferred') return 'bg-slate-100 text-slate-700 ring-slate-300';
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function IntegrationsWorkspace() {
    const { data, isLoading } = useQuery({
        queryKey: ['settings-integrations'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const services = data?.services || {};
    const serviceRows = [
        {
            key: 'sms',
            label: 'SMS Gateway',
            status: services.sms_gateway?.status || 'pending',
            detail: services.sms_gateway?.gateway_url || 'Gateway URL not configured',
        },
        {
            key: 'kopokopo',
            label: 'KopoKopo',
            status: services.kopokopo?.status || 'pending',
            detail: services.kopokopo?.base_url || 'Base URL not configured',
        },
        {
            key: 'sendgrid',
            label: 'SendGrid',
            status: services.sendgrid?.status || 'deferred',
            detail: services.sendgrid?.note || 'Deferred',
        },
    ];

    const platformRows = data?.platforms || [];

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard
                    label="Connected Services"
                    value={serviceRows.filter((item) => item.status === 'connected').length.toLocaleString()}
                    meta="runtime integration health"
                    tone="success"
                />
                <MetricCard
                    label="Markets Configured"
                    value={platformRows.length.toLocaleString()}
                    meta="platform runtime profiles"
                    tone="accent"
                />
                <MetricCard
                    label="WP Sync Ready"
                    value={platformRows.filter((item) => item.wp_sync?.status === 'connected').length.toLocaleString()}
                    meta="markets with credentials"
                    tone="default"
                />
            </section>

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Service Integrations</h3>
                        <p className="crm-panel-subtitle">Live status for SMS, payment, and deferred email channels.</p>
                    </div>
                </header>
                <div className="divide-y divide-slate-100">
                    {isLoading ? (
                        <p className="p-4 text-sm text-slate-500">Loading service health...</p>
                    ) : serviceRows.map((service) => (
                        <div key={service.key} className="flex flex-wrap items-center justify-between gap-3 p-4">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">{service.label}</p>
                                <p className="text-xs text-slate-500">{service.detail}</p>
                            </div>
                            <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(service.status)}`}>
                                {service.status.replaceAll('_', ' ')}
                            </span>
                        </div>
                    ))}
                </div>
            </section>

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Market Sync Health</h3>
                        <p className="crm-panel-subtitle">WordPress sync connectivity by market platform.</p>
                    </div>
                </header>
                <div className="max-h-[420px] overflow-auto">
                    {isLoading ? (
                        <p className="p-4 text-sm text-slate-500">Loading markets...</p>
                    ) : platformRows.length === 0 ? (
                        <p className="p-4 text-sm text-slate-500">No market platforms found.</p>
                    ) : (
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Market</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Country</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">WP API</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Currency</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {platformRows.map((platform) => (
                                    <tr key={platform.platform_id}>
                                        <td className="px-4 py-2.5 text-sm font-semibold text-slate-900">{platform.platform_name}</td>
                                        <td className="px-4 py-2.5 text-sm text-slate-600">{platform.country || '—'}</td>
                                        <td className="px-4 py-2.5">
                                            <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(platform.wp_sync?.status)}`}>
                                                {(platform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-sm text-slate-600">{platform.currency || 'KES'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </section>
        </div>
    );
}

function TemplatesWorkspace({ canManageTemplates }) {
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [editorForm, setEditorForm] = useState(null);
    const [feedback, setFeedback] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['settings-templates', page, search, statusFilter, categoryFilter],
        queryFn: () => api.get('/crm/settings/templates', {
            params: {
                page,
                per_page: 20,
                ...(search ? { search } : {}),
                ...(statusFilter ? { status: statusFilter } : {}),
                ...(categoryFilter ? { category: categoryFilter } : {}),
            },
        }).then((response) => response.data),
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }) => api.patch(`/crm/settings/templates/${id}`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-templates'] });
            setFeedback({ tone: 'success', text: 'Template updated successfully.' });
            setSelectedTemplate(null);
            setEditorForm(null);
        },
        onError: () => {
            setFeedback({ tone: 'danger', text: 'Template update failed. Please try again.' });
        },
    });

    const rows = data?.data || [];

    const metrics = useMemo(() => {
        const active = rows.filter((row) => row.status === 'active').length;
        const draft = rows.filter((row) => row.status === 'draft').length;
        const renewal = rows.filter((row) => row.category === 'renewal').length;
        return { active, draft, renewal };
    }, [rows]);

    const columns = [
        {
            key: 'title',
            label: 'Template',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.title}</p>
                    <p className="truncate text-xs text-slate-500">{row.body}</p>
                </div>
            ),
        },
        {
            key: 'category',
            label: 'Category',
            render: (row) => <span className="text-xs capitalize text-slate-700">{row.category.replace('_', ' ')}</span>,
        },
        {
            key: 'channel',
            label: 'Channel',
            render: (row) => (
                <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium uppercase text-slate-700 ring-1 ring-inset ring-slate-200">
                    {row.channel}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'updated_at',
            label: 'Updated',
            render: (row) => <span className="text-xs text-slate-500">{new Date(row.updated_at).toLocaleDateString()}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                canManageTemplates ? (
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            setSelectedTemplate(row);
                            setEditorForm({
                                title: row.title || '',
                                category: row.category || 'follow_up',
                                channel: row.channel || 'sms',
                                subject: row.subject || '',
                                body: row.body || '',
                                status: row.status || 'draft',
                            });
                        }}
                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                    >
                        Edit
                    </button>
                ) : (
                    <span className="text-xs text-slate-400">Read only</span>
                )
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Templates" value={metrics.active.toLocaleString()} meta="live automation copy" tone="success" />
                <MetricCard label="Draft Templates" value={metrics.draft.toLocaleString()} meta="pending approval" tone="warning" />
                <MetricCard label="Renewal Copy Sets" value={metrics.renewal.toLocaleString()} meta="expiry workflows" tone="accent" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSearch(searchInput.trim());
                            setPage(1);
                        }}
                        className="min-w-[240px] flex-1"
                    >
                        <div className="relative">
                            <input
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search template body or title..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    <select value={categoryFilter} onChange={(event) => setCategoryFilter(event.target.value)} className="crm-select">
                        <option value="">All categories</option>
                        <option value="payment">Payment</option>
                        <option value="renewal">Renewal</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="welcome">Welcome</option>
                        <option value="win_back">Win-back</option>
                    </select>
                    <select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} className="crm-select">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="draft">Draft</option>
                    </select>
                    {(search || categoryFilter || statusFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setCategoryFilter('');
                                setStatusFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
                {feedback ? (
                    <p className={`mt-2 text-xs font-medium ${feedback.tone === 'success' ? 'text-emerald-700' : 'text-rose-700'}`}>
                        {feedback.text}
                    </p>
                ) : null}
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => {
                    if (!canManageTemplates) {
                        return;
                    }
                    setSelectedTemplate(row);
                    setEditorForm({
                        title: row.title || '',
                        category: row.category || 'follow_up',
                        channel: row.channel || 'sms',
                        subject: row.subject || '',
                        body: row.body || '',
                        status: row.status || 'draft',
                    });
                }}
                isLoading={isLoading}
                compact
                emptyMessage="No templates found."
            />

            {selectedTemplate && editorForm ? (
                <div className="fixed inset-0 z-50 flex bg-slate-900/45" onClick={() => {
                    setSelectedTemplate(null);
                    setEditorForm(null);
                }}>
                    <aside className="ml-auto h-full w-full max-w-xl border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header sticky top-0 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Edit Template</h3>
                                <p className="crm-panel-subtitle">{selectedTemplate.title}</p>
                            </div>
                        </header>

                        <div className="space-y-3 p-4">
                            <input
                                value={editorForm.title}
                                onChange={(event) => setEditorForm({ ...editorForm, title: event.target.value })}
                                className="crm-input"
                                placeholder="Template title"
                            />

                            <div className="grid gap-3 md:grid-cols-2">
                                <select value={editorForm.category} onChange={(event) => setEditorForm({ ...editorForm, category: event.target.value })} className="crm-select">
                                    <option value="payment">Payment</option>
                                    <option value="renewal">Renewal</option>
                                    <option value="follow_up">Follow-up</option>
                                    <option value="welcome">Welcome</option>
                                    <option value="win_back">Win-back</option>
                                </select>
                                <select value={editorForm.status} onChange={(event) => setEditorForm({ ...editorForm, status: event.target.value })} className="crm-select">
                                    <option value="active">Active</option>
                                    <option value="draft">Draft</option>
                                </select>
                            </div>

                            <input
                                value={editorForm.subject}
                                onChange={(event) => setEditorForm({ ...editorForm, subject: event.target.value })}
                                className="crm-input"
                                placeholder="Subject (optional)"
                            />

                            <textarea
                                value={editorForm.body}
                                onChange={(event) => setEditorForm({ ...editorForm, body: event.target.value })}
                                className="crm-input min-h-[240px] resize-y"
                                placeholder="Template body"
                            />
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => {
                                setSelectedTemplate(null);
                                setEditorForm(null);
                            }}>
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => updateMutation.mutate({ id: selectedTemplate.id, payload: editorForm })}
                                disabled={!editorForm.title.trim() || !editorForm.body.trim() || updateMutation.isPending}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {updateMutation.isPending ? 'Saving...' : 'Save template'}
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}
        </div>
    );
}

function WebhookLogsWorkspace() {
    const [page, setPage] = useState(1);
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [selectedLog, setSelectedLog] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['settings-webhook-logs', page, search],
        queryFn: () => api.get('/crm/settings/webhook-logs', {
            params: {
                page,
                per_page: 25,
                ...(search ? { search } : {}),
            },
        }).then((response) => response.data),
    });

    const columns = [
        {
            key: 'created_at',
            label: 'Timestamp',
            render: (row) => <span className="text-xs text-slate-600">{row.created_at ? new Date(row.created_at).toLocaleString() : '--'}</span>,
        },
        {
            key: 'action',
            label: 'Action',
            render: (row) => <span className="text-xs font-semibold uppercase tracking-wide text-slate-700">{row.action.replaceAll('_', ' ')}</span>,
        },
        {
            key: 'entity',
            label: 'Entity',
            render: (row) => <span className="text-xs text-slate-600">{row.entity_type} #{row.entity_id}</span>,
        },
        {
            key: 'actor',
            label: 'Actor',
            render: (row) => <span className="text-xs text-slate-600">{row.actor?.name || 'System'}</span>,
        },
        {
            key: 'reason',
            label: 'Reason',
            render: (row) => <span className="truncate text-xs text-slate-500">{row.reason || '—'}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={(event) => {
                    event.stopPropagation();
                    setSelectedLog(row);
                }}>
                    Inspect
                </button>
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSearch(searchInput.trim());
                            setPage(1);
                        }}
                        className="min-w-[240px] flex-1"
                    >
                        <div className="relative">
                            <input
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search action, reason, or entity..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    {search ? (
                        <button type="button" className="crm-btn-secondary px-3 py-2" onClick={() => {
                            setSearch('');
                            setSearchInput('');
                            setPage(1);
                        }}>
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
                onRowClick={(row) => setSelectedLog(row)}
                isLoading={isLoading}
                compact
                emptyMessage="No webhook logs found."
            />

            {selectedLog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setSelectedLog(null)}>
                    <div className="w-full max-w-3xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Webhook Log Detail</h3>
                                <p className="crm-panel-subtitle">{selectedLog.action.replaceAll('_', ' ')} • {selectedLog.created_at ? new Date(selectedLog.created_at).toLocaleString() : '--'}</p>
                            </div>
                        </header>
                        <div className="grid gap-4 p-4 lg:grid-cols-2">
                            <section className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Before State</h4>
                                <pre className="crm-mono mt-2 max-h-64 overflow-auto text-xs text-slate-700">{JSON.stringify(selectedLog.before_state || {}, null, 2)}</pre>
                            </section>
                            <section className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <h4 className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">After State</h4>
                                <pre className="crm-mono mt-2 max-h-64 overflow-auto text-xs text-slate-700">{JSON.stringify(selectedLog.after_state || {}, null, 2)}</pre>
                            </section>
                        </div>
                        <footer className="flex justify-end border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setSelectedLog(null)}>
                                Close
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function roleClasses(role) {
    if (role === 'admin') return 'bg-indigo-50 text-indigo-700 ring-indigo-200';
    if (role === 'sub_admin') return 'bg-sky-50 text-sky-700 ring-sky-200';
    return 'bg-slate-100 text-slate-700 ring-slate-200';
}

function RolesWorkspace() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [selectedUser, setSelectedUser] = useState(null);
    const [editor, setEditor] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['settings-roles'],
        queryFn: () => api.get('/crm/settings/roles').then((response) => response.data),
    });

    const updateRoleMutation = useMutation({
        mutationFn: ({ userId, payload }) => api.patch(`/crm/settings/roles/${userId}`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-roles'] });
            toast.success('Role permissions updated.');
            setSelectedUser(null);
            setEditor(null);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Role update failed.');
        },
    });

    const users = data?.users || [];
    const summary = data?.summary || {};
    const availableMarkets = data?.available_markets || [];

    const openEditor = (user) => {
        setSelectedUser(user);
        setEditor({
            role: user.role || 'sales',
            status: user.status || 'active',
            assigned_market_ids: Array.isArray(user.assigned_market_ids) ? user.assigned_market_ids.map((id) => Number(id)) : [],
            reason: 'Role update from settings',
        });
    };

    const toggleMarket = (marketId) => {
        setEditor((current) => {
            if (!current) return current;

            const exists = current.assigned_market_ids.includes(marketId);
            return {
                ...current,
                assigned_market_ids: exists
                    ? current.assigned_market_ids.filter((id) => id !== marketId)
                    : [...current.assigned_market_ids, marketId],
            };
        });
    };

    return (
        <div className="space-y-4">
            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Admins" value={(summary.admins || 0).toLocaleString()} meta="full permissions" tone="accent" />
                <MetricCard label="Sub-admins" value={(summary.sub_admins || 0).toLocaleString()} meta="market-level controls" tone="default" />
                <MetricCard label="Sales Agents" value={(summary.sales || 0).toLocaleString()} meta="execution role" tone="success" />
                <MetricCard label="Inactive Users" value={(summary.inactive || 0).toLocaleString()} meta="access suspended" tone="warning" />
            </section>

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Access Matrix</h3>
                        <p className="crm-panel-subtitle">Role ownership and assigned market footprint.</p>
                    </div>
                </header>

                <div className="max-h-[520px] overflow-auto">
                    {isLoading ? (
                        <p className="p-4 text-sm text-slate-500">Loading user access map...</p>
                    ) : users.length === 0 ? (
                        <p className="p-4 text-sm text-slate-500">No users found.</p>
                    ) : (
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">User</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Role</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Status</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Assigned Markets</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {users.map((user) => {
                                    const assignedMarkets = Array.isArray(user.assigned_markets) ? user.assigned_markets : [];
                                    const marketCount = assignedMarkets.length;
                                    const marketLabel = marketCount > 0
                                        ? assignedMarkets.map((market) => market.name).join(', ')
                                        : 'None';

                                    return (
                                        <tr key={user.id}>
                                            <td className="px-4 py-2.5">
                                                <p className="text-sm font-semibold text-slate-900">{user.name}</p>
                                                <p className="text-xs text-slate-500">{user.email}</p>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${roleClasses(user.role)}`}>
                                                    {user.role.replace('_', ' ')}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                                    user.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                        : 'bg-slate-200 text-slate-700 ring-slate-300'
                                                }`}>
                                                    {user.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <p className="text-sm text-slate-700">{marketCount}</p>
                                                <p className="truncate text-xs text-slate-500">{marketLabel}</p>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditor(user)}
                                                    className="crm-btn-secondary px-3 py-1.5 text-xs"
                                                >
                                                    Edit
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </div>
            </section>

            {selectedUser && editor ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => {
                    setSelectedUser(null);
                    setEditor(null);
                }}>
                    <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Edit Role & Permissions</h3>
                                <p className="crm-panel-subtitle">{selectedUser.name} • {selectedUser.email}</p>
                            </div>
                        </header>

                        <div className="grid gap-3 p-4 md:grid-cols-2">
                            <div>
                                <label htmlFor="role-select" className="mb-1 block text-sm font-medium text-slate-700">Role</label>
                                <select
                                    id="role-select"
                                    value={editor.role}
                                    onChange={(event) => setEditor((current) => ({ ...current, role: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="sub_admin">Sub-admin</option>
                                    <option value="sales">Sales</option>
                                </select>
                            </div>

                            <div>
                                <label htmlFor="status-select" className="mb-1 block text-sm font-medium text-slate-700">Status</label>
                                <select
                                    id="status-select"
                                    value={editor.status}
                                    onChange={(event) => setEditor((current) => ({ ...current, status: event.target.value }))}
                                    className="crm-select w-full"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>

                            <div className="md:col-span-2">
                                <p className="mb-1 text-sm font-medium text-slate-700">Assigned markets</p>
                                {availableMarkets.length === 0 ? (
                                    <p className="text-sm text-slate-500">No markets available.</p>
                                ) : (
                                    <div className="grid max-h-56 gap-2 overflow-auto rounded-md border border-slate-200 p-2 sm:grid-cols-2">
                                        {availableMarkets.map((market) => (
                                            <label key={market.id} className="flex items-center gap-2 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-50">
                                                <input
                                                    type="checkbox"
                                                    checked={editor.assigned_market_ids.includes(market.id)}
                                                    onChange={() => toggleMarket(market.id)}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                <span>{market.name} {market.country ? `(${market.country})` : ''}</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="md:col-span-2">
                                <label htmlFor="role-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    id="role-reason"
                                    rows={3}
                                    value={editor.reason}
                                    onChange={(event) => setEditor((current) => ({ ...current, reason: event.target.value }))}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button
                                type="button"
                                className="crm-btn-secondary"
                                onClick={() => {
                                    setSelectedUser(null);
                                    setEditor(null);
                                }}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => updateRoleMutation.mutate({
                                    userId: selectedUser.id,
                                    payload: {
                                        role: editor.role,
                                        status: editor.status,
                                        assigned_market_ids: editor.assigned_market_ids,
                                        reason: editor.reason,
                                    },
                                })}
                                disabled={!editor.reason.trim() || updateRoleMutation.isPending}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {updateRoleMutation.isPending ? 'Saving...' : 'Save changes'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

export default function Settings() {
    const { user } = useAuth();
    const [activeTab, setActiveTab] = useState('integrations');
    const canManageTemplates = ['admin', 'sub_admin'].includes(user?.role || '');
    const canViewRoles = (user?.role || '') === 'admin';

    const tabs = useMemo(() => {
        return baseTabs.filter((tab) => (tab.id === 'roles' ? canViewRoles : true));
    }, [canViewRoles]);

    useEffect(() => {
        if (!tabs.find((tab) => tab.id === activeTab)) {
            setActiveTab(tabs[0]?.id || 'integrations');
        }
    }, [activeTab, tabs]);

    return (
        <div className="space-y-4">
            <PageHeader title="Settings" subtitle="Configure integrations, templates, and operational controls." />

            <section className="crm-surface p-2">
                <div className="flex flex-wrap gap-1">
                    {tabs.map((tab) => (
                        <button
                            key={tab.id}
                            type="button"
                            onClick={() => setActiveTab(tab.id)}
                            className={`rounded-md px-3 py-2 text-sm font-medium transition ${activeTab === tab.id ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </section>

            {activeTab === 'integrations' ? <IntegrationsWorkspace /> : null}

            {activeTab === 'templates' ? <TemplatesWorkspace canManageTemplates={canManageTemplates} /> : null}
            {activeTab === 'logs' ? <WebhookLogsWorkspace /> : null}
            {activeTab === 'roles' && canViewRoles ? <RolesWorkspace /> : null}
        </div>
    );
}
