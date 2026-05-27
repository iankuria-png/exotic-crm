import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHeader from '../components/PageHeader';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import { useToast } from '../components/ToastProvider';
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
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

export default function AdminCommissions() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('earned');
    const [agentId, setAgentId] = useState('');
    const [externalReference, setExternalReference] = useState('');
    const [selectedRows, setSelectedRows] = useState([]);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);

    const query = useQuery({
        queryKey: ['admin-commissions', page, status, agentId],
        queryFn: () => api.get('/crm/admin/commissions', {
            params: {
                page,
                per_page: 50,
                ...(status && { status }),
                ...(agentId && { agent_user_id: Number(agentId) }),
            },
        }).then((response) => response.data),
        keepPreviousData: true,
    });

    const paginator = query.data?.commissions || query.data?.data || {};
    const rows = paginator.data || [];
    const meta = paginator.meta || paginator;
    const agents = query.data?.agents || [];

    const selectedTotal = useMemo(() => {
        return selectedRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }, [selectedRows]);

    const selectedCurrency = selectedRows[0]?.currency || rows[0]?.currency || 'KES';
    const canMarkPaid = selectedRows.length > 0
        && selectedRows.every((row) => row.status === 'earned')
        && selectedRows.every((row) => Number(row.agent_id) === Number(selectedRows[0]?.agent_id))
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
            toast.success('Commission payout recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not mark commissions as paid.');
        },
    });

    const columns = [
        {
            key: 'agent',
            label: 'Agent',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.agent?.name || 'Agent'}</p>
                    <p className="text-xs text-slate-500">{row.agent?.email || row.platform?.name || '-'}</p>
                </div>
            ),
        },
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div>
                    <p className="text-sm text-slate-900">{row.client?.name || row.client?.phone_normalized || 'Client'}</p>
                    <p className="text-xs text-slate-500">{row.platform?.name || 'Market'}</p>
                </div>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            render: (row) => <span className="text-sm capitalize text-slate-700">{String(row.type || '').replace('_', ' ')}</span>,
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => <span className="text-sm font-semibold text-slate-900">{money(row.amount, row.currency || 'KES')}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className={`inline-flex rounded-md px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${statusClasses(row.status)}`}>
                    {row.status || 'earned'}
                </span>
            ),
        },
        {
            key: 'earned_at',
            label: 'Earned',
            render: (row) => <span className="text-xs text-slate-500">{row.earned_at ? new Date(row.earned_at).toLocaleString() : '-'}</span>,
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader title="Field Commissions" subtitle="Review and settle field-sales commission payouts." />

            <section className="crm-filter-row flex flex-wrap items-end gap-3">
                <FilterSelect
                    label="Status"
                    value={status}
                    onChange={(event) => {
                        setStatus(event.target.value);
                        setPage(1);
                    }}
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
                    onChange={(event) => {
                        setAgentId(event.target.value);
                        setPage(1);
                    }}
                    options={[
                        { value: '', label: 'All agents' },
                        ...agents.map((agent) => ({ value: String(agent.id), label: agent.name || agent.email })),
                    ]}
                />
                <label className="flex min-w-[14rem] flex-col gap-1">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Payment reference</span>
                    <input
                        value={externalReference}
                        onChange={(event) => setExternalReference(event.target.value)}
                        className="crm-input"
                        placeholder="Optional receipt or note"
                    />
                </label>
                <button
                    type="button"
                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    disabled={!canMarkPaid || markPaidMutation.isPending}
                    onClick={() => markPaidMutation.mutate()}
                    title={selectedRows.length > 0 && !canMarkPaid ? 'Select earned commissions for one agent and one currency.' : undefined}
                >
                    {markPaidMutation.isPending ? 'Recording...' : `Mark paid (${money(selectedTotal, selectedCurrency)})`}
                </button>
            </section>

            <DataTable
                columns={columns}
                data={rows}
                isLoading={query.isLoading}
                emptyMessage="No commissions match the current filters."
                pagination={meta}
                onPageChange={setPage}
                selectable
                onSelectionChange={setSelectedRows}
                clearSelectionKey={clearSelectionKey}
            />
        </div>
    );
}
