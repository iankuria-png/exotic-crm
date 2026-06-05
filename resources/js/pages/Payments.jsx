import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import RowActionMenu from '../components/RowActionMenu';
import StatusBadge from '../components/StatusBadge';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import PaymentImportDrawer from '../components/PaymentImportDrawer';
import FraudAuditWorkspace from '../components/FraudAuditWorkspace';
import PaymentExportModal from '../components/PaymentExportModal';
import ReportingCurrencyControl from '../components/ReportingCurrencyControl';
import FxNormalizationNotice from '../components/FxNormalizationNotice';
import { useToast } from '../components/ToastProvider';
import { platformOptionsWithFlags } from '../utils/flags';
import { candidateScore, scoreTone, toneClasses } from '../utils/scoring';
import { formatCurrency } from '../utils/currency';
import CurrencyAmount from '../components/CurrencyAmount';
import { useAuth } from '../hooks/useAuth';
import useReportingCurrency from '../hooks/useReportingCurrency';

const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';
const SUCCESSFUL_PAYMENT_STATUSES = ['completed', 'expired'];

function proofCacheKey(submission) {
    return submission?.id ? `submission:${submission.id}` : `url:${submission?.proof_url || ''}`;
}

function proofRequestPath(submission) {
    if (submission?.id) {
        return `/crm/payments/manual-submissions/${submission.id}/proof`;
    }

    const rawUrl = String(submission?.proof_url || '').trim();
    if (rawUrl === '') {
        return '';
    }

    if (typeof window !== 'undefined') {
        const origin = window.location.origin;
        if (rawUrl.startsWith(origin)) {
            return proofRequestPathFromApiPath(rawUrl.slice(origin.length));
        }
    }

    return proofRequestPathFromApiPath(rawUrl);
}

function proofRequestPathFromApiPath(path) {
    const normalized = String(path || '').trim();
    if (normalized.startsWith('/api/')) {
        return normalized.slice(4);
    }

    return normalized;
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

function formatDateInputValue(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function resolvePresetDateRange(preset) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const endDate = formatDateInputValue(today);
    const startDate = new Date(today);

    if (preset === 'today') {
        return { from: endDate, to: endDate };
    }

    if (preset === '7d') {
        startDate.setDate(startDate.getDate() - 6);
        return { from: formatDateInputValue(startDate), to: endDate };
    }

    if (preset === '30d') {
        startDate.setDate(startDate.getDate() - 29);
        return { from: formatDateInputValue(startDate), to: endDate };
    }

    if (preset === '90d') {
        startDate.setDate(startDate.getDate() - 89);
        return { from: formatDateInputValue(startDate), to: endDate };
    }

    if (preset === 'ytd') {
        startDate.setMonth(0, 1);
        return { from: formatDateInputValue(startDate), to: endDate };
    }

    return { from: '', to: '' };
}

function detectPresetDateRange(fromDate, toDate, presets = ['today', '7d', '30d']) {
    if (!fromDate || !toDate) {
        return '';
    }

    for (const preset of presets) {
        const range = resolvePresetDateRange(preset);
        if (range.from === fromDate && range.to === toDate) {
            return preset;
        }
    }

    return 'custom';
}

function toAmount(value) {
    const amount = Number(value || 0);
    return Number.isFinite(amount) ? amount : 0;
}

function formatAmountBreakdown(breakdown = {}, fallbackCurrency = 'USD') {
    const entries = Object.entries(breakdown || {}).filter(([, amount]) => Number(amount || 0) !== 0);

    if (entries.length === 0) {
        return formatCurrency(0, fallbackCurrency);
    }

    return entries
        .slice(0, 2)
        .map(([currency, amount]) => formatCurrency(amount, currency))
        .join(' + ');
}

function recoveryAmountDisplay(metrics, bucket, reportingCurrency, fallbackCurrency) {
    const normalizedAmount = metrics?.[`${bucket}_normalized_amount`];
    const normalizedCurrency = metrics?.normalized_currency || reportingCurrency?.targetCurrency || fallbackCurrency;
    const meta = metrics?.[`${bucket}_normalization_meta`];

    if (reportingCurrency?.isFlat && normalizedAmount !== null && normalizedAmount !== undefined) {
        return {
            value: formatCurrency(normalizedAmount, normalizedCurrency),
            hint: 'Converted value',
            meta,
        };
    }

    if (reportingCurrency?.isFlat) {
        return {
            value: formatAmountBreakdown(metrics?.[`${bucket}_amount_breakdown`], fallbackCurrency),
            hint: 'Native value; FX incomplete',
            meta,
        };
    }

    return {
        value: formatAmountBreakdown(metrics?.[`${bucket}_amount_breakdown`], fallbackCurrency),
        hint: 'Native value',
        meta: null,
    };
}

function formatRecoveryDuration(minutes) {
    const total = Number(minutes || 0);
    if (!Number.isFinite(total) || total <= 0) return 'same minute';
    if (total < 60) return `${Math.round(total)}m`;

    const hours = total / 60;
    if (hours < 48) return `${hours.toFixed(hours >= 10 ? 0 : 1)}h`;

    const days = hours / 24;
    return `${days.toFixed(days >= 10 ? 0 : 1)}d`;
}

function hoursSince(timestamp) {
    if (!timestamp) return Infinity;
    const parsed = new Date(timestamp).getTime();
    if (Number.isNaN(parsed)) return Infinity;
    const diffMs = Date.now() - parsed;
    return diffMs > 0 ? diffMs / (1000 * 60 * 60) : 0;
}

function pendingRecommendation(payment) {
    if (!['initiated', 'pending'].includes(payment?.status)) {
        return null;
    }

    const ageHours = hoursSince(payment.created_at);
    if (ageHours < 1) {
        return { label: 'Wait for callback', tone: 'default' };
    }
    if (ageHours < 24) {
        return { label: 'Send payment link now', tone: 'warning' };
    }
    if (ageHours < 72) {
        return { label: 'Retry STK then follow up', tone: 'warning' };
    }

    return { label: 'Escalate and follow up manually', tone: 'danger' };
}

function recommendationClass(tone) {
    if (tone === 'danger') {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }
    if (tone === 'warning') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }
    return 'border-slate-200 bg-slate-100 text-slate-600';
}

function recommendationDotClass(tone) {
    if (tone === 'danger') {
        return 'bg-rose-500';
    }
    if (tone === 'warning') {
        return 'bg-amber-500';
    }
    return 'bg-slate-400';
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function formatPaymentDateTime(timestamp, timezone) {
    if (!timestamp) return { date: '—', time: null };

    const dateValue = new Date(timestamp);
    if (Number.isNaN(dateValue.getTime())) return { date: '—', time: null };

    const resolvedTimezone = timezone || 'UTC';
    const date = new Intl.DateTimeFormat('en-GB', {
        timeZone: resolvedTimezone,
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(dateValue);

    const timeParts = new Intl.DateTimeFormat('en-GB', {
        timeZone: resolvedTimezone,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZoneName: 'short',
    }).formatToParts(dateValue);

    const hhmm = timeParts
        .filter((part) => ['hour', 'literal', 'minute'].includes(part.type))
        .map((part) => part.value)
        .join('')
        .trim();
    const timezoneLabel = timeParts.find((part) => part.type === 'timeZoneName')?.value || '';

    return {
        date,
        time: `${hhmm} ${timezoneLabel}`.trim() || null,
    };
}

function titleize(value) {
    if (!value) return '—';
    return String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function resolvePredictedSubscriptionLifecycle(record, fallback = 'new') {
    const lifecycle = String(record?.subscription_lifecycle || '').toLowerCase();
    if (lifecycle === 'new' || lifecycle === 'renewal') {
        return lifecycle;
    }

    return fallback;
}

function subscriptionLifecycleHelperText(lifecycle) {
    return lifecycle === 'renewal'
        ? 'Prefilled as Renewal because this client already has prior subscription history in this market.'
        : 'Prefilled as New because no prior subscription history was found for this client in this market.';
}

function paymentResolutionBadge(resolutionCode) {
    const normalized = String(resolutionCode || '').toLowerCase();
    if (!normalized) {
        return null;
    }

    if (normalized === 'reversed') {
        return { label: 'Reversed', className: 'bg-rose-50 text-rose-700 ring-rose-200' };
    }

    if (normalized === 'invalid_reference') {
        return { label: 'Invalid Ref', className: 'bg-amber-50 text-amber-700 ring-amber-200' };
    }

    return { label: titleize(normalized), className: 'bg-slate-100 text-slate-600 ring-slate-200' };
}

const PAYMENT_CLOSE_REASONS = [
    { code: 'customer_converted', label: 'Customer Converted' },
    { code: 'payment_failed', label: 'Payment Failed' },
    { code: 'customer_testing', label: 'Customer Was Testing' },
    { code: 'systems_down', label: 'Systems Were Down' },
    { code: 'duplicate_attempt', label: 'Duplicate Attempt' },
    { code: 'customer_abandoned', label: 'Customer Abandoned' },
    { code: 'other', label: 'Other' },
];

function inferPaymentCloseReason(payment) {
    const text = [
        payment?.failure_reason,
        payment?.raw_payload?.manual_close?.reason_note,
        payment?.raw_payload?.error_message,
    ].filter(Boolean).join(' ').toLowerCase();

    if (/(converted|completed|paid|success)/.test(text)) return 'customer_converted';
    if (/(test|sandbox|demo)/.test(text)) return 'customer_testing';
    if (/(system|down|outage|timeout|timed out|network|provider|service unavailable)/.test(text)) return 'systems_down';
    if (/(duplicate|already|repeat)/.test(text)) return 'duplicate_attempt';
    if (/(cancel|abandon|no response|declined|not interested)/.test(text)) return 'customer_abandoned';

    return 'payment_failed';
}

function defaultPaymentCloseNote(payment) {
    return payment?.failure_reason ? String(payment.failure_reason).slice(0, 1000) : '';
}

function isSandboxPayment(payment) {
    return String(payment?.provider_environment || '').toLowerCase() === 'sandbox'
        || Boolean(payment?.payment_data?.test_mode);
}

function isExplicitTestPayment(payment) {
    return String(payment?.record_classification || '').toLowerCase() === 'test';
}

function isTestPayment(payment) {
    return isExplicitTestPayment(payment) || isSandboxPayment(payment);
}

function paymentTestBadge(payment) {
    if (isSandboxPayment(payment)) {
        return { status: 'sandbox', label: 'Sandbox/Test', tone: 'sandbox' };
    }

    if (isExplicitTestPayment(payment)) {
        return { status: 'sandbox', label: 'Test Record', tone: 'sandbox' };
    }

    return null;
}

function sandboxStatusLabel(payment) {
    if (!isSandboxPayment(payment)) {
        return null;
    }

    const status = String(payment?.status || '').toLowerCase();
    const testResult = String(payment?.payment_data?.test_result || '').toLowerCase();

    if (testResult === 'failed' || status === 'failed') {
        return 'Sandbox Failed';
    }

    if (status === 'completed' || testResult === 'completed') {
        return 'Sandbox Completed';
    }

    if (['initiated', 'pending'].includes(status)) {
        return 'Sandbox Pending';
    }

    return `Sandbox ${titleize(status)}`;
}

function customerMixBucketMeta(bucketKey) {
    switch (bucketKey) {
        case 'new_active':
            return {
                label: 'New active users',
                helper: 'Active clients created in this period',
                dotClassName: 'bg-teal-500',
                activeClassName: 'border-teal-300 bg-teal-50/70',
                idleClassName: 'border-slate-200 bg-white hover:border-teal-200 hover:bg-teal-50/40',
                barClassName: 'bg-teal-500',
            };
        case 'existing_active':
            return {
                label: 'Existing active users',
                helper: 'Active clients created before this period',
                dotClassName: 'bg-indigo-500',
                activeClassName: 'border-indigo-300 bg-indigo-50/70',
                idleClassName: 'border-slate-200 bg-white hover:border-indigo-200 hover:bg-indigo-50/40',
                barClassName: 'bg-indigo-500',
            };
        case 'unattributed':
            return {
                label: 'Unattributed',
                helper: 'Confirmed payments without a matched client',
                dotClassName: 'bg-sky-500',
                activeClassName: 'border-sky-300 bg-sky-50/70',
                idleClassName: 'border-slate-200 bg-white hover:border-sky-200 hover:bg-sky-50/40',
                barClassName: 'bg-sky-500',
            };
        case 'other_matched':
        default:
            return {
                label: 'Other matched',
                helper: 'Matched payments outside active-client scope',
                dotClassName: 'bg-slate-400',
                activeClassName: 'border-slate-300 bg-slate-100',
                idleClassName: 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50',
                barClassName: 'bg-slate-400',
            };
    }
}

function renderPaymentStatusBadges(payment) {
    const manualReviewStatus = unresolvedManualReviewStatus(payment);
    const status = String(payment?.status || '').toLowerCase();
    const customLabel = sandboxStatusLabel(payment);
    const testBadge = paymentTestBadge(payment);
    const isBundlePayment = Boolean(payment?.manual_payment_bundle_id);
    const resolutionBadge = paymentResolutionBadge(payment?.resolution_code);

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {manualReviewStatus ? (
                <StatusBadge status={manualReviewStatus.status} label={manualReviewStatus.label} />
            ) : (
                <StatusBadge status={status} label={customLabel} />
            )}
            {resolutionBadge ? (
                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${resolutionBadge.className}`}>
                    {resolutionBadge.label}
                </span>
            ) : null}
            {testBadge ? <StatusBadge status={testBadge.status} label={testBadge.label} tone={testBadge.tone} /> : null}
            {isExplicitTestPayment(payment) ? (
                <span className="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-amber-300">
                    Admin test
                </span>
            ) : null}
            {isBundlePayment ? (
                <span className="inline-flex items-center rounded-md border border-violet-200 bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-violet-700">
                    Bundle
                </span>
            ) : null}
        </div>
    );
}

function paymentLinkModeLabel(mode) {
    return mode === 'proxy_hosted_checkout' ? 'CRM proxy' : 'Static URL';
}

function paymentLinkProviderOptionLabel(providerKey, providerConfig = {}) {
    const baseLabel = providerConfig?.label?.trim() || providerKey;
    return `${baseLabel} (${paymentLinkModeLabel(providerConfig?.mode)})`;
}

function renderPaymentReference(row) {
    const reference = row.transaction_reference || '—';
    const providerTransactionId = row.provider_transaction_id || '';

    return (
        <div className="flex min-w-0 max-w-full flex-col gap-0.5">
            <span
                className="crm-mono block max-w-full truncate text-xs text-slate-600"
                title={row.transaction_reference || undefined}
            >
                {reference}
            </span>
            {providerTransactionId ? (
                <span
                    className="crm-mono block max-w-full truncate text-[11px] leading-4 text-slate-400"
                    title={providerTransactionId}
                >
                    Provider Tx: {providerTransactionId}
                </span>
            ) : null}
        </div>
    );
}

function diagnosticToneClasses(status) {
    const normalized = String(status || '').toLowerCase();

    if (['completed', 'success', 'active'].includes(normalized)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (['pending', 'opened', 'checkout_initialized', 'rotated'].includes(normalized)) {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    if (['failed', 'expired', 'reversed'].includes(normalized)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    return 'border-slate-200 bg-slate-100 text-slate-600';
}

function buildDiagnosisHeadline(payment, failure) {
    const manualReviewStatus = unresolvedManualReviewStatus(payment);
    const status = String(payment?.status || '').toLowerCase();
    const stage = titleize(failure?.stage);

    if (manualReviewStatus) {
        return manualReviewStatus.label === 'Verification pending'
            ? 'Payment proof is awaiting manual verification while the subscription remains active.'
            : 'Payment proof is pending manual review before the subscription can be activated.';
    }

    if (status === 'completed') {
        return 'Payment is completed in CRM and the latest telemetry shows a resolved flow.';
    }

    if (status === 'reversed') {
        return failure?.reason || 'Payment was reversed after provider processing.';
    }

    if (status === 'failed') {
        if (failure?.reason) {
            return `${stage}: ${failure.reason}`;
        }

        if (failure?.stage) {
            return `${stage} failed and needs operator review.`;
        }

        return 'Payment failed before CRM could complete the flow.';
    }

    if (['initiated', 'pending', 'awaiting_payment'].includes(status)) {
        if (failure?.stage === 'callback_processing') {
            return 'CRM has provider activity, but the final callback outcome is still incomplete.';
        }

        return 'Payment is still in progress and waiting on customer or provider action.';
    }

    return 'Review the latest telemetry and recommended action before taking the next step.';
}

function resolveBrowserContextState(browserMeta) {
    const contextType = String(browserMeta?.context_type || '').toLowerCase();

    if (contextType === 'browser') {
        return {
            label: 'Browser captured',
            tone: diagnosticToneClasses('success'),
            description: 'Captured from the initiating browser request. These fields are safe to use for operator troubleshooting.',
        };
    }

    if (contextType === 'server') {
        return {
            label: 'Server-side request',
            tone: diagnosticToneClasses('pending'),
            description: 'This flow was initiated by a server-to-server request, so browser-only fields were intentionally not captured.',
        };
    }

    return {
        label: 'No browser context captured',
        tone: diagnosticToneClasses('default'),
        description: 'CRM has no trustworthy browser-origin context for this payment yet.',
    };
}

function formatStructuredValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (Array.isArray(value)) {
        return value.slice(0, 3).join(', ');
    }

    if (typeof value === 'object') {
        return null;
    }

    return String(value);
}

function describeTimelineContent(content) {
    if (!content || typeof content !== 'object') {
        return 'Structured event data recorded.';
    }

    const preferredKeys = ['summary', 'message', 'reason', 'status', 'payment_method', 'transaction_reference', 'amount', 'currency', 'expires_at'];
    const preferred = preferredKeys
        .map((key) => {
            const value = formatStructuredValue(content[key]);
            return value ? `${titleize(key)}: ${value}` : null;
        })
        .filter(Boolean);

    if (preferred.length > 0) {
        return preferred.join(' • ');
    }

    const fallback = Object.entries(content)
        .map(([key, value]) => {
            const normalized = formatStructuredValue(value);
            return normalized ? `${titleize(key)}: ${normalized}` : null;
        })
        .filter(Boolean)
        .slice(0, 4);

    if (fallback.length > 0) {
        return fallback.join(' • ');
    }

    const nestedEntry = Object.entries(content).find(([, value]) => value && typeof value === 'object');
    if (nestedEntry) {
        return `${titleize(nestedEntry[0])} updated`;
    }

    return 'Structured event data recorded.';
}

function isManualSubmissionPayment(payment) {
    return Boolean(payment?.manual_submission);
}

function manualCustomerStateValue(state) {
    if (state && typeof state === 'object') {
        return state.state || '';
    }

    return state || '';
}

function unresolvedManualReviewStatus(payment) {
    if (!isManualSubmissionPayment(payment) || payment?.reconciliation_state !== 'manual_review') {
        return null;
    }

    const customerState = manualCustomerStateValue(payment?.manual_submission?.customer_state);

    if (customerState === 'verification_pending') {
        return {
            status: 'pending',
            label: 'Verification pending',
        };
    }

    return {
        status: 'pending',
        label: 'Pending review',
    };
}

function manualSubmissionAction(payment) {
    if (!isManualSubmissionPayment(payment) || payment?.reconciliation_state !== 'manual_review') {
        return null;
    }

    const alreadyActive = Boolean(payment?.manual_submission?.activated_on_submit) || Boolean(payment?.deal_id);

    if (alreadyActive) {
        return {
            key: 'manual_verify',
            label: 'Mark verified',
        };
    }

    return {
        key: 'manual_approve',
        label: 'Approve & activate',
    };
}

function manualCustomerStateMeta(state) {
    const normalized = String(manualCustomerStateValue(state) || '').toLowerCase();

    if (normalized === 'verification_pending') {
        return {
            label: 'Verification pending',
            className: 'border-teal-200 bg-teal-50 text-teal-700',
        };
    }

    if (normalized === 'awaiting_review') {
        return {
            label: 'Awaiting review',
            className: 'border-amber-200 bg-amber-50 text-amber-700',
        };
    }

    if (normalized === 'rejected') {
        return {
            label: 'Rejected',
            className: 'border-rose-200 bg-rose-50 text-rose-700',
        };
    }

    if (normalized === 'verified') {
        return {
            label: 'Verified',
            className: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        };
    }

    return null;
}

function diagnosticsStageLabel(payment, failure) {
    const manualReviewStatus = unresolvedManualReviewStatus(payment);
    if (manualReviewStatus) {
        return manualReviewStatus.label;
    }

    return titleize(failure?.stage || 'overview');
}

function structuredDiagnosticsTone(status) {
    const normalized = String(status || '').toLowerCase();

    if (['healthy', 'online'].includes(normalized)) {
        return 'active';
    }

    if (['attention', 'pending', 'legacy_composed'].includes(normalized)) {
        return 'manual_review';
    }

    if (['degraded', 'failed', 'critical'].includes(normalized)) {
        return 'failed';
    }

    return 'sandbox';
}

function isUrlLikeValue(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value.trim());
}

function shortIdFromUrl(value) {
    const trimmed = String(value).trim().replace(/\/+$/, '');
    const segment = trimmed.split('/').pop() || trimmed;
    return segment || trimmed;
}

function DiagnosticEntryValue({ value }) {
    const [revealed, setRevealed] = useState(false);

    if (!value) {
        return <>—</>;
    }

    if (!isUrlLikeValue(value)) {
        return <>{value}</>;
    }

    const shortId = shortIdFromUrl(value);

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5 align-middle">
            <code className="break-all font-mono text-slate-700">{revealed ? value : shortId}</code>
            <button
                type="button"
                onClick={() => setRevealed((prev) => !prev)}
                className="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 hover:bg-slate-100"
            >
                {revealed ? 'Hide' : 'Reveal'}
            </button>
            <button
                type="button"
                onClick={() => navigator.clipboard?.writeText(value)}
                className="rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 hover:bg-slate-100"
            >
                Copy
            </button>
        </span>
    );
}

function StructuredDiagnosticsSection({ section }) {
    const entries = Array.isArray(section?.entries) ? section.entries : [];
    const items = Array.isArray(section?.items) ? section.items : [];

    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h4 className="text-sm font-semibold text-slate-900">{section?.title || 'Diagnostics'}</h4>
                        <StatusBadge
                            status={section?.status}
                            label={titleize(section?.status || 'unknown')}
                            tone={structuredDiagnosticsTone(section?.status)}
                        />
                    </div>
                    <p className="mt-2 text-xs text-slate-500">{section?.summary || 'No structured summary available.'}</p>
                </div>
            </div>

            {entries.length > 0 ? (
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {entries.map((entry) => (
                        <p key={`${section?.key}-${entry.label}`} className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                            <span className="font-semibold text-slate-800">{entry.label}:</span>{' '}
                            <DiagnosticEntryValue value={entry.value} />
                        </p>
                    ))}
                </div>
            ) : null}

            {items.length > 0 ? (
                <div className="mt-4 space-y-2">
                    {items.map((item, index) => (
                        <article key={`${section?.key}-item-${index}`} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-xs font-semibold text-slate-900">{item.label || 'Signal'}</p>
                                {item?.meta?.created_at ? <span className="text-[11px] text-slate-500">{item.meta.created_at}</span> : null}
                            </div>
                            <p className="mt-1 text-xs text-slate-600">{item.value || 'No detail available.'}</p>
                            {item?.meta?.provider_key ? (
                                <p className="mt-1 text-[11px] text-slate-500">Provider: {item.meta.provider_key}</p>
                            ) : null}
                        </article>
                    ))}
                </div>
            ) : null}
        </section>
    );
}

function RecoveryMetricTile({ label, value, hint, meta, tone = 'slate' }) {
    const toneClass = {
        emerald: 'border-emerald-200 bg-emerald-50/70',
        rose: 'border-rose-200 bg-rose-50/70',
        amber: 'border-amber-200 bg-amber-50/70',
        sky: 'border-sky-200 bg-sky-50/70',
        slate: 'border-slate-200 bg-white text-slate-700',
    }[tone] || 'border-slate-200 bg-white text-slate-700';

    return (
        <div className={`min-h-[8.5rem] rounded-lg border px-4 py-4 ${toneClass}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-2 break-words text-2xl font-semibold tracking-tight text-slate-950">{value}</p>
            {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
            {meta ? <FxNormalizationNotice meta={meta} className="mt-2" /> : null}
        </div>
    );
}

function RecoveryScopeBar({
    platformOptions,
    platformFilter,
    onPlatformChange,
    fromDate,
    toDate,
    onFromDateChange,
    onToDateChange,
    isRangeInvalid,
    preset,
    onPresetChange,
    reportingCurrency,
}) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[190px]">
                    <FilterSelect
                        label="Market"
                        value={platformFilter}
                        onChange={(event) => onPlatformChange(event.target.value)}
                        options={platformOptionsWithFlags(platformOptions)}
                    />
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="recovery-from">From</label>
                    <input
                        id="recovery-from"
                        type="date"
                        value={fromDate}
                        onChange={(event) => onFromDateChange(event.target.value)}
                        className="crm-input w-auto min-w-[140px]"
                    />
                </div>

                <div className="flex flex-col gap-1">
                    <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="recovery-to">To</label>
                    <input
                        id="recovery-to"
                        type="date"
                        value={toDate}
                        onChange={(event) => onToDateChange(event.target.value)}
                        className="crm-input w-auto min-w-[140px]"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">
                    {[
                        ['30d', '30 days'],
                        ['90d', '90 days'],
                        ['ytd', 'YTD'],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onPresetChange(key)}
                            className={`rounded-md px-2.5 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                preset === key
                                    ? 'bg-white text-teal-700 shadow-sm ring-1 ring-slate-200'
                                    : 'text-slate-500 hover:bg-white hover:text-slate-700'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                    <span className={`rounded-md px-2.5 py-1.5 text-xs font-semibold ${
                        preset === 'custom'
                            ? 'bg-white text-slate-700 shadow-sm ring-1 ring-slate-200'
                            : 'text-slate-400'
                    }`}>
                        Custom
                    </span>
                </div>

                <span className="ml-auto inline-flex h-9 items-center rounded border border-teal-200 bg-teal-50 px-3 text-xs font-semibold text-teal-800">
                    {reportingCurrency?.isFlat ? `Converted to ${reportingCurrency.targetCurrency}` : 'Native currencies'}
                </span>

                {isRangeInvalid ? (
                    <span className="pb-2 text-xs font-medium text-rose-500">From must be before To</span>
                ) : null}
            </div>
        </div>
    );
}

function RecoveryPaymentMiniCard({ title, payment, tone = 'slate' }) {
    const toneClass = tone === 'rose'
        ? 'border-rose-200 bg-rose-50/60'
        : 'border-emerald-200 bg-emerald-50/60';

    if (!payment) {
        return null;
    }

    return (
        <div className={`rounded-xl border px-4 py-4 ${toneClass}`}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{title}</p>
                    <p className="mt-2 text-lg font-semibold text-slate-950">
                        {formatCurrency(payment.amount, payment.currency)}
                    </p>
                </div>
                <span className="rounded-full border border-white/70 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-600">
                    {titleize(payment.status)}
                </span>
            </div>
            <div className="mt-4 grid gap-2 text-xs text-slate-600">
                <p><span className="font-semibold text-slate-800">Reference:</span> {payment.transaction_reference || payment.reference_number || '—'}</p>
                <p><span className="font-semibold text-slate-800">Phone:</span> {payment.phone || payment.client?.phone_normalized || '—'}</p>
                <p><span className="font-semibold text-slate-800">Client:</span> {payment.client?.name || 'Unmatched customer'}</p>
                <p><span className="font-semibold text-slate-800">Market:</span> {payment.platform?.name || '—'}</p>
                <p><span className="font-semibold text-slate-800">Created:</span> {formatDateTime(payment.created_at)}</p>
                {payment.completed_at ? (
                    <p><span className="font-semibold text-slate-800">Completed:</span> {formatDateTime(payment.completed_at)}</p>
                ) : null}
            </div>
        </div>
    );
}

function RecoveryLedger({
    report,
    isLoading,
    selectedPair,
    onSelectPair,
    fallbackCurrency,
    reportingCurrency,
    platformOptions,
    platformFilter,
    onPlatformChange,
    fromDate,
    toDate,
    onFromDateChange,
    onToDateChange,
    isRangeInvalid,
    onPresetChange,
}) {
    const metrics = report?.metrics || {};
    const pairs = report?.recovered_pairs || [];
    const failed = Number(metrics.failed_payments || 0);
    const recovered = Number(metrics.recovered_payments || 0);
    const lost = Number(metrics.lost_payments || 0);
    const affected = Number(metrics.failed_customers || 0);
    const rate = Number(metrics.payment_recovery_rate || 0);
    const customerRate = Number(metrics.customer_recovery_rate || 0);
    const lostCustomers = Number(metrics.lost_customers || 0);
    const recoveredAmount = recoveryAmountDisplay(metrics, 'recovered', reportingCurrency, fallbackCurrency);
    const lostAmount = recoveryAmountDisplay(metrics, 'lost', reportingCurrency, fallbackCurrency);
    const failedAmount = recoveryAmountDisplay(metrics, 'failed', reportingCurrency, fallbackCurrency);
    const preset = detectPresetDateRange(fromDate, toDate, ['30d', '90d', 'ytd']);

    if (isLoading) {
        return (
            <section className="space-y-4">
                <RecoveryScopeBar
                    platformOptions={platformOptions}
                    platformFilter={platformFilter}
                    onPlatformChange={onPlatformChange}
                    fromDate={fromDate}
                    toDate={toDate}
                    onFromDateChange={onFromDateChange}
                    onToDateChange={onToDateChange}
                    isRangeInvalid={isRangeInvalid}
                    preset={preset}
                    onPresetChange={onPresetChange}
                    reportingCurrency={reportingCurrency}
                />
                <div className="animate-pulse space-y-4">
                    <div className="h-24 rounded-xl bg-slate-100" />
                    <div className="grid gap-3 md:grid-cols-5">
                        {Array.from({ length: 5 }).map((_, index) => (
                            <div key={index} className="h-24 rounded-lg bg-slate-100" />
                        ))}
                    </div>
                    <div className="h-64 rounded-lg bg-slate-100" />
                </div>
            </section>
        );
    }

    return (
        <section className="space-y-4">
            <RecoveryScopeBar
                platformOptions={platformOptions}
                platformFilter={platformFilter}
                onPlatformChange={onPlatformChange}
                fromDate={fromDate}
                toDate={toDate}
                onFromDateChange={onFromDateChange}
                onToDateChange={onToDateChange}
                isRangeInvalid={isRangeInvalid}
                preset={preset}
                onPresetChange={onPresetChange}
                reportingCurrency={reportingCurrency}
            />

            <div className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900">Failed payment recovery</h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Failed attempts matched to later successful payments from the same phone or client.
                        </p>
                    </div>
                    <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">
                        {report?.filters?.from || metrics.window?.from || 'Start'} to {report?.filters?.to || metrics.window?.to || 'today'}
                    </span>
                </div>

                <div className="mt-4 grid gap-3 md:grid-cols-5">
                    <RecoveryMetricTile label="Recovery rate" value={`${rate.toFixed(1)}%`} hint={`${recovered.toLocaleString()}/${failed.toLocaleString()} failed payments recovered`} tone="emerald" />
                    <RecoveryMetricTile label="Recovered value" value={recoveredAmount.value} hint={`${recovered.toLocaleString()} recovered payments`} meta={recoveredAmount.meta} tone="emerald" />
                    <RecoveryMetricTile label="Lost value" value={lostAmount.value} hint={`${lost.toLocaleString()} failed payments not recovered`} meta={lostAmount.meta} tone={lost > 0 ? 'rose' : 'slate'} />
                    <RecoveryMetricTile label="Customers affected" value={affected.toLocaleString()} hint={`${customerRate.toFixed(1)}% recovered · ${lostCustomers.toLocaleString()} unresolved`} tone="amber" />
                    <RecoveryMetricTile label="Failed value" value={failedAmount.value} hint="Total attempted value" meta={failedAmount.meta} tone="sky" />
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_400px]">
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">Recovered payment pairs</p>
                            <p className="mt-1 text-xs text-slate-500">Select a row to compare the failed attempt with the successful recovery.</p>
                        </div>
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-500">
                            {pairs.length.toLocaleString()} shown
                        </span>
                    </div>
                    {pairs.length === 0 ? (
                        <div className="px-4 py-12 text-center text-sm text-slate-500">
                            No recovered failed-payment pairs in this window.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Customer</th>
                                        <th className="px-4 py-3">Failed</th>
                                        <th className="px-4 py-3">Recovered</th>
                                        <th className="px-4 py-3">Time to recover</th>
                                        <th className="px-4 py-3">Market</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {pairs.map((pair) => {
                                        const active = selectedPair?.failed_payment?.id === pair.failed_payment?.id;
                                        const failedPayment = pair.failed_payment || {};
                                        const recoveredPayment = pair.recovered_payment || {};

                                        return (
                                            <tr
                                                key={`${failedPayment.id}-${recoveredPayment.id}`}
                                                onClick={() => onSelectPair(pair)}
                                                className={`cursor-pointer transition ${active ? 'bg-teal-50/70' : 'hover:bg-slate-50'}`}
                                            >
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold text-slate-900">{failedPayment.client?.name || recoveredPayment.client?.name || 'Unmatched customer'}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">{failedPayment.phone || recoveredPayment.phone || 'No phone captured'}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold text-rose-700">{formatCurrency(failedPayment.amount, failedPayment.currency)}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">{formatDateTime(failedPayment.created_at)}</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold text-emerald-700">{formatCurrency(recoveredPayment.amount, recoveredPayment.currency)}</p>
                                                    <p className="mt-0.5 text-xs text-slate-500">{formatDateTime(recoveredPayment.completed_at || recoveredPayment.created_at)}</p>
                                                </td>
                                                <td className="px-4 py-3 font-semibold text-slate-700">{formatRecoveryDuration(pair.recovery_minutes)}</td>
                                                <td className="px-4 py-3 text-slate-600">{failedPayment.platform?.name || recoveredPayment.platform?.name || '-'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <aside className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm xl:sticky xl:top-4 xl:self-start">
                    {selectedPair ? (
                        <div className="space-y-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Recovery detail</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Recovered in {formatRecoveryDuration(selectedPair.recovery_minutes)}.
                                </p>
                            </div>
                            <RecoveryPaymentMiniCard title="Failed attempt" payment={selectedPair.failed_payment} tone="rose" />
                            <RecoveryPaymentMiniCard title="Recovered payment" payment={selectedPair.recovered_payment} tone="emerald" />
                        </div>
                    ) : (
                        <div className="flex min-h-[24rem] items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
                            Select a recovered pair to compare the failed attempt with the successful recovery.
                        </div>
                    )}
                </aside>
            </div>
        </section>
    );
}

export default function Payments() {
    const allowedStatuses = new Set(['awaiting_payment', 'completed', 'expired', 'initiated', 'pending', 'failed', 'recovery_queue', 'reversed']);
    const allowedMatchFilters = new Set(['matched', 'unmatched']);
    const allowedHasDiscountFilters = new Set(['0', '1']);
    const allowedSourceFilters = new Set(['gateway', 'excel_import']);
    const allowedEnvironmentFilters = new Set(['production', 'sandbox']);
    const allowedConfidenceFilters = new Set(['high', 'medium', 'low']);
    const allowedReviewStateFilters = new Set(['open', 'manual_review', 'resolved']);
    const allowedResolutionFilters = new Set(['reversed', 'invalid_reference']);
    const allowedTestVisibilityFilters = new Set(['hide', 'include', 'only']);
    const allowedCustomerMixSegments = new Set(['new_active', 'existing_active', 'unattributed', 'other_matched']);
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const [workspaceTab, setWorkspaceTab] = useState(() => (
        searchParams.get('recovery_tab') === 'recovered' ? 'recovery' : 'payments'
    ));
    const toast = useToast();
    const { user, isLoading: authLoading } = useAuth();
    const canViewTests = user?.role === 'admin';
    const canManageBundleFinanceReview = ['admin', 'sub_admin'].includes(String(user?.role || ''));
    const canAccessFraudAudit = ['admin', 'sub_admin'].includes(String(user?.role || ''));
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [hasInitializedFrom, setHasInitializedFrom] = useState(false);
    const [statusFilter, setStatusFilter] = useState(() => {
        const requested = (searchParams.get('status') || '').trim();
        return allowedStatuses.has(requested) ? requested : '';
    });
    const [matchFilter, setMatchFilter] = useState(() => {
        const requested = (searchParams.get('matched') || '').trim();
        return allowedMatchFilters.has(requested) ? requested : '';
    });
    const [hasDiscountFilter, setHasDiscountFilter] = useState(() => {
        const requested = (searchParams.get('has_discount') || '').trim();
        return allowedHasDiscountFilters.has(requested) ? requested : '';
    });
    const [sourceFilter, setSourceFilter] = useState(() => {
        const requested = (searchParams.get('source') || '').trim();
        return allowedSourceFilters.has(requested) ? requested : '';
    });
    const [environmentFilter, setEnvironmentFilter] = useState(() => {
        const requested = (searchParams.get('environment') || '').trim().toLowerCase();
        return allowedEnvironmentFilters.has(requested) ? requested : '';
    });
    const [testVisibility, setTestVisibility] = useState(() => {
        const requested = (searchParams.get('test_visibility') || '').trim().toLowerCase();
        return allowedTestVisibilityFilters.has(requested) ? requested : 'hide';
    });

    useEffect(() => {
        if (workspaceTab === 'fraud' && !canAccessFraudAudit) {
            setWorkspaceTab('payments');
        }
    }, [canAccessFraudAudit, workspaceTab]);
    const [confidenceFilter, setConfidenceFilter] = useState(() => {
        const requested = (searchParams.get('match_confidence') || '').trim();
        return allowedConfidenceFilters.has(requested) ? requested : '';
    });
    const [reviewStateFilter, setReviewStateFilter] = useState(() => {
        const requested = (searchParams.get('review_state') || '').trim();
        return allowedReviewStateFilters.has(requested) ? requested : '';
    });
    const [resolutionFilter, setResolutionFilter] = useState(() => {
        const requested = (searchParams.get('resolution_code') || '').trim();
        return allowedResolutionFilters.has(requested) ? requested : '';
    });
    const [customerMixSegment, setCustomerMixSegment] = useState(() => {
        const requested = (searchParams.get('customer_mix_segment') || '').trim();
        return allowedCustomerMixSegments.has(requested) ? requested : '';
    });
    const [platformFilter, setPlatformFilter] = useState(() => {
        const requested = normalizePlatformFilter(searchParams.get('platform_id'));
        if (requested) {
            return requested;
        }

        if (typeof window === 'undefined') {
            return '';
        }

        return normalizePlatformFilter(window.localStorage.getItem(DASHBOARD_MARKET_STORAGE_KEY));
    });
    const [showAdvancedFilters, setShowAdvancedFilters] = useState(() => !!(hasDiscountFilter || sourceFilter || environmentFilter || confidenceFilter || reviewStateFilter || resolutionFilter || testVisibility !== 'hide'));
    const [selectedPayment, setSelectedPayment] = useState(null);
    const [selectedClientId, setSelectedClientId] = useState('');
    const [confirmReason, setConfirmReason] = useState('Manual payment match from queue');
    const [candidateSearchInput, setCandidateSearchInput] = useState('');
    const [candidateSearch, setCandidateSearch] = useState('');
    const [selectedRows, setSelectedRows] = useState([]);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [queueAutoMatchDialog, setQueueAutoMatchDialog] = useState({
        open: false,
        reason: 'Batch auto-match from payment queue',
        preview: null,
        step: 'reason', // 'reason' | 'preview'
    });
    const [failedAutoMatchDialog, setFailedAutoMatchDialog] = useState({ open: false, payment: null });
    const [retryStkDialog, setRetryStkDialog] = useState({ open: false, payment: null, reason: 'Retry STK from payment queue' });
    const [sendLinkDialog, setSendLinkDialog] = useState({
        open: false,
        payment: null,
        channel: 'sms',
        provider: '',
        phone: '',
        reason: 'Send payment link from CRM',
    });
    const [createSubDialog, setCreateSubDialog] = useState({
        open: false,
        payment: null,
        reason: 'Create subscription from matched payment',
        subscriptionLifecycle: 'new',
        subscriptionLifecycleReason: '',
    });
    const [diagnosticsDrawer, setDiagnosticsDrawer] = useState({ open: false, payment: null });
    const [providerStatusSnapshot, setProviderStatusSnapshot] = useState(null);
    const [sandboxReconcileSnapshot, setSandboxReconcileSnapshot] = useState(null);
    const [manualCloseDialog, setManualCloseDialog] = useState({
        open: false,
        payment: null,
        reason_code: '',
        reason_note: '',
        converted_payment_id: '',
    });
    const [manualRejectDialog, setManualRejectDialog] = useState({
        open: false,
        payment: null,
        reason: '',
    });
    const [markTestDialog, setMarkTestDialog] = useState({
        open: false,
        payment: null,
        reason: 'Exclude non-business or QA payment from sales-facing metrics.',
    });
    const [deleteTestDialog, setDeleteTestDialog] = useState({
        open: false,
        payment: null,
        reason: 'Permanently remove non-business test payment after audit snapshot review.',
    });
    const [bulkMarkTestDialog, setBulkMarkTestDialog] = useState({
        open: false,
        payments: [],
        reason: 'Exclude non-business or QA payments from sales-facing metrics.',
    });
    const [markAndDeleteDialog, setMarkAndDeleteDialog] = useState({
        open: false,
        payments: [],
        reason: 'Permanently remove non-business test payment.',
    });
    const [bundleDetailDialog, setBundleDetailDialog] = useState({ open: false, bundleId: null });
    const [voidBundleDialog, setVoidBundleDialog] = useState({
        open: false,
        bundleId: null,
        reasonCode: 'fraud_suspected',
        notes: '',
    });
    const proofObjectUrlCacheRef = useRef(new Map());
    const [manualProofPreview, setManualProofPreview] = useState({
        objectUrl: '',
        isLoading: false,
        error: '',
    });
    const [importDrawerOpen, setImportDrawerOpen] = useState(false);
    const [exportModalOpen, setExportModalOpen] = useState(false);
    const [selectedRecoveryPair, setSelectedRecoveryPair] = useState(null);
    const reportingCurrency = useReportingCurrency({ preferFlat: !platformFilter });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'payments-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platformOptions = integrationData?.platforms || [];
    const selectedPlatformCurrency = useMemo(() => {
        if (!platformFilter) {
            return '';
        }

        const selected = platformOptions.find((platform) => String(platform.platform_id) === String(platformFilter));
        return selected?.currency || '';
    }, [platformFilter, platformOptions]);

    const resolveCurrency = (currencyCode) => currencyCode || selectedPlatformCurrency || 'KES';

    const isRangeInvalid = Boolean(fromDate && toDate && fromDate > toDate);
    const activeFilters = useMemo(() => ({
        ...(search && { search }),
        ...(statusFilter && { status: statusFilter }),
        ...(matchFilter && { matched: matchFilter }),
        ...(hasDiscountFilter && { has_discount: hasDiscountFilter }),
        ...(platformFilter && { platform_id: Number(platformFilter) }),
        ...(sourceFilter && { source: sourceFilter }),
        ...((canViewTests && environmentFilter) && { environment: environmentFilter }),
        ...((canViewTests && testVisibility !== 'hide') && { test_visibility: testVisibility }),
        ...(confidenceFilter && { match_confidence: confidenceFilter }),
        ...(reviewStateFilter && { review_state: reviewStateFilter }),
        ...(resolutionFilter && { resolution_code: resolutionFilter }),
        ...(customerMixSegment && { customer_mix_segment: customerMixSegment }),
        ...(fromDate && { from: fromDate }),
        ...(toDate && { to: toDate }),
        ...reportingCurrency.queryParams,
    }), [
        search,
        statusFilter,
        matchFilter,
        hasDiscountFilter,
        platformFilter,
        sourceFilter,
        canViewTests,
        environmentFilter,
        testVisibility,
        confidenceFilter,
        reviewStateFilter,
        resolutionFilter,
        customerMixSegment,
        fromDate,
        toDate,
        reportingCurrency.queryParams,
    ]);

    const { data, isLoading } = useQuery({
        queryKey: ['payments', page, perPage, search, statusFilter, matchFilter, hasDiscountFilter, platformFilter, sourceFilter, environmentFilter, testVisibility, confidenceFilter, reviewStateFilter, resolutionFilter, customerMixSegment, fromDate, toDate, canViewTests, reportingCurrency.displayMode, reportingCurrency.targetCurrency],
        queryFn: () =>
            api.get('/crm/payments', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(matchFilter && { matched: matchFilter }),
                    ...(hasDiscountFilter && { has_discount: hasDiscountFilter }),
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                    ...(sourceFilter && { source: sourceFilter }),
                    ...((canViewTests && environmentFilter) && { environment: environmentFilter }),
                    ...((canViewTests && testVisibility !== 'hide') && { test_visibility: testVisibility }),
                    ...(confidenceFilter && { match_confidence: confidenceFilter }),
                    ...(reviewStateFilter && { review_state: reviewStateFilter }),
                    ...(resolutionFilter && { resolution_code: resolutionFilter }),
                    ...(customerMixSegment && { customer_mix_segment: customerMixSegment }),
                    ...(fromDate && { from: fromDate }),
                    ...(toDate && { to: toDate }),
                    ...reportingCurrency.queryParams,
                },
            }).then((response) => response.data),
        enabled: !isRangeInvalid,
    });

    const {
        data: recoveryReportData,
        isLoading: recoveryReportLoading,
    } = useQuery({
        queryKey: ['payments-recovery-report', platformFilter, fromDate, toDate, reportingCurrency.displayMode, reportingCurrency.targetCurrency],
        queryFn: () => api.get('/crm/payments/recovery-report', {
            params: {
                ...(platformFilter && { platform_id: Number(platformFilter) }),
                ...(fromDate && { from: fromDate }),
                ...(toDate && { to: toDate }),
                limit: 100,
                ...reportingCurrency.queryParams,
            },
        }).then((response) => response.data),
        enabled: workspaceTab === 'recovery' && !isRangeInvalid,
    });

    // Hydrate fromDate from baseline cutoff on first successful response
    useEffect(() => {
        if (!hasInitializedFrom && data?.baseline_cutoff) {
            setFromDate(data.baseline_cutoff);
            setHasInitializedFrom(true);
        }
    }, [data?.baseline_cutoff, hasInitializedFrom]);

    useEffect(() => {
        if (workspaceTab !== 'recovery') {
            return;
        }

        const pairs = recoveryReportData?.recovered_pairs || [];
        if (pairs.length === 0) {
            setSelectedRecoveryPair(null);
            return;
        }

        const stillSelected = pairs.some((pair) => (
            pair.failed_payment?.id === selectedRecoveryPair?.failed_payment?.id
            && pair.recovered_payment?.id === selectedRecoveryPair?.recovered_payment?.id
        ));

        if (!stillSelected) {
            setSelectedRecoveryPair(pairs[0]);
        }
    }, [recoveryReportData?.recovered_pairs, selectedRecoveryPair, workspaceTab]);

    const { data: candidatesData, isLoading: candidatesLoading } = useQuery({
        queryKey: ['payment-candidates', selectedPayment?.id, candidateSearch],
        queryFn: () =>
            api.get(`/crm/payments/${selectedPayment.id}/candidates`, {
                params: {
                    ...(candidateSearch ? { search: candidateSearch } : {}),
                },
        }).then((response) => response.data),
        enabled: !!selectedPayment?.id,
    });

    const {
        data: diagnosticsData,
        isLoading: diagnosticsLoading,
        error: diagnosticsError,
    } = useQuery({
        queryKey: ['payment-diagnostics', diagnosticsDrawer.payment?.id],
        queryFn: () => api.get(`/crm/payments/${diagnosticsDrawer.payment.id}/diagnostics`).then((response) => response.data),
        enabled: diagnosticsDrawer.open && !!diagnosticsDrawer.payment?.id,
    });

    const {
        data: bundleDetailData,
        isLoading: bundleDetailLoading,
    } = useQuery({
        queryKey: ['bundle-detail', bundleDetailDialog.bundleId],
        queryFn: () => api.get(`/crm/manual-payment-bundles/${bundleDetailDialog.bundleId}`).then((response) => response.data),
        enabled: bundleDetailDialog.open && !!bundleDetailDialog.bundleId,
    });

    const approveBundleMutation = useMutation({
        mutationFn: (bundleId) =>
            api.post(`/crm/manual-payment-bundles/${bundleId}/approve`).then((response) => response.data),
        onSuccess: (_, bundleId) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['bundle-detail', bundleId] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success('Bundle approved. Child payments marked resolved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to approve bundle.');
        },
    });

    const voidBundleMutation = useMutation({
        mutationFn: ({ bundleId, reason_code, notes }) =>
            api.post(`/crm/manual-payment-bundles/${bundleId}/void`, { reason_code, notes }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['bundle-detail', voidBundleDialog.bundleId] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setVoidBundleDialog({ open: false, bundleId: null, reasonCode: 'fraud_suspected', notes: '' });
            setBundleDetailDialog({ open: false, bundleId: null });
            toast.success('Bundle voided successfully. All child subscriptions deactivated.');
        },
        onError: (error) => {
            const divergence = error?.response?.data?.errors?.divergence;
            if (divergence && Array.isArray(divergence)) {
                const divergentDeals = divergence.map((d) => typeof d === 'object' ? `Deal #${d.deal_id}: ${d.reason}` : String(d)).join('\n');
                toast.error(`Cannot void: child subscriptions have diverged.\n${divergentDeals}`);
            } else {
                toast.error(error?.response?.data?.message || 'Failed to void bundle.');
            }
        },
    });

    const autoMatchMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/auto-match`).then((response) => response.data),
        onSuccess: (_, paymentId) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', paymentId] });
            if (selectedPayment?.id) {
                queryClient.invalidateQueries({ queryKey: ['payment-candidates', selectedPayment.id] });
            }
            toast.success('Auto-match completed for payment.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Auto-match failed for payment.');
        },
    });

    const confirmMatchMutation = useMutation({
        mutationFn: ({ paymentId, clientId, reason }) =>
            api.post(`/crm/payments/${paymentId}/confirm-match`, {
                client_id: clientId,
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setSelectedPayment(null);
            setSelectedClientId('');
            setConfirmReason('Manual payment match from queue');
            toast.success('Payment match confirmed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual payment match failed.');
        },
    });

    const batchMatchDryRunMutation = useMutation({
        mutationFn: (reason) =>
            api.post('/crm/payments/batch-match', {
                reason,
                dry_run: true,
            }).then((response) => response.data),
        onSuccess: (result) => {
            setQueueAutoMatchDialog((d) => ({ ...d, preview: result, step: 'preview' }));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Auto-match preview failed.');
            setQueueAutoMatchDialog((d) => ({ ...d, open: false }));
        },
    });

    const batchMatchMutation = useMutation({
        mutationFn: (reason) =>
            api.post('/crm/payments/batch-match', {
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' });
            toast.success('Queue auto-match completed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Queue auto-match failed.');
        },
    });

    const retryStkMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/retry-stk`, { reason: reason || undefined }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setRetryStkDialog({ open: false, payment: null, reason: 'Retry STK from payment queue' });
            toast.success('STK push sent. Customer should complete the request on their phone.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Retry STK failed.');
        },
    });

    const sendPaymentLinkMutation = useMutation({
        mutationFn: ({ paymentId, channel, provider, phone, reason }) =>
            api.post(`/crm/payments/${paymentId}/send-payment-link`, {
                channel,
                ...(provider ? { provider } : {}),
                ...(phone && { phone: phone.trim() }),
                reason: reason || undefined,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setSendLinkDialog({ open: false, payment: null, channel: 'sms', provider: '', phone: '', reason: 'Send payment link from CRM' });
            toast.success(variables.channel === 'whatsapp' ? 'Payment link sent by WhatsApp.' : 'Payment link sent by SMS.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Send payment link failed.');
        },
    });

    const createSubscriptionMutation = useMutation({
        mutationFn: ({ paymentId, reason, subscriptionLifecycle, subscriptionLifecycleReason }) =>
            api.post(`/crm/payments/${paymentId}/create-subscription`, {
                reason: reason || undefined,
                subscription_lifecycle: subscriptionLifecycle || undefined,
                subscription_lifecycle_reason: subscriptionLifecycleReason || undefined,
            }).then((response) => response.data),
        onSuccess: (result, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setCreateSubDialog({
                open: false,
                payment: null,
                reason: 'Create subscription from matched payment',
                subscriptionLifecycle: 'new',
                subscriptionLifecycleReason: '',
            });
            toast.success(`Subscription created (Deal #${result.deal?.id}). Expires ${new Date(result.deal?.expires_at).toLocaleDateString()}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Create subscription failed.');
        },
    });

    const manualCloseMutation = useMutation({
        mutationFn: ({ paymentId, reasonCode, reasonNote, convertedPaymentId }) =>
            api.post(`/crm/payments/${paymentId}/manual-close`, {
                reason_code: reasonCode,
                reason_note: reasonNote,
                ...(convertedPaymentId ? { converted_payment_id: Number(convertedPaymentId) } : {}),
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            queryClient.invalidateQueries({ queryKey: ['clients-conversion-queue'] });
            setManualCloseDialog({ open: false, payment: null, reason_code: '', reason_note: '', converted_payment_id: '' });
            toast.success('Payment closed manually.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual payment closure failed.');
        },
    });

    const manualApproveMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/manual-approve`, {
                ...(reason ? { reason } : {}),
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            toast.success('Manual payment approved and subscription activated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Approving the manual payment failed.');
        },
    });

    const manualVerifyMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/manual-verify`, {
                ...(reason ? { reason } : {}),
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            toast.success('Manual payment marked as verified.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Verifying the manual payment failed.');
        },
    });

    const manualRejectMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/manual-reject`, {
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setManualRejectDialog({ open: false, payment: null, reason: '' });
            toast.success('Manual payment rejected and customer state updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Rejecting the manual payment failed.');
        },
    });

    const markTestMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/mark-test`, {
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setMarkTestDialog({
                open: false,
                payment: null,
                reason: 'Exclude non-business or QA payment from sales-facing metrics.',
            });
            toast.success('Payment marked as test and hidden from default business views.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Marking the payment as test failed.');
        },
    });

    const deleteTestMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.delete(`/crm/payments/${paymentId}/delete-test`, {
                data: { reason },
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.removeQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setDeleteTestDialog({
                open: false,
                payment: null,
                reason: 'Permanently remove non-business test payment after audit snapshot review.',
            });
            toast.success('Test payment deleted permanently.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Deleting the test payment failed.');
        },
    });

    const bulkMarkTestMutation = useMutation({
        mutationFn: async ({ payments, reason }) => {
            const toMark = payments.filter((p) => !isExplicitTestPayment(p));
            const skipped = payments.length - toMark.length;
            const results = await Promise.allSettled(
                toMark.map((p) => api.post(`/crm/payments/${p.id}/mark-test`, { reason }))
            );
            const succeeded = results.filter((r) => r.status === 'fulfilled').length;
            const failed = results.filter((r) => r.status === 'rejected').length;
            return { succeeded, failed, skipped };
        },
        onSuccess: ({ succeeded, failed, skipped }) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((v) => v + 1);
            setBulkMarkTestDialog((d) => ({ ...d, open: false }));
            const parts = [];
            if (succeeded) parts.push(`${succeeded} marked as test`);
            if (skipped) parts.push(`${skipped} already test`);
            if (failed) parts.push(`${failed} failed`);
            toast[failed ? 'warning' : 'success'](parts.join(', ') + '.');
        },
        onError: () => toast.error('Bulk mark-as-test failed.'),
    });

    const markAndDeleteMutation = useMutation({
        mutationFn: async ({ payments, reason }) => {
            const tally = { deleted: 0, keptAsTest: 0, errors: 0 };
            for (const p of payments) {
                if (!isExplicitTestPayment(p)) {
                    try {
                        await api.post(`/crm/payments/${p.id}/mark-test`, { reason });
                    } catch {
                        tally.errors++;
                        continue;
                    }
                }
                try {
                    await api.delete(`/crm/payments/${p.id}/delete-test`, { data: { reason } });
                    tally.deleted++;
                } catch (err) {
                    const blockers = err?.response?.data?.blockers;
                    if (err?.response?.status === 422 && blockers?.length) {
                        tally.keptAsTest++;
                    } else {
                        tally.errors++;
                    }
                }
            }
            return tally;
        },
        onSuccess: ({ deleted, keptAsTest, errors }) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((v) => v + 1);
            setMarkAndDeleteDialog((d) => ({ ...d, open: false }));
            const parts = [];
            if (deleted) parts.push(`${deleted} deleted`);
            if (keptAsTest) parts.push(`${keptAsTest} kept as test (linked to live records)`);
            if (errors) parts.push(`${errors} failed`);
            toast[errors || keptAsTest ? 'warning' : 'success'](parts.join(', ') + '.');
            if (keptAsTest) {
                toast.info('Some payments were marked as test but not deleted because they are linked to active deals or transactions.');
            }
        },
        onError: () => toast.error('Mark & delete failed.'),
    });

    const providerStatusMutation = useMutation({
        mutationFn: (paymentId) =>
            api.post(`/crm/payments/${paymentId}/check-provider-status`).then((response) => response.data),
        onSuccess: (result, paymentId) => {
            setProviderStatusSnapshot(result);
            setSandboxReconcileSnapshot(null);
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', paymentId] });
            toast.success(`Provider currently reports this payment as ${titleize(result.status)}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Provider status check failed.');
        },
    });

    const sandboxReconcileMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/sandbox-reconcile`, {
                reason: reason || undefined,
            }).then((response) => response.data),
        onSuccess: (result, variables) => {
            setSandboxReconcileSnapshot(result);
            setProviderStatusSnapshot(result.provider_snapshot || null);
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });

            if (result.already_reconciled) {
                toast.info(result.message || 'Sandbox payment was already reconciled.');
                return;
            }

            if (result.provider_snapshot?.status === 'failed') {
                toast.warning(result.message || 'Sandbox payment was reconciled as failed.');
                return;
            }

            if (result.provider_snapshot?.status === 'pending') {
                toast.info(result.message || 'Provider still reports this sandbox payment as pending.');
                return;
            }

            toast.success(result.message || 'Sandbox payment reconciled.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Sandbox reconcile failed.');
        },
    });

    const bulkAutoMatchMutation = useMutation({
        mutationFn: async (rows) => {
            const targets = rows.filter((row) => row.status !== 'failed');
            const results = await Promise.allSettled(
                targets.map((row) => api.post(`/crm/payments/${row.id}/auto-match`)),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { success, failed, total: targets.length };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Auto-match processed ${result.success}/${result.total} selected payments (${result.failed} failed).`);
                return;
            }
            toast.success(`Auto-match processed ${result.success}/${result.total} selected payments.`);
        },
    });

    const bulkConfirmMutation = useMutation({
        mutationFn: async (rows) => {
            let confirmed = 0;
            let autoMatched = 0;
            let skipped = 0;
            let failed = 0;

            for (const row of rows) {
                try {
                    if (row.client_id) {
                        skipped += 1;
                        continue;
                    }

                    const candidateResponse = await api.get(`/crm/payments/${row.id}/candidates`);
                    const candidates = candidateResponse.data?.data || [];

                    if (candidates.length === 1) {
                        await api.post(`/crm/payments/${row.id}/confirm-match`, {
                            client_id: candidates[0].id,
                            reason: 'Bulk confirm from payment queue',
                        });
                        confirmed += 1;
                    } else {
                        await api.post(`/crm/payments/${row.id}/auto-match`);
                        autoMatched += 1;
                    }
                } catch (error) {
                    failed += 1;
                }
            }

            return { confirmed, autoMatched, skipped, failed, total: rows.length };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Bulk confirm completed with issues: ${result.confirmed} direct, ${result.autoMatched} auto-match, ${result.failed} failed.`);
                return;
            }
            toast.success(`Bulk confirm done: ${result.confirmed} direct, ${result.autoMatched} auto-match, ${result.skipped} skipped.`);
        },
    });

    const reviewStateMutation = useMutation({
        mutationFn: ({ paymentId, state, reason }) =>
            api.post(`/crm/payments/${paymentId}/review-state`, {
                state,
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            toast.success(variables.state === 'resolved' ? 'Payment review marked resolved.' : 'Payment moved to manual review.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Updating review state failed.');
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const openImportTemplate = () => {
        if (typeof window === 'undefined') {
            return;
        }

        window.open('/api/crm/payments/import/template', '_blank', 'noopener,noreferrer');
    };

    const openManualMatch = (paymentRow) => {
        setSelectedPayment(paymentRow);
        setSelectedClientId('');
        setConfirmReason('Manual payment match from queue');
        setCandidateSearch('');
        setCandidateSearchInput('');
    };

    const openManualCloseDialog = (paymentRow) => {
        setManualCloseDialog({
            open: true,
            payment: paymentRow,
            reason_code: inferPaymentCloseReason(paymentRow),
            reason_note: defaultPaymentCloseNote(paymentRow),
            converted_payment_id: '',
        });
    };

    const openDiagnostics = (paymentRow) => {
        setDiagnosticsDrawer({ open: true, payment: paymentRow });
    };

    const closeDiagnostics = () => {
        setDiagnosticsDrawer({ open: false, payment: null });
    };

    const loadProofObjectUrl = useCallback(async (submission) => {
        const key = proofCacheKey(submission);
        const path = proofRequestPath(submission);

        if (!key || !path) {
            throw new Error('Proof image unavailable for this submission.');
        }

        const cached = proofObjectUrlCacheRef.current.get(key);
        if (cached?.objectUrl) {
            return cached.objectUrl;
        }

        if (cached?.promise) {
            return cached.promise;
        }

        const promise = api.get(path, { responseType: 'blob' })
            .then((response) => {
                const objectUrl = window.URL.createObjectURL(response.data);
                proofObjectUrlCacheRef.current.set(key, { objectUrl });
                return objectUrl;
            })
            .catch((error) => {
                proofObjectUrlCacheRef.current.delete(key);
                throw error;
            });

        proofObjectUrlCacheRef.current.set(key, { promise });

        return promise;
    }, []);

    const openManualProof = async (submission) => {
        if (!submission || typeof window === 'undefined') {
            return;
        }

        const pendingWindow = window.open('', '_blank');

        try {
            const objectUrl = await loadProofObjectUrl(submission);

            if (pendingWindow) {
                pendingWindow.opener = null;
                pendingWindow.location.href = objectUrl;
                return;
            }

            window.open(objectUrl, '_blank', 'noopener,noreferrer');
        } catch (error) {
            if (pendingWindow) {
                pendingWindow.close();
            }

            toast.error(error?.response?.data?.message || error?.message || 'Unable to load proof image.');
        }
    };

    useEffect(() => () => {
        proofObjectUrlCacheRef.current.forEach((entry) => {
            if (entry?.objectUrl) {
                window.URL.revokeObjectURL(entry.objectUrl);
            }
        });
        proofObjectUrlCacheRef.current.clear();
    }, []);

    const triggerRecommendation = (actionKey, paymentRow) => {
        if (!paymentRow) return;

        if (actionKey === 'retry_stk') {
            closeDiagnostics();
            setRetryStkDialog({ open: true, payment: paymentRow, reason: 'Retry STK from diagnostics drawer' });
            return;
        }

        if (actionKey === 'send_link') {
            closeDiagnostics();
            setSendLinkDialog({
                open: true,
                payment: paymentRow,
                channel: 'sms',
                provider: '',
                phone: paymentRow.phone || '',
                reason: 'Send payment link from diagnostics drawer',
            });
            return;
        }

        if (actionKey === 'auto_match') {
            autoMatchMutation.mutate(paymentRow.id);
            return;
        }

        if (actionKey === 'manual_match') {
            closeDiagnostics();
            openManualMatch(paymentRow);
            return;
        }

        if (actionKey === 'create_subscription') {
            if (isTestPayment(paymentRow)) {
                toast.info('Test-classified payments cannot create live subscriptions.');
                return;
            }
            closeDiagnostics();
                            setCreateSubDialog({
                                open: true,
                                payment: paymentRow,
                                reason: 'Create subscription from diagnostics drawer',
                                subscriptionLifecycle: resolvePredictedSubscriptionLifecycle(paymentRow),
                                subscriptionLifecycleReason: '',
                            });
            return;
        }

        if (actionKey === 'manual_close') {
            closeDiagnostics();
            openManualCloseDialog(paymentRow);
            return;
        }

        if (actionKey === 'manual_review') {
            reviewStateMutation.mutate({
                paymentId: paymentRow.id,
                state: 'manual_review',
                reason: 'Marked for manual review from diagnostics drawer',
            });
            return;
        }

        if (actionKey === 'check_provider_status') {
            providerStatusMutation.mutate(paymentRow.id);
            return;
        }

        if (actionKey === 'sandbox_reconcile') {
            sandboxReconcileMutation.mutate({
                paymentId: paymentRow.id,
                reason: 'Sandbox reconcile from diagnostics drawer',
            });
            return;
        }

        if (actionKey === 'wait_callback') {
            toast.info('Keep monitoring callback updates for this payment before retrying.');
        }
    };

    const rows = data?.data || [];

    const summary = useMemo(() => {
        if (data?.stats) {
            return {
                awaitingCount: Number(data.stats.pending || 0),
                awaitingAmount: toAmount(data.stats.pending_amount),
                awaitingBreakdown: data.stats.pending_amount_breakdown ?? {},
                awaitingNormalized: data.stats.pending_normalized_amount,
                awaitingNormalizationMeta: data.stats.pending_normalization_meta,
                confirmedCount: Number(data.stats.confirmed || 0),
                confirmedAmount: toAmount(data.stats.confirmed_amount),
                confirmedBreakdown: data.stats.confirmed_amount_breakdown ?? {},
                confirmedNormalized: data.stats.confirmed_normalized_amount,
                confirmedNormalizationMeta: data.stats.confirmed_normalization_meta,
                discountedCount: Number(data.stats.discounted || 0),
                discountedAmount: toAmount(data.stats.discounted_amount),
                discountedBreakdown: data.stats.discounted_amount_breakdown ?? {},
                discountedNormalized: data.stats.discounted_normalized_amount,
                discountedNormalizationMeta: data.stats.discounted_normalization_meta,
                reversedCount: Number(data.stats.reversed || 0),
                reversedAmount: toAmount(data.stats.reversed_amount),
                reversedBreakdown: data.stats.reversed_amount_breakdown ?? {},
                reversedNormalized: data.stats.reversed_normalized_amount,
                reversedNormalizationMeta: data.stats.reversed_normalization_meta,
                unmatchedCount: Number((data.stats.unmatched_review ?? data.stats.unmatched) || 0),
                unmatchedAmount: toAmount(data.stats.unmatched_review_amount),
                unmatchedBreakdown: data.stats.unmatched_review_amount_breakdown ?? {},
                unmatchedNormalized: data.stats.unmatched_review_normalized_amount,
                unmatchedNormalizationMeta: data.stats.unmatched_review_normalization_meta,
                failedCount: Number(data.stats.failed || 0),
                failedAmount: toAmount(data.stats.failed_amount),
                failedBreakdown: data.stats.failed_amount_breakdown ?? {},
                failedNormalized: data.stats.failed_normalized_amount,
                failedNormalizationMeta: data.stats.failed_normalization_meta,
            };
        }

        const awaitingRows = rows.filter((row) => ['initiated', 'pending'].includes(row.status));
        const completedRows = rows.filter((row) => SUCCESSFUL_PAYMENT_STATUSES.includes(row.status));
        const discountedRows = completedRows.filter((row) => row.deal?.discount_percentage);
        // Count unique deals (not payment rows) and sum foregone = original_amount × pct
        const discountedDealMap = new Map();
        for (const row of discountedRows) {
            if (row.deal?.id != null && !discountedDealMap.has(row.deal.id)) {
                discountedDealMap.set(row.deal.id, row.deal);
            }
        }
        const discountedForgone = [...discountedDealMap.values()].reduce((sum, deal) => {
            const orig = toAmount(deal.original_amount);
            const pct = parseFloat(deal.discount_percentage) || 0;
            return sum + (orig * pct / 100);
        }, 0);
        const reversedRows = rows.filter((row) => row.status === 'reversed'
            || (SUCCESSFUL_PAYMENT_STATUSES.includes(row.status) && String(row.resolution_code || '').toLowerCase() === 'reversed'));
        const unmatchedRows = completedRows.filter((row) => !row.client_id);
        const failedRows = rows.filter((row) => row.status === 'failed');
        const sumAmount = (list) => list.reduce((sum, row) => sum + toAmount(row.amount), 0);

        return {
            awaitingCount: awaitingRows.length,
            awaitingAmount: sumAmount(awaitingRows),
            confirmedCount: completedRows.length,
            confirmedAmount: sumAmount(completedRows),
            discountedCount: discountedDealMap.size,
            discountedAmount: discountedForgone,
            reversedCount: reversedRows.length,
            reversedAmount: sumAmount(reversedRows),
            reversedBreakdown: {},
            unmatchedCount: unmatchedRows.length,
            unmatchedAmount: sumAmount(unmatchedRows),
            failedCount: failedRows.length,
            failedAmount: sumAmount(failedRows),
        };
    }, [data?.stats, rows]);

    const statsScope = String(data?.stats_scope || 'business');
    const visibilityMode = canViewTests ? testVisibility : 'hide';
    const normalizedCurrency = data?.stats?.normalized_currency || reportingCurrency.targetCurrency;
    const renderSummaryAmount = (breakdown, scalarAmount, normalizedAmount, normalizationMeta) => {
        if (reportingCurrency.isFlat && normalizedAmount !== null && normalizedAmount !== undefined) {
            return (
                <div>
                    <p className="mt-1.5 text-sm font-semibold text-slate-700">{formatCurrency(normalizedAmount, normalizedCurrency)}</p>
                    <FxNormalizationNotice meta={normalizationMeta} className="mt-2" />
                </div>
            );
        }

        if (reportingCurrency.isFlat) {
            return (
                <div>
                    <CurrencyAmount breakdown={breakdown} scalarAmount={scalarAmount} fallbackCurrency={resolveCurrency(null)} className="mt-1.5 text-sm font-semibold text-slate-700" stackClassName="leading-snug" />
                    <FxNormalizationNotice meta={normalizationMeta} className="mt-2" />
                </div>
            );
        }

        return (
            <CurrencyAmount breakdown={breakdown} scalarAmount={scalarAmount} fallbackCurrency={resolveCurrency(null)} className="mt-1.5 text-sm font-semibold text-slate-700" stackClassName="leading-snug" />
        );
    };
    const customerMix = data?.stats?.customer_mix || null;
    const customerMixBuckets = customerMix?.buckets || {};
    const customerMixPreset = detectPresetDateRange(fromDate, toDate);
    const customerMixCards = ['new_active', 'existing_active', 'unattributed', 'other_matched'].map((bucketKey) => ({
        key: bucketKey,
        meta: customerMixBucketMeta(bucketKey),
        bucket: customerMixBuckets[bucketKey] || {},
    }));

    const activeMetric = useMemo(() => {
        if (customerMixSegment) return '';
        if (statusFilter === 'awaiting_payment') return 'awaiting';
        if (statusFilter === 'completed' && hasDiscountFilter === '1') return 'discounted';
        if (statusFilter === 'completed' && matchFilter === '') return 'confirmed';
        if (statusFilter === 'reversed') return 'reversed';
        if (statusFilter === 'completed' && matchFilter === 'unmatched') return 'unmatched';
        if (statusFilter === 'failed') return 'failed';
        return '';
    }, [customerMixSegment, statusFilter, matchFilter, hasDiscountFilter]);

    const applyMetricFilter = (metricKey) => {
        setCustomerMixSegment('');
        if (metricKey === 'awaiting') {
            setStatusFilter('awaiting_payment');
            setMatchFilter('');
            setHasDiscountFilter('');
        } else if (metricKey === 'confirmed') {
            setStatusFilter('completed');
            setMatchFilter('');
            setHasDiscountFilter('');
        } else if (metricKey === 'discounted') {
            setStatusFilter('completed');
            setMatchFilter('');
            setHasDiscountFilter('1');
        } else if (metricKey === 'reversed') {
            setStatusFilter('reversed');
            setMatchFilter('');
            setHasDiscountFilter('');
        } else if (metricKey === 'unmatched') {
            setStatusFilter('completed');
            setMatchFilter('unmatched');
            setHasDiscountFilter('');
        } else if (metricKey === 'failed') {
            setStatusFilter('failed');
            setMatchFilter('');
            setHasDiscountFilter('');
        }
        setPage(1);
    };

    const applyCustomerMixPreset = (preset) => {
        const range = resolvePresetDateRange(preset);
        if (!range.from || !range.to) {
            return;
        }

        setFromDate(range.from);
        setToDate(range.to);
        setHasInitializedFrom(true);
        setPage(1);
    };

    const applyRecoveryPreset = (preset) => {
        const range = resolvePresetDateRange(preset);
        if (!range.from || !range.to) {
            return;
        }

        setFromDate(range.from);
        setToDate(range.to);
        setHasInitializedFrom(true);
        setPage(1);
    };

    const applyCustomerMixSegment = (segmentKey) => {
        const nextSegment = customerMixSegment === segmentKey ? '' : segmentKey;
        setCustomerMixSegment(nextSegment);
        setStatusFilter('completed');
        setMatchFilter(nextSegment ? (segmentKey === 'unattributed' ? 'unmatched' : 'matched') : '');
        setHasDiscountFilter('');
        setPage(1);
    };

    const diagnosticsPayment = diagnosticsData?.payment || diagnosticsDrawer.payment;
    const manualSubmissionData = diagnosticsData?.manual_submission || diagnosticsPayment?.manual_submission || null;
    const manualSubmissionCustomerState = manualCustomerStateMeta(
        manualSubmissionData?.customer_state || diagnosticsPayment?.manual_submission?.customer_state,
    );
    const manualSubmissionActionState = manualSubmissionAction(diagnosticsPayment);
    const linkProxyData = diagnosticsData?.link_proxy || null;
    const structuredDiagnostics = diagnosticsData?.structured_diagnostics || null;
    const structuredDiagnosticsSections = Array.isArray(structuredDiagnostics?.sections) ? structuredDiagnostics.sections : [];
    const actionCapabilities = diagnosticsData?.action_capabilities || {};
    const providerStatusDisplay = providerStatusSnapshot || linkProxyData?.last_provider_check || null;
    const diagnosticsRecommendations = diagnosticsData?.recommendations || [];
    const providerCheckEligible = Boolean(actionCapabilities.check_provider_status);
    const providerCheckReady = providerCheckEligible && (!linkProxyData || !!linkProxyData.initialized_at || !!linkProxyData.provider_reference);
    const sandboxReconcileEligible = Boolean(actionCapabilities.sandbox_reconcile);
    const providerReference = providerStatusSnapshot?.provider_reference
        || linkProxyData?.provider_reference
        || diagnosticsPayment?.transaction_reference
        || diagnosticsPayment?.reference_number
        || '—';

    useEffect(() => {
        if (!diagnosticsDrawer.open || !manualSubmissionData?.proof_url) {
            setManualProofPreview({ objectUrl: '', isLoading: false, error: '' });
            return undefined;
        }

        let cancelled = false;
        setManualProofPreview({ objectUrl: '', isLoading: true, error: '' });

        loadProofObjectUrl(manualSubmissionData)
            .then((objectUrl) => {
                if (!cancelled) {
                    setManualProofPreview({ objectUrl, isLoading: false, error: '' });
                }
            })
            .catch((error) => {
                if (!cancelled) {
                    setManualProofPreview({
                        objectUrl: '',
                        isLoading: false,
                        error: error?.response?.data?.message || error?.message || 'Proof image unavailable for this submission.',
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [diagnosticsDrawer.open, loadProofObjectUrl, manualSubmissionData?.id, manualSubmissionData?.proof_url]);

    const linkProxySteps = useMemo(() => {
        if (!linkProxyData) {
            return [];
        }

        const callbackAt = linkProxyData.callback_at || (diagnosticsPayment?.status === 'completed'
            ? (diagnosticsPayment?.completed_at || diagnosticsPayment?.updated_at)
            : null);

        return [
            {
                key: 'sent',
                label: 'Sent',
                timestamp: linkProxyData.sent_at,
                helper: 'Customer payment link was issued from CRM.',
            },
            {
                key: 'opened',
                label: 'Opened',
                timestamp: linkProxyData.opened_at,
                helper: linkProxyData.open_count
                    ? `Opened ${linkProxyData.open_count} time${linkProxyData.open_count === 1 ? '' : 's'}.`
                    : 'Awaiting first customer open.',
            },
            {
                key: 'initialized',
                label: 'Checkout initialized',
                timestamp: linkProxyData.initialized_at,
                helper: linkProxyData.provider_reference
                    ? `Provider ref ${linkProxyData.provider_reference}`
                    : 'Provider session not started yet.',
            },
            {
                key: 'callback',
                label: 'Provider callback',
                timestamp: callbackAt,
                helper: callbackAt
                    ? 'CRM has provider completion evidence for this payment.'
                    : 'No provider callback recorded yet.',
            },
            {
                key: 'completed',
                label: 'Completed',
                timestamp: diagnosticsPayment?.status === 'completed'
                    ? (diagnosticsPayment?.completed_at || diagnosticsPayment?.updated_at)
                    : null,
                helper: diagnosticsPayment?.status === 'completed'
                    ? 'Payment is marked completed in CRM.'
                    : 'Payment is still awaiting completion.',
            },
        ];
    }, [diagnosticsPayment?.completed_at, diagnosticsPayment?.status, diagnosticsPayment?.updated_at, linkProxyData]);
    const diagnosticsSummary = useMemo(() => ({
        headline: buildDiagnosisHeadline(diagnosticsPayment, diagnosticsData?.failure),
        tone: diagnosticToneClasses(
            unresolvedManualReviewStatus(diagnosticsPayment)?.status
                || (diagnosticsPayment?.status === 'reversed'
                ? 'failed'
                : diagnosticsPayment?.status)
        ),
    }), [diagnosticsData?.failure, diagnosticsPayment]);
    const primaryRecommendation = useMemo(
        () => diagnosticsRecommendations.find((item) => item.recommended) || diagnosticsRecommendations[0] || null,
        [diagnosticsRecommendations],
    );
    const secondaryRecommendations = useMemo(
        () => diagnosticsRecommendations.filter((item) => item.key !== primaryRecommendation?.key),
        [diagnosticsRecommendations, primaryRecommendation],
    );
    const browserContextState = useMemo(
        () => resolveBrowserContextState(diagnosticsData?.browser_meta || null),
        [diagnosticsData?.browser_meta],
    );
    const diagnosticsFreshness = diagnosticsPayment?.updated_at
        || providerStatusDisplay?.checked_at
        || diagnosticsData?.attempts?.[0]?.created_at
        || null;
    const jumpToDiagnosticsSection = (sectionKey) => {
        if (typeof document === 'undefined') {
            return;
        }

        const node = document.getElementById(`payment-diagnostics-${sectionKey}`);
        if (node) {
            node.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };
    const sendLinkProviderEntries = useMemo(() => {
        const providers = sendLinkDialog.payment?.platform?.payment_link_providers?.providers || {};
        return Object.entries(providers).filter(([, providerConfig]) => providerConfig?.enabled !== false);
    }, [sendLinkDialog.payment]);
    const activeSendLinkProviderKey = sendLinkDialog.payment?.platform?.payment_link_providers?.active_provider || '';
    const activeSendLinkProviderEntry = useMemo(
        () => sendLinkProviderEntries.find(([providerKey]) => providerKey === activeSendLinkProviderKey) || null,
        [activeSendLinkProviderKey, sendLinkProviderEntries],
    );
    const effectiveSendLinkProviderEntry = useMemo(() => {
        if (sendLinkDialog.provider) {
            return sendLinkProviderEntries.find(([providerKey]) => providerKey === sendLinkDialog.provider) || null;
        }

        return activeSendLinkProviderEntry;
    }, [activeSendLinkProviderEntry, sendLinkDialog.provider, sendLinkProviderEntries]);

    const modalCandidates = useMemo(() => {
        const candidates = candidatesData?.data || [];
        return candidates
            .map((candidate) => {
                const score = candidateScore(selectedPayment, candidate);
                return {
                    ...candidate,
                    score,
                    tone: scoreTone(score),
                };
            })
            .sort((left, right) => right.score - left.score);
    }, [candidatesData, selectedPayment]);

    const bulkActions = [
        {
            key: 'bulk-confirm',
            label: 'Confirm selected',
            loadingLabel: 'Confirming...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkConfirmMutation.mutateAsync(rowsSelection);
            },
        },
        {
            key: 'bulk-auto',
            label: 'Auto-match selected',
            loadingLabel: 'Auto-matching...',
            onClick: async (rowsSelection) => {
                await bulkAutoMatchMutation.mutateAsync(rowsSelection);
            },
        },
        {
            key: 'bulk-open-first',
            label: 'Open first selected',
            onClick: (rowsSelection) => {
                if (!rowsSelection.length) return;
                openManualMatch(rowsSelection[0]);
            },
        },
        ...(canViewTests ? [
            {
                key: 'bulk-mark-test',
                label: 'Mark as test',
                loadingLabel: 'Marking…',
                variant: 'secondary',
                onClick: (rows) => {
                    const eligible = rows.filter((r) => !isExplicitTestPayment(r));
                    if (!eligible.length) {
                        toast.warning('All selected payments are already marked as test.');
                        return;
                    }
                    setBulkMarkTestDialog({
                        open: true,
                        payments: rows,
                        reason: 'Exclude non-business or QA payments from sales-facing metrics.',
                    });
                },
            },
            {
                key: 'bulk-mark-and-delete-test',
                label: 'Mark as test & delete',
                loadingLabel: 'Processing…',
                variant: 'danger',
                onClick: (rows) => {
                    setMarkAndDeleteDialog({
                        open: true,
                        payments: rows,
                        reason: 'Permanently remove non-business test payments.',
                    });
                },
            },
        ] : []),
    ];

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (platformFilter) {
            window.localStorage.setItem(DASHBOARD_MARKET_STORAGE_KEY, platformFilter);
            return;
        }

        window.localStorage.removeItem(DASHBOARD_MARKET_STORAGE_KEY);
    }, [platformFilter]);

    useEffect(() => {
        if (!platformFilter || !platformOptions.length) {
            return;
        }

        const platformStillAccessible = platformOptions.some(
            (platform) => String(platform.platform_id) === String(platformFilter),
        );

        if (!platformStillAccessible) {
            setPlatformFilter('');
            setPage(1);
        }
    }, [platformFilter, platformOptions]);

    useEffect(() => {
        if (authLoading) {
            return;
        }

        if (canViewTests) {
            return;
        }

        if (testVisibility !== 'hide') {
            setTestVisibility('hide');
        }

        if (environmentFilter === 'sandbox') {
            setEnvironmentFilter('');
        }
    }, [authLoading, canViewTests, environmentFilter, testVisibility]);

    useEffect(() => {
        const listener = (event) => {
            const isConfirmShortcut = (event.ctrlKey || event.metaKey) && event.key === 'Enter';
            if (!isConfirmShortcut || selectedRows.length === 0) {
                return;
            }

            event.preventDefault();
            if (!bulkConfirmMutation.isPending) {
                bulkConfirmMutation.mutate(selectedRows);
            }
        };

        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, [selectedRows, bulkConfirmMutation]);

    useEffect(() => {
        setProviderStatusSnapshot(null);
        setSandboxReconcileSnapshot(null);
    }, [diagnosticsDrawer.open, diagnosticsDrawer.payment?.id]);

    const columns = [
        {
            key: 'phone',
            label: 'Phone',
            width: '10rem',
            render: (row) => <span className="crm-mono text-xs text-slate-600">{row.phone || '—'}</span>,
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '8.5rem',
            render: (row) => <span className="text-sm font-semibold text-slate-900">{formatCurrency(row.amount, resolveCurrency(row.currency))}</span>,
        },
        {
            key: 'discount',
            label: 'Discount',
            width: '11rem',
            render: (row) => {
                const discountPercentage = row.deal?.discount_percentage;
                if (!discountPercentage) {
                    return <span className="text-xs text-slate-400">—</span>;
                }

                return (
                    <div className="flex flex-col gap-1 text-xs">
                        <span className="font-semibold text-violet-700">{discountPercentage}% off</span>
                        <span className="text-slate-500">{titleize(row.deal?.discount_source || 'discounted')}</span>
                    </div>
                );
            },
        },
        {
            key: 'product',
            label: 'Product',
            width: '11rem',
            render: (row) => <span className="text-sm text-slate-700">{row.product?.name || '—'}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            width: '12rem',
            render: (row) => renderPaymentStatusBadges(row),
        },
        {
            key: 'transaction_reference',
            label: 'Reference',
            width: '17rem',
            cellClassName: 'align-top',
            render: (row) => renderPaymentReference(row),
        },
        {
            key: 'created_at',
            label: 'Date',
            width: '10rem',
            cellClassName: 'align-top',
            render: (row) => {
                const { date, time } = formatPaymentDateTime(row.created_at, row.platform?.timezone || 'UTC');

                return (
                    <div className="text-xs leading-tight text-slate-500">
                        <div>{date}</div>
                        {time ? <div className="text-slate-400">{time}</div> : null}
                    </div>
                );
            },
        },
        {
            key: 'client',
            label: 'Matched Client',
            width: '12rem',
            render: (row) => (
                row.client
                    ? (
                        <a
                            href={`/clients/${row.client.id}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            onClick={(event) => event.stopPropagation()}
                            className="text-xs text-teal-700 hover:text-teal-900 hover:underline"
                        >
                            {row.client.name || `Client #${row.client.id}`}
                        </a>
                    )
                    : <span className="text-xs text-slate-400">Unmatched</span>
            ),
        },
        {
            key: 'match_confidence',
            label: 'Match',
            width: '7.5rem',
            render: (row) => row.reconciliation_confidence ? <StatusBadge status={row.reconciliation_confidence} /> : <span className="text-xs text-slate-400">—</span>,
        },
        {
            key: 'review_state',
            label: 'Review',
            width: '8rem',
            render: (row) => row.reconciliation_state ? <StatusBadge status={row.reconciliation_state} /> : <span className="text-xs text-slate-400">—</span>,
        },
        {
            key: 'resolution',
            label: 'Resolution',
            width: '9rem',
            render: (row) => {
                const badge = paymentResolutionBadge(row.resolution_code);
                return badge ? (
                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${badge.className}`}>
                        {badge.label}
                    </span>
                ) : (
                    <span className="text-xs text-slate-400">—</span>
                );
            },
        },
        {
            key: 'actions',
            label: '',
            width: '12.5rem',
            render: (row) => {
                const testRow = isTestPayment(row);
                const isFailed = row.status === 'failed' || row.status === 'initiated' || row.status === 'pending';
                const isCompletedUnmatched = row.status === 'completed' && !row.client_id;
                const isFailedUnmatched = row.status === 'failed' && !row.client_id;
                const isMatchedNoDeal = row.status === 'completed' && row.client_id && !row.deal_id && !testRow;
                const isLowConfidence = row.status === 'completed' && row.reconciliation_confidence === 'low' && row.reconciliation_state !== 'manual_review';
                const isManualReview = row.reconciliation_state === 'manual_review';
                const isBundleReviewRestricted = Boolean(row.manual_payment_bundle_id) && !canManageBundleFinanceReview;
                const manualAction = isBundleReviewRestricted ? null : manualSubmissionAction(row);

                let primary = null;
                if (manualAction?.key === 'manual_approve') {
                    primary = {
                        label: manualAction.label,
                        variant: 'success',
                        onClick: () => manualApproveMutation.mutate({
                            paymentId: row.id,
                            reason: 'Manual payment approved from payment queue',
                        }),
                    };
                } else if (manualAction?.key === 'manual_verify') {
                    primary = {
                        label: manualAction.label,
                        variant: 'success',
                        onClick: () => manualVerifyMutation.mutate({
                            paymentId: row.id,
                            reason: 'Manual payment verified from payment queue',
                        }),
                    };
                } else if (isManualReview && !isBundleReviewRestricted) {
                    primary = { label: 'Resolve', variant: 'success', onClick: () => reviewStateMutation.mutate({ paymentId: row.id, state: 'resolved', reason: 'Manual review resolved from payment queue' }) };
                } else if (isFailed) {
                    primary = { label: 'Retry STK', variant: 'warning', onClick: () => setRetryStkDialog({ open: true, payment: row, reason: 'Retry STK from payment queue' }) };
                } else if (isCompletedUnmatched) {
                    primary = { label: 'Auto-match', variant: 'primary', onClick: () => autoMatchMutation.mutate(row.id) };
                } else if (isMatchedNoDeal) {
                    primary = {
                        label: 'Create Sub',
                        variant: 'success',
                        onClick: () => {
                            if (row.reconciliation_confidence !== 'high') {
                                toast.warning('Subscription creation is limited to high-confidence reconciled payments.');
                                return;
                            }
                            setCreateSubDialog({
                                open: true,
                                payment: row,
                                reason: 'Create subscription from matched payment',
                                subscriptionLifecycle: resolvePredictedSubscriptionLifecycle(row),
                                subscriptionLifecycleReason: '',
                            });
                        },
                    };
                } else if (isLowConfidence) {
                    primary = { label: 'Mark review', variant: 'warning', onClick: () => reviewStateMutation.mutate({ paymentId: row.id, state: 'manual_review', reason: 'Marked for manual review from payment queue' }) };
                }

                const overflow = [
                    isManualSubmissionPayment(row) && isManualReview && !isBundleReviewRestricted && {
                        key: 'manual-reject',
                        label: 'Reject',
                        onClick: () => setManualRejectDialog({
                            open: true,
                            payment: row,
                            reason: '',
                        }),
                    },
                    isManualSubmissionPayment(row) && row.manual_submission?.proof_url && {
                        key: 'manual-proof',
                        label: 'View proof',
                        onClick: () => openManualProof(row.manual_submission),
                    },
                    canViewTests && !isExplicitTestPayment(row) && {
                        key: 'mark-test',
                        label: 'Mark as test',
                        variant: 'warning',
                        onClick: () => setMarkTestDialog({
                            open: true,
                            payment: row,
                            reason: 'Exclude non-business or QA payment from sales-facing metrics.',
                        }),
                    },
                    canViewTests && {
                        key: 'mark-and-delete-test',
                        label: isExplicitTestPayment(row) ? 'Delete test payment' : 'Mark as test & delete',
                        variant: 'danger',
                        onClick: () => setMarkAndDeleteDialog({
                            open: true,
                            payments: [row],
                            reason: isExplicitTestPayment(row)
                                ? 'Permanently remove non-business test payment after audit snapshot review.'
                                : 'Permanently remove non-business test payment.',
                        }),
                    },
                    isFailed && { key: 'send-link', label: 'Send payment link', onClick: () => setSendLinkDialog({ open: true, payment: row, channel: 'sms', provider: '', phone: row.phone || '', reason: 'Send payment link from CRM' }) },
                    (isCompletedUnmatched || isFailedUnmatched) && {
                        key: 'auto-match',
                        label: 'Auto-match',
                        onClick: () => {
                            if (isCompletedUnmatched) {
                                autoMatchMutation.mutate(row.id);
                                return;
                            }

                            setFailedAutoMatchDialog({ open: true, payment: row });
                        },
                    },
                    (isCompletedUnmatched || isFailedUnmatched) && { key: 'manual-match', label: 'Match manually', onClick: () => openManualMatch(row) },
                    { key: 'diagnose', label: 'Diagnose', onClick: () => openDiagnostics(row) },
                ].filter(Boolean);

                return (
                    <RowActionMenu
                        primaryAction={primary}
                        actions={overflow}
                        badge={isExplicitTestPayment(row) ? 'Test' : (testRow ? 'Sandbox' : (isManualSubmissionPayment(row) ? 'Manual proof' : (row.client_id ? 'Matched' : null)))}
                    />
                );
            },
        },
    ];

    return (
        <div className="space-y-4" data-tour="payments-untracked-queue">
            <PageHeader
                title="Payments"
                subtitle={data?.total ? `${data.total.toLocaleString()} payment records` : 'Incoming payments and match queue'}
                actions={(
                    <div className="flex flex-wrap items-center gap-2">
                        <ReportingCurrencyControl reporting={reportingCurrency} />
                        <button
                            type="button"
                            onClick={openImportTemplate}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Download import template
                        </button>
                        <button
                            type="button"
                            onClick={() => setImportDrawerOpen(true)}
                            className="crm-btn-secondary px-3 py-2"
                            data-tour="payments-import-panel"
                        >
                            Upload payments
                        </button>
                        <button
                            type="button"
                            onClick={() => setExportModalOpen(true)}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Export
                        </button>
                        <button
                            onClick={() => setQueueAutoMatchDialog({ open: true, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' })}
                            disabled={batchMatchMutation.isPending}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            data-tour="payments-auto-match-queue"
                        >
                            {batchMatchMutation.isPending ? 'Matching...' : 'Auto-match queue'}
                        </button>
                    </div>
                )}
            />

            <section className="grid gap-4 md:grid-cols-5">
                <button
                    type="button"
                    onClick={() => applyMetricFilter('awaiting')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'awaiting'
                            ? 'border-amber-300 bg-amber-50/60'
                            : 'border-slate-200 bg-white hover:border-amber-200 hover:bg-amber-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-amber-500" />
                        <p className="text-sm font-semibold text-slate-700">Awaiting Payment</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.awaitingCount.toLocaleString()}</p>
                    {renderSummaryAmount(summary.awaitingBreakdown, summary.awaitingAmount, summary.awaitingNormalized, summary.awaitingNormalizationMeta)}
                    <p className="mt-1 text-xs text-slate-500">Initiated + pending transactions</p>
                </button>

                <div
                    role="button"
                    tabIndex={0}
                    onClick={() => applyMetricFilter('confirmed')}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            applyMetricFilter('confirmed');
                        }
                    }}
                    className={`h-full cursor-pointer rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'confirmed'
                            ? 'border-emerald-300 bg-emerald-50/60'
                            : 'border-slate-200 bg-white hover:border-emerald-200 hover:bg-emerald-50/30'
                    }`}
                    aria-pressed={activeMetric === 'confirmed'}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                        <p className="text-sm font-semibold text-slate-700">Confirmed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.confirmedCount.toLocaleString()}</p>
                    {renderSummaryAmount(summary.confirmedBreakdown, summary.confirmedAmount, summary.confirmedNormalized, summary.confirmedNormalizationMeta)}
                    <p className="mt-1 text-xs text-slate-500">Completed + expired successful payments</p>
                    {summary.reversedCount > 0 ? (
                        <button
                            type="button"
                            onClick={(event) => {
                                event.stopPropagation();
                                applyMetricFilter('reversed');
                            }}
                            aria-pressed={activeMetric === 'reversed'}
                            className={`mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 ${
                                activeMetric === 'reversed'
                                    ? 'bg-orange-100 text-orange-800'
                                    : 'text-orange-700 hover:bg-orange-50 hover:text-orange-800'
                            }`}
                        >
                            <span aria-hidden="true" className="text-sm leading-none text-orange-500">↳</span>
                            <span>{summary.reversedCount} reversed</span>
                                <span className="text-xs font-semibold text-orange-700">
                                    {reportingCurrency.isFlat && summary.reversedNormalized !== null && summary.reversedNormalized !== undefined
                                        ? formatCurrency(summary.reversedNormalized, normalizedCurrency)
                                        : formatCurrency(summary.reversedAmount, resolveCurrency(null))}
                                </span>
                        </button>
                    ) : null}
                </div>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('discounted')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'discounted'
                            ? 'border-violet-300 bg-violet-50/60'
                            : 'border-slate-200 bg-white hover:border-violet-200 hover:bg-violet-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-violet-500" />
                        <p className="text-sm font-semibold text-slate-700">Discounted</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.discountedCount.toLocaleString()} <span className="text-base font-normal text-slate-400">deals</span></p>
                    {renderSummaryAmount(summary.discountedBreakdown, summary.discountedAmount, summary.discountedNormalized, summary.discountedNormalizationMeta)}
                    <p className="mt-1 text-xs text-slate-500">Revenue foregone on discounted deals</p>
                </button>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('unmatched')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'unmatched'
                            ? 'border-sky-300 bg-sky-50/60'
                            : 'border-slate-200 bg-white hover:border-sky-200 hover:bg-sky-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-sky-500" />
                        <p className="text-sm font-semibold text-slate-700">Unmatched Confirmed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.unmatchedCount.toLocaleString()}</p>
                    {renderSummaryAmount(summary.unmatchedBreakdown, summary.unmatchedAmount, summary.unmatchedNormalized, summary.unmatchedNormalizationMeta)}
                    <p className="mt-1 text-xs text-slate-500">Successful payments, no client linked</p>
                </button>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('failed')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'failed'
                            ? 'border-rose-300 bg-rose-50/70'
                            : 'border-slate-200 bg-white hover:border-rose-200 hover:bg-rose-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-rose-500" />
                        <p className="text-sm font-semibold text-slate-700">Failed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.failedCount.toLocaleString()}</p>
                    {renderSummaryAmount(summary.failedBreakdown, summary.failedAmount, summary.failedNormalized, summary.failedNormalizationMeta)}
                    <p className="mt-1 text-xs text-slate-500">Needs retry or follow-up</p>
                </button>
            </section>

            <section className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm font-semibold text-slate-900">Payment workspace</p>
                        <p className="mt-1 text-xs text-slate-500">Switch between the live payment queue and failed-payment recovery analysis.</p>
                    </div>
                    <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                        {[
                            ['payments', 'Payment queue'],
                            ['recovery', 'Failed recovery'],
                            ...(canAccessFraudAudit ? [['fraud', 'Fraud audit']] : []),
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setWorkspaceTab(key)}
                                className={`rounded-md px-3 py-2 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                    workspaceTab === key
                                        ? 'bg-white text-teal-700 shadow-sm ring-1 ring-slate-200'
                                        : 'text-slate-500 hover:bg-white hover:text-slate-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
            </section>

            {workspaceTab === 'recovery' ? (
                <RecoveryLedger
                    report={recoveryReportData}
                    isLoading={recoveryReportLoading}
                    selectedPair={selectedRecoveryPair}
                    onSelectPair={setSelectedRecoveryPair}
                    fallbackCurrency={resolveCurrency(null)}
                    reportingCurrency={reportingCurrency}
                    platformOptions={platformOptions}
                    platformFilter={platformFilter}
                    onPlatformChange={(value) => {
                        setPlatformFilter(value);
                        setPage(1);
                    }}
                    fromDate={fromDate}
                    toDate={toDate}
                    onFromDateChange={(value) => {
                        setFromDate(value);
                        setPage(1);
                    }}
                    onToDateChange={(value) => {
                        setToDate(value);
                        setPage(1);
                    }}
                    isRangeInvalid={isRangeInvalid}
                    onPresetChange={applyRecoveryPreset}
                />
            ) : workspaceTab === 'fraud' && canAccessFraudAudit ? (
                <FraudAuditWorkspace
                    platformOptions={platformOptions}
                    onOpenPayment={(paymentId) => setDiagnosticsDrawer({ open: true, payment: { id: paymentId } })}
                />
            ) : (
            <>
            <section className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-sm font-semibold text-slate-900">Customer revenue mix</h2>
                            {customerMix?.reconciliation?.reconciles_to_confirmed === false ? (
                                <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">
                                    Review totals
                                </span>
                            ) : null}
                        </div>
                        <p className="mt-1 text-xs text-slate-500">
                            Confirmed revenue in this payment period, grouped by matched client creation date.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1">
                        {[
                            ['today', 'Today'],
                            ['7d', '7 days'],
                            ['30d', '30 days'],
                        ].map(([preset, label]) => (
                            <button
                                key={preset}
                                type="button"
                                onClick={() => applyCustomerMixPreset(preset)}
                                className={`rounded-md px-2.5 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                    customerMixPreset === preset
                                        ? 'bg-white text-teal-700 shadow-sm ring-1 ring-slate-200'
                                        : 'text-slate-500 hover:bg-white hover:text-slate-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                        <span className={`rounded-md px-2.5 py-1.5 text-xs font-semibold ${
                            customerMixPreset === 'custom'
                                ? 'bg-white text-slate-700 shadow-sm ring-1 ring-slate-200'
                                : 'text-slate-400'
                        }`}>
                            Custom
                        </span>
                    </div>
                </div>

                <div className="mt-4 grid gap-3 xl:grid-cols-4 md:grid-cols-2">
                    {customerMixCards.map(({ key, meta, bucket }) => {
                        const isActive = customerMixSegment === key;
                        const paymentCount = Number(bucket.payments_count || 0);
                        const clientCount = Number(bucket.clients_count || 0);
                        const sharePercent = Number(bucket.share_percent || 0);

                        return (
                            <button
                                key={key}
                                type="button"
                                onClick={() => applyCustomerMixSegment(key)}
                                aria-pressed={isActive}
                                className={`min-h-[9rem] rounded-lg border px-3 py-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                    isActive ? meta.activeClassName : meta.idleClassName
                                }`}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full ${meta.dotClassName}`} />
                                        <p className="truncate text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{meta.label}</p>
                                    </div>
                                    <span className="text-xs font-semibold text-slate-500">{sharePercent.toFixed(1)}%</span>
                                </div>
                                <div className="mt-2">
                                    {renderSummaryAmount(
                                        bucket.amount_breakdown || {},
                                        bucket.amount,
                                        bucket.normalized_amount,
                                        bucket.normalization_meta,
                                    )}
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2 text-[11px] font-medium text-slate-500">
                                    <span>{paymentCount.toLocaleString()} payments</span>
                                    {key !== 'unattributed' ? <span>{clientCount.toLocaleString()} clients</span> : null}
                                </div>
                                <p className="mt-1 text-xs text-slate-500">{meta.helper}</p>
                            </button>
                        );
                    })}
                </div>

                <div className="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                    <div className="flex h-full w-full">
                        {customerMixCards.map(({ key, meta, bucket }) => {
                            const width = Math.max(0, Number(bucket.share_percent || 0));
                            return width > 0 ? (
                                <div
                                    key={key}
                                    className={`${meta.barClassName} transition-all`}
                                    style={{ width: `${width}%` }}
                                    title={`${meta.label}: ${width.toFixed(1)}%`}
                                />
                            ) : null;
                        })}
                    </div>
                </div>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-500">
                    <span>
                        Scope: {customerMix?.period?.from || 'Start'} to {customerMix?.period?.to || 'today'} by payment date; user status uses active client rules.
                    </span>
                    {customerMixSegment ? (
                        <button
                            type="button"
                            onClick={() => {
                                setCustomerMixSegment('');
                                setPage(1);
                            }}
                            className="font-semibold text-teal-700 transition hover:text-teal-900"
                        >
                            Clear customer mix filter
                        </button>
                    ) : null}
                </div>
            </section>

            <section className="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-xs text-slate-600">
                {visibilityMode === 'only'
                    ? 'Tests-only mode active: both the table and summary cards are showing non-business payment rows for admin review.'
                    : visibilityMode === 'include'
                        ? 'Admin test visibility is on: summary cards stay business-only while the table includes test and sandbox rows for review.'
                        : statsScope === 'test'
                            ? 'Test inspection mode is active for this view.'
                            : 'Business view active: test and sandbox rows stay hidden from the table and summary cards unless an admin reveals them.'}
            </section>

            <section className="crm-filter-row space-y-3">
                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={handleSearch} className="min-w-[220px] flex-1">
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Phone or reference..."
                                    className="crm-input pr-10"
                                />
                                <button type="submit" aria-label="Run payment search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>

                    <FilterSelect
                        label="Market"
                        value={platformFilter}
                        onChange={(event) => { setPlatformFilter(event.target.value); setPage(1); }}
                        options={platformOptionsWithFlags(platformOptions)}
                    />

                    <FilterSelect
                        label="Status"
                        value={statusFilter}
                        onChange={(event) => { setStatusFilter(event.target.value); setCustomerMixSegment(''); setPage(1); }}
                        options={[
                            { value: '', label: 'All statuses' },
                            { value: 'awaiting_payment', label: 'Awaiting payment' },
                            { value: 'recovery_queue', label: 'Recovery queue' },
                            { value: 'completed', label: 'Completed' },
                            { value: 'expired', label: 'Expired' },
                            { value: 'initiated', label: 'Initiated' },
                            { value: 'pending', label: 'Pending' },
                            { value: 'failed', label: 'Failed' },
                        ]}
                    />

                    <FilterSelect
                        label="Match"
                        value={matchFilter}
                        onChange={(event) => { setMatchFilter(event.target.value); setCustomerMixSegment(''); setPage(1); }}
                        options={[
                            { value: '', label: 'All' },
                            { value: 'matched', label: 'Matched' },
                            { value: 'unmatched', label: 'Unmatched' },
                        ]}
                    />

                    <div className="flex flex-col gap-1">
                        <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="payments-from">From</label>
                        <input
                            id="payments-from"
                            type="date"
                            value={fromDate}
                            onChange={(event) => { setFromDate(event.target.value); setPage(1); }}
                            className="crm-input w-auto min-w-[140px]"
                        />
                    </div>

                    <div className="flex flex-col gap-1">
                        <label className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400" htmlFor="payments-to">To</label>
                        <input
                            id="payments-to"
                            type="date"
                            value={toDate}
                            onChange={(event) => { setToDate(event.target.value); setPage(1); }}
                            className="crm-input w-auto min-w-[140px]"
                        />
                    </div>

                    {isRangeInvalid && (
                        <span className="self-end pb-2 text-xs text-rose-500">From must be before To</span>
                    )}

                    {(sourceFilter || (canViewTests && environmentFilter) || confidenceFilter || reviewStateFilter || resolutionFilter || (canViewTests && testVisibility !== 'hide')) || showAdvancedFilters ? (
                        <>
                            {canViewTests ? (
                                <FilterSelect
                                    label="Visibility"
                                    value={testVisibility}
                                    onChange={(event) => { setTestVisibility(event.target.value); setPage(1); }}
                                    options={[
                                        { value: 'hide', label: 'Business only' },
                                        { value: 'include', label: 'Show tests in table' },
                                        { value: 'only', label: 'Tests only' },
                                    ]}
                                />
                            ) : null}

                            <FilterSelect
                                label="Discount"
                                value={hasDiscountFilter}
                                onChange={(event) => { setHasDiscountFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All payments' },
                                    { value: '1', label: 'Discounted only' },
                                    { value: '0', label: 'No discount' },
                                ]}
                            />

                            <FilterSelect
                                label="Source"
                                value={sourceFilter}
                                onChange={(event) => { setSourceFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All sources' },
                                    { value: 'gateway', label: 'Gateway/API' },
                                    { value: 'excel_import', label: 'Excel import' },
                                ]}
                            />

                            {canViewTests ? (
                                <FilterSelect
                                    label="Environment"
                                    value={environmentFilter}
                                    onChange={(event) => { setEnvironmentFilter(event.target.value); setPage(1); }}
                                    options={[
                                        { value: '', label: 'All environments' },
                                        { value: 'production', label: 'Production only' },
                                        { value: 'sandbox', label: 'Sandbox only' },
                                    ]}
                                />
                            ) : null}

                            <FilterSelect
                                label="Confidence"
                                value={confidenceFilter}
                                onChange={(event) => { setConfidenceFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All' },
                                    { value: 'high', label: 'High' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'low', label: 'Low' },
                                ]}
                            />

                            <FilterSelect
                                label="Review"
                                value={reviewStateFilter}
                                onChange={(event) => { setReviewStateFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All states' },
                                    { value: 'open', label: 'Open' },
                                    { value: 'manual_review', label: 'Manual review' },
                                    { value: 'resolved', label: 'Resolved' },
                                ]}
                            />

                            <FilterSelect
                                label="Resolution"
                                value={resolutionFilter}
                                onChange={(event) => { setResolutionFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All outcomes' },
                                    { value: 'reversed', label: 'Reversed' },
                                    { value: 'invalid_reference', label: 'Invalid reference' },
                                ]}
                            />
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setShowAdvancedFilters(true)}
                            className="mb-0.5 flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs font-medium text-slate-500 transition hover:border-slate-400 hover:text-slate-700"
                        >
                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            More filters
                        </button>
                    )}

                    {(search || statusFilter || matchFilter || hasDiscountFilter || platformFilter || sourceFilter || (canViewTests && environmentFilter) || (canViewTests && testVisibility !== 'hide') || confidenceFilter || reviewStateFilter || resolutionFilter || customerMixSegment || fromDate || toDate) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setMatchFilter('');
                                setHasDiscountFilter('');
                                setPlatformFilter('');
                                setSourceFilter('');
                                setEnvironmentFilter('');
                                setTestVisibility('hide');
                                setConfidenceFilter('');
                                setReviewStateFilter('');
                                setResolutionFilter('');
                                setCustomerMixSegment('');
                                setFromDate('');
                                setToDate('');
                                setHasInitializedFrom(false);
                                setShowAdvancedFilters(false);
                                setPage(1);
                            }}
                            className="mb-0.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        >
                            Reset all
                        </button>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2">
                    <p className="text-xs text-slate-400">
                        <span className="crm-mono">Ctrl/Cmd+Enter</span> to confirm selected &middot; Import fields: <span className="crm-mono">payment_date</span>, <span className="crm-mono">amount</span>, + identifier
                    </p>
                    <p className="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                        <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Triage: link within 1h, retry STK within 24h, escalate after 72h
                    </p>
                </div>

            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={openDiagnostics}
                isLoading={isLoading}
                emptyMessage="No payments found."
                compact
                selectable
                bulkActions={bulkActions}
                onSelectionChange={setSelectedRows}
                clearSelectionKey={clearSelectionKey}
                perPage={perPage}
                onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            />
            </>
            )}

            {diagnosticsDrawer.open ? (
                <div className="fixed inset-0 z-40 flex bg-slate-900/45" onClick={closeDiagnostics}>
                    <aside
                        className="ml-auto flex h-full w-full max-w-xl flex-col border-l border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header sticky top-0 z-10 border-b border-slate-100 bg-white/95 backdrop-blur">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="crm-panel-title">Payment Diagnostics</h3>
                                    <p className="crm-panel-subtitle">
                                        Payment #{diagnosticsPayment?.id || '--'} • {diagnosticsPayment?.phone || 'No phone'}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeDiagnostics}
                                    className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                >
                                    Close
                                </button>
                            </div>
                            {diagnosticsData ? (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {[
                                        ['overview', 'Overview'],
                                        ...(manualSubmissionData ? [['manual-proof', 'Manual Proof']] : []),
                                        ['telemetry', 'Telemetry'],
                                        ['history', 'History'],
                                    ].map(([key, label]) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => jumpToDiagnosticsSection(key)}
                                            className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600 transition hover:border-slate-300 hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            ) : null}
                        </header>

                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                            {diagnosticsLoading ? (
                                <div className="animate-pulse space-y-4">
                                    <div className="rounded-xl border border-slate-200 bg-white p-4">
                                        <div className="h-3 w-24 rounded bg-slate-200" />
                                        <div className="mt-3 h-6 w-4/5 rounded bg-slate-200" />
                                        <div className="mt-3 grid gap-2 sm:grid-cols-3">
                                            <div className="h-16 rounded-lg bg-slate-100" />
                                            <div className="h-16 rounded-lg bg-slate-100" />
                                            <div className="h-16 rounded-lg bg-slate-100" />
                                        </div>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="h-40 rounded-xl border border-slate-200 bg-white" />
                                        <div className="h-40 rounded-xl border border-slate-200 bg-white" />
                                    </div>
                                    <div className="h-48 rounded-xl border border-slate-200 bg-white" />
                                </div>
                            ) : diagnosticsError ? (
                                <section className="rounded-xl border border-rose-200 bg-rose-50 p-4">
                                    <div className="flex items-start gap-3">
                                        <span className="mt-0.5 inline-flex rounded-full border border-rose-200 bg-white px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-rose-700">
                                            Error
                                        </span>
                                        <div>
                                            <h4 className="text-sm font-semibold text-rose-900">Diagnostics unavailable</h4>
                                            <p className="mt-1 text-sm text-rose-700">
                                                CRM could not load this payment’s diagnostics payload right now.
                                            </p>
                                            <p className="mt-2 text-xs text-rose-600">
                                                Close the drawer and retry from the payment row if the problem persists.
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            ) : diagnosticsData ? (
                                <>
                                    <section id="payment-diagnostics-overview" className="space-y-4">
                                        <section className={`rounded-xl border p-4 ${diagnosticsSummary.tone}`}>
                                            <div className="flex flex-wrap items-start justify-between gap-4">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {renderPaymentStatusBadges(diagnosticsData.payment)}
                                                        <span className="rounded-full border border-white/60 bg-white/60 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-700">
                                                            {diagnosticsStageLabel(diagnosticsData.payment, diagnosticsData.failure)}
                                                        </span>
                                                        {diagnosticsFreshness ? (
                                                            <span className="text-[11px] font-medium text-slate-600">
                                                                Last updated {formatDateTime(diagnosticsFreshness)}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <p className="mt-3 text-sm font-semibold text-slate-900">{diagnosticsSummary.headline}</p>
                                                    <p className="mt-2 text-xs text-slate-600">
                                                        {unresolvedManualReviewStatus(diagnosticsData.payment)
                                                            ? 'Awaiting operator review.'
                                                            : `Reason: ${diagnosticsData.failure?.reason || 'Not provided'} • Error: ${diagnosticsData.failure?.error_code || '—'} • HTTP: ${diagnosticsData.failure?.http_status || '—'}`}
                                                    </p>
                                                </div>
                                                <div className="grid min-w-[180px] gap-2 sm:text-right">
                                                    <div className="rounded-lg bg-white/70 px-3 py-2">
                                                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Amount</p>
                                                        <p className="mt-1 text-sm font-semibold text-slate-900">
                                                            {formatCurrency(diagnosticsData.payment?.amount, resolveCurrency(diagnosticsData.payment?.currency))}
                                                        </p>
                                                    </div>
                                                    <div className="rounded-lg bg-white/70 px-3 py-2">
                                                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Attempts</p>
                                                        <p className="mt-1 text-sm font-semibold text-slate-900">{diagnosticsData.performance?.attempt_count ?? 0}</p>
                                                    </div>
                                                    <div className="rounded-lg bg-white/70 px-3 py-2">
                                                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Avg latency</p>
                                                        <p className="mt-1 text-sm font-semibold text-slate-900">{diagnosticsData.performance?.avg_latency_ms ?? '—'} ms</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                {primaryRecommendation ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => triggerRecommendation(primaryRecommendation.key, diagnosticsPayment)}
                                                        className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                        title={primaryRecommendation.description}
                                                    >
                                                        {primaryRecommendation.label}
                                                    </button>
                                                ) : (
                                                    <span className="rounded-md border border-white/60 bg-white/60 px-3 py-1.5 text-xs font-medium text-slate-600">
                                                        No immediate action recommendation.
                                                    </span>
                                                )}
                                                {secondaryRecommendations.map((item) => (
                                                    <button
                                                        key={item.key}
                                                        type="button"
                                                        onClick={() => triggerRecommendation(item.key, diagnosticsPayment)}
                                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                        title={item.description}
                                                    >
                                                        {item.label}
                                                    </button>
                                                ))}
                                            </div>
                                        </section>

                                        {manualSubmissionData ? (
                                            <section id="payment-diagnostics-manual-proof" className="rounded-xl border border-slate-200 bg-white p-4">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <h4 className="text-sm font-semibold text-slate-900">Manual payment proof</h4>
                                                            <span className="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                                                {titleize(manualSubmissionData.manual_method_key || 'manual')}
                                                            </span>
                                                            {manualSubmissionCustomerState ? (
                                                                <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] ${manualSubmissionCustomerState.className}`}>
                                                                    {manualSubmissionCustomerState.label}
                                                                </span>
                                                            ) : null}
                                                            {manualSubmissionData.review_decision ? (
                                                                <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] ${
                                                                    manualSubmissionData.review_decision === 'approved'
                                                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                                        : 'border-rose-200 bg-rose-50 text-rose-700'
                                                                }`}>
                                                                    {titleize(manualSubmissionData.review_decision)}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                                            Review the exact destination snapshot, customer note, and uploaded proof before resolving the manual review queue.
                                                        </p>
                                                    </div>

                                                    <div className="flex flex-wrap items-center gap-2">
                                                        {manualSubmissionData.proof_url ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => openManualProof(manualSubmissionData)}
                                                                disabled={manualProofPreview.isLoading}
                                                                className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                            >
                                                                {manualProofPreview.isLoading ? 'Loading proof…' : 'View proof'}
                                                            </button>
                                                        ) : null}
                                                        {manualSubmissionActionState?.key === 'manual_approve' && (!diagnosticsPayment?.manual_payment_bundle_id || canManageBundleFinanceReview) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => manualApproveMutation.mutate({
                                                                    paymentId: diagnosticsPayment.id,
                                                                    reason: 'Manual payment approved from diagnostics drawer',
                                                                })}
                                                                disabled={manualApproveMutation.isPending}
                                                                className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                {manualApproveMutation.isPending ? 'Approving…' : 'Approve & activate'}
                                                            </button>
                                                        ) : null}
                                                        {manualSubmissionActionState?.key === 'manual_verify' && (!diagnosticsPayment?.manual_payment_bundle_id || canManageBundleFinanceReview) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => manualVerifyMutation.mutate({
                                                                    paymentId: diagnosticsPayment.id,
                                                                    reason: 'Manual payment verified from diagnostics drawer',
                                                                })}
                                                                disabled={manualVerifyMutation.isPending}
                                                                className="rounded-md bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                {manualVerifyMutation.isPending ? 'Verifying…' : 'Mark verified'}
                                                            </button>
                                                        ) : null}
                                                        {diagnosticsPayment?.reconciliation_state === 'manual_review' && (!diagnosticsPayment?.manual_payment_bundle_id || canManageBundleFinanceReview) ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => setManualRejectDialog({
                                                                    open: true,
                                                                    payment: diagnosticsPayment,
                                                                    reason: '',
                                                                })}
                                                                className="rounded-md border border-rose-300 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                                            >
                                                                Reject
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(260px,0.9fr)_minmax(0,1.1fr)]">
                                                    <div className="space-y-3">
                                                        {manualSubmissionData.proof_url ? (
                                                            manualProofPreview.isLoading ? (
                                                                <div className="flex h-64 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-sm font-medium text-slate-500">
                                                                    Loading proof image…
                                                                </div>
                                                            ) : manualProofPreview.objectUrl ? (
                                                                <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                                                                    <img
                                                                        src={manualProofPreview.objectUrl}
                                                                        alt="Manual payment proof"
                                                                        className="h-64 w-full object-cover"
                                                                        loading="lazy"
                                                                    />
                                                                </div>
                                                            ) : (
                                                                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                                                                    {manualProofPreview.error || 'Proof image unavailable for this submission.'}
                                                                </div>
                                                            )
                                                        ) : (
                                                            <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                                                                Proof image unavailable for this submission.
                                                            </div>
                                                        )}

                                                        <div className="grid gap-2">
                                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Sender name:</span> {manualSubmissionData.sender_name || '—'}</p>
                                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Transaction reference:</span> {manualSubmissionData.transaction_reference || '—'}</p>
                                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Activated on submit:</span> {manualSubmissionData.activated_on_submit ? 'Yes' : 'No'}</p>
                                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Reviewed at:</span> {manualSubmissionData.reviewed_at ? formatDateTime(manualSubmissionData.reviewed_at) : 'Pending review'}</p>
                                                        </div>
                                                    </div>

                                                    <div className="space-y-4">
                                                        <section className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <h5 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Instruction snapshot</h5>
                                                            <p className="mt-3 text-sm leading-6 text-slate-700">
                                                                {manualSubmissionData.instruction_snapshot?.instruction_intro || 'No instruction intro recorded.'}
                                                            </p>
                                                            {manualSubmissionData.instruction_snapshot?.instruction_footer ? (
                                                                <p className="mt-3 text-xs leading-6 text-slate-500">
                                                                    {manualSubmissionData.instruction_snapshot.instruction_footer}
                                                                </p>
                                                            ) : null}
                                                        </section>

                                                        <section className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <h5 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Destination snapshot</h5>
                                                            {manualSubmissionData.destination_snapshot ? (
                                                                <div className="mt-3 space-y-3">
                                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                                        <p className="rounded-md bg-white px-3 py-2 text-xs text-slate-600">
                                                                            <span className="font-semibold text-slate-800">Method:</span> {titleize(manualSubmissionData.destination_snapshot.method_key || 'manual')}
                                                                        </p>
                                                                        <p className="rounded-md bg-white px-3 py-2 text-xs text-slate-600">
                                                                            <span className="font-semibold text-slate-800">Display name:</span> {manualSubmissionData.destination_snapshot.display_name || '—'}
                                                                        </p>
                                                                    </div>
                                                                    {Object.entries(manualSubmissionData.destination_snapshot.details || {}).length > 0 ? (
                                                                        <div className="grid gap-2 sm:grid-cols-2">
                                                                            {Object.entries(manualSubmissionData.destination_snapshot.details || {}).map(([key, value]) => (
                                                                                <p key={key} className="rounded-md bg-white px-3 py-2 text-xs text-slate-600">
                                                                                    <span className="font-semibold text-slate-800">{titleize(key)}:</span> {String(value || '—')}
                                                                                </p>
                                                                            ))}
                                                                        </div>
                                                                    ) : null}
                                                                </div>
                                                            ) : (
                                                                <p className="mt-3 text-sm text-slate-500">No destination snapshot was captured.</p>
                                                            )}
                                                        </section>

                                                        <section className="rounded-xl border border-slate-200 bg-slate-50/60 p-4">
                                                            <h5 className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Customer note</h5>
                                                            <p className="mt-3 text-sm leading-6 text-slate-700">
                                                                {manualSubmissionData.customer_note || 'No customer note was provided.'}
                                                            </p>
                                                            {manualSubmissionData.rejection_reason ? (
                                                                <p className="mt-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                                                    <span className="font-semibold">Rejection reason:</span> {manualSubmissionData.rejection_reason}
                                                                </p>
                                                            ) : null}
                                                        </section>
                                                    </div>
                                                </div>
                                            </section>
                                        ) : null}

                                        {isTestPayment(diagnosticsData.payment) ? (
                                            <section className="rounded-xl border border-sky-200 bg-sky-50 p-4">
                                                <h4 className="text-sm font-semibold text-sky-900">Sandbox/Test Safeguards</h4>
                                                <p className="mt-1 text-sm text-sky-800">
                                                    This payment is flagged as non-business. Live wallet credits, subscriptions, and KPI reporting stay disabled until it is treated as a real payment again.
                                                </p>
                                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                    <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Test result:</span> {titleize(diagnosticsData.payment?.payment_data?.test_result || diagnosticsData.payment?.status)}</p>
                                                    <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Side effects skipped:</span> {diagnosticsData.payment?.payment_data?.side_effects_skipped ? 'Yes' : 'No'}</p>
                                                    <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Verified at:</span> {formatDateTime(diagnosticsData.payment?.payment_data?.verified_at)}</p>
                                                    <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Classification:</span> {isExplicitTestPayment(diagnosticsData.payment) ? 'Admin-marked test' : titleize(diagnosticsData.payment?.provider_environment || 'sandbox')}</p>
                                                </div>
                                            </section>
                                        ) : null}

                                        {diagnosticsPayment?.manual_payment_bundle_id ? (
                                            <section className="rounded-xl border border-violet-200 bg-violet-50/40 p-4">
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-violet-900">Shared Manual Payment Bundle</h4>
                                                        <p className="mt-1 text-xs text-violet-700">
                                                            This payment belongs to bundle #{diagnosticsPayment.manual_payment_bundle_id}. All payments in the bundle share the same base reference.
                                                        </p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() => setBundleDetailDialog({ open: true, bundleId: diagnosticsPayment.manual_payment_bundle_id })}
                                                        className="whitespace-nowrap rounded-md border border-violet-300 bg-white px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:bg-violet-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"
                                                    >
                                                        View bundle
                                                    </button>
                                                </div>
                                            </section>
                                        ) : null}

                                        {providerCheckEligible || providerStatusDisplay ? (
                                            <section className="rounded-xl border border-slate-200 bg-white p-4">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-slate-900">Live Provider Status</h4>
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            {sandboxReconcileEligible
                                                                ? 'Read-only verification plus sandbox-safe reconcile for supported hosted-checkout test flows.'
                                                                : 'Read-only verification against the current provider session when diagnostics capabilities allow it.'}
                                                        </p>
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => diagnosticsPayment?.id && providerStatusMutation.mutate(diagnosticsPayment.id)}
                                                            disabled={!providerCheckReady || providerStatusMutation.isPending}
                                                            className="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {providerStatusMutation.isPending ? 'Checking...' : 'Check Provider Status'}
                                                        </button>
                                                        {sandboxReconcileEligible ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => diagnosticsPayment?.id && sandboxReconcileMutation.mutate({
                                                                    paymentId: diagnosticsPayment.id,
                                                                    reason: 'Sandbox reconcile from diagnostics drawer',
                                                                })}
                                                                disabled={sandboxReconcileMutation.isPending}
                                                                className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                {sandboxReconcileMutation.isPending ? 'Reconciling...' : 'Sandbox Reconcile'}
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </div>

                                                {!providerCheckReady && providerCheckEligible ? (
                                                    <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                                        Hosted checkout needs to initialize before CRM can verify provider-side status.
                                                    </p>
                                                ) : null}

                                                {sandboxReconcileSnapshot?.message && sandboxReconcileEligible ? (
                                                    <p className="mt-3 text-xs text-slate-600">
                                                        {sandboxReconcileSnapshot.message}
                                                    </p>
                                                ) : null}

                                                {providerStatusDisplay ? (
                                                    <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${diagnosticToneClasses(providerStatusDisplay.status)}`}>
                                                                {titleize(providerStatusDisplay.status)}
                                                            </span>
                                                            <span className="text-[11px] text-slate-500">
                                                                Checked {formatDateTime(providerStatusDisplay.checked_at)}
                                                            </span>
                                                        </div>
                                                        <p className="mt-2 text-sm text-slate-700">{providerStatusDisplay.message || 'No provider message returned.'}</p>
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            Provider: {titleize(diagnosticsPayment?.provider_key)} • Reference: {providerReference}
                                                        </p>
                                                    </div>
                                                ) : null}
                                            </section>
                                        ) : null}
                                    </section>

                                    <section id="payment-diagnostics-telemetry" className="space-y-4">
                                        {structuredDiagnosticsSections.length > 0 ? (
                                            <section className="space-y-4">
                                                {structuredDiagnosticsSections.map((section) => (
                                                    <StructuredDiagnosticsSection key={section.key || section.title} section={section} />
                                                ))}
                                            </section>
                                        ) : null}

                                        {linkProxyData ? (
                                            <section className="rounded-xl border border-slate-200 bg-white p-4">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-slate-900">Proxy Link Lifecycle</h4>
                                                        <p className="mt-1 text-xs text-slate-500">
                                                            {titleize(linkProxyData.session_status)} • {titleize(linkProxyData.mode)}
                                                        </p>
                                                    </div>
                                                    <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${diagnosticToneClasses(linkProxyData.token_status)}`}>
                                                        {titleize(linkProxyData.token_status)}
                                                    </span>
                                                </div>

                                                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Sent:</span> {formatDateTime(linkProxyData.sent_at)}</p>
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Opened:</span> {formatDateTime(linkProxyData.opened_at)}</p>
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Open count:</span> {linkProxyData.open_count ?? 0}</p>
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Provider ref:</span> {linkProxyData.provider_reference || '—'}</p>
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Environment:</span> {titleize(linkProxyData.environment)}</p>
                                                    <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Expires:</span> {formatDateTime(linkProxyData.token_expires_at)}</p>
                                                </div>

                                                <div className="mt-4 space-y-3">
                                                    {linkProxySteps.map((step, index) => {
                                                        const firstIncompleteIndex = linkProxySteps.findIndex((item) => !item.timestamp);
                                                        const isComplete = Boolean(step.timestamp);
                                                        const isCurrent = !isComplete && firstIncompleteIndex === index;

                                                        return (
                                                            <div key={step.key} className="flex gap-3">
                                                                <div className="flex flex-col items-center">
                                                                    <span
                                                                        aria-hidden="true"
                                                                        className={`mt-1 h-2.5 w-2.5 rounded-full ${
                                                                            isComplete
                                                                                ? 'bg-emerald-500'
                                                                                : (isCurrent ? 'bg-amber-500' : 'bg-slate-300')
                                                                        }`}
                                                                    />
                                                                    {index < linkProxySteps.length - 1 ? <span className="mt-1 h-8 w-px bg-slate-200" /> : null}
                                                                </div>
                                                                <div className="min-w-0 flex-1">
                                                                    <p className={`text-xs font-semibold ${isComplete ? 'text-slate-900' : (isCurrent ? 'text-amber-700' : 'text-slate-500')}`}>
                                                                        {step.label}
                                                                    </p>
                                                                    <p className="mt-0.5 text-xs text-slate-500">
                                                                        {step.timestamp ? formatDateTime(step.timestamp) : step.helper}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            </section>
                                        ) : null}

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <section className="rounded-xl border border-slate-200 bg-white p-4">
                                                <h4 className="text-sm font-semibold text-slate-900">API Performance</h4>
                                                <div className="mt-3 grid gap-2">
                                                    <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Attempts:</span> {diagnosticsData.performance?.attempt_count ?? 0}</p>
                                                    <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Avg:</span> {diagnosticsData.performance?.avg_latency_ms ?? '—'} ms</p>
                                                    <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">P95:</span> {diagnosticsData.performance?.p95_latency_ms ?? '—'} ms</p>
                                                </div>
                                            </section>

                                            <section className="rounded-xl border border-slate-200 bg-white p-4">
                                                <div className="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-slate-900">Browser & Request Context</h4>
                                                        <p className="mt-1 text-xs text-slate-500">{browserContextState.description}</p>
                                                    </div>
                                                    <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${browserContextState.tone}`}>
                                                        {browserContextState.label}
                                                    </span>
                                                </div>

                                                {diagnosticsData.browser_meta?.context_type === 'browser' ? (
                                                    <div className="mt-3 grid gap-2">
                                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Origin:</span> {diagnosticsData.browser_meta?.origin_url || '—'}</p>
                                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Referrer:</span> {diagnosticsData.browser_meta?.referrer || '—'}</p>
                                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600"><span className="font-semibold text-slate-800">Browser:</span> {diagnosticsData.browser_meta?.user_agent_family || '—'} • <span className="font-semibold text-slate-800">Device:</span> {diagnosticsData.browser_meta?.device_type || '—'}</p>
                                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500"><span className="font-semibold text-slate-700">IP hash:</span> {diagnosticsData.browser_meta?.ip_hash || '—'}</p>
                                                    </div>
                                                ) : (
                                                    <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-600">
                                                        {diagnosticsData.browser_meta?.request_id
                                                            ? `Request ID: ${diagnosticsData.browser_meta.request_id}`
                                                            : 'No browser-origin headers were captured for this payment.'}
                                                    </div>
                                                )}
                                            </section>
                                        </div>

                                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <h4 className="text-sm font-semibold text-slate-900">Recent Attempts</h4>
                                                    <p className="mt-1 text-xs text-slate-500">Most recent telemetry first. Use this section before diving into the longer history.</p>
                                                </div>
                                                <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                                                    {(diagnosticsData.attempts || []).length} logged
                                                </span>
                                            </div>
                                            {(diagnosticsData.attempts || []).length === 0 ? (
                                                <p className="mt-3 rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                                    No telemetry attempts recorded for this payment yet.
                                                </p>
                                            ) : (
                                                <div className="mt-3 space-y-2">
                                                    {diagnosticsData.attempts.slice(0, 8).map((attempt) => (
                                                        <article key={attempt.id} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="text-xs font-semibold text-slate-900">{titleize(attempt.attempt_type)}</span>
                                                                    <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${diagnosticToneClasses(attempt.status)}`}>
                                                                        {titleize(attempt.status)}
                                                                    </span>
                                                                </div>
                                                                <span className="text-[11px] text-slate-500">{formatDateTime(attempt.created_at)}</span>
                                                            </div>
                                                            <div className="mt-2 grid gap-2 text-xs text-slate-600 sm:grid-cols-2">
                                                                <p><span className="font-semibold text-slate-800">Provider:</span> {attempt.provider || '—'}</p>
                                                                <p><span className="font-semibold text-slate-800">Latency:</span> {attempt.latency_ms ?? '—'} ms</p>
                                                                <p><span className="font-semibold text-slate-800">HTTP:</span> {attempt.http_status || '—'}</p>
                                                                <p><span className="font-semibold text-slate-800">Actor:</span> {attempt.actor?.name || 'System'}</p>
                                                            </div>
                                                            <p className="mt-2 text-xs text-slate-600">
                                                                <span className="font-semibold text-slate-800">Reason:</span> {attempt.error_message || 'No error message recorded.'}
                                                            </p>
                                                        </article>
                                                    ))}
                                                </div>
                                            )}
                                        </section>
                                    </section>

                                    <section id="payment-diagnostics-history" className="space-y-4">
                                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                                            <details className="group">
                                                <summary className="flex cursor-pointer list-none items-center justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-900">
                                                            Audit Trail
                                                            <span className="ml-2 text-xs font-medium text-slate-500">
                                                                ({(diagnosticsData.audit_trail || []).length})
                                                            </span>
                                                        </p>
                                                        <p className="mt-1 text-xs text-slate-500">Expand to review operator actions and recorded reasons.</p>
                                                    </div>
                                                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-slate-500 transition group-open:rotate-180">
                                                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className="h-4 w-4">
                                                            <path d="M5 8l5 5 5-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                                                        </svg>
                                                    </span>
                                                </summary>
                                                {(diagnosticsData.audit_trail || []).length === 0 ? (
                                                    <p className="mt-3 rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                                        No audit entries were recorded for this payment yet.
                                                    </p>
                                                ) : (
                                                    <div className="mt-3 space-y-2">
                                                        {diagnosticsData.audit_trail.map((entry) => (
                                                            <article key={entry.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                                <p className="text-xs font-semibold text-slate-800">{titleize(entry.action)}</p>
                                                                <p className="mt-1 text-xs text-slate-600">Actor: {entry.actor?.name || 'System'} • {formatDateTime(entry.created_at)}</p>
                                                                <p className="mt-1 text-xs text-slate-600">Reason: {entry.reason || 'No reason provided'}</p>
                                                            </article>
                                                        ))}
                                                    </div>
                                                )}
                                            </details>
                                        </section>

                                        <section className="rounded-xl border border-slate-200 bg-white p-4">
                                            <details className="group">
                                                <summary className="flex cursor-pointer list-none items-center justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-semibold text-slate-900">
                                                            Timeline Events
                                                            <span className="ml-2 text-xs font-medium text-slate-500">
                                                                ({(diagnosticsData.timeline || []).length})
                                                            </span>
                                                        </p>
                                                        <p className="mt-1 text-xs text-slate-500">Expand to review linked CRM events in chronological order.</p>
                                                    </div>
                                                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-slate-500 transition group-open:rotate-180">
                                                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" className="h-4 w-4">
                                                            <path d="M5 8l5 5 5-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
                                                        </svg>
                                                    </span>
                                                </summary>
                                                {(diagnosticsData.timeline || []).length === 0 ? (
                                                    <p className="mt-3 rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                                        No payment-linked timeline events have been recorded yet.
                                                    </p>
                                                ) : (
                                                    <div className="mt-3 space-y-2">
                                                        {diagnosticsData.timeline.map((event) => (
                                                            <article key={event.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                                <p className="text-xs font-semibold text-slate-800">{titleize(event.event_type)}</p>
                                                                <p className="mt-1 text-xs text-slate-600">{describeTimelineContent(event.content)}</p>
                                                                <p className="mt-1 text-[11px] text-slate-500">
                                                                    {event.actor?.name || 'System'} • {formatDateTime(event.created_at)}
                                                                </p>
                                                            </article>
                                                        ))}
                                                    </div>
                                                )}
                                            </details>
                                        </section>
                                    </section>
                                </>
                            ) : (
                                <p className="text-sm text-slate-500">Select a payment to review diagnostics.</p>
                            )}
                        </div>

                        <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 p-4">
                            {['initiated', 'pending'].includes(diagnosticsPayment?.status) ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        closeDiagnostics();
                                        openManualCloseDialog(diagnosticsPayment);
                                    }}
                                    className="crm-btn-danger"
                                >
                                    Close manually
                                </button>
                            ) : null}
                            <button type="button" onClick={closeDiagnostics} className="crm-btn-secondary">
                                Close
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}

            {selectedPayment ? (
                <div className="fixed inset-0 z-50 flex bg-slate-900/45" onClick={() => setSelectedPayment(null)}>
                    <aside
                        className="ml-auto h-full w-full max-w-md border-l border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header sticky top-0 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Manual Match</h3>
                                <p className="crm-panel-subtitle">
                                    Payment #{selectedPayment.id} • {selectedPayment.phone || 'No phone'} • {formatCurrency(selectedPayment.amount, resolveCurrency(selectedPayment.currency))}
                                </p>
                            </div>
                        </header>

                        <div className="h-[calc(100%-132px)] overflow-y-auto p-4">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setCandidateSearch(candidateSearchInput.trim());
                                }}
                                className="mb-3"
                            >
                                <div className="relative">
                                    <input
                                        value={candidateSearchInput}
                                        onChange={(event) => setCandidateSearchInput(event.target.value)}
                                        placeholder="Search client by name, phone, CRM/WP IDs..."
                                        className="crm-input pr-10"
                                    />
                                    <button type="submit" aria-label="Search client candidates" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </button>
                                </div>
                                {candidateSearch ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCandidateSearch('');
                                            setCandidateSearchInput('');
                                        }}
                                        className="mt-2 text-xs font-semibold text-teal-700 underline decoration-teal-200 underline-offset-2 hover:text-teal-800"
                                    >
                                        Clear candidate search
                                    </button>
                                ) : null}
                            </form>

                            {candidatesLoading ? (
                                <p className="text-sm text-slate-500">Loading candidate clients...</p>
                            ) : modalCandidates.length === 0 ? (
                                <p className="text-sm text-slate-500">No candidates found. Try searching by client name, phone, or WP IDs.</p>
                            ) : (
                                <div className="space-y-2">
                                    {modalCandidates.map((client) => {
                                        const tone = toneClasses(client.tone);

                                        return (
                                            <label
                                                key={client.id}
                                                className={`flex cursor-pointer items-start justify-between gap-3 rounded-md border px-3 py-2.5 text-sm transition ${selectedClientId === String(client.id) ? 'border-teal-600 bg-teal-50/60' : 'border-slate-200 hover:bg-slate-50'}`}
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-1.5">
                                                        <span className="truncate font-semibold text-slate-900">{client.name || `Client #${client.id}`}</span>
                                                        <button
                                                            type="button"
                                                            onClick={(e) => {
                                                                e.stopPropagation();
                                                                e.preventDefault();
                                                                if (modalCandidates.length > 1) {
                                                                    window.open(`/clients/${client.id}`, '_blank');
                                                                } else {
                                                                    navigate(`/clients/${client.id}`);
                                                                }
                                                            }}
                                                            className="inline-flex shrink-0 items-center rounded px-1 py-0.5 text-[10px] font-semibold text-teal-700 hover:bg-teal-50 hover:underline"
                                                            title={modalCandidates.length > 1 ? 'Open client profile in new tab' : 'Go to client profile'}
                                                        >
                                                            View profile &rarr;
                                                        </button>
                                                    </span>
                                                    <span className="mt-0.5 block truncate text-xs text-slate-500">{client.phone_normalized || 'No phone'} • {client.profile_status}</span>
                                                    <span className={`mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${tone}`}>
                                                        Match score {client.score}%
                                                    </span>
                                                </span>
                                                <input
                                                    type="radio"
                                                    name="client"
                                                    value={client.id}
                                                    checked={selectedClientId === String(client.id)}
                                                    onChange={(event) => setSelectedClientId(event.target.value)}
                                                    className="mt-1 h-4 w-4 border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                            </label>
                                        );
                                    })}
                                </div>
                            )}

                            <div className="mt-4">
                                <label htmlFor="confirm-reason" className="mb-1 block text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
                                    Reason
                                </label>
                                <textarea
                                    id="confirm-reason"
                                    rows={3}
                                    value={confirmReason}
                                    onChange={(event) => setConfirmReason(event.target.value)}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" onClick={() => setSelectedPayment(null)} className="crm-btn-secondary">
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!selectedClientId || !confirmReason.trim() || confirmMatchMutation.isPending}
                                onClick={() =>
                                    confirmMatchMutation.mutate({
                                        paymentId: selectedPayment.id,
                                        clientId: Number(selectedClientId),
                                        reason: confirmReason.trim(),
                                    })
                                }
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {confirmMatchMutation.isPending ? 'Saving...' : 'Confirm match'}
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}

            <ConfirmDialog
                open={queueAutoMatchDialog.open}
                title={queueAutoMatchDialog.step === 'preview' ? 'Auto-match Preview' : 'Auto-match Review Queue'}
                message={
                    queueAutoMatchDialog.step === 'preview' && queueAutoMatchDialog.preview
                        ? `${queueAutoMatchDialog.preview.matched} high-confidence, ${queueAutoMatchDialog.preview.low_confidence} low-confidence, ${queueAutoMatchDialog.preview.unmatched} unmatched.`
                        : 'Enter a reason and preview matches before applying.'
                }
                confirmLabel={queueAutoMatchDialog.step === 'preview' ? 'Apply Matches' : 'Preview Matches'}
                onCancel={() => setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' })}
                onConfirm={() => {
                    if (queueAutoMatchDialog.step === 'reason') {
                        batchMatchDryRunMutation.mutate(queueAutoMatchDialog.reason.trim());
                    } else {
                        batchMatchMutation.mutate(queueAutoMatchDialog.reason.trim());
                    }
                }}
                confirmDisabled={
                    !queueAutoMatchDialog.reason.trim()
                    || batchMatchMutation.isPending
                    || batchMatchDryRunMutation.isPending
                    || (queueAutoMatchDialog.step === 'preview' && queueAutoMatchDialog.preview && queueAutoMatchDialog.preview.matched === 0 && queueAutoMatchDialog.preview.low_confidence === 0)
                }
                isPending={batchMatchMutation.isPending || batchMatchDryRunMutation.isPending}
            >
                {queueAutoMatchDialog.step === 'reason' ? (
                    <>
                        <label htmlFor="queue-auto-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            id="queue-auto-reason"
                            rows={3}
                            value={queueAutoMatchDialog.reason}
                            onChange={(event) => setQueueAutoMatchDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                        />
                    </>
                ) : queueAutoMatchDialog.preview?.proposals?.length ? (
                    <div className="max-h-56 overflow-y-auto rounded border border-slate-200">
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-slate-50">
                                <tr>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Phone</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Amount</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Client</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Confidence</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {queueAutoMatchDialog.preview.proposals.map((p) => (
                                    <tr key={p.payment_id}>
                                        <td className="px-2 py-1.5 text-slate-800">{p.payment_phone || '—'}</td>
                                        <td className="px-2 py-1.5 text-slate-600">{p.payment_amount}</td>
                                        <td className="px-2 py-1.5 text-slate-800">{p.client_name || '—'}</td>
                                        <td className="px-2 py-1.5">
                                            <span className={p.confidence === 'auto_high' ? 'text-emerald-700' : 'text-amber-600'}>
                                                {p.confidence === 'auto_high' ? 'High' : 'Low'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-sm text-slate-500">No matchable payments found.</p>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={failedAutoMatchDialog.open && !!failedAutoMatchDialog.payment}
                title="Auto-match failed payment?"
                message="This payment has not completed. Matching links the client for tracking only and will not create a subscription."
                confirmLabel="Auto-match anyway"
                tone="warning"
                onCancel={() => setFailedAutoMatchDialog({ open: false, payment: null })}
                onConfirm={() => {
                    const payment = failedAutoMatchDialog.payment;
                    if (payment) {
                        autoMatchMutation.mutate(payment.id);
                    }
                    setFailedAutoMatchDialog({ open: false, payment: null });
                }}
                isPending={autoMatchMutation.isPending}
            />

            <ConfirmDialog
                open={retryStkDialog.open && !!retryStkDialog.payment}
                title="Retry STK push"
                message={retryStkDialog.payment
                    ? `Send another M-Pesa STK push for payment #${retryStkDialog.payment.id} (${formatCurrency(retryStkDialog.payment.amount, resolveCurrency(retryStkDialog.payment.currency))} to ${retryStkDialog.payment.phone || 'customer'}).`
                    : ''}
                confirmLabel="Send STK"
                onCancel={() => setRetryStkDialog({ open: false, payment: null, reason: 'Retry STK from payment queue' })}
                onConfirm={() => {
                    if (retryStkDialog.payment) {
                        retryStkMutation.mutate({
                            paymentId: retryStkDialog.payment.id,
                            reason: retryStkDialog.reason.trim() || undefined,
                        });
                    }
                }}
                confirmDisabled={retryStkMutation.isPending}
                isPending={retryStkMutation.isPending}
            >
                <label htmlFor="retry-stk-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                <textarea
                    id="retry-stk-reason"
                    rows={2}
                    value={retryStkDialog.reason}
                    onChange={(event) => setRetryStkDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                    placeholder="e.g. Retry STK from payment queue"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={sendLinkDialog.open && !!sendLinkDialog.payment}
                title="Send payment link"
                message={sendLinkDialog.payment
                    ? `Send a payment page link for payment #${sendLinkDialog.payment.id} (${formatCurrency(sendLinkDialog.payment.amount, resolveCurrency(sendLinkDialog.payment.currency))}).`
                    : ''}
                confirmLabel={sendLinkDialog.channel === 'whatsapp' ? 'Send WhatsApp' : 'Send SMS'}
                onCancel={() => setSendLinkDialog({ open: false, payment: null, channel: 'sms', provider: '', phone: '', reason: 'Send payment link from CRM' })}
                onConfirm={() => {
                    if (sendLinkDialog.payment) {
                        sendPaymentLinkMutation.mutate({
                            paymentId: sendLinkDialog.payment.id,
                            channel: sendLinkDialog.channel,
                            provider: sendLinkDialog.provider || undefined,
                            phone: sendLinkDialog.phone.trim() || undefined,
                            reason: sendLinkDialog.reason.trim() || undefined,
                        });
                    }
                }}
                confirmDisabled={sendPaymentLinkMutation.isPending}
                isPending={sendPaymentLinkMutation.isPending}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="send-link-channel" className="mb-1 block text-sm font-medium text-slate-700">Channel</label>
                        <select
                            id="send-link-channel"
                            value={sendLinkDialog.channel}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, channel: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="sms">SMS</option>
                            <option value="whatsapp">WhatsApp</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="send-link-provider" className="mb-1 block text-sm font-medium text-slate-700">Payment link provider</label>
                        <select
                            id="send-link-provider"
                            value={sendLinkDialog.provider}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, provider: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="">
                                {activeSendLinkProviderEntry
                                    ? `Default configured provider: ${paymentLinkProviderOptionLabel(activeSendLinkProviderEntry[0], activeSendLinkProviderEntry[1])}`
                                    : 'Default configured provider'}
                            </option>
                            {sendLinkProviderEntries.map(([providerKey, providerConfig]) => (
                                <option key={providerKey} value={providerKey}>
                                    {paymentLinkProviderOptionLabel(providerKey, providerConfig)}
                                </option>
                            ))}
                        </select>
                        {effectiveSendLinkProviderEntry ? (
                            <div className="mt-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <p className="text-xs font-semibold text-slate-800">
                                    {sendLinkDialog.provider ? 'Selected route' : 'Current default'}
                                    {' '}
                                    · {paymentLinkProviderOptionLabel(effectiveSendLinkProviderEntry[0], effectiveSendLinkProviderEntry[1])}
                                </p>
                                <p className="mt-1 text-xs text-slate-600">
                                    {effectiveSendLinkProviderEntry[1]?.mode === 'proxy_hosted_checkout'
                                        ? `This sends a CRM-owned link that opens hosted checkout through ${effectiveSendLinkProviderEntry[1]?.label || effectiveSendLinkProviderEntry[0]}${effectiveSendLinkProviderEntry[1]?.environment ? ` (${effectiveSendLinkProviderEntry[1].environment})` : ''}.`
                                        : 'This sends the external pay-page URL configured for this provider.'}
                                </p>
                            </div>
                        ) : (
                            <p className="mt-2 text-xs text-amber-700">No enabled payment-link providers are configured for this market yet.</p>
                        )}
                        <div className="mt-2 flex items-center justify-between gap-2">
                            <p className="text-xs text-slate-500">Need to edit provider URLs or active routing?</p>
                            <button
                                type="button"
                                onClick={() => {
                                    const platformId = sendLinkDialog.payment?.platform_id || sendLinkDialog.payment?.platform?.id;
                                    const query = new URLSearchParams({ integrationArea: 'payment_links' });
                                    if (platformId) {
                                        query.set('platform_id', String(platformId));
                                    }
                                    navigate(`/settings?${query.toString()}`);
                                }}
                                className="text-xs font-semibold text-teal-700 underline decoration-teal-200 underline-offset-2 hover:text-teal-800"
                            >
                                Manage providers
                            </button>
                        </div>
                    </div>
                    <div>
                        <label htmlFor="send-link-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone number (optional)</label>
                        <input
                            id="send-link-phone"
                            type="text"
                            value={sendLinkDialog.phone}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, phone: event.target.value }))}
                            className="crm-input"
                            placeholder="Leave empty to use payment phone"
                        />
                    </div>
                    <div>
                        <label htmlFor="send-link-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                        <input
                            id="send-link-reason"
                            type="text"
                            value={sendLinkDialog.reason}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                            placeholder="e.g. Send payment link from CRM"
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={createSubDialog.open && !!createSubDialog.payment}
                title="Create subscription"
                message={createSubDialog.payment
                    ? `Activate a subscription for payment #${createSubDialog.payment.id} (${formatCurrency(createSubDialog.payment.amount, resolveCurrency(createSubDialog.payment.currency))}) matched to ${createSubDialog.payment.client?.name || 'client'}.`
                    : ''}
                confirmLabel="Create subscription"
                onCancel={() => setCreateSubDialog({
                    open: false,
                    payment: null,
                    reason: 'Create subscription from matched payment',
                    subscriptionLifecycle: 'new',
                    subscriptionLifecycleReason: '',
                })}
                onConfirm={() => {
                    if (createSubDialog.payment) {
                        if (isTestPayment(createSubDialog.payment)) {
                            toast.info('Test-classified payments cannot create live subscriptions.');
                            return;
                        }
                        const predictedLifecycle = resolvePredictedSubscriptionLifecycle(createSubDialog.payment);
                        const lifecycleOverridden = createSubDialog.subscriptionLifecycle !== predictedLifecycle;
                        if (lifecycleOverridden && !createSubDialog.subscriptionLifecycleReason.trim()) {
                            toast.error('Add a short reason when overriding the lifecycle classification.');
                            return;
                        }
                        createSubscriptionMutation.mutate({
                            paymentId: createSubDialog.payment.id,
                            reason: createSubDialog.reason.trim() || undefined,
                            subscriptionLifecycle: createSubDialog.subscriptionLifecycle,
                            subscriptionLifecycleReason: lifecycleOverridden ? createSubDialog.subscriptionLifecycleReason.trim() : undefined,
                        });
                    }
                }}
                confirmDisabled={
                    createSubscriptionMutation.isPending
                    || (
                        createSubDialog.payment
                        && createSubDialog.subscriptionLifecycle !== resolvePredictedSubscriptionLifecycle(createSubDialog.payment)
                        && !createSubDialog.subscriptionLifecycleReason.trim()
                    )
                }
                isPending={createSubscriptionMutation.isPending}
            >
                {createSubDialog.payment ? (
                    <div className="mb-4 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-800">Subscriber Type</p>
                            <p className="mt-1 text-xs text-slate-500">
                                {subscriptionLifecycleHelperText(resolvePredictedSubscriptionLifecycle(createSubDialog.payment))}
                            </p>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {['new', 'renewal'].map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => setCreateSubDialog((current) => ({ ...current, subscriptionLifecycle: option }))}
                                    className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                        createSubDialog.subscriptionLifecycle === option
                                            ? 'border-teal-300 bg-teal-50 text-teal-700'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                    }`}
                                >
                                    {option === 'new' ? 'New' : 'Renewal'}
                                </button>
                            ))}
                        </div>
                        {createSubDialog.subscriptionLifecycle !== resolvePredictedSubscriptionLifecycle(createSubDialog.payment) ? (
                            <div>
                                <label htmlFor="create-sub-lifecycle-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Override reason
                                </label>
                                <textarea
                                    id="create-sub-lifecycle-reason"
                                    rows={2}
                                    value={createSubDialog.subscriptionLifecycleReason}
                                    onChange={(event) => setCreateSubDialog((current) => ({ ...current, subscriptionLifecycleReason: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Explain why this should be classified differently"
                                />
                            </div>
                        ) : null}
                    </div>
                ) : null}
                <label htmlFor="create-sub-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                <textarea
                    id="create-sub-reason"
                    rows={2}
                    value={createSubDialog.reason}
                    onChange={(event) => setCreateSubDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                    placeholder="e.g. Create subscription from matched payment"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={manualRejectDialog.open && !!manualRejectDialog.payment}
                title="Reject manual payment"
                message={manualRejectDialog.payment
                    ? `Reject payment #${manualRejectDialog.payment.id} and notify the customer that the submitted proof could not be verified.`
                    : ''}
                confirmLabel="Reject payment"
                tone="danger"
                onCancel={() => setManualRejectDialog({ open: false, payment: null, reason: '' })}
                onConfirm={() => {
                    if (manualRejectDialog.payment) {
                        manualRejectMutation.mutate({
                            paymentId: manualRejectDialog.payment.id,
                            reason: manualRejectDialog.reason.trim(),
                        });
                    }
                }}
                confirmDisabled={manualRejectMutation.isPending || !manualRejectDialog.reason.trim()}
                isPending={manualRejectMutation.isPending}
            >
                <label htmlFor="manual-reject-reason" className="mb-1 block text-sm font-medium text-slate-700">Rejection reason</label>
                <textarea
                    id="manual-reject-reason"
                    rows={3}
                    value={manualRejectDialog.reason}
                    onChange={(event) => setManualRejectDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                    placeholder="Explain why the payment proof could not be accepted."
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={markTestDialog.open && !!markTestDialog.payment}
                title="Mark payment as test"
                message={markTestDialog.payment
                    ? `Exclude payment #${markTestDialog.payment.id} from default dashboards, reports, and sales-facing tables.`
                    : ''}
                confirmLabel="Mark as test"
                tone="warning"
                onCancel={() => setMarkTestDialog({ open: false, payment: null, reason: 'Exclude non-business or QA payment from sales-facing metrics.' })}
                onConfirm={() => {
                    if (markTestDialog.payment) {
                        markTestMutation.mutate({
                            paymentId: markTestDialog.payment.id,
                            reason: markTestDialog.reason.trim(),
                        });
                    }
                }}
                confirmDisabled={markTestMutation.isPending || !markTestDialog.reason.trim()}
                isPending={markTestMutation.isPending}
            >
                <div className="space-y-3">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        This keeps the row out of normal business totals immediately, but preserves it for admin review and audit history.
                    </div>
                    <div>
                        <label htmlFor="mark-test-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            id="mark-test-reason"
                            rows={3}
                            value={markTestDialog.reason}
                            onChange={(event) => setMarkTestDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                            placeholder="Explain why this payment should be treated as a non-business/test record."
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={bulkMarkTestDialog.open && bulkMarkTestDialog.payments.length > 0}
                title="Mark payments as test"
                message={`Mark ${bulkMarkTestDialog.payments.filter((p) => !isExplicitTestPayment(p)).length} payment(s) as test. They will be hidden from all business views immediately.`}
                tone="warning"
                confirmLabel="Mark as test"
                onCancel={() => setBulkMarkTestDialog((d) => ({ ...d, open: false }))}
                onConfirm={() => bulkMarkTestMutation.mutate(bulkMarkTestDialog)}
                confirmDisabled={bulkMarkTestMutation.isPending || !bulkMarkTestDialog.reason.trim()}
                isPending={bulkMarkTestMutation.isPending}
            >
                <div className="space-y-3">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        These payments will be excluded from dashboards, reports, and sales-facing tables immediately and preserved for audit review.
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            rows={2}
                            value={bulkMarkTestDialog.reason}
                            onChange={(e) => setBulkMarkTestDialog((d) => ({ ...d, reason: e.target.value }))}
                            className="crm-input"
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={markAndDeleteDialog.open && markAndDeleteDialog.payments.length > 0}
                title={markAndDeleteDialog.payments.length === 1 && isExplicitTestPayment(markAndDeleteDialog.payments[0])
                    ? 'Delete test payment'
                    : markAndDeleteDialog.payments.length === 1
                        ? 'Mark as test & delete'
                        : `Mark ${markAndDeleteDialog.payments.length} payments as test & delete`}
                message={`${markAndDeleteDialog.payments.length} payment(s) will be marked as test and permanently deleted. Payments linked to live deals or transactions will be kept as test-only instead of being deleted.`}
                tone="danger"
                confirmLabel="Mark & delete"
                onCancel={() => setMarkAndDeleteDialog((d) => ({ ...d, open: false }))}
                onConfirm={() => markAndDeleteMutation.mutate(markAndDeleteDialog)}
                confirmDisabled={markAndDeleteMutation.isPending || !markAndDeleteDialog.reason.trim()}
                isPending={markAndDeleteMutation.isPending}
            >
                <div className="space-y-3">
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        CRM keeps a full audit snapshot before deletion. Payments blocked by live records will be hidden as test-only instead of deleted.
                    </div>
                    {markAndDeleteDialog.payments.length === 1 ? (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-700"><span className="font-semibold text-slate-900">Reference:</span> {markAndDeleteDialog.payments[0]?.transaction_reference || '—'}</p>
                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-700"><span className="font-semibold text-slate-900">Amount:</span> {formatCurrency(markAndDeleteDialog.payments[0]?.amount, resolveCurrency(markAndDeleteDialog.payments[0]?.currency))}</p>
                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-700"><span className="font-semibold text-slate-900">Client:</span> {markAndDeleteDialog.payments[0]?.client?.name || 'Unmatched'}</p>
                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-700"><span className="font-semibold text-slate-900">Classification:</span> {isExplicitTestPayment(markAndDeleteDialog.payments[0]) ? 'Admin-marked test' : 'Not yet marked'}</p>
                        </div>
                    ) : null}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            rows={2}
                            value={markAndDeleteDialog.reason}
                            onChange={(e) => setMarkAndDeleteDialog((d) => ({ ...d, reason: e.target.value }))}
                            className="crm-input"
                            placeholder="Explain why these test payments should be permanently removed."
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={manualCloseDialog.open && !!manualCloseDialog.payment}
                title="Close payment manually"
                message={manualCloseDialog.payment
                    ? `Close payment #${manualCloseDialog.payment.id} (${formatCurrency(manualCloseDialog.payment.amount, resolveCurrency(manualCloseDialog.payment.currency))}) and mark it as resolved.`
                    : ''}
                confirmLabel="Close payment"
                onCancel={() => setManualCloseDialog({ open: false, payment: null, reason_code: '', reason_note: '', converted_payment_id: '' })}
                onConfirm={() => {
                    if (manualCloseDialog.payment) {
                        manualCloseMutation.mutate({
                            paymentId: manualCloseDialog.payment.id,
                            reasonCode: manualCloseDialog.reason_code,
                            reasonNote: manualCloseDialog.reason_note.trim() || null,
                            convertedPaymentId: manualCloseDialog.converted_payment_id,
                        });
                    }
                }}
                confirmDisabled={
                    manualCloseMutation.isPending
                    || !manualCloseDialog.reason_code
                    || (manualCloseDialog.reason_code === 'other' && !manualCloseDialog.reason_note.trim())
                }
                isPending={manualCloseMutation.isPending}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="manual-close-reason-code" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <select
                            id="manual-close-reason-code"
                            value={manualCloseDialog.reason_code}
                            onChange={(event) => setManualCloseDialog((current) => ({ ...current, reason_code: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="">Select a reason…</option>
                            {PAYMENT_CLOSE_REASONS.map((reason) => (
                                <option key={reason.code} value={reason.code}>{reason.label}</option>
                            ))}
                        </select>
                    </div>
                    {manualCloseDialog.reason_code === 'customer_converted' ? (
                        <div>
                            <label htmlFor="manual-close-converted-payment" className="mb-1 block text-sm font-medium text-slate-700">
                                Converted payment ID <span className="font-normal text-slate-400">(optional)</span>
                            </label>
                            <input
                                id="manual-close-converted-payment"
                                type="number"
                                min="1"
                                value={manualCloseDialog.converted_payment_id}
                                onChange={(event) => setManualCloseDialog((current) => ({ ...current, converted_payment_id: event.target.value }))}
                                className="crm-input"
                                placeholder="Leave blank to auto-link the matching completed payment"
                            />
                            <p className="mt-1 text-xs text-slate-500">When left blank, CRM looks for a completed payment from the same client or phone and stores that link in the resolution metadata.</p>
                        </div>
                    ) : null}
                    <div>
                        <label htmlFor="manual-close-reason-note" className="mb-1 block text-sm font-medium text-slate-700">
                            Note {manualCloseDialog.reason_code === 'other' ? <span className="text-rose-600">*</span> : <span className="font-normal text-slate-400">(optional)</span>}
                        </label>
                        <textarea
                            id="manual-close-reason-note"
                            rows={3}
                            value={manualCloseDialog.reason_note}
                            onChange={(event) => setManualCloseDialog((current) => ({ ...current, reason_note: event.target.value }))}
                            className="crm-input"
                            placeholder={manualCloseDialog.reason_code === 'other' ? 'Required — what happened?' : 'Add context (attempts made, response received, etc.).'}
                            maxLength={1000}
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <PaymentImportDrawer
                open={importDrawerOpen}
                onClose={() => setImportDrawerOpen(false)}
                platformOptions={platformOptions}
                onCommitSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['payments'] });
                }}
            />
            <PaymentExportModal
                open={exportModalOpen}
                onClose={() => setExportModalOpen(false)}
                activeFilters={activeFilters}
            />

            {bundleDetailDialog.open ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" onClick={() => setBundleDetailDialog({ open: false, bundleId: null })}>
                    <div
                        className="relative mx-4 max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-2xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className="sticky top-0 z-10 border-b border-slate-100 bg-white/95 px-5 py-4 backdrop-blur">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-base font-semibold text-slate-900">Bundle Detail</h3>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Bundle #{bundleDetailDialog.bundleId}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setBundleDetailDialog({ open: false, bundleId: null })}
                                    className="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                >
                                    Close
                                </button>
                            </div>
                        </div>

                        <div className="p-5">
                            {bundleDetailLoading ? (
                                <div className="animate-pulse space-y-3">
                                    <div className="h-4 w-3/5 rounded bg-slate-200" />
                                    <div className="h-20 rounded-lg bg-slate-100" />
                                    <div className="h-32 rounded-lg bg-slate-100" />
                                </div>
                            ) : bundleDetailData?.bundle ? (
                                <div className="space-y-4">
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Reference:</span>{' '}
                                            <span className="crm-mono">{bundleDetailData.bundle.reference_root}</span>
                                        </p>
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Status:</span>{' '}
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                                bundleDetailData.bundle.status === 'committed' ? 'bg-emerald-100 text-emerald-700' :
                                                bundleDetailData.bundle.status === 'voided' ? 'bg-rose-100 text-rose-700' :
                                                bundleDetailData.bundle.status === 'compensation_failed' ? 'bg-amber-100 text-amber-700' :
                                                'bg-slate-100 text-slate-600'
                                            }`}>{bundleDetailData.bundle.status}</span>
                                        </p>
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Total paid:</span>{' '}
                                            {formatCurrency(bundleDetailData.bundle.total_amount, bundleDetailData.bundle.currency || 'KES')}
                                        </p>
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Allocated:</span>{' '}
                                            {formatCurrency(bundleDetailData.bundle.allocated_amount, bundleDetailData.bundle.currency || 'KES')}
                                        </p>
                                        {Number(bundleDetailData.bundle.unallocated_amount) > 0 ? (
                                            <p className="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                                <span className="font-semibold">Unallocated:</span>{' '}
                                                {formatCurrency(bundleDetailData.bundle.unallocated_amount, bundleDetailData.bundle.currency || 'KES')}
                                            </p>
                                        ) : null}
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Audit:</span>{' '}
                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                                bundleDetailData.bundle.audit_state === 'resolved' ? 'bg-emerald-100 text-emerald-700' :
                                                bundleDetailData.bundle.audit_state === 'voided' ? 'bg-rose-100 text-rose-700' :
                                                'bg-amber-100 text-amber-700'
                                            }`}>{bundleDetailData.bundle.audit_state?.replace(/_/g, ' ')}</span>
                                        </p>
                                        {bundleDetailData.bundle.created_by ? (
                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                                <span className="font-semibold text-slate-800">Created by:</span>{' '}
                                                {bundleDetailData.bundle.created_by.name}
                                            </p>
                                        ) : null}
                                    </div>

                                    {bundleDetailData.bundle.reason ? (
                                        <p className="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                            <span className="font-semibold text-slate-800">Reason:</span> {bundleDetailData.bundle.reason}
                                        </p>
                                    ) : null}

                                    <div>
                                        <h4 className="mb-2 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Child Payments ({bundleDetailData.bundle.payments?.length || 0})</h4>
                                        <div className="space-y-1.5">
                                            {(bundleDetailData.bundle.payments || []).map((p) => (
                                                <div key={p.id} className="flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2">
                                                    <div className="min-w-0">
                                                        <p className="crm-mono text-xs text-slate-700">{p.transaction_reference || `#${p.id}`}</p>
                                                        <p className="text-[11px] text-slate-500">{p.client_name || `Client #${p.client_id || '?'}`}</p>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-medium text-slate-700">{formatCurrency(p.amount, bundleDetailData.bundle.currency || 'KES')}</span>
                                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                                            p.status === 'completed' ? 'bg-emerald-100 text-emerald-700' :
                                                            p.status === 'failed' ? 'bg-rose-100 text-rose-700' :
                                                            'bg-slate-100 text-slate-600'
                                                        }`}>{p.status}</span>
                                                        {p.resolution_code ? (
                                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                                                p.resolution_code === 'reversed' ? 'bg-rose-100 text-rose-700' :
                                                                'bg-amber-100 text-amber-700'
                                                            }`}>{p.resolution_code}</span>
                                                        ) : null}
                                                        {p.deal_status ? (
                                                            <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] ${
                                                                p.deal_status === 'active' ? 'bg-emerald-100 text-emerald-700' :
                                                                p.deal_status === 'cancelled' ? 'bg-rose-100 text-rose-700' :
                                                                'bg-slate-100 text-slate-600'
                                                            }`}>sub: {p.deal_status}</span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    {bundleDetailData.divergence && bundleDetailData.divergence.length > 0 ? (
                                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                            <h4 className="text-xs font-semibold text-amber-800">Divergence Detected</h4>
                                            <p className="mt-1 text-xs text-amber-700">
                                                Some child subscriptions have changed since bundle creation. Bundle void is blocked until these are resolved manually.
                                            </p>
                                            <ul className="mt-2 space-y-1">
                                                {bundleDetailData.divergence.map((d, i) => (
                                                    <li key={i} className="text-xs text-amber-700">
                                                        <span className="crm-mono font-semibold">Deal #{d.deal_id}:</span> {d.reason}
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ) : null}

                                    {user?.role === 'admin' && bundleDetailData.bundle.status === 'committed' && bundleDetailData.bundle.audit_state === 'pending_finance_review' ? (
                                        <div className="border-t border-slate-100 pt-4">
                                            <button
                                                type="button"
                                                onClick={() => approveBundleMutation.mutate(bundleDetailData.bundle.id)}
                                                disabled={approveBundleMutation.isPending}
                                                className="w-full rounded-md border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {approveBundleMutation.isPending ? 'Approving…' : 'Approve bundle'}
                                            </button>
                                            <p className="mt-2 text-center text-[11px] text-slate-500">
                                                Marks the bundle and its child payments as resolved so they count toward confirmed revenue.
                                            </p>
                                        </div>
                                    ) : null}

                                    {user?.role === 'admin' && bundleDetailData.bundle.status !== 'voided' && (!bundleDetailData.divergence || bundleDetailData.divergence.length === 0) ? (
                                        <div className="border-t border-slate-100 pt-4">
                                            <button
                                                type="button"
                                                onClick={() => setVoidBundleDialog({
                                                    open: true,
                                                    bundleId: bundleDetailData.bundle.id,
                                                    reasonCode: 'fraud_suspected',
                                                    notes: '',
                                                })}
                                                className="w-full rounded-md border border-rose-300 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                                            >
                                                Void entire bundle
                                            </button>
                                            <p className="mt-2 text-center text-[11px] text-slate-500">
                                                Voids all child payments, deactivates all linked subscriptions, and deactivates WordPress profiles. This action cannot be undone.
                                            </p>
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">Bundle not found.</p>
                            )}
                        </div>
                    </div>
                </div>
            ) : null}

            <ConfirmDialog
                open={voidBundleDialog.open && !!voidBundleDialog.bundleId}
                title="Void payment bundle"
                message={`This will reverse all child payments, deactivate all linked subscriptions, deactivate WordPress profiles, and may mark clients as high risk. This cannot be undone.`}
                confirmLabel="Void bundle"
                variant="danger"
                onCancel={() => setVoidBundleDialog({ open: false, bundleId: null, reasonCode: 'fraud_suspected', notes: '' })}
                onConfirm={() => {
                    if (voidBundleDialog.bundleId) {
                        voidBundleMutation.mutate({
                            bundleId: voidBundleDialog.bundleId,
                            reason_code: voidBundleDialog.reasonCode,
                            notes: voidBundleDialog.notes.trim(),
                        });
                    }
                }}
                confirmDisabled={voidBundleMutation.isPending || !voidBundleDialog.reasonCode}
                isPending={voidBundleMutation.isPending}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="void-bundle-reason-code" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <select
                            id="void-bundle-reason-code"
                            value={voidBundleDialog.reasonCode}
                            onChange={(event) => setVoidBundleDialog((current) => ({ ...current, reasonCode: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="fraud_suspected">Fraud suspected</option>
                            <option value="payment_reversed">Payment reversed</option>
                            <option value="duplicate_entry">Duplicate entry</option>
                            <option value="invalid_reference">Invalid reference</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="void-bundle-notes" className="mb-1 block text-sm font-medium text-slate-700">Notes</label>
                        <textarea
                            id="void-bundle-notes"
                            rows={3}
                            value={voidBundleDialog.notes}
                            onChange={(event) => setVoidBundleDialog((current) => ({ ...current, notes: event.target.value }))}
                            className="crm-input"
                            placeholder="Provide additional context for the void action."
                        />
                    </div>
                    <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        {voidBundleDialog.reasonCode === 'fraud_suspected' || voidBundleDialog.reasonCode === 'payment_reversed'
                            ? 'All clients in this bundle will be flagged as high risk.'
                            : 'Client risk flags will not be changed for this reason code.'}
                    </p>
                </div>
            </ConfirmDialog>
        </div>
    );
}
