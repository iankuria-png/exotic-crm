import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import MetricCard from '../../components/MetricCard';
import ClientCreateModal from '../../components/clients/ClientCreateModal';
import { useToast } from '../../components/ToastProvider';
import api from '../../services/api';

function currency(value, code = 'KES') {
    const amount = Number(value || 0);
    return new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: code,
        maximumFractionDigits: 2,
    }).format(amount);
}

function clientName(client) {
    return client?.name || client?.display_name || client?.email || client?.phone_normalized || 'Client';
}

export default function FieldHome() {
    const toast = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [activeClient, setActiveClient] = useState(null);

    const homeQuery = useQuery({
        queryKey: ['field-home'],
        queryFn: () => api.get('/crm/field/home').then((response) => response.data),
        refetchInterval: 45_000,
    });

    const markets = homeQuery.data?.markets || [];
    const summary = homeQuery.data?.summary || {};
    const recentClients = homeQuery.data?.recent_clients || [];
    const preferredMarketId = useMemo(() => {
        if (markets.length === 1) {
            return String(markets[0].id);
        }

        return markets[0]?.id ? String(markets[0].id) : '';
    }, [markets]);

    const depositQuery = useQuery({
        queryKey: ['field-client-deposit-status', activeClient?.id],
        queryFn: () => api.get(`/crm/field/clients/${activeClient.id}/deposit-status`).then((response) => response.data),
        enabled: Boolean(activeClient?.id),
        refetchInterval: (query) => query.state.data?.deposit?.received ? false : 15_000,
    });

    const loginMutation = useMutation({
        mutationFn: ({ client }) => api.post(`/crm/clients/${client.id}/login-as-client`, {
            target: 'home',
            source: 'field_sales.deposit_flow',
            reason: 'Field sales deposit handoff',
        }).then((response) => response.data),
        onSuccess: (result, variables) => {
            if (result?.url) {
                const popup = variables?.popup;
                if (popup && !popup.closed) {
                    popup.location.href = result.url;
                    popup.focus();
                } else {
                    window.open(result.url, '_blank', 'noopener,noreferrer');
                }
                toast.success('Client session opened.');
            }
        },
        onError: (error, variables) => {
            if (variables?.popup && !variables.popup.closed) {
                variables.popup.close();
            }
            toast.error(error?.response?.data?.message || 'Could not open the client session.');
        },
    });

    const activateTrialMutation = useMutation({
        mutationFn: (client) => api.post(`/crm/field/clients/${client.id}/activate-trial`, {
            reason: 'Field sales free trial activation after deposit',
        }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['field-home'] });
            queryClient.invalidateQueries({ queryKey: ['field-client-deposit-status', activeClient?.id] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success('Free trial activated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Free trial activation failed.');
        },
    });

    const deposit = depositQuery.data?.deposit || depositQuery.data || {};
    const wallet = depositQuery.data?.wallet || {};

    return (
        <div className="space-y-4">
            <PageHeader
                title="Field Sales"
                subtitle="Create, fund, and activate client accounts from one fast workspace."
                actions={(
                    <button type="button" className="crm-btn-primary" onClick={() => setCreateOpen(true)}>
                        Add field client
                    </button>
                )}
            />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard
                    label="Clients Created"
                    value={Number(summary.clients_created || 0).toLocaleString()}
                    meta={summary.clients_field_tagged != null
                        ? `${Number(summary.clients_field_tagged).toLocaleString()} field-tagged`
                        : 'all accounts you created'}
                    tone="accent"
                />
                <MetricCard label="Trials Activated" value={Number(summary.trials_activated || summary.trials_active || 0).toLocaleString()} meta="after deposit" tone="success" />
                <MetricCard label="Paid Conversions" value={Number(summary.paid_conversions || 0).toLocaleString()} meta="field-attributed" tone="default" />
                <MetricCard label="Commission Earned" value={currency(summary.commission_earned || summary.commission_accrued || 0, summary.currency || 'KES')} meta="all earned status" tone="warning" />
            </section>

            {Number(summary.clients_untagged || 0) > 0 ? (
                <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
                    {Number(summary.clients_untagged).toLocaleString()} of your client(s) lost their field tag during a WordPress re-sync.
                    They are still listed below. An admin can re-tag them by running{' '}
                    <code className="rounded bg-amber-100 px-1.5 py-0.5">php artisan crm:backfill-field-signup-source --apply</code>.
                </div>
            ) : null}

            <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]">
                <div className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Recent Field Clients</h3>
                            <p className="crm-panel-subtitle">Accounts you created from this workspace.</p>
                        </div>
                        <Link to="/clients?signup_source=field" className="crm-btn-secondary px-3 py-2 text-sm">
                            View all
                        </Link>
                    </header>

                    {homeQuery.isLoading ? (
                        <p className="p-4 text-sm text-slate-500">Loading field activity...</p>
                    ) : (recentClients.length === 0 && (homeQuery.data?.clients || []).length === 0) ? (
                        <p className="p-4 text-sm text-slate-500">No field clients yet.</p>
                    ) : (
                        <div className="divide-y divide-slate-100">
                            {(recentClients.length ? recentClients : (homeQuery.data?.clients || [])).map((client) => (
                                <button
                                    key={client.id}
                                    type="button"
                                    onClick={() => setActiveClient(client)}
                                    className={`flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-slate-50 ${Number(activeClient?.id) === Number(client.id) ? 'bg-teal-50/70' : ''}`}
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-slate-900">{clientName(client)}</p>
                                        <p className="truncate text-xs text-slate-500">{client.phone_normalized || client.email || 'No contact saved'}</p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <span className="inline-flex rounded-md bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-200">
                                            Field
                                        </span>
                                        <p className="mt-1 text-[11px] text-slate-400">{client.platform?.name || 'Market'}</p>
                                    </div>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                <aside className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Activation</h3>
                            <p className="crm-panel-subtitle">Deposit handoff and trial activation.</p>
                        </div>
                    </header>

                    {!activeClient ? (
                        <div className="p-4 text-sm text-slate-500">
                            Create or select a field client to continue.
                        </div>
                    ) : (
                        <div className="space-y-4 p-4">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">{clientName(activeClient)}</p>
                                <p className="text-xs text-slate-500">{activeClient.phone_normalized || activeClient.email || 'No contact saved'}</p>
                            </div>

                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Deposit</span>
                                    <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${deposit.received ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'}`}>
                                        {deposit.received ? 'Received' : 'Waiting'}
                                    </span>
                                </div>
                                <p className="mt-2 text-sm text-slate-700">
                                    Balance: <span className="font-semibold text-slate-900">{currency(wallet.balance || 0, wallet.currency || 'KES')}</span>
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Required: {currency(deposit.threshold || 0, wallet.currency || 'KES')}
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <button
                                    type="button"
                                    className="crm-btn-primary w-full justify-center"
                                    disabled={loginMutation.isPending}
                                    onClick={() => {
                                        const popup = window.open('', '_blank');
                                        if (popup && !popup.closed) {
                                            popup.document.write('<p style="font-family: sans-serif; padding: 16px;">Opening client session...</p>');
                                        }
                                        loginMutation.mutate({ client: activeClient, popup });
                                    }}
                                >
                                    {loginMutation.isPending ? 'Opening...' : 'Log in as client'}
                                </button>
                                <button
                                    type="button"
                                    className="crm-btn-secondary w-full justify-center"
                                    onClick={() => depositQuery.refetch()}
                                    disabled={depositQuery.isFetching}
                                >
                                    {depositQuery.isFetching ? 'Checking...' : 'Check deposit'}
                                </button>
                                <button
                                    type="button"
                                    className="crm-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-50"
                                    disabled={!deposit.received || activateTrialMutation.isPending}
                                    onClick={() => activateTrialMutation.mutate(activeClient)}
                                >
                                    {activateTrialMutation.isPending ? 'Activating...' : 'Activate free trial'}
                                </button>
                                <button
                                    type="button"
                                    className="crm-btn-secondary w-full justify-center"
                                    onClick={() => navigate(`/clients/${activeClient.id}`)}
                                >
                                    Open CRM profile
                                </button>
                            </div>
                        </div>
                    )}
                </aside>
            </section>

            <ClientCreateModal
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                initialPlatformId={preferredMarketId}
                lockedOnboardingMode="wp_provision"
                signupSource="field"
                title="Add Field Client"
                subtitle="Create the account quickly, then move straight to deposit and trial activation."
                submitLabel="Create field client"
                reason="Field sales account creation"
                onCreated={(client) => {
                    setActiveClient(client);
                    setCreateOpen(false);
                }}
            />
        </div>
    );
}
