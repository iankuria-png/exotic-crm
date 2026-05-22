import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import SlaPill, { BUCKET_BORDER } from './SlaPill';
import CloseCaseDialog from '../CloseCaseDialog';
import ConfirmDialog from '../ConfirmDialog';

const PAYMENT_CLOSE_REASONS = [
    { code: 'customer_converted', label: 'Customer Converted' },
    { code: 'payment_failed', label: 'Payment Failed' },
    { code: 'customer_testing', label: 'Customer Was Testing' },
    { code: 'systems_down', label: 'Systems Were Down' },
    { code: 'duplicate_attempt', label: 'Duplicate Attempt' },
    { code: 'customer_abandoned', label: 'Customer Abandoned' },
    { code: 'other', label: 'Other' },
];

function inferPaymentCloseReason(row) {
    const text = String(row?.failure_reason || '').toLowerCase();
    if (/(converted|completed|paid|success)/.test(text)) return 'customer_converted';
    if (/(test|sandbox|demo)/.test(text)) return 'customer_testing';
    if (/(system|down|outage|timeout|timed out|network|provider|service unavailable)/.test(text)) return 'systems_down';
    if (/(duplicate|already|repeat)/.test(text)) return 'duplicate_attempt';
    if (/(cancel|abandon|no response|declined|not interested)/.test(text)) return 'customer_abandoned';
    return 'payment_failed';
}

function SectionHeader({ title, count, refreshedAgo, onToggle, collapsed, emptyHint }) {
    return (
        <header className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    onClick={onToggle}
                    className="flex items-center gap-2 text-left"
                    aria-expanded={!collapsed}
                >
                    <svg
                        className={`h-3 w-3 text-slate-400 transition-transform ${collapsed ? '-rotate-90' : ''}`}
                        fill="currentColor"
                        viewBox="0 0 20 20"
                    >
                        <path d="M5.5 7l4.5 5 4.5-5" stroke="currentColor" strokeWidth="2" fill="none" />
                    </svg>
                    <h3 className="text-sm font-semibold text-slate-800">{title}</h3>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                        {count}
                    </span>
                </button>
            </div>
            <span className="text-[11px] text-slate-400">
                {refreshedAgo != null ? `Updated ${refreshedAgo}s ago` : ''}
                {count === 0 && emptyHint ? <span className="ml-2 italic">{emptyHint}</span> : null}
            </span>
        </header>
    );
}

function ActionChip({ onClick, variant = 'secondary', disabled = false, children }) {
    const base = 'inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50';
    const tone = variant === 'primary'
        ? 'bg-teal-700 text-white hover:bg-teal-800 focus-visible:ring-teal-600'
        : variant === 'danger'
            ? 'border border-rose-200 bg-white text-rose-700 hover:bg-rose-50 focus-visible:ring-rose-300'
            : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-400';
    return (
        <button type="button" onClick={onClick} disabled={disabled} className={`${base} ${tone}`}>
            {children}
        </button>
    );
}

function NewSignupCard({ row, onMarkContacted, onCloseCase, onOpenClient, onOpenChat }) {
    return (
        <article className={`flex flex-col gap-2 border-l-4 px-4 py-3 transition hover:bg-slate-50 ${BUCKET_BORDER(row.sla_bucket)}`}>
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <SlaPill bucket={row.sla_bucket} ageSeconds={row.age_seconds} />
                    <button type="button" onClick={onOpenClient} className="truncate text-left text-sm font-semibold text-slate-800 hover:text-teal-700">
                        {row.name || '—'}
                    </button>
                    <span className="truncate text-xs text-slate-500 crm-mono">{row.phone || ''}</span>
                    {row.city ? <span className="truncate text-xs text-slate-400">• {row.city}</span> : null}
                    {row.platform?.name ? (
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                            {row.platform.name}
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <ActionChip variant="primary" onClick={onMarkContacted}>Mark contacted</ActionChip>
                {row.sb_user_id ? <ActionChip onClick={onOpenChat}>Open chat</ActionChip> : null}
                <ActionChip onClick={onOpenClient}>Open client</ActionChip>
                <ActionChip variant="danger" onClick={onCloseCase}>Close case</ActionChip>
            </div>
        </article>
    );
}

function FailedPaymentCard({ row, onRetryStk, onSendLink, onOpenClient, onClosePayment }) {
    const amount = typeof row.amount === 'number' ? row.amount.toLocaleString() : row.amount;
    return (
        <article className={`flex flex-col gap-2 border-l-4 px-4 py-3 transition hover:bg-slate-50 ${BUCKET_BORDER(row.sla_bucket)}`}>
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <SlaPill bucket={row.sla_bucket} ageSeconds={row.age_seconds} />
                    <span className="truncate text-xs text-slate-500 crm-mono">{row.phone || row.client?.phone || '—'}</span>
                    <span className="text-sm font-semibold text-slate-800">{row.currency || 'KES'} {amount}</span>
                    {row.product ? (
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                            {row.product}
                        </span>
                    ) : null}
                    {row.client?.name ? (
                        <button type="button" onClick={onOpenClient} className="truncate text-xs text-teal-700 hover:underline">
                            {row.client.name}
                        </button>
                    ) : null}
                </div>
            </div>
            {row.failure_reason ? (
                <p className="text-[11px] italic text-slate-500">{row.failure_reason}</p>
            ) : null}
            <div className="flex flex-wrap items-center gap-2">
                <ActionChip variant="primary" onClick={onRetryStk}>Retry STK</ActionChip>
                <ActionChip onClick={onSendLink}>Send link</ActionChip>
                {row.client?.id ? <ActionChip onClick={onOpenClient}>Open client</ActionChip> : null}
                <ActionChip variant="danger" onClick={onClosePayment}>Close payment</ActionChip>
            </div>
        </article>
    );
}

function StalledCard({ row, onMarkContacted, onCloseCase, onOpenClient }) {
    return (
        <article className={`flex flex-col gap-2 border-l-4 px-4 py-3 transition hover:bg-slate-50 ${BUCKET_BORDER(row.sla_bucket)}`}>
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <SlaPill bucket={row.sla_bucket} ageSeconds={row.age_seconds} />
                    <button type="button" onClick={onOpenClient} className="truncate text-sm font-semibold text-slate-800 hover:text-teal-700">
                        {row.name || '—'}
                    </button>
                    <span className="truncate text-xs text-slate-500 crm-mono">{row.phone || ''}</span>
                    {row.assigned_agent?.name ? (
                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-600">
                            {row.assigned_agent.name}
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <ActionChip variant="primary" onClick={onMarkContacted}>Mark contacted</ActionChip>
                <ActionChip onClick={onOpenClient}>Open client</ActionChip>
                <ActionChip variant="danger" onClick={onCloseCase}>Close case</ActionChip>
            </div>
        </article>
    );
}

export default function ConversionQueueView({ platformId = '' }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [collapsed, setCollapsed] = useState({ new_signups: false, failed_payments: false, stalled_contacted: false });
    const [closeDialog, setCloseDialog] = useState({ open: false, client: null });
    const [closeError, setCloseError] = useState(null);
    const [closePaymentDialog, setClosePaymentDialog] = useState({ open: false, payment: null, reason_code: 'payment_failed', reason_note: '', converted_payment_id: '' });

    const queueQuery = useQuery({
        queryKey: ['clients-conversion-queue', platformId || 'all'],
        queryFn: () => api.get('/crm/clients/conversion-queue', {
            params: platformId ? { platform_id: Number(platformId) } : {},
        }).then((r) => r.data),
        refetchInterval: 60_000,
        staleTime: 30_000,
    });

    const refreshedAgo = queueQuery.dataUpdatedAt ? Math.max(0, Math.round((Date.now() - queueQuery.dataUpdatedAt) / 1000)) : null;

    const markContactedMutation = useMutation({
        mutationFn: ({ clientId, channel }) => api.post(`/crm/clients/${clientId}/contacted`, { channel }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('Marked as contacted.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Failed to mark contacted.'),
    });

    const retryStkMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/retry-stk`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('STK retry sent.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Retry failed.'),
    });

    const sendLinkMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/send-payment-link`, { channel: 'sms' }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('Payment link sent.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Send link failed.'),
    });

    const closePaymentMutation = useMutation({
        mutationFn: ({ paymentId, reason_code, reason_note, converted_payment_id }) =>
            api.post(`/crm/payments/${paymentId}/manual-close`, {
                reason_code,
                reason_note,
                ...(converted_payment_id ? { converted_payment_id: Number(converted_payment_id) } : {}),
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('Payment closed.');
            setClosePaymentDialog({ open: false, payment: null, reason_code: 'payment_failed', reason_note: '', converted_payment_id: '' });
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Close failed.'),
    });

    const closeCaseMutation = useMutation({
        mutationFn: ({ clientId, payload }) =>
            api.post(`/crm/clients/${clientId}/close-case`, payload).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast?.success?.(`Case closed. ${data.cascaded_payments_count || 0} payment${data.cascaded_payments_count === 1 ? '' : 's'} resolved.`);
            setCloseDialog({ open: false, client: null });
            setCloseError(null);
        },
        onError: (err) => {
            const data = err?.response?.data;
            setCloseError(data?.message || 'Close failed.');
        },
    });

    const buckets = queueQuery.data || { new_signups: [], failed_payments: [], stalled_contacted: [], counts: {} };

    const togglerFor = (key) => () => setCollapsed((c) => ({ ...c, [key]: !c[key] }));

    return (
        <section className="space-y-4">
            <div className="grid gap-3 md:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">New signups (24h)</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{buckets.counts?.new_signups ?? 0}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Failed payments (14d)</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{buckets.counts?.failed_payments ?? 0}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Stalled contacted ({'>'}72h)</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{buckets.counts?.stalled_contacted ?? 0}</p>
                </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="New signups"
                    count={buckets.new_signups?.length ?? 0}
                    refreshedAgo={refreshedAgo}
                    collapsed={collapsed.new_signups}
                    onToggle={togglerFor('new_signups')}
                    emptyHint={buckets.new_signups?.length === 0 ? 'Inbox zero — no new signups in the last 24 hours.' : null}
                />
                {!collapsed.new_signups ? (
                    <div className="divide-y divide-slate-100">
                        {(buckets.new_signups || []).map((row) => (
                            <NewSignupCard
                                key={`signup-${row.id}`}
                                row={row}
                                onMarkContacted={() => markContactedMutation.mutate({ clientId: row.id, channel: 'whatsapp' })}
                                onOpenClient={() => navigate(`/clients/${row.id}`)}
                                onOpenChat={() => navigate(`/conversations?client_id=${row.id}`)}
                                onCloseCase={() => { setCloseError(null); setCloseDialog({ open: true, client: row }); }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="Failed payments"
                    count={buckets.failed_payments?.length ?? 0}
                    refreshedAgo={refreshedAgo}
                    collapsed={collapsed.failed_payments}
                    onToggle={togglerFor('failed_payments')}
                    emptyHint={buckets.failed_payments?.length === 0 ? 'No open failed payments in the last 14 days.' : null}
                />
                {!collapsed.failed_payments ? (
                    <div className="divide-y divide-slate-100">
                        {(buckets.failed_payments || []).map((row) => (
                            <FailedPaymentCard
                                key={`payment-${row.id}`}
                                row={row}
                                onRetryStk={() => retryStkMutation.mutate(row.id)}
                                onSendLink={() => sendLinkMutation.mutate(row.id)}
                                onOpenClient={() => row.client?.id && navigate(`/clients/${row.client.id}`)}
                                onClosePayment={() => setClosePaymentDialog({
                                    open: true,
                                    payment: row,
                                    reason_code: inferPaymentCloseReason(row),
                                    reason_note: row.failure_reason || '',
                                    converted_payment_id: '',
                                })}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="Stalled contacted"
                    count={buckets.stalled_contacted?.length ?? 0}
                    refreshedAgo={refreshedAgo}
                    collapsed={collapsed.stalled_contacted}
                    onToggle={togglerFor('stalled_contacted')}
                    emptyHint={buckets.stalled_contacted?.length === 0 ? 'Nothing to chase — all contacted clients have either paid or been closed.' : null}
                />
                {!collapsed.stalled_contacted ? (
                    <div className="divide-y divide-slate-100">
                        {(buckets.stalled_contacted || []).map((row) => (
                            <StalledCard
                                key={`stalled-${row.id}`}
                                row={row}
                                onMarkContacted={() => markContactedMutation.mutate({ clientId: row.id, channel: 'whatsapp' })}
                                onOpenClient={() => navigate(`/clients/${row.id}`)}
                                onCloseCase={() => { setCloseError(null); setCloseDialog({ open: true, client: row }); }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>

            <CloseCaseDialog
                open={closeDialog.open}
                clientName={closeDialog.client?.name || ''}
                isPending={closeCaseMutation.isPending}
                error={closeError}
                onCancel={() => { if (!closeCaseMutation.isPending) { setCloseDialog({ open: false, client: null }); setCloseError(null); } }}
                onConfirm={(payload) => closeCaseMutation.mutate({ clientId: closeDialog.client.id, payload })}
            />

            <ConfirmDialog
                open={closePaymentDialog.open && !!closePaymentDialog.payment}
                title="Close failed payment"
                message={closePaymentDialog.payment ? `Mark payment #${closePaymentDialog.payment.id} as resolved.` : ''}
                confirmLabel="Close payment"
                isPending={closePaymentMutation.isPending}
                confirmDisabled={
                    closePaymentMutation.isPending
                    || !closePaymentDialog.reason_code
                    || (closePaymentDialog.reason_code === 'other' && !closePaymentDialog.reason_note.trim())
                }
                onCancel={() => {
                    if (!closePaymentMutation.isPending) {
                        setClosePaymentDialog({ open: false, payment: null, reason_code: 'payment_failed', reason_note: '', converted_payment_id: '' });
                    }
                }}
                onConfirm={() => closePaymentMutation.mutate({
                    paymentId: closePaymentDialog.payment.id,
                    reason_code: closePaymentDialog.reason_code,
                    reason_note: closePaymentDialog.reason_note.trim() || null,
                    converted_payment_id: closePaymentDialog.converted_payment_id,
                })}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="queue-close-payment-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <select
                            id="queue-close-payment-reason"
                            value={closePaymentDialog.reason_code}
                            onChange={(event) => setClosePaymentDialog((current) => ({ ...current, reason_code: event.target.value }))}
                            className="crm-select"
                        >
                            {PAYMENT_CLOSE_REASONS.map((reason) => (
                                <option key={reason.code} value={reason.code}>{reason.label}</option>
                            ))}
                        </select>
                    </div>
                    {closePaymentDialog.reason_code === 'customer_converted' ? (
                        <div>
                            <label htmlFor="queue-close-payment-converted-id" className="mb-1 block text-sm font-medium text-slate-700">
                                Converted payment ID <span className="font-normal text-slate-400">(optional)</span>
                            </label>
                            <input
                                id="queue-close-payment-converted-id"
                                type="number"
                                min="1"
                                value={closePaymentDialog.converted_payment_id}
                                onChange={(event) => setClosePaymentDialog((current) => ({ ...current, converted_payment_id: event.target.value }))}
                                className="crm-input"
                                placeholder="Auto-link if blank"
                            />
                        </div>
                    ) : null}
                    <div>
                        <label htmlFor="queue-close-payment-note" className="mb-1 block text-sm font-medium text-slate-700">
                            Note {closePaymentDialog.reason_code === 'other' ? <span className="text-rose-600">*</span> : <span className="font-normal text-slate-400">(optional)</span>}
                        </label>
                        <textarea
                            id="queue-close-payment-note"
                            rows={3}
                            value={closePaymentDialog.reason_note}
                            onChange={(event) => setClosePaymentDialog((current) => ({ ...current, reason_note: event.target.value }))}
                            className="crm-input"
                            maxLength={1000}
                        />
                    </div>
                </div>
            </ConfirmDialog>
        </section>
    );
}
