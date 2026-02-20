import React, { useMemo, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import Timeline from '../components/Timeline';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';

function formatCurrency(value, currency = 'KES') {
    return `${currency} ${Number(value || 0).toLocaleString()}`;
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function ProfileInfoCard({ title, children }) {
    return (
        <section className="crm-surface p-5">
            <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{title}</h3>
            <div className="mt-3">{children}</div>
        </section>
    );
}

function DefinitionRow({ label, value, mono = false }) {
    return (
        <div className="flex items-start justify-between gap-3 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className={`text-right font-medium text-slate-900 ${mono ? 'crm-mono text-xs' : ''}`}>{value}</dd>
        </div>
    );
}

export default function ClientDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [activeTab, setActiveTab] = useState('overview');
    const [noteForm, setNoteForm] = useState({ note_type: 'internal', content: '', follow_up_at: '' });
    const [showDealModal, setShowDealModal] = useState(false);
    const [showSyncConfirm, setShowSyncConfirm] = useState(false);

    const { data: client, isLoading } = useQuery({
        queryKey: ['client', id],
        queryFn: () => api.get(`/crm/clients/${id}`).then((r) => r.data),
    });

    const { data: timelineData } = useQuery({
        queryKey: ['client-timeline', id],
        queryFn: () => api.get(`/crm/clients/${id}/timeline`).then((r) => r.data),
        enabled: activeTab === 'timeline',
    });

    const { data: products } = useQuery({
        queryKey: ['products'],
        queryFn: () => api.get('/crm/products').then((r) => r.data),
    });

    const addNoteMutation = useMutation({
        mutationFn: (note) =>
            api.post(`/crm/clients/${id}/notes`, {
                ...note,
                follow_up_at: note.follow_up_at || null,
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setNoteForm({ note_type: 'internal', content: '', follow_up_at: '' });
            toast.success('Note added.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to add note.');
        },
    });

    const createDealMutation = useMutation({
        mutationFn: (deal) =>
            api.post('/crm/deals', {
                ...deal,
                product_id: Number(deal.product_id),
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setShowDealModal(false);
            toast.success('Deal created for client.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Deal creation failed.');
        },
    });

    const activateDealMutation = useMutation({
        mutationFn: (dealId) => api.post(`/crm/deals/${dealId}/activate`, { reason: 'Activated from client profile' }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            toast.success('Deal activated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Deal activation failed.');
        },
    });

    const syncMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${id}/sync`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            toast.success('Client profile synced from WordPress.');
            setShowSyncConfirm(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'WordPress sync failed.');
            setShowSyncConfirm(false);
        },
    });

    const tabs = useMemo(() => [
        { key: 'overview', label: 'Overview' },
        { key: 'deals', label: `Deals (${client?.deals?.length || 0})` },
        { key: 'notes', label: `Notes (${client?.notes?.length || 0})` },
        { key: 'timeline', label: 'Timeline' },
        { key: 'payments', label: `Payments (${client?.payments?.length || 0})` },
    ], [client]);

    if (isLoading) {
        return (
            <div className="flex h-64 items-center justify-center">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
            </div>
        );
    }

    if (!client) {
        return <p className="py-12 text-center text-sm text-slate-500">Client not found.</p>;
    }

    const isExpired = client.escort_expire ? new Date(client.escort_expire * 1000) < new Date() : false;

    const canSyncFromWp = Number(client.wp_post_id || 0) > 0;

    return (
        <div className="space-y-4">
            <button
                onClick={() => navigate('/clients')}
                className="inline-flex items-center gap-1 text-sm font-medium text-teal-700 transition hover:text-teal-800"
            >
                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
                Back to Clients
            </button>

            <section className="crm-surface px-5 py-5">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-4">
                        {client.main_image_url ? (
                            <img src={client.main_image_url} alt="" className="h-16 w-16 rounded-full object-cover ring-1 ring-slate-200" />
                        ) : (
                            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-xl font-semibold text-slate-600 ring-1 ring-slate-200">
                                {client.name?.charAt(0) || '?'}
                            </div>
                        )}

                        <div>
                            <h2 className="crm-page-title">{client.name || 'Unnamed'}</h2>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <StatusBadge status={client.profile_status} />
                                {client.premium ? <span className="inline-flex items-center rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-200">Premium</span> : null}
                                {client.featured ? <span className="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">Featured</span> : null}
                                {client.verified ? <span className="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">Verified</span> : null}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            onClick={() => setShowSyncConfirm(true)}
                            disabled={!canSyncFromWp || syncMutation.isPending}
                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                            title={!canSyncFromWp ? 'Sync unavailable for manual CRM-only records' : undefined}
                        >
                            {syncMutation.isPending ? 'Syncing...' : 'Sync latest from WP'}
                        </button>
                        <button
                            onClick={() => setShowDealModal(true)}
                            className="crm-btn-primary"
                        >
                            New deal
                        </button>
                    </div>
                </div>
            </section>

            <section className="grid gap-4 lg:grid-cols-3">
                <ProfileInfoCard title="Contact Info">
                    <dl className="space-y-2.5">
                        <DefinitionRow label="Phone" value={client.phone_normalized || '—'} mono />
                        <DefinitionRow label="Email" value={client.email || '—'} />
                        <DefinitionRow label="City" value={client.city || '—'} />
                        <DefinitionRow label="Market" value={client.platform?.name || '—'} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Subscription">
                    <dl className="space-y-2.5">
                        <DefinitionRow
                            label="Active Deal"
                            value={client.active_deal ? (client.active_deal.product?.name || client.active_deal.plan_type) : 'None'}
                        />
                        <DefinitionRow
                            label="Expires"
                            value={client.escort_expire ? (
                                <span className={isExpired ? 'text-rose-700' : 'text-slate-900'}>{new Date(client.escort_expire * 1000).toLocaleDateString()}</span>
                            ) : '—'}
                        />
                        <DefinitionRow label="WP Post ID" value={client.wp_post_id || '—'} mono />
                        <DefinitionRow label="WP User ID" value={client.wp_user_id || '—'} mono />
                        <DefinitionRow
                            label="Profile URL"
                            value={client.wp_profile_url ? (
                                <a href={client.wp_profile_url} target="_blank" rel="noreferrer" className="text-teal-700 underline decoration-teal-200 underline-offset-2">
                                    Open profile
                                </a>
                            ) : 'Not available'}
                        />
                        <DefinitionRow label="Last Synced" value={formatDateTime(client.last_synced_at)} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Summary">
                    <dl className="space-y-2.5">
                        <DefinitionRow label="Total Deals" value={client.deals?.length || 0} />
                        <DefinitionRow label="Total Payments" value={client.payments?.length || 0} />
                        <DefinitionRow label="Notes" value={client.notes?.length || 0} />
                        <DefinitionRow label="Agent" value={client.assigned_agent?.name || 'Unassigned'} />
                    </dl>
                </ProfileInfoCard>
            </section>

            <section className="crm-surface p-2">
                <nav className="flex flex-wrap gap-1">
                    {tabs.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key)}
                            className={`rounded-md px-3 py-2 text-sm font-medium transition ${activeTab === tab.key ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </section>

            {activeTab === 'overview' ? (
                <section className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Recent Activity</h3>
                            <p className="crm-panel-subtitle">Most recent deals for this client. New deals remain pending until activated.</p>
                        </div>
                    </header>
                    <div className="p-4">
                        {client.deals?.length > 0 ? (
                            <div className="space-y-2">
                                {client.deals.slice(0, 5).map((deal) => (
                                    <div key={deal.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2.5">
                                        <div>
                                    <p className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type} - {deal.duration}</p>
                                            <p className="text-xs text-slate-500">{formatCurrency(deal.amount, deal.currency || 'KES')} • Activation enables subscription access.</p>
                                        </div>
                                        <StatusBadge status={deal.status} />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-slate-500">No deals yet. Create one to get started.</p>
                        )}
                    </div>
                </section>
            ) : null}

            {activeTab === 'deals' ? (
                <div className="space-y-3">
                    {client.deals?.length > 0 ? client.deals.map((deal) => (
                        <section key={deal.id} className="crm-surface p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type}</h4>
                                        <StatusBadge status={deal.status} />
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {formatCurrency(deal.amount, deal.currency || 'KES')} - {deal.duration}
                                        {deal.expires_at ? ` - Expires ${new Date(deal.expires_at).toLocaleDateString()}` : ''}
                                    </p>
                                </div>

                                {deal.status === 'pending' ? (
                                    <button
                                        onClick={() => activateDealMutation.mutate(deal.id)}
                                        disabled={activateDealMutation.isPending}
                                        className="crm-btn-primary px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {activateDealMutation.isPending ? 'Activating...' : 'Activate'}
                                    </button>
                                ) : null}
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No deals yet.</section>
                    )}
                </div>
            ) : null}

            {activeTab === 'notes' ? (
                <div className="space-y-3">
                    <section className="crm-surface p-4">
                        <h3 className="crm-panel-title">Add Note</h3>
                        <div className="mt-3 space-y-3">
                            <div className="flex flex-wrap gap-2">
                                <select
                                    value={noteForm.note_type}
                                    onChange={(e) => setNoteForm({ ...noteForm, note_type: e.target.value })}
                                    className="crm-select"
                                >
                                    <option value="internal">Internal</option>
                                    <option value="call">Call</option>
                                    <option value="sms">SMS</option>
                                    <option value="email">Email</option>
                                </select>
                                <input
                                    type="datetime-local"
                                    value={noteForm.follow_up_at}
                                    onChange={(e) => setNoteForm({ ...noteForm, follow_up_at: e.target.value })}
                                    className="crm-input max-w-[260px]"
                                    placeholder="Follow-up date"
                                />
                            </div>

                            <textarea
                                value={noteForm.content}
                                onChange={(e) => setNoteForm({ ...noteForm, content: e.target.value })}
                                placeholder="Write a note..."
                                rows={3}
                                className="crm-input"
                            />

                            <div className="flex items-center gap-2">
                                <button
                                    onClick={() => addNoteMutation.mutate(noteForm)}
                                    disabled={!noteForm.content.trim() || addNoteMutation.isPending}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {addNoteMutation.isPending ? 'Saving...' : 'Add note'}
                                </button>
                            </div>
                        </div>
                    </section>

                    {client.notes?.length > 0 ? client.notes.map((note) => (
                        <section key={note.id} className="crm-surface p-4">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium capitalize text-slate-600 ring-1 ring-inset ring-slate-200">
                                        {note.note_type}
                                    </span>
                                    <span className="text-xs text-slate-500">by {note.author?.name || 'Unknown'}</span>
                                </div>
                                <span className="text-xs text-slate-400">{formatDateTime(note.created_at)}</span>
                            </div>
                            <p className="whitespace-pre-wrap text-sm text-slate-700">{note.content}</p>
                            {note.follow_up_at ? <p className="mt-2 text-xs text-teal-700">Follow-up: {formatDateTime(note.follow_up_at)}</p> : null}
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No notes yet.</section>
                    )}
                </div>
            ) : null}

            {activeTab === 'timeline' ? (
                <section className="crm-surface p-5">
                    <Timeline events={timelineData?.data} isLoading={!timelineData} />
                </section>
            ) : null}

            {activeTab === 'payments' ? (
                <div className="space-y-3">
                    {client.payments?.length > 0 ? client.payments.map((payment) => (
                        <section key={payment.id} className="crm-surface flex items-start justify-between gap-3 p-4">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">
                                    {formatCurrency(payment.amount, payment.currency || 'KES')}
                                    {payment.product ? <span className="font-normal text-slate-500"> - {payment.product.name}</span> : null}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {payment.phone || 'No phone'}
                                    {payment.transaction_reference ? ` | Ref: ${payment.transaction_reference}` : ''}
                                </p>
                            </div>
                            <div className="text-right">
                                <StatusBadge status={payment.status} />
                                <p className="mt-1 text-xs text-slate-400">{formatDateTime(payment.created_at)}</p>
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No payments recorded.</section>
                    )}
                </div>
            ) : null}

            {showDealModal ? (
                <DealModal
                    client={client}
                    products={products}
                    onClose={() => setShowDealModal(false)}
                    onSubmit={(deal) => createDealMutation.mutate(deal)}
                    isPending={createDealMutation.isPending}
                    error={createDealMutation.error}
                />
            ) : null}

            <ConfirmDialog
                open={showSyncConfirm}
                title="Sync Client from WordPress"
                message="This refreshes client profile fields from WordPress and may overwrite CRM-side contact data for synced fields."
                confirmLabel="Sync now"
                onCancel={() => setShowSyncConfirm(false)}
                onConfirm={() => syncMutation.mutate()}
                confirmDisabled={syncMutation.isPending}
                isPending={syncMutation.isPending}
            />
        </div>
    );
}

function DealModal({ client, products, onClose, onSubmit, isPending, error }) {
    const [form, setForm] = useState({
        client_id: client.id,
        product_id: '',
        plan_type: 'basic',
        duration: 'monthly',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(form);
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Create New Deal</h3>
                        <p className="crm-panel-subtitle">{client.name}</p>
                    </div>
                </header>

                <form onSubmit={handleSubmit} className="space-y-4 p-4">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Product</label>
                        <select
                            value={form.product_id}
                            onChange={(e) => setForm({ ...form, product_id: e.target.value })}
                            required
                            className="crm-select w-full"
                        >
                            <option value="">Select a product...</option>
                            {products?.map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.name} - {formatCurrency(product.monthly_price, 'KES')}/mo
                                </option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Plan Type</label>
                        <select
                            value={form.plan_type}
                            onChange={(e) => setForm({ ...form, plan_type: e.target.value })}
                            className="crm-select w-full"
                        >
                            <option value="basic">Basic</option>
                            <option value="premium">Premium</option>
                            <option value="vip">VIP</option>
                        </select>
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Duration</label>
                        <select
                            value={form.duration}
                            onChange={(e) => setForm({ ...form, duration: e.target.value })}
                            className="crm-select w-full"
                        >
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>

                    {error ? <p className="text-sm text-rose-700">Failed to create deal. {error.response?.data?.message || 'Please try again.'}</p> : null}

                    <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                        <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                        <button type="submit" disabled={!form.product_id || isPending} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                            {isPending ? 'Creating...' : 'Create deal'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
