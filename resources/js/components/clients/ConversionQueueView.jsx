import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import SlaPill, { BUCKET_BORDER } from './SlaPill';
import CloseCaseDialog from '../CloseCaseDialog';
import ConfirmDialog from '../ConfirmDialog';
import QueueRangeSelector from './QueueRangeSelector';

const PAYMENT_CLOSE_REASONS = [
    { code: 'customer_converted', label: 'Customer Converted' },
    { code: 'payment_failed', label: 'Payment Failed' },
    { code: 'customer_testing', label: 'Customer Was Testing' },
    { code: 'systems_down', label: 'Systems Were Down' },
    { code: 'duplicate_attempt', label: 'Duplicate Attempt' },
    { code: 'customer_abandoned', label: 'Customer Abandoned' },
    { code: 'other', label: 'Other' },
];

const ALLOWED_PRESET_HOURS = new Set([24, 48, 168, 720]);
const INITIAL_BUCKET_LIMIT = 50;
const BUCKET_LIMIT_STEP = 50;
const MAX_BUCKET_LIMIT = 500;

function readRangeFromUrl(searchParams) {
    const from = searchParams.get('from') || '';
    const to = searchParams.get('to') || '';
    if (from || to) {
        return { mode: 'custom', from, to };
    }
    const hoursRaw = parseInt(searchParams.get('range_hours') || '', 10);
    const hours = ALLOWED_PRESET_HOURS.has(hoursRaw) ? hoursRaw : 48;
    return { mode: 'preset', hours };
}

function inferPaymentCloseReason(row) {
    const text = String(row?.failure_reason || '').toLowerCase();
    if (/(converted|completed|paid|success)/.test(text)) return 'customer_converted';
    if (/(test|sandbox|demo)/.test(text)) return 'customer_testing';
    if (/(system|down|outage|timeout|timed out|network|provider|service unavailable)/.test(text)) return 'systems_down';
    if (/(duplicate|already|repeat)/.test(text)) return 'duplicate_attempt';
    if (/(cancel|abandon|no response|declined|not interested)/.test(text)) return 'customer_abandoned';
    return 'payment_failed';
}

function SectionHeader({ title, count, visibleCount, refreshedAgo, onToggle, collapsed, emptyHint }) {
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
                        {Number(count || 0).toLocaleString()}
                    </span>
                </button>
            </div>
            <span className="text-[11px] text-slate-400">
                {Number(count || 0) > Number(visibleCount || 0) ? (
                    <span className="mr-2 text-slate-500">
                        Showing {Number(visibleCount || 0).toLocaleString()} of {Number(count || 0).toLocaleString()}
                    </span>
                ) : null}
                {refreshedAgo != null ? `Updated ${refreshedAgo}s ago` : ''}
                {count === 0 && emptyHint ? <span className="ml-2 italic">{emptyHint}</span> : null}
            </span>
        </header>
    );
}

function LoadMoreFooter({ loaded, total, limit, onLoadMore }) {
    const remaining = Math.max(0, Number(total || 0) - Number(loaded || 0));
    if (remaining <= 0) {
        return null;
    }

    const currentLimit = Number(limit || loaded || 0);
    const canLoadMore = currentLimit < MAX_BUCKET_LIMIT;
    const nextCount = Math.min(BUCKET_LIMIT_STEP, remaining, Math.max(0, MAX_BUCKET_LIMIT - currentLimit));

    return (
        <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-4 py-3">
            <p className="text-xs text-slate-500">
                Showing {Number(loaded || 0).toLocaleString()} of {Number(total || 0).toLocaleString()} matching items.
            </p>
            {canLoadMore ? (
                <button
                    type="button"
                    onClick={onLoadMore}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                >
                    Load {nextCount.toLocaleString()} more
                </button>
            ) : null}
        </footer>
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

function timeAgoShort(iso) {
    if (!iso) return '';
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
    if (seconds < 3600) return `${Math.max(1, Math.round(seconds / 60))}m ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)}h ago`;
    return `${Math.round(seconds / 86400)}d ago`;
}

// Automation-state chip: shows whether the lifecycle engine (or a colleague)
// already messaged this client for a flow, so nobody double-sends.
function LifecycleBadge({ entry, label }) {
    if (!entry) return null;
    const sent = entry.status === 'sent';
    const tone = sent
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
        : 'bg-amber-50 text-amber-700 ring-amber-600/20';
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${tone}`}
            title={`${label} ${entry.status} ${timeAgoShort(entry.sent_at)} (${entry.source === 'manual' ? 'manual' : 'auto'})`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${sent ? 'bg-emerald-500' : 'bg-amber-500'}`} />
            {label} {sent ? 'sent' : entry.status} {timeAgoShort(entry.sent_at)}
        </span>
    );
}

function NewSignupCard({ row, onMarkContacted, onCloseCase, onOpenClient, onOpenChat, onSendWelcome, welcomePending, onPreview, selected, onToggleSelect, selectable }) {
    return (
        <article className={`flex flex-col gap-2 border-l-4 px-4 py-3 transition hover:bg-slate-50 ${selected ? 'bg-teal-50/40' : ''} ${BUCKET_BORDER(row.sla_bucket)}`}>
            <div className="flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    {selectable ? (
                        <input
                            type="checkbox"
                            checked={Boolean(selected)}
                            onChange={onToggleSelect}
                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                            aria-label={`Select ${row.name || 'client'}`}
                        />
                    ) : null}
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
                    <LifecycleBadge entry={row.lifecycle?.onboarding} label="Welcome" />
                    {row.last_online_at ? (
                        <span className="truncate text-[10px] text-slate-400">online {timeAgoShort(row.last_online_at)}</span>
                    ) : null}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <ActionChip variant="primary" onClick={onMarkContacted}>Mark contacted</ActionChip>
                {row.welcome_eligible ? (
                    <>
                        <ActionChip
                            onClick={onSendWelcome}
                            disabled={welcomePending || row.lifecycle?.onboarding?.status === 'sent'}
                        >
                            {row.lifecycle?.onboarding?.status === 'sent' ? 'Welcome sent' : 'Send welcome'}
                        </ActionChip>
                        <ActionChip onClick={onPreview}>Preview</ActionChip>
                    </>
                ) : null}
                {row.sb_user_id ? <ActionChip onClick={onOpenChat}>Open chat</ActionChip> : null}
                <ActionChip onClick={onOpenClient}>Open client</ActionChip>
                <ActionChip variant="danger" onClick={onCloseCase}>Close case</ActionChip>
            </div>
        </article>
    );
}

function FailedPaymentCard({ row, onRetryStk, onSendLink, onSendRecovery, recoveryPending, onOpenClient, onClosePayment }) {
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
                    <LifecycleBadge entry={row.lifecycle?.recovery} label="Recovery" />
                    {row.is_manual_payment ? (
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 ring-1 ring-inset ring-slate-300" title="Manual payment awaiting review — recovery SMS is suppressed for this payment.">
                            Manual review
                        </span>
                    ) : null}
                </div>
            </div>
            {row.failure_reason ? (
                <p className="text-[11px] italic text-slate-500">{row.failure_reason}</p>
            ) : null}
            <div className="flex flex-wrap items-center gap-2">
                <ActionChip variant="primary" onClick={onRetryStk}>Retry STK</ActionChip>
                <ActionChip onClick={onSendLink}>Send link</ActionChip>
                {!row.is_manual_payment ? (
                    <ActionChip
                        onClick={onSendRecovery}
                        disabled={recoveryPending || row.lifecycle?.recovery?.status === 'sent'}
                    >
                        {row.lifecycle?.recovery?.status === 'sent' ? 'Recovery sent' : 'Recovery SMS'}
                    </ActionChip>
                ) : null}
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
                    {row.lifecycle ? (
                        <LifecycleBadge
                            entry={Object.values(row.lifecycle)[0]}
                            label={`${Object.keys(row.lifecycle)[0] || 'Lifecycle'}`}
                        />
                    ) : null}
                    {row.last_online_at ? (
                        <span className="truncate text-[10px] text-slate-400">online {timeAgoShort(row.last_online_at)}</span>
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

// Preview what a client will actually receive for a lifecycle flow — no send.
function LifecyclePreviewDialog({ open, client, flow, onClose }) {
    const query = useQuery({
        queryKey: ['lifecycle-preview', client?.id, flow],
        queryFn: () => api.get(`/crm/clients/${client.id}/lifecycle-sms/preview`, { params: { flow } }).then((r) => r.data),
        enabled: open && Boolean(client?.id),
    });

    if (!open) return null;
    const data = query.data;

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div role="dialog" aria-modal="true" className="w-full max-w-lg overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="border-b border-slate-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-slate-900">Message preview — {client?.name || 'client'}</h3>
                    <p className="text-xs text-slate-500">Exactly what this client would receive. Nothing is sent.</p>
                </header>
                <div className="p-4">
                    {query.isLoading ? (
                        <div className="py-8 text-center text-sm text-slate-400">Rendering preview…</div>
                    ) : query.isError ? (
                        <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">Could not load preview.</div>
                    ) : (
                        <div className="space-y-3">
                            {!data?.would_send ? (
                                <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Would be skipped: <span className="font-semibold">{data?.skip_reason || 'not eligible'}</span>. Preview shown for reference.
                                </div>
                            ) : null}
                            {data?.body ? (
                                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800 whitespace-pre-wrap">{data.body}</div>
                            ) : (
                                <div className="rounded-md border border-dashed border-slate-200 py-6 text-center text-sm text-slate-400">No template available for this flow.</div>
                            )}
                            {data?.body ? (
                                <p className="text-[11px] text-slate-400">{data.body.length} characters · ~{data.segments || 1} SMS segment{(data.segments || 1) === 1 ? '' : 's'}{data.template_title ? ` · ${data.template_title}` : ''}</p>
                            ) : null}
                        </div>
                    )}
                </div>
                <footer className="flex items-center justify-end border-t border-slate-100 p-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary">Close</button>
                </footer>
            </div>
        </div>
    );
}

export default function ConversionQueueView({ platformId = '' }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [searchParams, setSearchParams] = useSearchParams();
    const [collapsed, setCollapsed] = useState({ new_signups: false, failed_payments: false, stalled_contacted: false });
    const [bucketLimits, setBucketLimits] = useState({
        new_signups: INITIAL_BUCKET_LIMIT,
        failed_payments: INITIAL_BUCKET_LIMIT,
        stalled_contacted: INITIAL_BUCKET_LIMIT,
    });
    const [closeDialog, setCloseDialog] = useState({ open: false, client: null });
    const [closeError, setCloseError] = useState(null);
    const [closePaymentDialog, setClosePaymentDialog] = useState({ open: false, payment: null, reason_code: 'payment_failed', reason_note: '', converted_payment_id: '' });
    const [selectedSignups, setSelectedSignups] = useState(() => new Set());
    const [preview, setPreview] = useState({ open: false, client: null, flow: 'onboarding' });

    const range = useMemo(() => readRangeFromUrl(searchParams), [searchParams]);

    const queryParams = useMemo(() => {
        const limitParams = {
            new_signups_limit: bucketLimits.new_signups,
            failed_payments_limit: bucketLimits.failed_payments,
            stalled_contacted_limit: bucketLimits.stalled_contacted,
        };

        if (range.mode === 'custom') {
            return {
                from: range.from || undefined,
                to: range.to || undefined,
                ...limitParams,
            };
        }
        return { range_hours: range.hours, ...limitParams };
    }, [bucketLimits, range]);

    useEffect(() => {
        setBucketLimits({
            new_signups: INITIAL_BUCKET_LIMIT,
            failed_payments: INITIAL_BUCKET_LIMIT,
            stalled_contacted: INITIAL_BUCKET_LIMIT,
        });
    }, [platformId, range.mode, range.hours, range.from, range.to]);

    const handleRangeChange = (next) => {
        const params = new URLSearchParams(searchParams);
        // Preserve `tab=conversion` etc.
        params.delete('range_hours');
        params.delete('from');
        params.delete('to');
        if (next.mode === 'custom') {
            if (next.from) params.set('from', next.from);
            if (next.to) params.set('to', next.to);
        } else if (next.hours && next.hours !== 48) {
            // Keep default (48h) out of the URL so the page is sharable without noise.
            params.set('range_hours', String(next.hours));
        }
        setSearchParams(params, { replace: true });
    };

    const queueQuery = useQuery({
        queryKey: ['clients-conversion-queue', platformId || 'all', queryParams],
        queryFn: () => api.get('/crm/clients/conversion-queue', {
            params: {
                ...(platformId ? { platform_id: Number(platformId) } : {}),
                ...queryParams,
            },
        }).then((r) => r.data),
        refetchInterval: 60_000,
        staleTime: 30_000,
        keepPreviousData: true,
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

    const sendWelcomeMutation = useMutation({
        mutationFn: (clientId) => api.post(`/crm/clients/${clientId}/lifecycle-sms`, { flow: 'onboarding' }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('Welcome SMS sent.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Welcome SMS failed.'),
    });

    const sendRecoveryMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/lifecycle-recovery`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            toast?.success?.('Recovery SMS sent.');
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Recovery SMS failed.'),
    });

    const bulkWelcomeMutation = useMutation({
        mutationFn: (clientIds) => api.post('/crm/lifecycle-sms/bulk', { flow: 'onboarding', client_ids: clientIds }).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            setSelectedSignups(new Set());
            const s = data?.summary || {};
            toast?.success?.(data?.message || `${s.sent || 0} sent.`);
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Bulk send failed.'),
    });

    const toggleSignup = (id) => setSelectedSignups((current) => {
        const next = new Set(current);
        if (next.has(id)) next.delete(id); else next.add(id);
        return next;
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
    const loadMoreFor = (key) => () => {
        setBucketLimits((current) => ({
            ...current,
            [key]: Math.min(MAX_BUCKET_LIMIT, Number(current[key] || INITIAL_BUCKET_LIMIT) + BUCKET_LIMIT_STEP),
        }));
    };

    return (
        <section className="space-y-4">
            <QueueRangeSelector
                value={range}
                onChange={handleRangeChange}
                currentLabel={buckets.range?.label}
            />

            <div className="grid gap-3 md:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">New signups</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(buckets.counts?.new_signups || 0).toLocaleString()}</p>
                    <p className="mt-0.5 text-[11px] text-slate-400">
                        {buckets.range?.label || 'Last 48 hours'}
                        {Number(buckets.counts?.new_signups || 0) > Number(buckets.visible_counts?.new_signups || buckets.new_signups?.length || 0)
                            ? ` · showing ${Number(buckets.visible_counts?.new_signups || buckets.new_signups?.length || 0).toLocaleString()}`
                            : ''}
                    </p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Failed payments</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(buckets.counts?.failed_payments || 0).toLocaleString()}</p>
                    <p className="mt-0.5 text-[11px] text-slate-400">
                        {buckets.range?.label || 'Last 48 hours'}
                        {Number(buckets.counts?.failed_payments || 0) > Number(buckets.visible_counts?.failed_payments || buckets.failed_payments?.length || 0)
                            ? ` · showing ${Number(buckets.visible_counts?.failed_payments || buckets.failed_payments?.length || 0).toLocaleString()}`
                            : ''}
                    </p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Stalled contacted ({'>'}72h)</p>
                    <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(buckets.counts?.stalled_contacted || 0).toLocaleString()}</p>
                    <p className="mt-0.5 text-[11px] text-slate-400">
                        Idle &gt; 72 hours · independent of range
                        {Number(buckets.counts?.stalled_contacted || 0) > Number(buckets.visible_counts?.stalled_contacted || buckets.stalled_contacted?.length || 0)
                            ? ` · showing ${Number(buckets.visible_counts?.stalled_contacted || buckets.stalled_contacted?.length || 0).toLocaleString()}`
                            : ''}
                    </p>
                </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="New signups"
                    count={buckets.counts?.new_signups ?? buckets.new_signups?.length ?? 0}
                    visibleCount={buckets.visible_counts?.new_signups ?? buckets.new_signups?.length ?? 0}
                    refreshedAgo={refreshedAgo}
                    collapsed={collapsed.new_signups}
                    onToggle={togglerFor('new_signups')}
                    emptyHint={buckets.new_signups?.length === 0 ? `Inbox zero — no new signups in ${(buckets.range?.label || 'this window').toLowerCase()}.` : null}
                />
                {!collapsed.new_signups ? (
                    <div className="divide-y divide-slate-100">
                        {(() => {
                            const eligible = (buckets.new_signups || []).filter((r) => r.welcome_eligible && r.lifecycle?.onboarding?.status !== 'sent');
                            const allSelected = eligible.length > 0 && eligible.every((r) => selectedSignups.has(r.id));
                            if (eligible.length === 0) return null;
                            return (
                                <div className="flex flex-wrap items-center justify-between gap-2 bg-slate-50 px-4 py-2">
                                    <label className="flex items-center gap-2 text-xs font-medium text-slate-600">
                                        <input
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={(e) => {
                                                setSelectedSignups(() => e.target.checked ? new Set(eligible.map((r) => r.id)) : new Set());
                                            }}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Select all welcome-eligible ({eligible.length})
                                    </label>
                                    {selectedSignups.size > 0 ? (
                                        <div className="flex items-center gap-2">
                                            <span className="text-xs text-slate-500">{selectedSignups.size} selected</span>
                                            <button
                                                type="button"
                                                onClick={() => setSelectedSignups(new Set())}
                                                className="text-xs text-slate-500 hover:underline"
                                            >
                                                Clear
                                            </button>
                                            <button
                                                type="button"
                                                disabled={bulkWelcomeMutation.isPending}
                                                onClick={() => {
                                                    if (window.confirm(`Send the welcome SMS to ${selectedSignups.size} client${selectedSignups.size === 1 ? '' : 's'}? Real SMS will go out to eligible clients.`)) {
                                                        bulkWelcomeMutation.mutate([...selectedSignups]);
                                                    }
                                                }}
                                                className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {bulkWelcomeMutation.isPending ? 'Sending…' : `Send welcome to ${selectedSignups.size}`}
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            );
                        })()}
                        {(buckets.new_signups || []).map((row) => (
                            <NewSignupCard
                                key={`signup-${row.id}`}
                                row={row}
                                selectable={row.welcome_eligible && row.lifecycle?.onboarding?.status !== 'sent'}
                                selected={selectedSignups.has(row.id)}
                                onToggleSelect={() => toggleSignup(row.id)}
                                onMarkContacted={() => markContactedMutation.mutate({ clientId: row.id, channel: 'whatsapp' })}
                                onOpenClient={() => navigate(`/clients/${row.id}`)}
                                onOpenChat={() => navigate(`/conversations?client_id=${row.id}`)}
                                onCloseCase={() => { setCloseError(null); setCloseDialog({ open: true, client: row }); }}
                                onSendWelcome={() => sendWelcomeMutation.mutate(row.id)}
                                welcomePending={sendWelcomeMutation.isPending}
                                onPreview={() => setPreview({ open: true, client: row, flow: 'onboarding' })}
                            />
                        ))}
                        <LoadMoreFooter
                            loaded={buckets.visible_counts?.new_signups ?? buckets.new_signups?.length ?? 0}
                            total={buckets.counts?.new_signups ?? buckets.new_signups?.length ?? 0}
                            limit={buckets.limits?.new_signups ?? bucketLimits.new_signups}
                            onLoadMore={loadMoreFor('new_signups')}
                        />
                    </div>
                ) : null}
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="Failed payments"
                    count={buckets.counts?.failed_payments ?? buckets.failed_payments?.length ?? 0}
                    visibleCount={buckets.visible_counts?.failed_payments ?? buckets.failed_payments?.length ?? 0}
                    refreshedAgo={refreshedAgo}
                    collapsed={collapsed.failed_payments}
                    onToggle={togglerFor('failed_payments')}
                    emptyHint={buckets.failed_payments?.length === 0 ? `No open failed payments in ${(buckets.range?.label || 'this window').toLowerCase()}.` : null}
                />
                {!collapsed.failed_payments ? (
                    <div className="divide-y divide-slate-100">
                        {(buckets.failed_payments || []).map((row) => (
                            <FailedPaymentCard
                                key={`payment-${row.id}`}
                                row={row}
                                onRetryStk={() => retryStkMutation.mutate(row.id)}
                                onSendLink={() => sendLinkMutation.mutate(row.id)}
                                onSendRecovery={() => sendRecoveryMutation.mutate(row.id)}
                                recoveryPending={sendRecoveryMutation.isPending}
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
                        <LoadMoreFooter
                            loaded={buckets.visible_counts?.failed_payments ?? buckets.failed_payments?.length ?? 0}
                            total={buckets.counts?.failed_payments ?? buckets.failed_payments?.length ?? 0}
                            limit={buckets.limits?.failed_payments ?? bucketLimits.failed_payments}
                            onLoadMore={loadMoreFor('failed_payments')}
                        />
                    </div>
                ) : null}
            </div>

            <div className="rounded-lg border border-slate-200 bg-white">
                <SectionHeader
                    title="Stalled contacted"
                    count={buckets.counts?.stalled_contacted ?? buckets.stalled_contacted?.length ?? 0}
                    visibleCount={buckets.visible_counts?.stalled_contacted ?? buckets.stalled_contacted?.length ?? 0}
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
                        <LoadMoreFooter
                            loaded={buckets.visible_counts?.stalled_contacted ?? buckets.stalled_contacted?.length ?? 0}
                            total={buckets.counts?.stalled_contacted ?? buckets.stalled_contacted?.length ?? 0}
                            limit={buckets.limits?.stalled_contacted ?? bucketLimits.stalled_contacted}
                            onLoadMore={loadMoreFor('stalled_contacted')}
                        />
                    </div>
                ) : null}
            </div>

            <LifecyclePreviewDialog
                open={preview.open}
                client={preview.client}
                flow={preview.flow}
                onClose={() => setPreview({ open: false, client: null, flow: 'onboarding' })}
            />

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
