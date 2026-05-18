import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useParams, useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';
import Timeline from '../components/Timeline';
import ConfirmDialog from '../components/ConfirmDialog';
import CredentialDispatchDrawer from '../components/CredentialDispatchDrawer';
import SupportBoardChat from '../components/SupportBoardChat';
import ClientSubscriptionDeactivationDialog from '../components/subscriptions/ClientSubscriptionDeactivationDialog';
import { useToast } from '../components/ToastProvider';
import { getAllowedCrmPaymentMethods, getWalletAutoRenewPresentation } from '../utils/billingMethodPolicy';
import { getDefaultPaymentLinkProviderKey, getEnabledPaymentLinkProviders } from '../utils/paymentLinkProviders';
import { DEAL_DEACTIVATION_REASON_OPTIONS, LINKED_PAYMENT_ACTION_OPTIONS, defaultLinkedPaymentAction } from '../utils/deactivationOptions';
import ClientHealthSection from '../components/ClientHealthSection';
import GenerateBioButton from '../components/seo/GenerateBioButton';
import SeoQualityPanel from '../components/seo/SeoQualityPanel';
import ClientAnalyticsTab from '../components/ClientAnalyticsTab';
import KycPanel from '../components/kyc/KycPanel';
import { proxyImageUrl } from '../utils/imageProxy';
import { deriveClientProfileState, isClientTrueForeverPlan } from '../utils/clientProfileState';
import { getMediaUploadPreflight, useMediaUploads } from '../components/MediaUploadProvider';

const mediaProxyAvailabilityCache = new Map();

function formatCurrency(value, currency = 'KES') {
    return `${currency} ${Number(value || 0).toLocaleString()}`;
}

function normalizeDiscountPercentage(value) {
    const parsed = Number.parseFloat(String(value ?? '').trim());
    if (!Number.isFinite(parsed) || parsed <= 0) return 0;
    return roundPercent(parsed);
}

function roundMoney(value) {
    const parsed = Number(value || 0);
    if (!Number.isFinite(parsed)) return 0;
    return Math.round(parsed * 100) / 100;
}

function roundPercent(value) {
    const parsed = Number(value || 0);
    if (!Number.isFinite(parsed)) return 0;
    return Math.round(parsed * 100) / 100;
}

function discountedAmount(baseAmount, discountPercentage) {
    const safeBase = Number(baseAmount || 0);
    const safeDiscount = normalizeDiscountPercentage(discountPercentage);
    if (safeDiscount <= 0) return safeBase;
    return roundMoney(safeBase * (1 - safeDiscount / 100));
}

function normalizePayableAmount(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') return null;

    const parsed = Number.parseFloat(raw);
    if (!Number.isFinite(parsed) || parsed <= 0) return null;

    return roundMoney(parsed);
}

function discountPercentageFromPayable(baseAmount, payableAmount) {
    const safeBase = Number(baseAmount || 0);
    const safePayable = Number(payableAmount || 0);
    if (!Number.isFinite(safeBase) || !Number.isFinite(safePayable) || safeBase <= 0) return 0;
    return roundPercent(((safeBase - safePayable) / safeBase) * 100);
}

function effectiveDiscountedAmount(baseAmount, discountPercentage, payableAmount) {
    const payable = normalizePayableAmount(payableAmount);
    const payableDiscount = payable !== null ? discountPercentageFromPayable(baseAmount, payable) : 0;
    if (payable !== null && payableDiscount >= 1 && payableDiscount <= 99) {
        return payable;
    }

    return discountedAmount(baseAmount, discountPercentage);
}

function formatNumericInput(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '';
    return String(roundMoney(parsed));
}

function formatPercentInput(value) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) return '';
    return String(roundPercent(parsed));
}

function DiscountPricingEditor({
    idPrefix,
    applyDiscount,
    onToggle,
    baseAmount,
    currency,
    payableAmount,
    discountPercentage,
    discountPin,
    discountedTotal,
    savingsAmount,
    onPayableChange,
    onPayableBlur,
    onPercentageChange,
    onPinChange,
}) {
    const discountValue = normalizeDiscountPercentage(discountPercentage);
    const baseLabel = formatCurrency(baseAmount, currency);
    const payableLabel = formatCurrency(discountedTotal, currency);
    const savingsLabel = formatCurrency(Math.max(0, savingsAmount), currency);
    const minimumPayableAmount = roundMoney(Number(baseAmount || 0) * 0.01);
    const discountOutOfRange = discountValue > 99;
    const payableInputId = `${idPrefix}-discount-payable`;
    const percentageInputId = `${idPrefix}-discount-percentage`;
    const pinInputId = `${idPrefix}-discount-pin`;

    return (
        <div className="space-y-3 rounded-md border border-amber-200 bg-amber-50/60 p-3">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input
                    type="checkbox"
                    checked={applyDiscount}
                    onChange={(event) => onToggle(event.target.checked)}
                    className="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-200"
                />
                Apply Discount
            </label>

            {applyDiscount ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
                        <div>
                            <label htmlFor={payableInputId} className="mb-1 block text-sm font-medium text-slate-700">Final payable amount</label>
                            <input
                                id={payableInputId}
                                type="number"
                                min="0"
                                max={baseAmount || undefined}
                                step="0.01"
                                value={payableAmount}
                                onChange={(event) => onPayableChange(event.target.value)}
                                onBlur={onPayableBlur}
                                className="crm-input"
                                placeholder={`e.g. ${formatNumericInput(discountedAmount(baseAmount, 10)) || '2500'}`}
                            />
                            {discountOutOfRange ? (
                                <p className="mt-1 text-xs text-rose-600">
                                    Minimum payable is {formatCurrency(minimumPayableAmount, currency)} for the 99% discount limit.
                                </p>
                            ) : null}
                        </div>
                        <div>
                            <label htmlFor={percentageInputId} className="mb-1 block text-sm font-medium text-slate-700">Discount %</label>
                            <input
                                id={percentageInputId}
                                type="number"
                                min="1"
                                max="99"
                                step="0.01"
                                value={discountPercentage}
                                onChange={(event) => onPercentageChange(event.target.value)}
                                className="crm-input"
                                placeholder="e.g. 20"
                            />
                        </div>
                    </div>

                    <div>
                        <label htmlFor={pinInputId} className="mb-1 block text-sm font-medium text-slate-700">Discount PIN</label>
                        <input
                            id={pinInputId}
                            type="password"
                            inputMode="numeric"
                            maxLength={6}
                            value={discountPin}
                            onChange={(event) => onPinChange(event.target.value.replace(/\D/g, '').slice(0, 6))}
                            className="crm-input"
                            placeholder="Enter discount PIN"
                        />
                    </div>

                    <div className="rounded-md border border-amber-200 bg-white px-3 py-2 text-sm text-slate-700">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="font-medium text-slate-900">Price preview</p>
                                <p className="mt-1 flex flex-wrap items-center gap-2">
                                    <span className="line-through text-slate-500">{baseLabel}</span>
                                    <span className="text-base font-semibold text-slate-900">{payableLabel}</span>
                                    {discountValue > 0 ? (
                                        <span className="text-xs font-semibold uppercase tracking-wide text-amber-700">{discountValue}% off</span>
                                    ) : null}
                                </p>
                            </div>
                            <div className="text-right">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-400">Savings</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{savingsLabel}</p>
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-slate-500">Final payable is saved exactly when entered. Percentage is used for approvals and audit display.</p>
                    </div>
                </>
            ) : null}
        </div>
    );
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function formatProfileDate(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '—';
    return date.toLocaleDateString();
}

function parseDateValue(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

function parseUnixDate(value) {
    const timestamp = Number(value || 0);
    if (!Number.isFinite(timestamp) || timestamp <= 0) return null;
    const date = new Date(timestamp * 1000);
    return Number.isNaN(date.getTime()) ? null : date;
}

function resolveClientExpiryDate(client) {
    return parseDateValue(client?.active_deal?.expires_at)
        || parseUnixDate(client?.escort_expire)
        || parseUnixDate(client?.premium_expire)
        || parseUnixDate(client?.featured_expire)
        || null;
}

function buildSubscriptionExpiryMessage(client, isForeverPlan = false) {
    const expiryDate = resolveClientExpiryDate(client);

    if (expiryDate) {
        const label = formatProfileDate(expiryDate);
        return expiryDate < new Date()
            ? `Subscription expired on ${label}`
            : `Subscription will expire on ${label}`;
    }

    return isForeverPlan ? 'Subscription does not expire' : 'No expiry date tracked';
}

function buildProfileCopyMessage(client, isForeverPlan = false) {
    const profileUrl = client?.wp_profile_permalink || client?.wp_profile_url || '';
    return `Profile: ${profileUrl}\n${buildSubscriptionExpiryMessage(client, isForeverPlan)}`;
}

async function copyTextValue(value) {
    if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return;
    }

    throw new Error('Clipboard access is unavailable.');
}

function resolveDialogPredictedLifecycle(dialogType, record) {
    const persisted = String(record?.subscription_lifecycle || '').toLowerCase();
    if (persisted === 'new' || persisted === 'renewal') {
        return persisted;
    }

    return dialogType === 'renew' || dialogType === 'extend' ? 'renewal' : 'new';
}

function subscriptionLifecycleHelperText(lifecycle) {
    return lifecycle === 'renewal'
        ? 'Prefilled as Renewal because this client already has prior subscription history in this market.'
        : 'Prefilled as New because no prior subscription history was found for this client in this market.';
}

function titleize(value) {
    if (!value) return '—';
    return String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
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

function formatRelativeFromUnix(unixTs) {
    const ts = Number(unixTs || 0);
    if (!ts) return '—';

    const diffSeconds = Math.floor(Date.now() / 1000) - ts;
    if (diffSeconds < 60) return 'just now';

    const minutes = Math.floor(diffSeconds / 60);
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;

    const months = Math.floor(days / 30);
    if (months < 12) return `${months}mo ago`;

    const years = Math.floor(months / 12);
    return `${years}y ago`;
}

function normalizeNewBadgeMode(value, fallbackForceNew = false) {
    const normalized = String(value || '').trim().toLowerCase();
    if (normalized === 'auto' || normalized === 'force_on' || normalized === 'force_off') {
        return normalized;
    }

    return fallbackForceNew ? 'force_on' : 'auto';
}

function getNewBadgeModePresentation(mode) {
    if (mode === 'force_on') {
        return {
            chipLabel: 'NEW pinned',
            buttonLabel: 'NEW pinned',
            buttonTitle: 'NEW badge is pinned on the public profile',
            buttonClassName: 'border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100',
            toastMessage: 'NEW badge pinned to profile.',
        };
    }

    if (mode === 'force_off') {
        return {
            chipLabel: 'NEW hidden',
            buttonLabel: 'NEW hidden',
            buttonTitle: 'NEW badge is hidden on the public profile',
            buttonClassName: 'border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100',
            toastMessage: 'NEW badge hidden from profile.',
        };
    }

    return {
        chipLabel: '',
        buttonLabel: 'NEW auto',
        buttonTitle: 'NEW badge follows the normal publish-date rule',
        buttonClassName: 'border-slate-200 bg-white text-slate-500 hover:border-slate-300',
        toastMessage: 'NEW badge returned to automatic behavior.',
    };
}

const NEW_BADGE_MODE_OPTIONS = [
    {
        mode: 'auto',
        title: 'Automatic',
        description: 'Use the normal publish-date rule from WordPress.',
    },
    {
        mode: 'force_on',
        title: 'Force On',
        description: 'Always show the NEW badge on the public listing and profile.',
    },
    {
        mode: 'force_off',
        title: 'Force Off',
        description: 'Hide the NEW badge even if the profile is still naturally new.',
    },
];

function toDateString(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toISOString().slice(0, 10);
}

const FOREVER_PLAN_TOOLTIP = 'Reference: This profile is intentionally kept active to avoid zero-escort locations, which protects search ranking.';

const PROFILE_ENUM_CHOICES = {
    gender: [
        { code: '1', label: 'Female' },
        { code: '2', label: 'Male' },
        { code: '3', label: 'Couple' },
        { code: '4', label: 'Gay' },
        { code: '5', label: 'Transsexual' },
    ],
    ethnicity: [
        { code: '1', label: 'Latin' },
        { code: '2', label: 'Caucasian' },
        { code: '3', label: 'Black' },
        { code: '4', label: 'White' },
        { code: '5', label: 'MiddleEast' },
        { code: '6', label: 'Asian' },
        { code: '7', label: 'Indian' },
        { code: '8', label: 'Aborigine' },
        { code: '9', label: 'Native American' },
        { code: '10', label: 'Other' },
    ],
    build: [
        { code: '1', label: 'Skinny' },
        { code: '2', label: 'Slim' },
        { code: '3', label: 'Regular' },
        { code: '4', label: 'Curvy' },
        { code: '5', label: 'Fat' },
    ],
    services: [
        { code: '1', label: 'BDSM' },
        { code: '2', label: 'Couples' },
        { code: '3', label: 'Domination' },
        { code: '4', label: 'Escort' },
        { code: '5', label: 'Massage' },
        { code: '6', label: 'Fetish' },
        { code: '7', label: 'Mature' },
        { code: '8', label: 'GFE' },
    ],
    haircolor: [
        { code: '1', label: 'Black' }, { code: '2', label: 'Blonde' },
        { code: '3', label: 'Brown' }, { code: '4', label: 'Brunette' },
        { code: '5', label: 'Chestnut' }, { code: '6', label: 'Auburn' },
        { code: '7', label: 'Dark-blonde' }, { code: '8', label: 'Golden' },
        { code: '9', label: 'Red' }, { code: '10', label: 'Grey' },
        { code: '11', label: 'Silver' }, { code: '12', label: 'White' },
        { code: '13', label: 'Other' },
    ],
    hairlength: [
        { code: '1', label: 'Bald' }, { code: '2', label: 'Short' },
        { code: '3', label: 'Shoulder' }, { code: '4', label: 'Long' },
        { code: '5', label: 'Very Long' },
    ],
    bustsize: [
        { code: '1', label: 'Very small' }, { code: '2', label: 'Small (A)' },
        { code: '3', label: 'Medium (B)' }, { code: '4', label: 'Large (C)' },
        { code: '5', label: 'Very Large (D)' }, { code: '6', label: 'Enormous (E+)' },
    ],
    looks: [
        { code: '1', label: 'Nothing Special' }, { code: '2', label: 'Average' },
        { code: '3', label: 'Sexy' }, { code: '4', label: 'Ultra Sexy' },
    ],
    smoker: [
        { code: '1', label: 'Yes' }, { code: '2', label: 'No' },
    ],
    availability: [
        { code: '1', label: 'Incall' }, { code: '2', label: 'Outcall' },
    ],
    languagelevel: [
        { code: '1', label: 'Minimal' }, { code: '2', label: 'Conversational' },
        { code: '3', label: 'Fluent' },
    ],
};

const LEGACY_HEIGHT_CODE_TO_CM = {
    1: '128',
    2: '134',
    3: '140',
    4: '146',
    5: '152',
    6: '155',
    7: '158',
    8: '162',
    9: '165',
    10: '168',
    11: '171',
    12: '174',
    13: '177',
    14: '180',
    15: '183',
    16: '189',
    17: '195',
    18: '201',
    19: '207',
    20: '213',
};

const PROFILE_ENUM_OPTIONS = Object.fromEntries(
    Object.entries(PROFILE_ENUM_CHOICES).map(([field, options]) => [
        field,
        options.map((option) => ({
            value: option.code,
            plainLabel: option.label,
            label: `${option.label} (${option.code})`,
        })),
    ]),
);

const PROFILE_ENUM_LOOKUP = Object.fromEntries(
    Object.entries(PROFILE_ENUM_OPTIONS).map(([field, options]) => {
        const byCode = new Map();
        const byLabel = new Map();

        options.forEach((option) => {
            byCode.set(option.value, option.value);
            byLabel.set(normalizeLookupToken(option.plainLabel), option.value);
            byLabel.set(normalizeLookupToken(option.label), option.value);
            byLabel.set(normalizeLookupToken(`${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.plainLabel} ${option.value}`), option.value);
            byLabel.set(normalizeLookupToken(`${option.value} ${option.plainLabel}`), option.value);
        });

        return [field, { byCode, byLabel }];
    }),
);

function normalizeLookupToken(value) {
    const normalized = String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

    return normalized;
}

function resolveProfileEnumValue(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return raw;

    if (lookup.byCode.has(raw)) {
        return lookup.byCode.get(raw);
    }

    const numericCode = raw.replace(/[^0-9]/g, '');
    if (numericCode) {
        const normalizedCode = String(Number.parseInt(numericCode, 10));
        if (lookup.byCode.has(normalizedCode)) {
            return normalizedCode;
        }
    }

    const token = normalizeLookupToken(raw);
    if (lookup.byLabel.has(token)) {
        return lookup.byLabel.get(token);
    }

    return raw;
}

function isKnownProfileEnumCode(field, value) {
    const raw = String(value ?? '').trim();
    if (!raw) return true;

    const lookup = PROFILE_ENUM_LOOKUP[field];
    if (!lookup) return true;

    return lookup.byCode.has(raw);
}

function parseProfileServices(value) {
    const tokens = Array.isArray(value)
        ? value
        : String(value ?? '')
            .split(',')
            .map((item) => item.trim());

    const normalized = [];
    tokens.forEach((token) => {
        const raw = String(token ?? '').trim();
        if (!raw) return;

        const resolved = resolveProfileEnumValue('services', raw);
        if (!normalized.includes(resolved)) {
            normalized.push(resolved);
        }
    });

    return normalized;
}

function toDateInputValue(year, month, day) {
    const y = Number.parseInt(year, 10);
    const m = Number.parseInt(month, 10);
    const d = Number.parseInt(day, 10);
    if (!Number.isInteger(y) || !Number.isInteger(m) || !Number.isInteger(d)) return '';
    if (m < 1 || m > 12 || d < 1 || d > 31) return '';

    const paddedMonth = String(m).padStart(2, '0');
    const paddedDay = String(d).padStart(2, '0');
    return `${y}-${paddedMonth}-${paddedDay}`;
}

function normalizeBirthdayForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';

    const ymdMatch = raw.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/);
    if (ymdMatch) {
        return toDateInputValue(ymdMatch[1], ymdMatch[2], ymdMatch[3]);
    }

    const dmyMatch = raw.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$/);
    if (dmyMatch) {
        // Stored legacy format may be dd/mm/yyyy or mm/dd/yyyy; date parser resolves valid local date.
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }

        return toDateInputValue(dmyMatch[3], dmyMatch[2], dmyMatch[1]);
    }

    if (/^\d{10,13}$/.test(raw)) {
        const numeric = Number.parseInt(raw, 10);
        const millis = raw.length === 13 ? numeric : numeric * 1000;
        const parsed = new Date(millis);
        if (!Number.isNaN(parsed.getTime())) {
            return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
        }
    }

    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    return toDateInputValue(parsed.getFullYear(), parsed.getMonth() + 1, parsed.getDate());
}

function normalizeBirthdayForSave(value) {
    const normalized = normalizeBirthdayForEditor(value);
    return normalized || null;
}

function normalizeHeightForEditor(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    if (LEGACY_HEIGHT_CODE_TO_CM[raw]) return LEGACY_HEIGHT_CODE_TO_CM[raw];

    const cmInParens = raw.match(/\((\d+(?:\.\d+)?)\)/);
    if (cmInParens) {
        return String(Math.round(Number.parseFloat(cmInParens[1])));
    }

    const explicitCm = raw.match(/(\d+(?:\.\d+)?)\s*cm/i);
    if (explicitCm) {
        return String(Math.round(Number.parseFloat(explicitCm[1])));
    }

    const feetInches = raw.match(/(\d+)\s*(?:ft|')\s*(\d+)?/i);
    if (feetInches) {
        const feet = Number.parseInt(feetInches[1], 10);
        const inches = Number.parseInt(feetInches[2] || '0', 10);
        if (Number.isFinite(feet) && Number.isFinite(inches)) {
            return String(Math.round((feet * 12 + inches) * 2.54));
        }
    }

    const numeric = raw.match(/^\d+(?:\.\d+)?$/);
    if (numeric) {
        return String(Math.round(Number.parseFloat(raw)));
    }

    return raw;
}

function normalizeHeightForSave(value) {
    const normalized = normalizeHeightForEditor(value);
    if (!normalized) return null;
    return normalized;
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
    const [searchParams, setSearchParams] = useSearchParams();
    const queryClient = useQueryClient();
    const toast = useToast();
    const { startClientMediaUpload, uploadsForClient, retryUpload, dismissUpload } = useMediaUploads();
    const profileLinkPopoverRef = useRef(null);
    const requestedTab = (searchParams.get('tab') || '').toLowerCase();
    const initialTab = ['overview', 'deals', 'notes', 'timeline', 'chat', 'wallet', 'payments', 'edit_profile', 'profile_health']
        .includes(requestedTab)
        ? requestedTab
        : 'overview';
    const [activeTab, setActiveTab] = useState(initialTab);
    const [noteForm, setNoteForm] = useState({ note_type: 'internal', content: '', follow_up_at: '' });
    const [showDealModal, setShowDealModal] = useState(false);
    const [showPaymentLinkModal, setShowPaymentLinkModal] = useState(false);
    const [showDeleteDialog, setShowDeleteDialog] = useState(false);
    const [deletePreview, setDeletePreview] = useState(null);
    const [deleteConfirmText, setDeleteConfirmText] = useState('');
    const [deleteReason, setDeleteReason] = useState('Client deleted from CRM');
    const [paymentLinkResult, setPaymentLinkResult] = useState(null);
    const [activationDialog, setActivationDialog] = useState({
        open: false,
        dealId: null,
        dealLabel: '',
    });
    const [activationReason, setActivationReason] = useState('Activation initiated from client profile');
    const [activationPaymentMethod, setActivationPaymentMethod] = useState('manual');
    const [activationSubscriptionLifecycle, setActivationSubscriptionLifecycle] = useState('new');
    const [activationSubscriptionLifecycleReason, setActivationSubscriptionLifecycleReason] = useState('');
    const [activationPaymentReference, setActivationPaymentReference] = useState('');
    const [activationFreeTrialPin, setActivationFreeTrialPin] = useState('');
    const [activationPaymentLinkProvider, setActivationPaymentLinkProvider] = useState('');
    const [activationApplyDiscount, setActivationApplyDiscount] = useState(false);
    const [activationDiscountPercentage, setActivationDiscountPercentage] = useState('');
    const [activationDiscountPayableAmount, setActivationDiscountPayableAmount] = useState('');
    const [activationDiscountPin, setActivationDiscountPin] = useState('');
    const [showSyncConfirm, setShowSyncConfirm] = useState(false);
    const [profileSection, setProfileSection] = useState('personal');
    const [profileForm, setProfileForm] = useState(null);
    const [profileReason, setProfileReason] = useState('Profile edited from CRM');
    const [profileForce, setProfileForce] = useState(false);
    const [profileConflict, setProfileConflict] = useState(null);
    const [mediaUploadFiles, setMediaUploadFiles] = useState([]);
    const [mediaUploadSetMain, setMediaUploadSetMain] = useState(false);
    const mediaUploadInputRef = useRef(null);
    const [healthAction, setHealthAction] = useState('keep_primary');
    const [healthReason, setHealthReason] = useState('Duplicate resolution from CRM');
    const [selectedDuplicateIds, setSelectedDuplicateIds] = useState([]);
    const [updatePhoneTargetId, setUpdatePhoneTargetId] = useState('');
    const [updatePhoneValue, setUpdatePhoneValue] = useState('');
    const [showCredentialDrawer, setShowCredentialDrawer] = useState(false);
    const [dealActionDialog, setDealActionDialog] = useState({ type: null, deal: null });
    const [clientDeactivateDialog, setClientDeactivateDialog] = useState({
        open: false,
        reasonCode: 'other',
        reasonNotes: 'Deactivated from client profile',
        notifyClient: false,
    });
    const [deactivationReasonCode, setDeactivationReasonCode] = useState('other');
    const [deactivationReasonNotes, setDeactivationReasonNotes] = useState('Deactivated from client profile');
    const [deactivationLinkedPaymentAction, setDeactivationLinkedPaymentAction] = useState('none');
    const [extendDays, setExtendDays] = useState('7');
    const [extendReason, setExtendReason] = useState('Extended from client profile');
    const [renewDays, setRenewDays] = useState('30');
    const [renewReason, setRenewReason] = useState('Renewed from client profile');
    const [dealPaymentMethod, setDealPaymentMethod] = useState('manual');
    const [dealSubscriptionLifecycle, setDealSubscriptionLifecycle] = useState('renewal');
    const [dealSubscriptionLifecycleReason, setDealSubscriptionLifecycleReason] = useState('');
    const [dealPaymentReference, setDealPaymentReference] = useState('');
    const [dealFreeTrialPin, setDealFreeTrialPin] = useState('');
    const [dealPaymentLinkProvider, setDealPaymentLinkProvider] = useState('');
    const [dealApplyDiscount, setDealApplyDiscount] = useState(false);
    const [dealDiscountPercentage, setDealDiscountPercentage] = useState('');
    const [dealDiscountPayableAmount, setDealDiscountPayableAmount] = useState('');
    const [dealDiscountPin, setDealDiscountPin] = useState('');
    const [notifyClient, setNotifyClient] = useState(false);
    const [showNewBadgeDialog, setShowNewBadgeDialog] = useState(false);
    const [showTourModal, setShowTourModal] = useState(false);
    const [tourForm, setTourForm] = useState({ city: '', start: '', end: '', phone: '' });
    const [notificationTemplateId, setNotificationTemplateId] = useState('');
    const [notificationMessage, setNotificationMessage] = useState('');
    const [walletTopupForm, setWalletTopupForm] = useState({
        amount: '',
        pin: '',
        reason: 'Manual wallet top-up from client profile',
    });
    const [walletAdjustmentForm, setWalletAdjustmentForm] = useState({
        type: 'debit',
        amount: '',
        pin: '',
        reason: 'Wallet adjustment from client profile',
    });
    const [showProfileLinkPeek, setShowProfileLinkPeek] = useState(false);

    const navigateToTab = useCallback((tabKey, sectionKey = null) => {
        setActiveTab(tabKey);
        if (sectionKey) setProfileSection(sectionKey);
        const next = new URLSearchParams(searchParams);
        if (tabKey === 'overview') next.delete('tab');
        else next.set('tab', tabKey);
        setSearchParams(next, { replace: true });
    }, [searchParams, setSearchParams]);

    const { data: client, isLoading } = useQuery({
        queryKey: ['client', id],
        queryFn: () => api.get(`/crm/clients/${id}`).then((r) => r.data),
    });
    const platformPhonePrefix = client?.platform?.phone_prefix || '254';
    const clientPlatformId = Number(client?.platform_id || client?.platform?.id || 0);

    const { data: meData } = useQuery({
        queryKey: ['me'],
        queryFn: () => api.get('/crm/me').then((response) => response.data),
    });
    const currentUser = meData?.user || null;
    const isReadOnly = currentUser?.role === 'marketing';
    const canManageWallet = ['admin', 'sub_admin', 'sales'].includes(String(currentUser?.role || ''));
    const canDeleteClient = ['admin', 'sub_admin'].includes(String(currentUser?.role || ''));
    const canOverridePaymentLinkProvider = ['admin', 'sub_admin'].includes(String(currentUser?.role || ''));

    const { data: timelineData } = useQuery({
        queryKey: ['client-timeline', id],
        queryFn: () => api.get(`/crm/clients/${id}/timeline`).then((r) => r.data),
        enabled: activeTab === 'timeline',
    });

    const { data: products } = useQuery({
        queryKey: ['products', clientPlatformId],
        queryFn: () => api.get('/crm/products', { params: { platform_id: clientPlatformId } }).then((r) => r.data),
        enabled: clientPlatformId > 0,
    });

    const { data: wpProfileData, error: wpProfileError } = useQuery({
        queryKey: ['client-wp-profile', id],
        queryFn: () => api.get(`/crm/clients/${id}/wp-profile`).then((r) => r.data),
        enabled: activeTab === 'edit_profile' && Number(client?.wp_post_id || 0) > 0,
        retry: false,
        refetchOnWindowFocus: false,
    });

    useEffect(() => {
        if (!showProfileLinkPeek) {
            return undefined;
        }

        const handlePointerDown = (event) => {
            if (profileLinkPopoverRef.current && !profileLinkPopoverRef.current.contains(event.target)) {
                setShowProfileLinkPeek(false);
            }
        };

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                setShowProfileLinkPeek(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [showProfileLinkPeek]);

    const { data: mediaData, isLoading: mediaLoading, error: mediaError } = useQuery({
        queryKey: ['client-media', id],
        queryFn: () => api.get(`/crm/clients/${id}/media`).then((r) => r.data),
        enabled: activeTab === 'edit_profile' && profileSection === 'media' && Number(client?.wp_post_id || 0) > 0,
        retry: false,
        refetchOnWindowFocus: false,
    });

    const { data: healthData, isLoading: healthLoading } = useQuery({
        queryKey: ['client-health', id],
        queryFn: () => api.get(`/crm/clients/${id}/health`).then((r) => r.data),
        enabled: activeTab === 'profile_health',
    });

    const { data: citiesData } = useQuery({
        queryKey: ['cities', clientPlatformId],
        queryFn: () =>
            api.get('/crm/clients/cities', {
                params: clientPlatformId ? { platform_id: clientPlatformId } : {},
            }).then((r) => r.data),
        enabled: activeTab === 'edit_profile' && profileSection === 'contact',
    });
    const availableCities = citiesData?.cities || [];

    const {
        data: walletData,
        isLoading: walletLoading,
        refetch: refetchWallet,
        isFetching: walletFetching,
    } = useQuery({
        queryKey: ['client-wallet', id],
        queryFn: () => api.get(`/crm/clients/${id}/wallet`).then((r) => r.data),
        enabled: activeTab === 'wallet',
    });

    const [analyticsPeriod, setAnalyticsPeriod] = useState('30d');
    const analyticsRange = useMemo(() => {
        const to = new Date();
        const from = new Date();
        const periodDays = analyticsPeriod === '7d' ? 7 : analyticsPeriod === '90d' ? 90 : 30;

        from.setDate(from.getDate() - periodDays);

        return {
            from: toDateString(from),
            to: toDateString(to),
        };
    }, [analyticsPeriod]);

    const {
        data: analyticsData,
        isLoading: analyticsLoading,
        error: analyticsError,
    } = useQuery({
        queryKey: ['client-analytics', id, analyticsRange],
        queryFn: () => api.get(`/crm/clients/${id}/analytics`, { params: analyticsRange }).then((response) => response.data),
        enabled: activeTab === 'analytics' && Number(client?.wp_post_id || 0) > 0,
        staleTime: 300_000,
    });

    const { data: completenessData } = useQuery({
        queryKey: ['client-completeness', id],
        queryFn: () => api.get(`/crm/clients/${id}/completeness`).then((r) => r.data),
        enabled: activeTab === 'overview',
    });

    const {
        data: retentionInsight,
        isLoading: retentionInsightLoading,
    } = useQuery({
        queryKey: ['client-retention-insight', id],
        queryFn: () => api.get(`/crm/clients/${id}/retention-insight`).then((r) => r.data),
        enabled: activeTab === 'overview',
        staleTime: 60_000,
    });
    const paymentLinkProviderOptions = useMemo(
        () => getEnabledPaymentLinkProviders(client?.platform),
        [client?.platform],
    );
    const defaultPaymentLinkProvider = useMemo(
        () => getDefaultPaymentLinkProviderKey(client?.platform),
        [client?.platform],
    );

    const { data: deactivateTemplatesData } = useQuery({
        queryKey: ['settings-templates', 'client-deal-deactivate'],
        queryFn: () => api.get('/crm/settings/templates').then((r) => r.data),
        enabled: dealActionDialog.type === 'deactivate',
    });
    const smsTemplates = (deactivateTemplatesData?.templates || []).filter((t) => t.channel === 'sms');
    const activationDeal = client?.deals?.find((deal) => deal.id === activationDialog.dealId) || null;
    const activationPolicySource = client?.platform || activationDeal || client;
    const activationPaymentMethods = useMemo(
        () => getAllowedCrmPaymentMethods(activationPolicySource, 'activation'),
        [activationPolicySource],
    );
    const dealActionPolicySource = dealActionDialog.deal?.client?.platform || client?.platform || dealActionDialog.deal;
    const dealActionPaymentMethods = useMemo(
        () => getAllowedCrmPaymentMethods(dealActionPolicySource, 'renewal'),
        [dealActionPolicySource],
    );

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
                product_price_id: deal.product_price_id ? Number(deal.product_price_id) : undefined,
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setShowDealModal(false);
            toast.success('Subscription created for client.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription creation failed.');
        },
    });

    const sendPaymentLinkMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/payment-link`, payload).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setPaymentLinkResult(payload);

            if (payload?.sms_result?.success === false) {
                toast.warning(payload?.message || 'SMS failed, but the payment link is ready to share manually.');
                return;
            }

            toast.success(payload?.message || 'Payment link prepared.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Payment link could not be prepared.');
        },
    });

    const deletePreviewMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${id}/delete-preview`).then((response) => response.data),
        onSuccess: (payload) => {
            setDeletePreview(payload);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Delete preview could not be loaded.');
            setShowDeleteDialog(false);
            setDeletePreview(null);
        },
    });

    const deleteClientMutation = useMutation({
        mutationFn: ({ confirm, reason }) => api.delete(`/crm/clients/${id}`, {
            data: {
                confirm,
                reason,
            },
        }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            toast.success('Client deleted successfully.');
            setShowDeleteDialog(false);
            setDeletePreview(null);
            setDeleteConfirmText('');
            setDeleteReason('Client deleted from CRM');
            navigate('/clients');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Client deletion failed.');
        },
    });

    const activateDealMutation = useMutation({
        mutationFn: ({ dealId, reason, paymentMethod, paymentReference, freeTrialPin, paymentLinkProvider, discountPercentage, discountPayableAmount, discountPin, subscriptionLifecycle, subscriptionLifecycleReason }) =>
            api.post(`/crm/deals/${dealId}/activate`, {
                reason,
                payment_method: paymentMethod,
                subscription_lifecycle: subscriptionLifecycle,
                ...(subscriptionLifecycleReason ? { subscription_lifecycle_reason: subscriptionLifecycleReason } : {}),
                ...(paymentMethod === 'manual' ? { payment_reference: paymentReference } : {}),
                ...(paymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPin } : {}),
                ...(paymentMethod === 'link' && canOverridePaymentLinkProvider && paymentLinkProvider ? { payment_link_provider: paymentLinkProvider } : {}),
                ...(paymentMethod !== 'free_trial' && discountPercentage > 0
                    ? {
                        discount_percentage: discountPercentage,
                        ...(discountPayableAmount !== null ? { discount_payable_amount: discountPayableAmount } : {}),
                        discount_pin: discountPin,
                    }
                    : {}),
            }).then((r) => r.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setActivationDialog({ open: false, dealId: null, dealLabel: '' });
            setActivationReason('Activation initiated from client profile');
            setActivationPaymentMethod(activationPaymentMethods[0] || '');
            setActivationSubscriptionLifecycle('new');
            setActivationSubscriptionLifecycleReason('');
            setActivationPaymentReference('');
            setActivationFreeTrialPin('');
            setActivationPaymentLinkProvider(defaultPaymentLinkProvider);
            setActivationApplyDiscount(false);
            setActivationDiscountPercentage('');
            setActivationDiscountPayableAmount('');
            setActivationDiscountPin('');
            toast.success(payload?.message || 'Subscription activation request submitted.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription activation failed.');
        },
    });

    const deactivateDealMutation = useMutation({
        mutationFn: ({ dealId, reasonCode, reasonNotes, linkedPaymentAction, shouldNotify, templateId, message }) =>
            api.post(`/crm/deals/${dealId}/deactivate`, {
                reason_code: reasonCode,
                reason_notes: reasonNotes,
                linked_payment_action: linkedPaymentAction,
                notify_client: Boolean(shouldNotify),
                notification_template_id: templateId || null,
                notification_message: message || null,
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setDealActionDialog({ type: null, deal: null });
            setDeactivationReasonCode('other');
            setDeactivationReasonNotes('Deactivated from client profile');
            setDeactivationLinkedPaymentAction('none');
            setNotifyClient(false);
            setNotificationTemplateId('');
            setNotificationMessage('');
            toast.success('Subscription deactivated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription deactivation failed.');
        },
    });

    const deactivateClientSubscriptionMutation = useMutation({
        mutationFn: ({ reasonCode, reasonNotes, notifyClient: shouldNotify }) =>
            api.post(`/crm/clients/${id}/deactivate-subscription`, {
                reason_code: reasonCode,
                reason_notes: reasonNotes,
                notify_client: Boolean(shouldNotify),
            }).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClientDeactivateDialog({
                open: false,
                reasonCode: 'other',
                reasonNotes: 'Deactivated from client profile',
                notifyClient: false,
            });
            toast.success(payload?.message || 'Profile subscription deactivated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Profile subscription deactivation failed.');
        },
    });

    // ── New Badge ────────────────────────────────────────────────────────────
    const updateNewBadgeMutation = useMutation({
        mutationFn: (mode) =>
            api.post(`/crm/clients/${id}/new-badge`, { mode }).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.setQueryData(['client', id], data);
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setShowNewBadgeDialog(false);
            const nextMode = normalizeNewBadgeMode(data.new_badge_mode, data.force_new);
            toast.success(getNewBadgeModePresentation(nextMode).toastMessage);
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Failed to update NEW badge.');
        },
    });

    // ── Tours ────────────────────────────────────────────────────────────────
    const toursQuery = useQuery({
        queryKey: ['client-tours', id],
        queryFn: () => api.get(`/crm/clients/${id}/tours`).then((r) => r.data),
        enabled: !!id && !!client,
    });

    const addTourMutation = useMutation({
        mutationFn: (payload) =>
            api.post(`/crm/clients/${id}/tours`, payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-tours', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setShowTourModal(false);
            setTourForm({ city: '', start: '', end: '', phone: '' });
            toast.success('Tour added.');
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Failed to add tour.');
        },
    });

    const deleteTourMutation = useMutation({
        mutationFn: (tourId) =>
            api.delete(`/crm/clients/${id}/tours/${tourId}`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-tours', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            toast.success('Tour removed.');
        },
        onError: (err) => {
            toast.error(err?.response?.data?.message || 'Failed to delete tour.');
        },
    });

    const extendDealMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, extensionReason, selectedPaymentMethod, referenceValue, freeTrialPinValue, paymentLinkProviderValue, discountPercentageValue, discountPayableAmountValue, discountPinValue, subscriptionLifecycleValue, subscriptionLifecycleReasonValue }) =>
            api.post(`/crm/deals/${dealId}/extend`, {
                additional_days: additionalDays,
                reason: extensionReason,
                payment_method: selectedPaymentMethod,
                subscription_lifecycle: subscriptionLifecycleValue,
                ...(subscriptionLifecycleReasonValue ? { subscription_lifecycle_reason: subscriptionLifecycleReasonValue } : {}),
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPinValue } : {}),
                ...(selectedPaymentMethod === 'link' && canOverridePaymentLinkProvider && paymentLinkProviderValue ? { payment_link_provider: paymentLinkProviderValue } : {}),
                ...(selectedPaymentMethod !== 'free_trial' && discountPercentageValue > 0
                    ? {
                        discount_percentage: discountPercentageValue,
                        ...(discountPayableAmountValue !== null ? { discount_payable_amount: discountPayableAmountValue } : {}),
                        discount_pin: discountPinValue,
                    }
                    : {}),
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setDealActionDialog({ type: null, deal: null });
            setExtendDays('7');
            setExtendReason('Extended from client profile');
            setDealPaymentMethod(dealActionPaymentMethods[0] || '');
            setDealSubscriptionLifecycle('renewal');
            setDealSubscriptionLifecycleReason('');
            setDealPaymentReference('');
            setDealFreeTrialPin('');
            setDealPaymentLinkProvider(defaultPaymentLinkProvider);
            setDealApplyDiscount(false);
            setDealDiscountPercentage('');
            setDealDiscountPayableAmount('');
            setDealDiscountPin('');
            toast.success('Subscription extension saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription extension failed.');
        },
    });

    const renewDealMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, renewalReason, selectedPaymentMethod, referenceValue, freeTrialPinValue, paymentLinkProviderValue, discountPercentageValue, discountPayableAmountValue, discountPinValue, subscriptionLifecycleValue, subscriptionLifecycleReasonValue }) =>
            api.post(`/crm/deals/${dealId}/renew`, {
                additional_days: additionalDays,
                reason: renewalReason,
                payment_method: selectedPaymentMethod,
                subscription_lifecycle: subscriptionLifecycleValue,
                ...(subscriptionLifecycleReasonValue ? { subscription_lifecycle_reason: subscriptionLifecycleReasonValue } : {}),
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPinValue } : {}),
                ...(selectedPaymentMethod === 'link' && canOverridePaymentLinkProvider && paymentLinkProviderValue ? { payment_link_provider: paymentLinkProviderValue } : {}),
                ...(selectedPaymentMethod !== 'free_trial' && discountPercentageValue > 0
                    ? {
                        discount_percentage: discountPercentageValue,
                        ...(discountPayableAmountValue !== null ? { discount_payable_amount: discountPayableAmountValue } : {}),
                        discount_pin: discountPinValue,
                    }
                    : {}),
            }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
            setDealActionDialog({ type: null, deal: null });
            setRenewDays('30');
            setRenewReason('Renewed from client profile');
            setDealPaymentMethod(dealActionPaymentMethods[0] || '');
            setDealSubscriptionLifecycle('renewal');
            setDealSubscriptionLifecycleReason('');
            setDealPaymentReference('');
            setDealFreeTrialPin('');
            setDealPaymentLinkProvider(defaultPaymentLinkProvider);
            setDealApplyDiscount(false);
            setDealDiscountPercentage('');
            setDealDiscountPayableAmount('');
            setDealDiscountPin('');
            toast.success('Subscription renewed successfully.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription renewal failed.');
        },
    });

    const syncMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${id}/sync`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success('Client profile synced from WordPress.');
            setShowSyncConfirm(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'WordPress sync failed.');
            setShowSyncConfirm(false);
        },
    });

    const updateProfileMutation = useMutation({
        mutationFn: ({ fields, force }) =>
            api.patch(`/crm/clients/${id}/wp-profile`, {
                fields,
                force,
                reason: profileReason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            setProfileConflict(null);
            setProfileForce(false);
            toast.success('Profile synced to WordPress successfully.');
        },
        onError: (error) => {
            if (error?.response?.status === 409) {
                setProfileConflict(error.response.data?.conflict || null);
                toast.warning('WordPress profile changed since last sync. Review conflict and force save if needed.');
                return;
            }
            toast.error(error?.response?.data?.message || 'Profile update failed.');
        },
    });

    const deleteMediaMutation = useMutation({
        mutationFn: (attachmentId) =>
            api.delete(`/crm/clients/${id}/media/${attachmentId}`, {
                data: { reason: 'Deleted media from client detail' },
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success('Image deleted from WordPress.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Media delete failed.');
        },
    });

    const setMainMediaMutation = useMutation({
        mutationFn: (attachmentId) =>
            api.patch(`/crm/clients/${id}/media/${attachmentId}/set-main`, {
                reason: 'Set main image from client detail',
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success('Main image updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Setting main image failed.');
        },
    });

    const repairWpLinkMutation = useMutation({
        mutationFn: () => api.post(`/crm/clients/${id}/repair-wp-link`).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-wp-profile', id] });
            queryClient.invalidateQueries({ queryKey: ['client-media', id] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            toast.success(payload?.message || 'WordPress link repaired.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'WordPress link repair failed.');
        },
    });

    const resolveHealthMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/health/resolve`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            queryClient.invalidateQueries({ queryKey: ['client-health', id] });
            setSelectedDuplicateIds([]);
            setUpdatePhoneTargetId('');
            setUpdatePhoneValue('');
            toast.success('Profile health resolution applied.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Profile health resolution failed.');
        },
    });

    const walletTopupMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/wallet/topup`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-wallet', id] });
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            setWalletTopupForm({
                amount: '',
                pin: '',
                reason: 'Manual wallet top-up from client profile',
            });
            toast.success('Wallet top-up recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Wallet top-up failed.');
        },
    });

    const walletAdjustmentMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${id}/wallet/adjustment`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['client-wallet', id] });
            queryClient.invalidateQueries({ queryKey: ['client', id] });
            setWalletAdjustmentForm((current) => ({
                ...current,
                amount: '',
                pin: '',
                reason: 'Wallet adjustment from client profile',
            }));
            toast.success('Wallet adjustment recorded.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Wallet adjustment failed.');
        },
    });

    const tabLinks = useMemo(() => {
        const links = [
            { key: 'overview', label: 'Overview' },
            { key: 'analytics', label: 'Analytics' },
            { key: 'deals', label: `Subscriptions (${client?.deals?.length || 0})` },
            { key: 'notes', label: `Notes (${client?.notes?.length || 0})` },
            { key: 'timeline', label: 'Timeline' },
            { key: 'chat', label: 'Chat' },
            { key: 'wallet', label: 'Wallet' },
            { key: 'payments', label: `Payments (${client?.payments?.length || 0})` },
            { key: 'edit_profile', label: 'Edit Profile' },
            { key: 'profile_health', label: `Profile Health (${healthData?.summary?.duplicate_count || 0})` },
        ];

        if (!isReadOnly) {
            return links;
        }

        return links.filter((tab) => !['edit_profile', 'profile_health', 'chat'].includes(tab.key));
    }, [client, healthData?.summary?.duplicate_count, isReadOnly]);

    useEffect(() => {
        const allowedTabs = tabLinks.map((tab) => tab.key);
        const nextTab = allowedTabs.includes(requestedTab) ? requestedTab : 'overview';
        if (nextTab !== activeTab) {
            setActiveTab(nextTab);
        }
    }, [activeTab, requestedTab, tabLinks]);

    useEffect(() => {
        if (isReadOnly) {
            return;
        }

        const requestedAction = (searchParams.get('action') || '').toLowerCase();
        if (requestedAction !== 'new_subscription') {
            return;
        }

        setActiveTab('deals');
        setShowDealModal(true);

        const next = new URLSearchParams(searchParams);
        next.set('tab', 'deals');
        next.delete('action');
        setSearchParams(next, { replace: true });
    }, [isReadOnly, searchParams, setSearchParams]);

    useEffect(() => {
        if (!activationDialog.open) {
            return;
        }

        if (!activationPaymentMethods.includes(activationPaymentMethod)) {
            setActivationPaymentMethod(activationPaymentMethods[0] || '');
        }

        if (!activationPaymentLinkProvider && defaultPaymentLinkProvider) {
            setActivationPaymentLinkProvider(defaultPaymentLinkProvider);
        }
    }, [activationDialog.open, activationPaymentLinkProvider, activationPaymentMethod, activationPaymentMethods, defaultPaymentLinkProvider]);

    useEffect(() => {
        if (!activationDialog.open) {
            return;
        }

        setActivationSubscriptionLifecycle(resolveDialogPredictedLifecycle('activate', activationDeal));
        setActivationSubscriptionLifecycleReason('');
    }, [activationDeal, activationDialog.open]);

    useEffect(() => {
        if (!dealActionDialog.deal) {
            return;
        }

        if (!dealActionPaymentMethods.includes(dealPaymentMethod)) {
            setDealPaymentMethod(dealActionPaymentMethods[0] || '');
        }

        if (!dealPaymentLinkProvider && defaultPaymentLinkProvider) {
            setDealPaymentLinkProvider(defaultPaymentLinkProvider);
        }
    }, [dealActionDialog.deal, dealActionPaymentMethods, dealPaymentLinkProvider, dealPaymentMethod, defaultPaymentLinkProvider]);

    useEffect(() => {
        if (!dealActionDialog.deal) {
            return;
        }

        setDealSubscriptionLifecycle(resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal));
        setDealSubscriptionLifecycleReason('');
    }, [dealActionDialog.deal, dealActionDialog.type]);

    useEffect(() => {
        if (!wpProfileData?.wp_profile) {
            return;
        }

        const profile = wpProfileData.wp_profile;
        const meta = profile.meta || {};
        const cityName = profile?.taxonomies?.city?.name || profile.city || '';

        setProfileForm({
            name: profile.name || profile?.post?.title || '',
            phone: meta.phone || profile.phone || client?.phone_normalized || '',
            email: profile.email || client?.email || '',
            city: cityName || client?.city || '',
            birthday: normalizeBirthdayForEditor(meta.birthday),
            gender: resolveProfileEnumValue('gender', meta.gender),
            ethnicity: resolveProfileEnumValue('ethnicity', meta.ethnicity),
            height: normalizeHeightForEditor(meta.height),
            build: resolveProfileEnumValue('build', meta.build || meta.body_type),
            haircolor: resolveProfileEnumValue('haircolor', meta.haircolor),
            hairlength: resolveProfileEnumValue('hairlength', meta.hairlength),
            bustsize: resolveProfileEnumValue('bustsize', meta.bustsize),
            weight: meta.weight || '',
            looks: resolveProfileEnumValue('looks', meta.looks),
            smoker: resolveProfileEnumValue('smoker', meta.smoker),
            availability: parseProfileServices(meta.availability),
            services: parseProfileServices(meta.services),
            extraservices: meta.extraservices || '',
            rates_incall: meta.incall || meta.rate_incall || '',
            rates_outcall: meta.outcall || meta.rate_outcall || '',
            rate30min_incall: meta.rate30min_incall || '', rate30min_outcall: meta.rate30min_outcall || '',
            rate1h_incall: meta.rate1h_incall || '', rate1h_outcall: meta.rate1h_outcall || '',
            rate2h_incall: meta.rate2h_incall || '', rate2h_outcall: meta.rate2h_outcall || '',
            rate3h_incall: meta.rate3h_incall || '', rate3h_outcall: meta.rate3h_outcall || '',
            rate6h_incall: meta.rate6h_incall || '', rate6h_outcall: meta.rate6h_outcall || '',
            rate12h_incall: meta.rate12h_incall || '', rate12h_outcall: meta.rate12h_outcall || '',
            rate24h_incall: meta.rate24h_incall || '', rate24h_outcall: meta.rate24h_outcall || '',
            whatsapp: meta.whatsapp || meta.whatsapp_number || '',
            instagram: meta.instagram || meta.instagram_url || '',
            twitter: meta.twitter || meta.twitter_url || '',
            telegram: meta.telegram || '',
            website: meta.website || meta.website_url || '',
            facebook: meta.facebook || '',
            snapchat: meta.snapchat || '',
            bio: profile?.post?.content || meta.bio || '',
            education: meta.education || '',
            occupation: meta.occupation || '',
            sports: meta.sports || '',
            hobbies: meta.hobbies || '',
            zodiacsign: meta.zodiacsign || '',
            sexualorientation: meta.sexualorientation || '',
            language1: meta.language1 || '', language1level: resolveProfileEnumValue('languagelevel', meta.language1level),
            language2: meta.language2 || '', language2level: resolveProfileEnumValue('languagelevel', meta.language2level),
            language3: meta.language3 || '', language3level: resolveProfileEnumValue('languagelevel', meta.language3level),
        });
    }, [wpProfileData?.wp_profile, client?.city, client?.email, client?.phone_normalized]);

    const profileSections = [
        { key: 'personal', label: 'Personal Info' },
        { key: 'appearance', label: 'Appearance' },
        { key: 'services', label: 'Services & Rates' },
        { key: 'contact', label: 'Social & Contact' },
        { key: 'lifestyle', label: 'Lifestyle & Languages' },
        { key: 'subscription', label: 'Subscription & Status' },
        { key: 'media', label: 'Media' },
    ];

    const serviceOptions = useMemo(() => {
        const selectedServices = Array.isArray(profileForm?.services) ? profileForm.services : [];
        const unknownOptions = selectedServices
            .map((code) => String(code || '').trim())
            .filter((code) => code && !isKnownProfileEnumCode('services', code))
            .map((code) => ({
                value: code,
                plainLabel: /^\d+$/.test(code) ? `Legacy service code` : 'Unknown service value',
                label: /^\d+$/.test(code) ? `Legacy service code (${code})` : `Unknown service value (${code})`,
            }));

        return [...PROFILE_ENUM_OPTIONS.services, ...unknownOptions];
    }, [profileForm?.services]);
    const selectedServiceCodes = Array.isArray(profileForm?.services)
        ? profileForm.services.map((value) => String(value || '').trim()).filter(Boolean)
        : [];

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

    const profileState = deriveClientProfileState(client);
    const currentNewBadgeMode = normalizeNewBadgeMode(client.new_badge_mode, client.force_new);
    const newBadgePresentation = getNewBadgeModePresentation(currentNewBadgeMode);
    const canonicalExpiryDate = resolveClientExpiryDate(client);
    const isExpired = canonicalExpiryDate ? canonicalExpiryDate < new Date() : false;
    const isUntrackedForeverPlan = isClientTrueForeverPlan(client);
    const activeSubscriptionLabel = client.active_deal
        ? (client.active_deal.product?.name || client.active_deal.plan_type)
        : (isUntrackedForeverPlan ? 'Forever plan' : 'None');
    const subscriptionExpiryLabel = canonicalExpiryDate
        ? formatProfileDate(canonicalExpiryDate)
        : (isUntrackedForeverPlan ? 'Forever' : '—');
    const subscriptionExpiryDetailLabel = canonicalExpiryDate
        ? canonicalExpiryDate.toLocaleString()
        : (isUntrackedForeverPlan ? 'Forever' : '—');
    const profilePrimaryUrl = client.wp_profile_permalink || client.wp_profile_url || '';
    const profileExpiryMessage = buildSubscriptionExpiryMessage(client, isUntrackedForeverPlan);
    const profileCopyMessage = buildProfileCopyMessage(client, isUntrackedForeverPlan);
    const hasProfileUrl = Boolean(profilePrimaryUrl);

    const canSyncFromWp = Number(client.wp_post_id || 0) > 0;
    const canOpenClientAccess = Boolean(client?.id);
    const mediaItems = mediaData?.data || [];
    const mediaUploadHasSelection = mediaUploadFiles.length > 0;
    const mediaUploadHasMultiple = mediaUploadFiles.length > 1;
    const mediaUploadIsVideo = mediaUploadFiles.length === 1 && isVideoUploadFile(mediaUploadFiles[0]);
    const mediaUploadPreflight = getMediaUploadPreflight(
        mediaUploadFiles,
        mediaUploadHasMultiple || mediaUploadIsVideo ? false : mediaUploadSetMain
    );
    const mediaUploadHasInvalidBatch = mediaUploadHasSelection && !mediaUploadPreflight.ok;
    const backgroundMediaUploads = uploadsForClient(id);
    const hasBackgroundMediaUploads = backgroundMediaUploads.length > 0;
    const mediaUploadSelectionLabel = mediaUploadHasSelection
        ? mediaUploadHasMultiple
            ? `${mediaUploadFiles.length} images selected`
            : mediaUploadFiles[0]?.name || '1 file selected'
        : '';
    const wpProfileErrorData = wpProfileError?.response?.data || null;
    const mediaErrorData = mediaError?.response?.data || null;
    const staleWpLink = mediaErrorData?.stale_link || wpProfileErrorData?.stale_link || null;
    const repairableWpLink = Boolean(staleWpLink?.repairable);
    const healthDuplicates = healthData?.duplicates || [];
    const walletSummary = walletData?.wallet || null;
    const walletTransactions = walletSummary?.transactions || [];
    const activationRequiresReference = activationPaymentMethod === 'manual';
    const activationRequiresFreeTrialPin = activationPaymentMethod === 'free_trial';
    const activationRequiresProvider = activationPaymentMethod === 'link';
    const activationDiscountAllowed = activationPaymentMethod !== 'free_trial';
    const activationTargetPhone = client?.phone_normalized || '';
    const dealPaymentRequiresReference = dealPaymentMethod === 'manual';
    const dealPaymentRequiresFreeTrialPin = dealPaymentMethod === 'free_trial';
    const dealPaymentRequiresProvider = dealPaymentMethod === 'link';
    const dealDiscountAllowed = dealPaymentMethod !== 'free_trial';
    const activationBaseAmount = Number(activationDeal?.original_amount ?? activationDeal?.amount ?? 0);
    const activationDiscountValue = activationApplyDiscount ? normalizeDiscountPercentage(activationDiscountPercentage) : 0;
    const activationDiscountPayableValue = activationApplyDiscount ? normalizePayableAmount(activationDiscountPayableAmount) : null;
    const activationDiscountedTotal = effectiveDiscountedAmount(activationBaseAmount, activationDiscountValue, activationDiscountPayableAmount);
    const activationSavingsAmount = roundMoney(Math.max(0, activationBaseAmount - activationDiscountedTotal));
    const activationDiscountValid = activationDiscountValue >= 1 && activationDiscountValue <= 99;
    const selectedDealBaseAmount = Number(dealActionDialog.deal?.original_amount ?? dealActionDialog.deal?.amount ?? 0);
    const selectedDealDiscountValue = dealApplyDiscount ? normalizeDiscountPercentage(dealDiscountPercentage) : 0;
    const selectedDealDiscountPayableValue = dealApplyDiscount ? normalizePayableAmount(dealDiscountPayableAmount) : null;
    const selectedDealDiscountedTotal = effectiveDiscountedAmount(selectedDealBaseAmount, selectedDealDiscountValue, dealDiscountPayableAmount);
    const selectedDealSavingsAmount = roundMoney(Math.max(0, selectedDealBaseAmount - selectedDealDiscountedTotal));
    const selectedDealDiscountValid = selectedDealDiscountValue >= 1 && selectedDealDiscountValue <= 99;
    const paymentLinkEligibleDeals = (client.deals || []).filter((deal) => ['pending', 'awaiting_payment'].includes(deal.status));
    const renderDealAmount = (deal) => {
        const hasDiscount = normalizeDiscountPercentage(deal?.discount_percentage) > 0 && deal?.original_amount !== null;
        if (!hasDiscount) {
            return formatCurrency(deal?.amount, deal?.currency || 'KES');
        }

        return (
            <>
                <span className="line-through opacity-70">{formatCurrency(deal.original_amount, deal.currency || 'KES')}</span>
                <span className="font-semibold text-slate-900">{formatCurrency(deal.amount, deal.currency || 'KES')}</span>
                <span className="text-amber-700">({Number(deal.discount_percentage)}% off)</span>
            </>
        );
    };

    const renderWalletAutoRenewState = (deal, compact = false) => {
        const renewalState = getWalletAutoRenewPresentation(deal);
        if (!renewalState) {
            return null;
        }

        return (
            <p className={`flex flex-wrap items-center gap-1.5 text-slate-500 ${compact ? 'mt-1 text-[11px]' : 'mt-2 text-xs'}`}>
                <StatusBadge status={renewalState.status} label={renewalState.label} tone={renewalState.tone} />
                <span>{renewalState.detail}</span>
                {renewalState.updatedAt ? <span>• {new Date(renewalState.updatedAt).toLocaleString()}</span> : null}
            </p>
        );
    };

    const openActivationDialog = (deal) => {
        const dealLabel = deal?.product?.name || deal?.plan_type || 'Subscription';
        const nextPaymentMethods = getAllowedCrmPaymentMethods(client?.platform || deal, 'activation');
        setActivationDialog({
            open: true,
            dealId: deal.id,
            dealLabel,
        });
        setActivationReason('Activation initiated from client profile');
        setActivationPaymentMethod(nextPaymentMethods[0] || '');
        setActivationPaymentReference('');
        setActivationFreeTrialPin('');
        setActivationPaymentLinkProvider(defaultPaymentLinkProvider);
        setActivationApplyDiscount(false);
        setActivationDiscountPercentage('');
        setActivationDiscountPayableAmount('');
        setActivationDiscountPin('');
    };

    const syncActivationDiscountFromPercentage = (value) => {
        const raw = String(value ?? '').trim();
        if (raw === '') {
            setActivationDiscountPercentage('');
            setActivationDiscountPayableAmount('');
            return;
        }

        const nextPercent = normalizeDiscountPercentage(raw);
        setActivationDiscountPercentage(value);
        setActivationDiscountPayableAmount(nextPercent > 0
            ? formatNumericInput(discountedAmount(activationBaseAmount, nextPercent))
            : '');
    };

    const syncActivationDiscountFromPayable = (value) => {
        const raw = String(value ?? '').trim();
        if (raw === '') {
            setActivationDiscountPercentage('');
            setActivationDiscountPayableAmount('');
            return;
        }

        const nextPayable = Number.parseFloat(raw);
        if (!Number.isFinite(nextPayable)) {
            setActivationDiscountPercentage('');
            setActivationDiscountPayableAmount(value);
            return;
        }

        const nextPercent = discountPercentageFromPayable(activationBaseAmount, nextPayable);
        setActivationDiscountPercentage(nextPercent > 0 ? formatPercentInput(nextPercent) : '');
        setActivationDiscountPayableAmount(raw);
    };

    const normalizeActivationPayableOnBlur = () => {
        if (!activationDiscountValid) {
            return;
        }

        setActivationDiscountPayableAmount(formatNumericInput(activationDiscountedTotal));
    };

    const syncDealDiscountFromPercentage = (value) => {
        const raw = String(value ?? '').trim();
        if (raw === '') {
            setDealDiscountPercentage('');
            setDealDiscountPayableAmount('');
            return;
        }

        const nextPercent = normalizeDiscountPercentage(raw);
        setDealDiscountPercentage(value);
        setDealDiscountPayableAmount(nextPercent > 0
            ? formatNumericInput(discountedAmount(selectedDealBaseAmount, nextPercent))
            : '');
    };

    const syncDealDiscountFromPayable = (value) => {
        const raw = String(value ?? '').trim();
        if (raw === '') {
            setDealDiscountPercentage('');
            setDealDiscountPayableAmount('');
            return;
        }

        const nextPayable = Number.parseFloat(raw);
        if (!Number.isFinite(nextPayable)) {
            setDealDiscountPercentage('');
            setDealDiscountPayableAmount(value);
            return;
        }

        const nextPercent = discountPercentageFromPayable(selectedDealBaseAmount, nextPayable);
        setDealDiscountPercentage(nextPercent > 0 ? formatPercentInput(nextPercent) : '');
        setDealDiscountPayableAmount(raw);
    };

    const normalizeDealPayableOnBlur = () => {
        if (!selectedDealDiscountValid) {
            return;
        }

        setDealDiscountPayableAmount(formatNumericInput(selectedDealDiscountedTotal));
    };

    const openPaymentLinkModal = () => {
        setPaymentLinkResult(null);
        setShowPaymentLinkModal(true);
    };

    const openDeleteDialog = () => {
        setDeleteConfirmText('');
        setDeleteReason('Client deleted from CRM');
        setDeletePreview(null);
        setShowDeleteDialog(true);
        deletePreviewMutation.mutate();
    };

    const openClientDeactivationDialog = () => {
        setClientDeactivateDialog({
            open: true,
            reasonCode: 'other',
            reasonNotes: 'Deactivated from client profile',
            notifyClient: false,
        });
    };

    const closeClientDeactivationDialog = () => {
        if (deactivateClientSubscriptionMutation.isPending) {
            return;
        }

        setClientDeactivateDialog({
            open: false,
            reasonCode: 'other',
            reasonNotes: 'Deactivated from client profile',
            notifyClient: false,
        });
    };

    const closeDeleteDialog = () => {
        if (deletePreviewMutation.isPending || deleteClientMutation.isPending) {
            return;
        }

        setShowDeleteDialog(false);
        setDeletePreview(null);
        setDeleteConfirmText('');
        setDeleteReason('Client deleted from CRM');
    };

    const closePaymentLinkModal = () => {
        if (sendPaymentLinkMutation.isPending) {
            return;
        }

        setShowPaymentLinkModal(false);
        setPaymentLinkResult(null);
    };

    const copyPaymentLinkUrl = async () => {
        const paymentUrl = paymentLinkResult?.payment_url;
        if (!paymentUrl) {
            toast.error('No payment URL is available to copy.');
            return;
        }

        try {
            await copyTextValue(paymentUrl);
            toast.success('Payment link copied.');
        } catch (error) {
            toast.error('Payment link could not be copied.');
        }
    };

    const copyProfileMessage = async () => {
        if (!hasProfileUrl) {
            toast.error('No profile URL is available to copy.');
            return;
        }

        try {
            await copyTextValue(profileCopyMessage);
            toast.success('Profile message copied.');
        } catch (error) {
            toast.error('Profile message could not be copied.');
        }
    };

    const copyProfileUrl = async (label, value) => {
        if (!value) {
            toast.error(`${label} is not available.`);
            return;
        }

        try {
            await copyTextValue(value);
            toast.success(`${label} copied.`);
        } catch (error) {
            toast.error(`${label} could not be copied.`);
        }
    };

    const closeActivationDialog = () => {
        setActivationDialog({ open: false, dealId: null, dealLabel: '' });
        setActivationReason('Activation initiated from client profile');
        setActivationPaymentMethod(activationPaymentMethods[0] || '');
        setActivationPaymentReference('');
        setActivationFreeTrialPin('');
        setActivationPaymentLinkProvider(defaultPaymentLinkProvider);
        setActivationApplyDiscount(false);
        setActivationDiscountPercentage('');
        setActivationDiscountPayableAmount('');
        setActivationDiscountPin('');
    };

    const openDealActionDialog = (type, deal) => {
        const nextPaymentMethods = getAllowedCrmPaymentMethods(deal?.client?.platform || client?.platform || deal, 'renewal');
        setDealActionDialog({ type, deal });
        setDealPaymentMethod(nextPaymentMethods[0] || '');
        setDealPaymentReference('');
        setDealFreeTrialPin('');
        setDealPaymentLinkProvider(defaultPaymentLinkProvider);
        setDealApplyDiscount(false);
        setDealDiscountPercentage('');
        setDealDiscountPayableAmount('');
        setDealDiscountPin('');
        if (type === 'deactivate') {
            setDeactivationReasonCode('other');
            setDeactivationReasonNotes('Deactivated from client profile');
            setDeactivationLinkedPaymentAction('none');
            setNotifyClient(false);
            setNotificationTemplateId('');
            setNotificationMessage('');
        }
        if (type === 'extend') {
            setExtendReason('Extended from client profile');
            setExtendDays('7');
        }
        if (type === 'renew') {
            setRenewReason('Renewed from client profile');
            setRenewDays('30');
        }
    };

    const submitActivation = () => {
        if (!activationDialog.dealId) {
            return;
        }

        if (activationRequiresReference && !activationPaymentReference.trim()) {
            toast.error('Transaction reference is required for manual activation.');
            return;
        }

        if (activationRequiresFreeTrialPin && activationFreeTrialPin.trim().length < 4) {
            toast.error('Enter the configured free-trial PIN to continue.');
            return;
        }

        if (activationRequiresProvider && !activationPaymentLinkProvider) {
            toast.error('Choose an enabled payment-link provider for this market.');
            return;
        }

        if (activationApplyDiscount && !activationDiscountAllowed) {
            toast.error('Discounts cannot be applied to free trials.');
            return;
        }

        if (activationApplyDiscount && !activationDiscountValid) {
            toast.error('Enter a discount percentage between 1 and 99.');
            return;
        }

        if (activationApplyDiscount && activationDiscountPin.trim().length < 4) {
            toast.error('Enter the configured discount PIN to continue.');
            return;
        }

        if (
            activationSubscriptionLifecycle !== resolveDialogPredictedLifecycle('activate', activationDeal)
            && !activationSubscriptionLifecycleReason.trim()
        ) {
            toast.error('Add a short reason when overriding the lifecycle classification.');
            return;
        }

        activateDealMutation.mutate({
            dealId: activationDialog.dealId,
            reason: activationReason.trim() || 'Activation initiated from client profile',
            paymentMethod: activationPaymentMethod,
            subscriptionLifecycle: activationSubscriptionLifecycle,
            subscriptionLifecycleReason: activationSubscriptionLifecycle !== resolveDialogPredictedLifecycle('activate', activationDeal)
                ? activationSubscriptionLifecycleReason.trim()
                : undefined,
            paymentReference: activationPaymentReference.trim(),
            freeTrialPin: activationFreeTrialPin.trim(),
            paymentLinkProvider: activationPaymentLinkProvider || undefined,
            discountPercentage: activationApplyDiscount ? activationDiscountValue : 0,
            discountPayableAmount: activationApplyDiscount ? activationDiscountPayableValue : null,
            discountPin: activationDiscountPin.trim(),
        });
    };

    const activationSubmitDisabled = activateDealMutation.isPending
        || !activationDialog.dealId
        || (activationRequiresReference && !activationPaymentReference.trim())
        || (activationRequiresFreeTrialPin && activationFreeTrialPin.trim().length < 4)
        || (activationRequiresProvider && !activationPaymentLinkProvider)
        || (activationApplyDiscount && !activationDiscountValid)
        || (activationApplyDiscount && activationDiscountPin.trim().length < 4)
        || (
            activationSubscriptionLifecycle !== resolveDialogPredictedLifecycle('activate', activationDeal)
            && !activationSubscriptionLifecycleReason.trim()
        );

    const submitProfileUpdate = () => {
        if (!profileForm) {
            return;
        }

        const normalizedGender = resolveProfileEnumValue('gender', profileForm.gender);
        const normalizedEthnicity = resolveProfileEnumValue('ethnicity', profileForm.ethnicity);
        const normalizedBuild = resolveProfileEnumValue('build', profileForm.build);
        const normalizedServices = parseProfileServices(profileForm.services)
            .map((value) => String(value || '').trim())
            .filter(Boolean);
        const invalidServiceValues = normalizedServices.filter((value) => !/^\d+$/.test(value));

        if (normalizedGender && !isKnownProfileEnumCode('gender', normalizedGender)) {
            toast.error('Gender must be selected from the dropdown list (label + code).');
            return;
        }

        if (normalizedEthnicity && !isKnownProfileEnumCode('ethnicity', normalizedEthnicity)) {
            toast.error('Ethnicity must be selected from the dropdown list (label + code).');
            return;
        }

        if (normalizedBuild && !isKnownProfileEnumCode('build', normalizedBuild)) {
            toast.error('Build must be selected from the dropdown list (label + code).');
            return;
        }

        if (invalidServiceValues.length > 0) {
            toast.error('Services include unknown text values. Re-select using listed service codes before saving.');
            return;
        }

        const fields = {
            name: profileForm.name?.trim() || '',
            phone: profileForm.phone?.trim() || null,
            email: profileForm.email?.trim() || null,
            city: profileForm.city?.trim() || null,
            birthday: normalizeBirthdayForSave(profileForm.birthday),
            gender: normalizedGender || null,
            ethnicity: normalizedEthnicity || null,
            height: normalizeHeightForSave(profileForm.height),
            build: normalizedBuild || null,
            haircolor: profileForm.haircolor || null,
            hairlength: profileForm.hairlength || null,
            bustsize: profileForm.bustsize || null,
            weight: profileForm.weight?.toString().trim() || null,
            looks: profileForm.looks || null,
            smoker: profileForm.smoker || null,
            availability: Array.isArray(profileForm.availability) && profileForm.availability.length ? profileForm.availability : null,
            services: normalizedServices.length ? normalizedServices : null,
            extraservices: profileForm.extraservices?.trim() || null,
            incall: profileForm.rates_incall?.trim() || null,
            outcall: profileForm.rates_outcall?.trim() || null,
            rate30min_incall: profileForm.rate30min_incall?.trim() || null,
            rate30min_outcall: profileForm.rate30min_outcall?.trim() || null,
            rate1h_incall: profileForm.rate1h_incall?.trim() || null,
            rate1h_outcall: profileForm.rate1h_outcall?.trim() || null,
            rate2h_incall: profileForm.rate2h_incall?.trim() || null,
            rate2h_outcall: profileForm.rate2h_outcall?.trim() || null,
            rate3h_incall: profileForm.rate3h_incall?.trim() || null,
            rate3h_outcall: profileForm.rate3h_outcall?.trim() || null,
            rate6h_incall: profileForm.rate6h_incall?.trim() || null,
            rate6h_outcall: profileForm.rate6h_outcall?.trim() || null,
            rate12h_incall: profileForm.rate12h_incall?.trim() || null,
            rate12h_outcall: profileForm.rate12h_outcall?.trim() || null,
            rate24h_incall: profileForm.rate24h_incall?.trim() || null,
            rate24h_outcall: profileForm.rate24h_outcall?.trim() || null,
            whatsapp: profileForm.whatsapp?.trim() || null,
            instagram: profileForm.instagram?.trim() || null,
            twitter: profileForm.twitter?.trim() || null,
            telegram: profileForm.telegram?.trim() || null,
            website: profileForm.website?.trim() || null,
            facebook: profileForm.facebook?.trim() || null,
            snapchat: profileForm.snapchat?.trim() || null,
            content: profileForm.bio || '',
            education: profileForm.education?.trim() || null,
            occupation: profileForm.occupation?.trim() || null,
            sports: profileForm.sports?.trim() || null,
            hobbies: profileForm.hobbies?.trim() || null,
            zodiacsign: profileForm.zodiacsign?.trim() || null,
            sexualorientation: profileForm.sexualorientation?.trim() || null,
            language1: profileForm.language1?.trim() || null,
            language1level: profileForm.language1level || null,
            language2: profileForm.language2?.trim() || null,
            language2level: profileForm.language2level || null,
            language3: profileForm.language3?.trim() || null,
            language3level: profileForm.language3level || null,
        };

        updateProfileMutation.mutate({ fields, force: profileForce });
    };

    const applyHealthResolution = () => {
        if (!healthReason.trim()) {
            return;
        }

        if (healthAction === 'update_phone') {
            if (!updatePhoneTargetId || !updatePhoneValue.trim()) {
                return;
            }

            resolveHealthMutation.mutate({
                action: 'update_phone',
                duplicate_id: Number(updatePhoneTargetId),
                new_phone_normalized: updatePhoneValue.trim(),
                reason: healthReason.trim(),
            });
            return;
        }

        if (!selectedDuplicateIds.length) {
            return;
        }

        resolveHealthMutation.mutate({
            action: healthAction,
            duplicate_ids: selectedDuplicateIds.map((duplicateId) => Number(duplicateId)),
            reason: healthReason.trim(),
        });
    };
    const profileHeaderImageUrl = proxyImageUrl(client?.display_image_url || client?.main_image_url || '');

    const isSynced = canSyncFromWp && Boolean(client.last_synced_at);

    return (
        <div className="space-y-4" data-tour="client-detail-root">
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
                        {(!isReadOnly && canSyncFromWp) ? (
                            <button
                                type="button"
                                onClick={() => navigateToTab('edit_profile', 'media')}
                                className="group relative flex-shrink-0"
                                title="View profile media"
                            >
                                {profileHeaderImageUrl ? (
                                    <img src={profileHeaderImageUrl} alt="" className="h-16 w-16 rounded-full object-cover ring-1 ring-slate-200" />
                                ) : (
                                    <div className="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-xl font-semibold text-slate-600 ring-1 ring-slate-200">
                                        {client.name?.charAt(0) || '?'}
                                    </div>
                                )}
                                <span className="absolute inset-0 flex items-center justify-center rounded-full bg-black/25 opacity-0 transition group-hover:opacity-100">
                                    <svg className="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </span>
                            </button>
                        ) : profileHeaderImageUrl ? (
                            <img src={profileHeaderImageUrl} alt="" className="h-16 w-16 flex-shrink-0 rounded-full object-cover ring-1 ring-slate-200" />
                        ) : (
                            <div className="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-full bg-slate-100 text-xl font-semibold text-slate-600 ring-1 ring-slate-200">
                                {client.name?.charAt(0) || '?'}
                            </div>
                        )}

                        <div>
                            <h2 className="crm-page-title">{client.name || 'Unnamed'}</h2>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                {client.is_high_risk ? <span className="inline-flex shrink-0 items-center rounded-md bg-rose-50 px-2.5 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">High Risk</span> : null}
                                <StatusBadge status={profileState.status} tone={profileState.tone} label={profileState.label} />
                                {client.premium ? <span className="inline-flex items-center rounded-md bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700 ring-1 ring-inset ring-teal-200">Premium</span> : null}
                                {client.featured ? <span className="inline-flex items-center rounded-md bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">Featured</span> : null}
                                {client.verified ? <span className="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">Verified</span> : null}
                                {newBadgePresentation.chipLabel ? (
                                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                        currentNewBadgeMode === 'force_off'
                                            ? 'bg-rose-50 text-rose-700 ring-rose-200'
                                            : 'bg-violet-50 text-violet-700 ring-violet-200'
                                    }`}
                                    >
                                        {newBadgePresentation.chipLabel}
                                    </span>
                                ) : null}
                                {isUntrackedForeverPlan ? (
                                    <span
                                        className="inline-flex cursor-help items-center rounded-md bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-200"
                                        title={FOREVER_PLAN_TOOLTIP}
                                    >
                                        Forever plan
                                    </span>
                                ) : null}
                            </div>
                            {profileState.isConflict ? (
                                <p className="mt-2 max-w-2xl text-xs font-medium text-amber-700">
                                    {profileState.detail}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <div className="flex flex-col gap-2">
                        {!isReadOnly ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {/* Sync from WP — muted when unsynced, teal when synced */}
                                <button
                                    type="button"
                                    onClick={() => setShowSyncConfirm(true)}
                                    disabled={!canSyncFromWp || syncMutation.isPending}
                                    title={!canSyncFromWp ? 'Sync unavailable for manual CRM-only records' : isSynced ? `Last synced ${client.last_synced_at}` : 'Sync profile from WordPress'}
                                    className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40 ${
                                        isSynced
                                            ? 'border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100'
                                            : 'border-slate-200 bg-white text-slate-400 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-600'
                                    }`}
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    {syncMutation.isPending ? 'Syncing…' : 'Sync from WP'}
                                </button>

                                {/* Client access */}
                                <button
                                    type="button"
                                    onClick={() => setShowCredentialDrawer(true)}
                                    disabled={!canOpenClientAccess}
                                    title={!canOpenClientAccess ? 'Client access tools are unavailable until this client loads.' : 'Manage client access'}
                                    data-tour="client-detail-client-access"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                    </svg>
                                    Client access
                                </button>

                                {/* Payment Link */}
                                <button
                                    type="button"
                                    onClick={openPaymentLinkModal}
                                    data-tour="client-detail-payment-link"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                    Payment link
                                </button>

                                {/* Add Tour */}
                                <button
                                    type="button"
                                    onClick={() => setShowTourModal(true)}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    Add tour
                                </button>

                                {/* NEW badge pin toggle */}
                                <button
                                    type="button"
                                    onClick={() => setShowNewBadgeDialog(true)}
                                    title={newBadgePresentation.buttonTitle}
                                    className={`inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition ${newBadgePresentation.buttonClassName}`}
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                    </svg>
                                    {newBadgePresentation.buttonLabel}
                                </button>
                            </div>
                        ) : null}

                        {/* Consequence row */}
                        {(!isReadOnly || canDeleteClient) ? (
                            <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
                                {!isReadOnly && client.can_deactivate_without_deal ? (
                                    <button
                                        type="button"
                                        onClick={openClientDeactivationDialog}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                                        data-tour="client-detail-subscription-actions"
                                    >
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Deactivate subscription
                                    </button>
                                ) : null}
                                {!isReadOnly ? (
                                    <button
                                        type="button"
                                        onClick={() => setShowDealModal(true)}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800"
                                        data-tour="client-detail-new-subscription"
                                    >
                                        <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M12 4v16m8-8H4" />
                                        </svg>
                                        New subscription
                                    </button>
                                ) : null}
                                {canDeleteClient ? (
                                    <>
                                        {!isReadOnly ? <span className="mx-1 h-5 w-px bg-slate-200" /> : null}
                                        <button
                                            type="button"
                                            onClick={openDeleteDialog}
                                            title="Delete client"
                                            className="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-white p-1.5 text-rose-500 transition hover:bg-rose-50 hover:text-rose-700"
                                        >
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                </div>
            </section>

            <KycPanel client={client} canReview={['admin', 'sub_admin', 'sales', 'marketing'].includes(String(currentUser?.role || ''))} />

            <section className="grid gap-4 lg:grid-cols-3">
                <ProfileInfoCard title="Contact Info">
                    <dl className="space-y-2.5">
                        <DefinitionRow
                            label="Phone"
                            mono
                            value={(!isReadOnly && canSyncFromWp && client.phone_normalized) ? (
                                <button
                                    type="button"
                                    onClick={() => navigateToTab('edit_profile', 'contact')}
                                    className="crm-mono text-xs font-medium text-teal-700 underline-offset-2 hover:underline"
                                >
                                    {client.phone_normalized}
                                </button>
                            ) : (client.phone_normalized || '—')}
                        />
                        <DefinitionRow
                            label="Email"
                            value={(!isReadOnly && canSyncFromWp && client.email) ? (
                                <button
                                    type="button"
                                    onClick={() => navigateToTab('edit_profile', 'contact')}
                                    className="font-medium text-teal-700 underline-offset-2 hover:underline"
                                >
                                    {client.email}
                                </button>
                            ) : (client.email || '—')}
                        />
                        <DefinitionRow label="City" value={client.city || '—'} />
                        <DefinitionRow label="Market" value={client.platform?.name || '—'} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Subscription">
                    <dl className="space-y-2.5">
                        <DefinitionRow label="Profile State" value={profileState.detail} />
                        <DefinitionRow
                            label="Active Subscription"
                            value={isUntrackedForeverPlan && !client.active_deal ? (
                                <span className="inline-flex items-center gap-1">
                                    <span>Forever plan</span>
                                    <span
                                        className="inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-slate-200 text-[10px] font-semibold text-slate-400"
                                        title={FOREVER_PLAN_TOOLTIP}
                                    >
                                        ?
                                    </span>
                                </span>
                            ) : activeSubscriptionLabel}
                        />
                        <DefinitionRow
                            label="Expires"
                            value={client.escort_expire ? (
                                <span className={isExpired ? 'text-rose-700' : 'text-slate-900'}>{subscriptionExpiryLabel}</span>
                            ) : subscriptionExpiryLabel}
                        />
                        <DefinitionRow label="WP Post ID" value={client.wp_post_id || '—'} mono />
                        <DefinitionRow label="WP User ID" value={client.wp_user_id || '—'} mono />
                        <DefinitionRow
                            label="Profile link"
                            value={(
                                <div ref={profileLinkPopoverRef} className="relative flex flex-wrap items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setShowProfileLinkPeek((current) => !current)}
                                        className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                                    >
                                        Peek
                                    </button>
                                    <button
                                        type="button"
                                        onClick={copyProfileMessage}
                                        disabled={!hasProfileUrl}
                                        className="rounded-md border border-teal-200 bg-teal-50 px-2 py-1 text-xs font-semibold text-teal-700 transition hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        Copy message
                                    </button>
                                    {hasProfileUrl ? (
                                        <a
                                            href={profilePrimaryUrl}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                                        >
                                            Open
                                        </a>
                                    ) : (
                                        <span className="rounded-md border border-slate-100 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-400">
                                            Open
                                        </span>
                                    )}

                                    {showProfileLinkPeek ? (
                                        <div className="absolute right-0 top-full z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-lg border border-slate-200 bg-white p-3 text-left shadow-lg">
                                            <div className="space-y-3">
                                                <div>
                                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Public URL</p>
                                                    {client.wp_profile_permalink ? (
                                                        <div className="mt-1 flex items-center gap-2">
                                                            <a
                                                                href={client.wp_profile_permalink}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                title={client.wp_profile_permalink}
                                                                className="min-w-0 flex-1 truncate text-xs font-medium text-teal-700 underline decoration-teal-200 underline-offset-2"
                                                            >
                                                                {client.wp_profile_permalink}
                                                            </a>
                                                            <button
                                                                type="button"
                                                                onClick={() => copyProfileUrl('Public URL', client.wp_profile_permalink)}
                                                                className="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50"
                                                            >
                                                                Copy
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <p className="mt-1 text-xs text-slate-500">Not synced yet</p>
                                                    )}
                                                </div>

                                                <div>
                                                    <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Short WP URL</p>
                                                    {client.wp_profile_url ? (
                                                        <div className="mt-1 flex items-center gap-2">
                                                            <a
                                                                href={client.wp_profile_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                title={client.wp_profile_url}
                                                                className="crm-mono min-w-0 flex-1 truncate text-xs font-medium text-slate-700 underline decoration-slate-200 underline-offset-2"
                                                            >
                                                                {client.wp_profile_url}
                                                            </a>
                                                            <button
                                                                type="button"
                                                                onClick={() => copyProfileUrl('Short WP URL', client.wp_profile_url)}
                                                                className="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50"
                                                            >
                                                                Copy
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <p className="mt-1 text-xs text-slate-500">Not available</p>
                                                    )}
                                                </div>

                                                <div className="grid gap-2 border-t border-slate-100 pt-3">
                                                    <div className="flex items-start justify-between gap-3">
                                                        <span className="text-xs text-slate-500">Slug</span>
                                                        <span className="crm-mono max-w-[13rem] truncate text-right text-xs font-semibold text-slate-800" title={client.wp_profile_slug || 'Not synced yet'}>
                                                            {client.wp_profile_slug || 'Not synced yet'}
                                                        </span>
                                                    </div>
                                                    <div className="flex items-start justify-between gap-3">
                                                        <span className="text-xs text-slate-500">Expiry message</span>
                                                        <span className={`max-w-[13rem] text-right text-xs font-semibold ${isExpired ? 'text-rose-700' : 'text-slate-800'}`}>
                                                            {profileExpiryMessage}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            )}
                        />
                        <DefinitionRow
                            label="Last Online"
                            value={client.last_online_at ? (
                                <span>
                                    {new Date(client.last_online_at * 1000).toLocaleString()}
                                    <span className="ml-1 text-xs text-slate-500">({formatRelativeFromUnix(client.last_online_at)})</span>
                                </span>
                            ) : '—'}
                        />
                        <DefinitionRow label="Last Synced" value={formatDateTime(client.last_synced_at)} />
                    </dl>
                </ProfileInfoCard>

                <ProfileInfoCard title="Summary">
                    <dl className="space-y-2.5">
                        <DefinitionRow
                            label="Total Subscriptions"
                            value={(() => {
                                const count = client.deals?.length || 0;
                                return count > 0 ? (
                                    <button type="button" onClick={() => navigateToTab('deals')} className="font-semibold text-teal-700 underline-offset-2 hover:underline">{count}</button>
                                ) : <span className="font-medium text-slate-900">0</span>;
                            })()}
                        />
                        <DefinitionRow
                            label="Total Payments"
                            value={(() => {
                                const count = client.payments?.length || 0;
                                return count > 0 ? (
                                    <button type="button" onClick={() => navigateToTab('payments')} className="font-semibold text-teal-700 underline-offset-2 hover:underline">{count}</button>
                                ) : <span className="font-medium text-slate-900">0</span>;
                            })()}
                        />
                        <DefinitionRow
                            label="Notes"
                            value={(() => {
                                const count = client.notes?.length || 0;
                                return count > 0 ? (
                                    <button type="button" onClick={() => navigateToTab('notes')} className="font-semibold text-teal-700 underline-offset-2 hover:underline">{count}</button>
                                ) : <span className="font-medium text-slate-900">0</span>;
                            })()}
                        />
                        <DefinitionRow label="Agent" value={client.assigned_agent?.name || 'Unassigned'} />
                    </dl>
                </ProfileInfoCard>
            </section>

            <section className="crm-surface p-2">
                <nav className="flex flex-wrap gap-1">
                    {tabLinks.map((tab) => (
                        <button
                            key={tab.key}
                            onClick={() => {
                                setActiveTab(tab.key);
                                const next = new URLSearchParams(searchParams);
                                if (tab.key === 'overview') {
                                    next.delete('tab');
                                } else {
                                    next.set('tab', tab.key);
                                }
                                setSearchParams(next, { replace: true });
                            }}
                            className={`rounded-md px-3 py-2 text-sm font-medium transition ${activeTab === tab.key ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'}`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </section>

            {activeTab === 'overview' ? (
                <>
                <section className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Recent Activity</h3>
                            <p className="crm-panel-subtitle">Most recent subscriptions for this client. New subscriptions remain pending until activated.</p>
                        </div>
                    </header>
                    <div className="p-4">
                        {client.deals?.length > 0 ? (
                            <div className="space-y-2">
                                {client.deals.slice(0, 5).map((deal) => (
                                    <div key={deal.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2.5">
                                        <div>
                                            <div className="flex items-center gap-1.5">
                                                <p className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type} - {deal.duration}</p>
                                                {deal.origin === 'mpesa_import' && (
                                                    <span className="inline-flex items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA</span>
                                                )}
                                                {deal.is_free_trial && (
                                                    <span className="inline-flex items-center rounded-sm bg-violet-50 px-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 ring-1 ring-inset ring-violet-600/20">Free Trial</span>
                                                )}
                                                {normalizeDiscountPercentage(deal.discount_percentage) > 0 && deal.original_amount !== null && (
                                                    <span className="inline-flex items-center rounded-sm bg-amber-50 px-1 text-[10px] font-bold uppercase tracking-wider text-amber-700 ring-1 ring-inset ring-amber-600/20">Discounted</span>
                                                )}
                                            </div>
                                            <p className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                                {renderDealAmount(deal)}
                                                {deal.activated_at ? ` • Paid ${new Date(deal.activated_at).toLocaleDateString()}` : ''}
                                                {deal.payment_reference ? ` • Ref: ${deal.payment_reference}` : ' • Activation enables subscription access.'}
                                            </p>
                                            {renderWalletAutoRenewState(deal, true)}
                                        </div>
                                        <StatusBadge status={deal.status} />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-4">
                                <p className="text-sm text-slate-600">No subscription records yet for this client.</p>
                                {!isReadOnly ? (
                                    <button
                                        type="button"
                                        onClick={() => setShowDealModal(true)}
                                        className="mt-3 crm-btn-primary"
                                    >
                                        Add subscription
                                    </button>
                                ) : null}
                            </div>
                        )}
                    </div>
                </section>

                <ClientHealthSection
                    completenessData={completenessData}
                    retentionInsight={retentionInsight}
                    retentionLoading={retentionInsightLoading}
                    clientId={id}
                    onSwitchTab={(tab) => {
                        setActiveTab(tab);
                        const next = new URLSearchParams(searchParams);
                        if (tab === 'overview') { next.delete('tab'); } else { next.set('tab', tab); }
                        setSearchParams(next, { replace: true });
                    }}
                    onOpenActivationDialog={openActivationDialog}
                    activeDeal={client?.deals?.find((d) => ['pending', 'awaiting_payment'].includes(d.status))}
                />

                {/* ── Tours Panel ────────────────────────────────────────── */}
                <section className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Tours</h3>
                            <p className="crm-panel-subtitle">Scheduled appearances linked to this profile on WordPress.</p>
                        </div>
                        {!isReadOnly ? (
                            <button type="button" onClick={() => setShowTourModal(true)} className="crm-btn-primary shrink-0">
                                Add Tour
                            </button>
                        ) : null}
                    </header>
                    <div className="p-4">
                        {toursQuery.isLoading ? (
                            <p className="text-sm text-slate-400">Loading tours…</p>
                        ) : toursQuery.isError ? (
                            <p className="text-sm text-rose-600">Failed to load tours.</p>
                        ) : (toursQuery.data?.tours?.length || 0) === 0 ? (
                            <p className="text-sm text-slate-400">No tours scheduled.</p>
                        ) : (
                            <div className="space-y-2">
                                {toursQuery.data.tours.map((tour) => (
                                    <div key={tour.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2.5">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">
                                                {tour.city}{tour.country ? `, ${tour.country}` : ''}
                                            </p>
                                            <p className="mt-0.5 text-xs text-slate-500">
                                                {tour.start} → {tour.end}
                                                {tour.phone ? <span className="ml-2 font-mono">{tour.phone}</span> : null}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {tour.needs_payment ? (
                                                <span className="inline-flex items-center rounded-sm bg-amber-50 px-1.5 text-[10px] font-bold uppercase tracking-wider text-amber-700 ring-1 ring-inset ring-amber-600/20">Payment required</span>
                                            ) : tour.status === 'publish' ? (
                                                <span className="inline-flex items-center rounded-sm bg-teal-50 px-1.5 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">Active</span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-sm bg-slate-100 px-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-600 ring-1 ring-inset ring-slate-200">{tour.status}</span>
                                            )}
                                            {!isReadOnly ? (
                                                <button
                                                    type="button"
                                                    onClick={() => deleteTourMutation.mutate(tour.id)}
                                                    disabled={deleteTourMutation.isPending}
                                                    className="rounded p-1 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 disabled:opacity-50"
                                                    title="Delete tour"
                                                >
                                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fillRule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clipRule="evenodd" />
                                                    </svg>
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </section>
                </>
            ) : null}

            {activeTab === 'analytics' ? (
                <ClientAnalyticsTab
                    client={client}
                    data={analyticsData}
                    error={analyticsError}
                    isLoading={analyticsLoading}
                    analyticsPeriod={analyticsPeriod}
                    onPeriodChange={setAnalyticsPeriod}
                />
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
                                        {deal.origin === 'mpesa_import' && (
                                            <span className="inline-flex items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA Import</span>
                                        )}
                                        {deal.is_free_trial && (
                                            <span className="inline-flex items-center rounded-sm bg-violet-50 px-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 ring-1 ring-inset ring-violet-600/20">Free Trial</span>
                                        )}
                                        {normalizeDiscountPercentage(deal.discount_percentage) > 0 && deal.original_amount !== null && (
                                            <span className="inline-flex items-center rounded-sm bg-amber-50 px-1 text-[10px] font-bold uppercase tracking-wider text-amber-700 ring-1 ring-inset ring-amber-600/20">Discounted</span>
                                        )}
                                    </div>
                                    <p className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
                                        {renderDealAmount(deal)} <span>- {deal.duration}</span>
                                        {deal.activated_at ? ` - Paid ${new Date(deal.activated_at).toLocaleDateString()}` : ''}
                                        {deal.expires_at ? ` - Expires ${new Date(deal.expires_at).toLocaleDateString()}` : ''}
                                        {deal.payment_reference ? ` - Ref: ${deal.payment_reference}` : ''}
                                    </p>
                                    {renderWalletAutoRenewState(deal)}
                                </div>

                                {!isReadOnly ? (
                                    <div className="flex items-center gap-2">
                                        {deal.status === 'pending' ? (
                                            <button
                                                onClick={() => openActivationDialog(deal)}
                                                disabled={activateDealMutation.isPending}
                                                className="crm-btn-primary px-3 py-1.5 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {activateDealMutation.isPending ? 'Submitting...' : 'Activate'}
                                            </button>
                                        ) : deal.status === 'active' ? (
                                            <>
                                                <button
                                                    onClick={() => openDealActionDialog('extend', deal)}
                                                    className="rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                                                >
                                                    Extend
                                                </button>
                                                <button
                                                    onClick={() => openDealActionDialog('deactivate', deal)}
                                                    className="rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:border-amber-300 hover:bg-amber-100"
                                                >
                                                    Deactivate
                                                </button>
                                            </>
                                        ) : ['expired', 'cancelled', 'deactivated'].includes(deal.status) ? (
                                            <button
                                                onClick={() => openDealActionDialog('renew', deal)}
                                                className="rounded-md border border-teal-200 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 hover:border-teal-300 hover:bg-teal-100"
                                            >
                                                Renew
                                            </button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center">
                            <p className="text-sm text-slate-600">No subscriptions yet for this client.</p>
                            {!isReadOnly ? (
                                <button
                                    type="button"
                                    onClick={() => setShowDealModal(true)}
                                    className="mt-4 crm-btn-primary"
                                >
                                    Add subscription
                                </button>
                            ) : null}
                        </section>
                    )}
                </div>
            ) : null}

            {dealActionDialog.deal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setDealActionDialog({ type: null, deal: null })}>
                    <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">
                                    {dealActionDialog.type === 'extend' ? 'Extend Subscription'
                                        : dealActionDialog.type === 'renew' ? 'Renew Subscription'
                                        : 'Deactivate Subscription'}
                                </h3>
                                <p className="crm-panel-subtitle">
                                    {client.name} &bull; {dealActionDialog.deal.product?.name || dealActionDialog.deal.plan_type}
                                </p>
                            </div>
                        </header>
                        <div className="space-y-4 p-4">
                                    {['extend', 'renew'].includes(dealActionDialog.type) ? (
                                <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-sm font-semibold text-slate-800">Payment Method</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {dealActionPaymentMethods.map((method) => (
                                            <button
                                                key={method}
                                                type="button"
                                                onClick={() => {
                                                    setDealPaymentMethod(method);
                                                    if (method === 'free_trial') {
                                                        setDealApplyDiscount(false);
                                                        setDealDiscountPercentage('');
                                                        setDealDiscountPayableAmount('');
                                                        setDealDiscountPin('');
                                                    }
                                                }}
                                                className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                    dealPaymentMethod === method
                                                        ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                                }`}
                                            >
                                                {method === 'manual' ? 'Manual Payment' : method === 'stk' ? 'STK Push' : method === 'link' ? 'Payment Link' : 'Free Trial'}
                                            </button>
                                        ))}
                                    </div>
                                    {dealActionPaymentMethods.length === 0 ? (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                            No CRM payment methods are enabled for this market on this renewal action.
                                        </div>
                                    ) : null}
                                    {dealPaymentMethod === 'manual' ? (
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">MPESA / Transaction Reference</label>
                                            <input type="text" value={dealPaymentReference} onChange={(e) => setDealPaymentReference(e.target.value)} className="crm-input" placeholder="e.g. MPESA123ABC" />
                                        </div>
                                    ) : null}
                                    {dealPaymentMethod === 'link' && canOverridePaymentLinkProvider ? (
                                        <div className="space-y-2">
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Link provider</label>
                                            <select
                                                value={dealPaymentLinkProvider}
                                                onChange={(e) => setDealPaymentLinkProvider(e.target.value)}
                                                className="crm-select"
                                                disabled={!paymentLinkProviderOptions.length}
                                            >
                                                <option value="">{paymentLinkProviderOptions.length ? 'Choose link provider' : 'No enabled provider available'}</option>
                                                {paymentLinkProviderOptions.map((provider) => (
                                                    <option key={provider.key} value={provider.key}>
                                                        {provider.optionLabel}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-xs text-slate-500">
                                                Choose who sends the payment link.
                                            </p>
                                        </div>
                                    ) : null}
                                    {dealPaymentMethod === 'link' && !canOverridePaymentLinkProvider ? (
                                        <div className="space-y-2">
                                            <p className="mb-1 block text-sm font-medium text-slate-700">Link provider</p>
                                            <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                {paymentLinkProviderOptions.length
                                                    ? 'Billing policy will use the market active provider for this payment link.'
                                                    : 'No enabled payment-link provider is configured for this market yet.'}
                                            </div>
                                            <p className="text-xs text-slate-500">
                                                Operators follow the market billing policy. Admins can override the provider in Billing settings.
                                            </p>
                                        </div>
                                    ) : null}
                                    {dealPaymentMethod === 'free_trial' ? (
                                        <div className="space-y-2">
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Free-trial PIN</label>
                                            <input
                                                type="password"
                                                inputMode="numeric"
                                                maxLength={6}
                                                value={dealFreeTrialPin}
                                                onChange={(e) => setDealFreeTrialPin(e.target.value.replace(/\D/g, '').slice(0, 6))}
                                                className="crm-input"
                                                placeholder="Enter free-trial PIN"
                                            />
                                            <p className="text-xs text-slate-500">
                                                Enter the free-trial PIN from Settings.
                                            </p>
                                        </div>
                                    ) : null}
                                    {dealDiscountAllowed ? (
                                        <DiscountPricingEditor
                                            idPrefix="client-deal-action"
                                            applyDiscount={dealApplyDiscount}
                                            onToggle={(checked) => {
                                                setDealApplyDiscount(checked);
                                                if (!checked) {
                                                    setDealDiscountPercentage('');
                                                    setDealDiscountPayableAmount('');
                                                    setDealDiscountPin('');
                                                }
                                            }}
                                            baseAmount={selectedDealBaseAmount}
                                            currency={dealActionDialog.deal.currency || 'KES'}
                                            payableAmount={dealDiscountPayableAmount}
                                            discountPercentage={dealDiscountPercentage}
                                            discountPin={dealDiscountPin}
                                            discountedTotal={selectedDealDiscountedTotal}
                                            savingsAmount={selectedDealSavingsAmount}
                                            onPayableChange={syncDealDiscountFromPayable}
                                            onPayableBlur={normalizeDealPayableOnBlur}
                                            onPercentageChange={syncDealDiscountFromPercentage}
                                            onPinChange={setDealDiscountPin}
                                        />
                                    ) : null}

                                    <div className="space-y-3 rounded-md border border-slate-200 bg-white p-3">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-800">Subscriber Type</p>
                                            <p className="mt-1 text-xs text-slate-500">
                                                {subscriptionLifecycleHelperText(resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal))}
                                            </p>
                                        </div>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {['new', 'renewal'].map((option) => (
                                                <button
                                                    key={option}
                                                    type="button"
                                                    onClick={() => setDealSubscriptionLifecycle(option)}
                                                    className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                        dealSubscriptionLifecycle === option
                                                            ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                                    }`}
                                                >
                                                    {option === 'new' ? 'New' : 'Renewal'}
                                                </button>
                                            ))}
                                        </div>
                                        {dealSubscriptionLifecycle !== resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal) ? (
                                            <div>
                                                <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="client-deal-lifecycle-reason">
                                                    Override reason
                                                </label>
                                                <textarea
                                                    id="client-deal-lifecycle-reason"
                                                    rows={2}
                                                    value={dealSubscriptionLifecycleReason}
                                                    onChange={(event) => setDealSubscriptionLifecycleReason(event.target.value)}
                                                    className="crm-input"
                                                    placeholder="Explain why this should be classified differently"
                                                />
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            ) : null}

                            {dealActionDialog.type === 'extend' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700">Additional days</label>
                                    <input type="number" min={1} value={extendDays} onChange={(e) => setExtendDays(e.target.value)} className="crm-input" />
                                    <label className="block text-sm font-medium text-slate-700">Reason</label>
                                    <textarea rows={3} value={extendReason} onChange={(e) => setExtendReason(e.target.value)} className="crm-input" />
                                </>
                            ) : null}

                            {dealActionDialog.type === 'renew' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700">Additional days</label>
                                    <input type="number" min={1} value={renewDays} onChange={(e) => setRenewDays(e.target.value)} className="crm-input" />
                                    <label className="block text-sm font-medium text-slate-700">Reason</label>
                                    <textarea rows={3} value={renewReason} onChange={(e) => setRenewReason(e.target.value)} className="crm-input" />
                                </>
                            ) : null}

                            {dealActionDialog.type === 'deactivate' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-reason-code">Reason</label>
                                    <select
                                        id="deactivate-reason-code"
                                        value={deactivationReasonCode}
                                        onChange={(e) => {
                                            setDeactivationReasonCode(e.target.value);
                                            setDeactivationLinkedPaymentAction(defaultLinkedPaymentAction(e.target.value));
                                        }}
                                        className="crm-select"
                                    >
                                        {DEAL_DEACTIVATION_REASON_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </select>

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-linked-payment-action">Linked payment action</label>
                                    <select
                                        id="deactivate-linked-payment-action"
                                        value={deactivationLinkedPaymentAction}
                                        onChange={(e) => setDeactivationLinkedPaymentAction(e.target.value)}
                                        className="crm-select"
                                    >
                                        {LINKED_PAYMENT_ACTION_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </select>

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-reason-notes">Notes</label>
                                    <textarea
                                        id="deactivate-reason-notes"
                                        rows={3}
                                        value={deactivationReasonNotes}
                                        onChange={(e) => setDeactivationReasonNotes(e.target.value)}
                                        className="crm-input"
                                        placeholder="Explain why this subscription is being deactivated."
                                    />

                                    <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" checked={notifyClient} onChange={(e) => setNotifyClient(e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                            Notify client via SMS
                                        </label>
                                        {notifyClient ? (
                                            <>
                                                <select value={notificationTemplateId} onChange={(e) => setNotificationTemplateId(e.target.value)} className="crm-select">
                                                    <option value="">Choose SMS template (optional)</option>
                                                    {smsTemplates.map((t) => (
                                                        <option key={t.id} value={t.id}>{t.title}</option>
                                                    ))}
                                                </select>
                                                <textarea rows={2} value={notificationMessage} onChange={(e) => setNotificationMessage(e.target.value)} className="crm-input" placeholder="Custom message (optional)" />
                                            </>
                                        ) : null}
                                    </div>
                                </>
                            ) : null}
                        </div>
                        <footer className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                            <button type="button" onClick={() => setDealActionDialog({ type: null, deal: null })} className="rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                Cancel
                            </button>
                            {dealActionDialog.type === 'extend' ? (
                                <button
                                    type="button"
                                    disabled={
                                        !extendDays
                                        || extendDealMutation.isPending
                                        || (dealPaymentRequiresReference && !dealPaymentReference.trim())
                                        || (dealPaymentRequiresFreeTrialPin && dealFreeTrialPin.trim().length < 4)
                                        || (dealPaymentRequiresProvider && !dealPaymentLinkProvider)
                                        || (dealApplyDiscount && !selectedDealDiscountValid)
                                        || (dealApplyDiscount && dealDiscountPin.trim().length < 4)
                                        || (dealSubscriptionLifecycle !== resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal) && !dealSubscriptionLifecycleReason.trim())
                                    }
                                    onClick={() => extendDealMutation.mutate({
                                        dealId: dealActionDialog.deal.id,
                                        additionalDays: Number(extendDays),
                                        extensionReason: extendReason,
                                        selectedPaymentMethod: dealPaymentMethod,
                                        subscriptionLifecycleValue: dealSubscriptionLifecycle,
                                        subscriptionLifecycleReasonValue: dealSubscriptionLifecycle !== resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal)
                                            ? dealSubscriptionLifecycleReason.trim()
                                            : undefined,
                                        referenceValue: dealPaymentReference,
                                        freeTrialPinValue: dealFreeTrialPin,
                                        paymentLinkProviderValue: dealPaymentLinkProvider,
                                        discountPercentageValue: dealApplyDiscount ? selectedDealDiscountValue : 0,
                                        discountPayableAmountValue: dealApplyDiscount ? selectedDealDiscountPayableValue : null,
                                        discountPinValue: dealDiscountPin,
                                    })}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {extendDealMutation.isPending ? 'Extending...' : 'Extend Subscription'}
                                </button>
                            ) : null}
                            {dealActionDialog.type === 'renew' ? (
                                <button
                                    type="button"
                                    disabled={
                                        !renewDays
                                        || renewDealMutation.isPending
                                        || (dealPaymentRequiresReference && !dealPaymentReference.trim())
                                        || (dealPaymentRequiresFreeTrialPin && dealFreeTrialPin.trim().length < 4)
                                        || (dealPaymentRequiresProvider && !dealPaymentLinkProvider)
                                        || (dealApplyDiscount && !selectedDealDiscountValid)
                                        || (dealApplyDiscount && dealDiscountPin.trim().length < 4)
                                        || (dealSubscriptionLifecycle !== resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal) && !dealSubscriptionLifecycleReason.trim())
                                    }
                                    onClick={() => renewDealMutation.mutate({
                                        dealId: dealActionDialog.deal.id,
                                        additionalDays: Number(renewDays),
                                        renewalReason: renewReason,
                                        selectedPaymentMethod: dealPaymentMethod,
                                        subscriptionLifecycleValue: dealSubscriptionLifecycle,
                                        subscriptionLifecycleReasonValue: dealSubscriptionLifecycle !== resolveDialogPredictedLifecycle(dealActionDialog.type, dealActionDialog.deal)
                                            ? dealSubscriptionLifecycleReason.trim()
                                            : undefined,
                                        referenceValue: dealPaymentReference,
                                        freeTrialPinValue: dealFreeTrialPin,
                                        paymentLinkProviderValue: dealPaymentLinkProvider,
                                        discountPercentageValue: dealApplyDiscount ? selectedDealDiscountValue : 0,
                                        discountPayableAmountValue: dealApplyDiscount ? selectedDealDiscountPayableValue : null,
                                        discountPinValue: dealDiscountPin,
                                    })}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {renewDealMutation.isPending ? 'Renewing...' : 'Renew Subscription'}
                                </button>
                            ) : null}
                            {dealActionDialog.type === 'deactivate' ? (
                                <button
                                    type="button"
                                    disabled={!deactivationReasonCode || deactivateDealMutation.isPending}
                                    onClick={() => deactivateDealMutation.mutate({
                                        dealId: dealActionDialog.deal.id,
                                        reasonCode: deactivationReasonCode,
                                        reasonNotes: deactivationReasonNotes,
                                        linkedPaymentAction: deactivationLinkedPaymentAction,
                                        shouldNotify: notifyClient,
                                        templateId: notificationTemplateId,
                                        message: notificationMessage,
                                    })}
                                    className="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {deactivateDealMutation.isPending ? 'Deactivating...' : 'Deactivate Subscription'}
                                </button>
                            ) : null}
                        </footer>
                    </div>
                </div>
            ) : null}

            <ClientSubscriptionDeactivationDialog
                open={clientDeactivateDialog.open}
                title="Deactivate Profile Subscription"
                message="This will strip paid badges, require payment, and set the linked WordPress profile to private."
                reasonCode={clientDeactivateDialog.reasonCode}
                onReasonCodeChange={(value) => setClientDeactivateDialog((current) => ({ ...current, reasonCode: value }))}
                reasonNotes={clientDeactivateDialog.reasonNotes}
                onReasonNotesChange={(value) => setClientDeactivateDialog((current) => ({ ...current, reasonNotes: value }))}
                notifyClient={clientDeactivateDialog.notifyClient}
                onNotifyClientChange={(value) => setClientDeactivateDialog((current) => ({ ...current, notifyClient: value }))}
                onCancel={closeClientDeactivationDialog}
                onConfirm={() => deactivateClientSubscriptionMutation.mutate({
                    reasonCode: clientDeactivateDialog.reasonCode,
                    reasonNotes: clientDeactivateDialog.reasonNotes,
                    notifyClient: clientDeactivateDialog.notifyClient,
                })}
                confirmDisabled={!clientDeactivateDialog.reasonNotes.trim() || deactivateClientSubscriptionMutation.isPending}
                isPending={deactivateClientSubscriptionMutation.isPending}
            />

            {activeTab === 'notes' ? (
                <div className="space-y-3">
                    {!isReadOnly ? (
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
                    ) : null}

                    {client.notes?.length > 0 ? client.notes.map((note) => (
                        <section key={note.id} className="crm-surface p-4">
                            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${
                                        note.note_type === 'support_chat'
                                            ? 'bg-sky-50 text-sky-700 ring-sky-200'
                                            : 'bg-slate-100 text-slate-600 ring-slate-200'
                                    }`}>
                                        {{ support_chat: 'Chat' }[note.note_type] || note.note_type}
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

            {activeTab === 'chat' ? (
                <SupportBoardChat clientId={id} client={client} />
            ) : null}

            {activeTab === 'wallet' ? (
                <div className="space-y-4">
                    <section className="crm-surface p-4">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h3 className="crm-panel-title">Client Wallet</h3>
                                <p className="crm-panel-subtitle">Balance, recent wallet activity, and manual wallet actions for this escort profile.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => refetchWallet()}
                                disabled={walletFetching}
                                className="crm-btn-secondary self-start disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {walletFetching ? 'Refreshing...' : 'Refresh wallet'}
                            </button>
                        </div>

                        {walletLoading ? (
                            <p className="mt-4 text-sm text-slate-500">Loading wallet...</p>
                        ) : walletSummary ? (
                            <div className="mt-4 grid gap-3 md:grid-cols-3">
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Balance</p>
                                    <p className="mt-2 text-2xl font-semibold text-slate-900">{formatCurrency(walletSummary.balance, walletSummary.currency || 'KES')}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Last Top-up</p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900">
                                        {walletSummary.last_topup
                                            ? formatCurrency(walletSummary.last_topup.amount, walletSummary.last_topup.currency || walletSummary.currency || 'KES')
                                            : 'No top-ups yet'}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">{formatDateTime(walletSummary.last_topup?.created_at)}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Synced</p>
                                    <p className="mt-2 text-sm font-semibold text-slate-900">{formatDateTime(walletSummary.wallet_last_synced_at)}</p>
                                    <p className="mt-1 text-xs text-slate-500">Refreshed {formatDateTime(walletSummary.refreshed_at)}</p>
                                </div>
                            </div>
                        ) : (
                            <p className="mt-4 text-sm text-slate-500">Wallet data is not available for this client yet.</p>
                        )}
                    </section>

                    {canManageWallet ? (
                        <section className="grid gap-4 lg:grid-cols-2">
                            <div className="crm-surface p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Manual Top-up</h4>
                                <p className="mt-1 text-xs text-slate-500">Use this for verified offline credits or support-side balance corrections.</p>
                                <div className="mt-4 space-y-3">
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={walletTopupForm.amount}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, amount: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Top-up amount"
                                    />
                                    <input
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={6}
                                        value={walletTopupForm.pin}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                        className="crm-input"
                                        placeholder="Wallet PIN"
                                    />
                                    <textarea
                                        rows={3}
                                        value={walletTopupForm.reason}
                                        onChange={(event) => setWalletTopupForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for wallet top-up"
                                    />
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => walletTopupMutation.mutate({
                                                amount: walletTopupForm.amount,
                                                pin: walletTopupForm.pin,
                                                reason: walletTopupForm.reason.trim(),
                                            })}
                                            disabled={!walletTopupForm.amount || !walletTopupForm.pin.trim() || !walletTopupForm.reason.trim() || walletTopupMutation.isPending}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {walletTopupMutation.isPending ? 'Recording...' : 'Record top-up'}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="crm-surface p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Balance Adjustment</h4>
                                <p className="mt-1 text-xs text-slate-500">Debit or credit the wallet directly when support needs to correct a balance.</p>
                                <div className="mt-4 space-y-3">
                                    <select
                                        value={walletAdjustmentForm.type}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, type: event.target.value }))}
                                        className="crm-select"
                                    >
                                        <option value="debit">Debit wallet</option>
                                        <option value="credit">Credit wallet</option>
                                    </select>
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value={walletAdjustmentForm.amount}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, amount: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Adjustment amount"
                                    />
                                    <input
                                        type="password"
                                        inputMode="numeric"
                                        maxLength={6}
                                        value={walletAdjustmentForm.pin}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, pin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                        className="crm-input"
                                        placeholder="Wallet PIN"
                                    />
                                    <textarea
                                        rows={3}
                                        value={walletAdjustmentForm.reason}
                                        onChange={(event) => setWalletAdjustmentForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Reason for wallet adjustment"
                                    />
                                    <div className="flex justify-end">
                                        <button
                                            type="button"
                                            onClick={() => walletAdjustmentMutation.mutate({
                                                type: walletAdjustmentForm.type,
                                                amount: walletAdjustmentForm.amount,
                                                pin: walletAdjustmentForm.pin,
                                                reason: walletAdjustmentForm.reason.trim(),
                                            })}
                                            disabled={!walletAdjustmentForm.amount || !walletAdjustmentForm.pin.trim() || !walletAdjustmentForm.reason.trim() || walletAdjustmentMutation.isPending}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {walletAdjustmentMutation.isPending ? 'Recording...' : 'Record adjustment'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>
                    ) : null}

                    <section className="crm-surface">
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Recent Wallet Transactions</h3>
                                <p className="crm-panel-subtitle">Latest balance changes for this escort wallet.</p>
                            </div>
                        </header>
                        <div className="p-4">
                            {walletLoading ? (
                                <p className="text-sm text-slate-500">Loading wallet transactions...</p>
                            ) : walletTransactions.length > 0 ? (
                                <div className="space-y-2">
                                    {walletTransactions.map((transaction) => (
                                        <div key={transaction.id} className="flex flex-col gap-2 rounded-md border border-slate-200 px-3 py-3 md:flex-row md:items-start md:justify-between">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
                                                        transaction.type === 'credit'
                                                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                                                            : 'bg-rose-50 text-rose-700 ring-rose-200'
                                                    }`}>
                                                        {transaction.type}
                                                    </span>
                                                    <p className="text-sm font-semibold text-slate-900">{formatCurrency(transaction.amount, transaction.currency || walletSummary?.currency || 'KES')}</p>
                                                </div>
                                                <p className="mt-1 text-sm text-slate-600">{transaction.description || 'Wallet transaction'}</p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Ref: {transaction.reference_type || '—'}
                                                    {transaction.reference_id ? ` #${transaction.reference_id}` : ''}
                                                    {transaction.payment_id ? ` • Payment #${transaction.payment_id}` : ''}
                                                    {transaction.deal_id ? ` • Deal #${transaction.deal_id}` : ''}
                                                </p>
                                            </div>
                                            <div className="text-left md:text-right">
                                                <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Balance After</p>
                                                <p className="mt-1 text-sm font-semibold text-slate-900">{formatCurrency(transaction.balance_after, transaction.currency || walletSummary?.currency || 'KES')}</p>
                                                <p className="mt-1 text-xs text-slate-500">{formatDateTime(transaction.created_at)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                    No wallet transactions recorded for this client yet.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
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
                                <div className="flex flex-wrap justify-end gap-1">
                                    <StatusBadge status={payment.status} />
                                    {payment.resolution_code ? (() => {
                                        const badge = paymentResolutionBadge(payment.resolution_code);
                                        return badge ? (
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${badge.className}`}>
                                                {badge.label}
                                            </span>
                                        ) : null;
                                    })() : null}
                                </div>
                                <p className="mt-1 text-xs text-slate-400">{formatDateTime(payment.created_at)}</p>
                            </div>
                        </section>
                    )) : (
                        <section className="crm-surface p-8 text-center text-sm text-slate-500">No payments recorded.</section>
                    )}
                </div>
            ) : null}

            {activeTab === 'edit_profile' && !isReadOnly ? (
                <section className="crm-surface p-4">
                    {!canSyncFromWp ? (
                        <p className="text-sm text-slate-500">This is a CRM-only client record and does not support WordPress profile editing.</p>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                {profileSections.map((section) => (
                                    <button
                                        key={section.key}
                                        type="button"
                                        onClick={() => setProfileSection(section.key)}
                                        className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                            profileSection === section.key
                                                ? 'bg-teal-700 text-white'
                                                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                        }`}
                                    >
                                        {section.label}
                                    </button>
                                ))}
                            </div>

                            {profileConflict ? (
                                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                                    <p className="font-semibold">WordPress has newer changes than CRM cache.</p>
                                    <p className="mt-1">WP modified: {formatDateTime(profileConflict.wp_modified_at)} • CRM synced: {formatDateTime(profileConflict.crm_last_synced_at)}</p>
                                    <div className="mt-2 space-y-1">
                                        {Object.entries(profileConflict.diff || {}).map(([field, values]) => (
                                            <p key={field}>
                                                <span className="font-semibold">{field}:</span> CRM "{String(values.crm_value ?? '')}" vs WP "{String(values.wp_value ?? '')}"
                                            </p>
                                        ))}
                                    </div>
                                    <label className="mt-2 flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={profileForce}
                                            onChange={(event) => setProfileForce(event.target.checked)}
                                            className="h-4 w-4 rounded border-amber-300 text-amber-700 focus:ring-amber-200"
                                        />
                                        Force overwrite WordPress values
                                    </label>
                                </div>
                            ) : null}

                            {wpProfileErrorData ? (
                                <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                                    <p className="font-semibold">
                                        {staleWpLink
                                            ? 'This CRM client is linked to a missing WordPress profile.'
                                            : 'WordPress profile data could not be loaded.'}
                                    </p>
                                    <p className="mt-1">{wpProfileErrorData.message || 'Failed to load WordPress profile data.'}</p>
                                    {staleWpLink ? (
                                        <p className="mt-1 text-xs text-rose-700">
                                            WP Post ID: {staleWpLink.wp_post_id || '—'} • WP User ID: {staleWpLink.wp_user_id || '—'}
                                        </p>
                                    ) : null}
                                    {repairableWpLink && !isReadOnly ? (
                                        <div className="mt-3">
                                            <button
                                                type="button"
                                                onClick={() => repairWpLinkMutation.mutate()}
                                                disabled={repairWpLinkMutation.isPending}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {repairWpLinkMutation.isPending ? 'Repairing WordPress link...' : 'Repair WordPress link'}
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            {profileSection === 'personal' ? (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">Use the dropdown options with visible codes. CRM saves the WordPress code value automatically.</p>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Display Name</span>
                                            <input value={profileForm?.name || ''} onChange={(event) => setProfileForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="e.g. Majesty" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Birthday</span>
                                            <input type="date" value={profileForm?.birthday || ''} onChange={(event) => setProfileForm((current) => ({ ...current, birthday: event.target.value }))} className="crm-input" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Gender (Code)</span>
                                            <select value={profileForm?.gender || ''} onChange={(event) => setProfileForm((current) => ({ ...current, gender: event.target.value }))} className="crm-input">
                                                <option value="">Select gender</option>
                                                {PROFILE_ENUM_OPTIONS.gender.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.gender && !isKnownProfileEnumCode('gender', profileForm.gender) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.gender)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Ethnicity (Code)</span>
                                            <select value={profileForm?.ethnicity || ''} onChange={(event) => setProfileForm((current) => ({ ...current, ethnicity: event.target.value }))} className="crm-input">
                                                <option value="">Select ethnicity</option>
                                                {PROFILE_ENUM_OPTIONS.ethnicity.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.ethnicity && !isKnownProfileEnumCode('ethnicity', profileForm.ethnicity) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.ethnicity)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Height (cm)</span>
                                            <input type="text" value={profileForm?.height || ''} onChange={(event) => setProfileForm((current) => ({ ...current, height: event.target.value }))} className="crm-input" placeholder={`e.g. 167 or 5'6" (167.64)`} />
                                            <p className="text-xs text-slate-500">You can enter cm or legacy formats. CRM auto-converts to centimeter value on save.</p>
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Build (Code)</span>
                                            <select value={profileForm?.build || ''} onChange={(event) => setProfileForm((current) => ({ ...current, build: event.target.value }))} className="crm-input">
                                                <option value="">Select build</option>
                                                {PROFILE_ENUM_OPTIONS.build.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                            {profileForm?.build && !isKnownProfileEnumCode('build', profileForm.build) ? (
                                                <p className="text-xs text-rose-600">Unknown current value: {String(profileForm.build)}</p>
                                            ) : null}
                                        </label>
                                        <label className="space-y-1 md:col-span-2">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Profile Bio</span>
                                            <textarea
                                                value={profileForm?.bio || ''}
                                                onChange={(event) => setProfileForm((current) => ({ ...current, bio: event.target.value }))}
                                                className="crm-input"
                                                rows={4}
                                                placeholder="Public profile description"
                                            />
                                            <div className="flex items-center gap-3 mt-1">
                                                <GenerateBioButton
                                                    clientId={client?.id ?? null}
                                                    platformId={client?.platform_id ?? null}
                                                    snapshot={profileForm ?? {}}
                                                    mode="preview"
                                                    onAccept={(bioHtml) => setProfileForm((current) => ({ ...current, bio: bioHtml }))}
                                                />
                                            </div>
                                        </label>
                                        <div className="md:col-span-2">
                                            <SeoQualityPanel
                                                score={client?.seo_score ?? null}
                                                breakdown={client?.seo_score_breakdown ?? null}
                                                stale={client?.seo_score_stale ?? false}
                                            />
                                        </div>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'appearance' ? (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">Appearance fields are saved as WordPress meta codes.</p>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {['haircolor', 'hairlength', 'bustsize', 'looks', 'smoker'].map((field) => (
                                            <label key={field} className="space-y-1">
                                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                                                    {field === 'haircolor' ? 'Hair Color' : field === 'hairlength' ? 'Hair Length' : field === 'bustsize' ? 'Bust Size' : field === 'looks' ? 'Looks' : 'Smoker'}
                                                </span>
                                                <select
                                                    value={profileForm?.[field] || ''}
                                                    onChange={(e) => setProfileForm((c) => ({ ...c, [field]: e.target.value }))}
                                                    className="crm-input"
                                                >
                                                    <option value="">Select</option>
                                                    {PROFILE_ENUM_OPTIONS[field]?.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                    ))}
                                                </select>
                                            </label>
                                        ))}
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Weight (kg)</span>
                                            <input type="text" value={profileForm?.weight || ''} onChange={(e) => setProfileForm((c) => ({ ...c, weight: e.target.value }))} className="crm-input" placeholder="e.g. 55" />
                                        </label>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'services' ? (
                                <div className="space-y-3">
                                    <p className="text-xs text-slate-500">Services are saved as WordPress service codes. Select one or more options with visible code values.</p>
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="space-y-1 md:col-span-2">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Services (Code)</span>
                                            <div className="rounded-md border border-slate-200 bg-white p-3">
                                                <div className="flex flex-wrap gap-2">
                                                    {serviceOptions.map((option) => {
                                                        const isSelected = selectedServiceCodes.includes(option.value);
                                                        const isUnknown = !isKnownProfileEnumCode('services', option.value);

                                                        return (
                                                            <button
                                                                key={`${option.value}-${option.label}`}
                                                                type="button"
                                                                onClick={() => {
                                                                    setProfileForm((current) => {
                                                                        const currentValues = Array.isArray(current?.services)
                                                                            ? current.services.map((value) => String(value || '').trim()).filter(Boolean)
                                                                            : [];

                                                                        const nextValues = currentValues.includes(option.value)
                                                                            ? currentValues.filter((value) => value !== option.value)
                                                                            : [...currentValues, option.value];

                                                                        return {
                                                                            ...current,
                                                                            services: nextValues,
                                                                        };
                                                                    });
                                                                }}
                                                                aria-pressed={isSelected}
                                                                className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                                                    isSelected
                                                                        ? 'border-teal-600 bg-teal-50 text-teal-700'
                                                                        : isUnknown
                                                                            ? 'border-amber-300 bg-amber-50 text-amber-700'
                                                                            : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400 hover:text-teal-700'
                                                                }`}
                                                            >
                                                                {option.label}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </div>
                                            <p className="text-xs text-slate-500">Click a service chip to add or remove it. Selected: {selectedServiceCodes.length}</p>
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Incall Rate (default)</span>
                                            <div className="flex items-center gap-1.5">
                                                <span className="shrink-0 rounded bg-slate-100 px-2 py-1.5 text-xs font-semibold text-slate-600">{client?.platform?.currency_code || 'KES'}</span>
                                                <input value={profileForm?.rates_incall || ''} onChange={(event) => setProfileForm((current) => ({ ...current, rates_incall: event.target.value }))} className="crm-input" placeholder="e.g. 1500" />
                                            </div>
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Outcall Rate (default)</span>
                                            <div className="flex items-center gap-1.5">
                                                <span className="shrink-0 rounded bg-slate-100 px-2 py-1.5 text-xs font-semibold text-slate-600">{client?.platform?.currency_code || 'KES'}</span>
                                                <input value={profileForm?.rates_outcall || ''} onChange={(event) => setProfileForm((current) => ({ ...current, rates_outcall: event.target.value }))} className="crm-input" placeholder="e.g. 2000" />
                                            </div>
                                        </label>

                                        <div className="md:col-span-2">
                                            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Rates by Duration <span className="ml-1 font-normal normal-case text-slate-400">({client?.platform?.currency_code || 'KES'})</span></p>
                                            <div className="overflow-auto rounded-md border border-slate-200">
                                                <table className="w-full text-xs">
                                                    <thead>
                                                        <tr className="bg-slate-50 text-slate-600">
                                                            <th className="px-2 py-1.5 text-left font-semibold">Duration</th>
                                                            <th className="px-2 py-1.5 text-left font-semibold">Incall</th>
                                                            <th className="px-2 py-1.5 text-left font-semibold">Outcall</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {[['30min', '30 min'], ['1h', '1 hour'], ['2h', '2 hours'], ['3h', '3 hours'], ['6h', '6 hours'], ['12h', '12 hours'], ['24h', '24 hours']].map(([key, label]) => (
                                                            <tr key={key} className="border-t border-slate-100">
                                                                <td className="px-2 py-1 text-slate-700">{label}</td>
                                                                <td className="px-1 py-1">
                                                                    <input value={profileForm?.[`rate${key}_incall`] || ''} onChange={(e) => setProfileForm((c) => ({ ...c, [`rate${key}_incall`]: e.target.value }))} className="crm-input py-1 text-xs" placeholder="—" />
                                                                </td>
                                                                <td className="px-1 py-1">
                                                                    <input value={profileForm?.[`rate${key}_outcall`] || ''} onChange={(e) => setProfileForm((c) => ({ ...c, [`rate${key}_outcall`]: e.target.value }))} className="crm-input py-1 text-xs" placeholder="—" />
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Availability</span>
                                            <div className="flex gap-2">
                                                {PROFILE_ENUM_OPTIONS.availability?.map((opt) => {
                                                    const selected = (profileForm?.availability || []).includes(opt.value);
                                                    return (
                                                        <button key={opt.value} type="button" onClick={() => setProfileForm((c) => {
                                                            const current = Array.isArray(c?.availability) ? [...c.availability] : [];
                                                            return { ...c, availability: selected ? current.filter((v) => v !== opt.value) : [...current, opt.value] };
                                                        })} className={`rounded-full border px-3 py-1.5 text-xs transition ${selected ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-slate-300 bg-white text-slate-700 hover:border-teal-400'}`}>
                                                            {opt.plainLabel}
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </label>

                                        <label className="space-y-1 md:col-span-2">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Extra Services</span>
                                            <textarea value={profileForm?.extraservices || ''} onChange={(e) => setProfileForm((c) => ({ ...c, extraservices: e.target.value }))} className="crm-input" rows={2} placeholder="Additional services not in the standard list" />
                                        </label>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'contact' ? (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <input value={profileForm?.phone || ''} onChange={(event) => setProfileForm((current) => ({ ...current, phone: event.target.value }))} className="crm-input" placeholder="Phone" />
                                    <input value={profileForm?.email || ''} onChange={(event) => setProfileForm((current) => ({ ...current, email: event.target.value }))} className="crm-input" placeholder="Email" />
                                    <select value={profileForm?.city || ''} onChange={(event) => setProfileForm((current) => ({ ...current, city: event.target.value }))} className="crm-input">
                                        <option value="">City</option>
                                        {availableCities.map((city) => (
                                            <option key={city} value={city}>{city}</option>
                                        ))}
                                    </select>
                                    <input value={profileForm?.whatsapp || ''} onChange={(event) => setProfileForm((current) => ({ ...current, whatsapp: event.target.value }))} className="crm-input" placeholder="WhatsApp" />
                                    <input value={profileForm?.instagram || ''} onChange={(event) => setProfileForm((current) => ({ ...current, instagram: event.target.value }))} className="crm-input" placeholder="Instagram URL" />
                                    <input value={profileForm?.twitter || ''} onChange={(event) => setProfileForm((current) => ({ ...current, twitter: event.target.value }))} className="crm-input" placeholder="Twitter URL" />
                                    <input value={profileForm?.telegram || ''} onChange={(event) => setProfileForm((current) => ({ ...current, telegram: event.target.value }))} className="crm-input" placeholder="Telegram" />
                                    <input value={profileForm?.website || ''} onChange={(event) => setProfileForm((current) => ({ ...current, website: event.target.value }))} className="crm-input" placeholder="Website" />
                                    <input value={profileForm?.facebook || ''} onChange={(event) => setProfileForm((current) => ({ ...current, facebook: event.target.value }))} className="crm-input" placeholder="Facebook URL" />
                                    <input value={profileForm?.snapchat || ''} onChange={(event) => setProfileForm((current) => ({ ...current, snapchat: event.target.value }))} className="crm-input" placeholder="SnapChat" />
                                </div>
                            ) : null}

                            {profileSection === 'lifestyle' ? (
                                <div className="space-y-4">
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Education</span>
                                            <input value={profileForm?.education || ''} onChange={(e) => setProfileForm((c) => ({ ...c, education: e.target.value }))} className="crm-input" placeholder="e.g. University degree" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Occupation</span>
                                            <input value={profileForm?.occupation || ''} onChange={(e) => setProfileForm((c) => ({ ...c, occupation: e.target.value }))} className="crm-input" placeholder="e.g. Model, Entrepreneur" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Sports</span>
                                            <input value={profileForm?.sports || ''} onChange={(e) => setProfileForm((c) => ({ ...c, sports: e.target.value }))} className="crm-input" placeholder="e.g. Swimming, Yoga" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Hobbies</span>
                                            <input value={profileForm?.hobbies || ''} onChange={(e) => setProfileForm((c) => ({ ...c, hobbies: e.target.value }))} className="crm-input" placeholder="e.g. Travel, Reading" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Zodiac Sign</span>
                                            <input value={profileForm?.zodiacsign || ''} onChange={(e) => setProfileForm((c) => ({ ...c, zodiacsign: e.target.value }))} className="crm-input" placeholder="e.g. Scorpio" />
                                        </label>
                                        <label className="space-y-1">
                                            <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Sexual Orientation</span>
                                            <input value={profileForm?.sexualorientation || ''} onChange={(e) => setProfileForm((c) => ({ ...c, sexualorientation: e.target.value }))} className="crm-input" placeholder="e.g. Bisexual" />
                                        </label>
                                    </div>

                                    <div className="border-t border-slate-200 pt-3">
                                        <h4 className="mb-2 text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Languages</h4>
                                        <div className="space-y-2">
                                            {[1, 2, 3].map((n) => (
                                                <div key={n} className="grid grid-cols-2 gap-2">
                                                    <input
                                                        value={profileForm?.[`language${n}`] || ''}
                                                        onChange={(e) => setProfileForm((c) => ({ ...c, [`language${n}`]: e.target.value }))}
                                                        className="crm-input"
                                                        placeholder={`Language ${n} (e.g. English, Swahili)`}
                                                    />
                                                    <select
                                                        value={profileForm?.[`language${n}level`] || ''}
                                                        onChange={(e) => setProfileForm((c) => ({ ...c, [`language${n}level`]: e.target.value }))}
                                                        className="crm-input"
                                                    >
                                                        <option value="">Level</option>
                                                        {PROFILE_ENUM_OPTIONS.languagelevel?.map((opt) => (
                                                            <option key={opt.value} value={opt.value}>{opt.plainLabel}</option>
                                                        ))}
                                                    </select>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ) : null}

                            {profileSection === 'subscription' ? (
                                <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-sm text-slate-700">Subscription fields are read-only in profile editor. Manage activation, extension, and deactivation from subscriptions workflows.</p>
                                    <div className="grid gap-2 md:grid-cols-2">
                                        <p className="text-xs text-slate-600">Status: <span className="font-semibold text-slate-900">{profileState.label}</span></p>
                                        <p className="text-xs text-slate-600">Plan: <span className="font-semibold text-slate-900">{client.plan_label || 'Basic'}</span></p>
                                        <p className="text-xs text-slate-600">Expires: <span className="font-semibold text-slate-900">{subscriptionExpiryDetailLabel}</span></p>
                                        <p className="text-xs text-slate-600">
                                            Active subscription: <span className="font-semibold text-slate-900">{activeSubscriptionLabel}</span>
                                            {isUntrackedForeverPlan && !client.active_deal ? (
                                                <span
                                                    className="ml-1 inline-flex h-4 w-4 cursor-help items-center justify-center rounded-full border border-slate-200 text-[10px] font-semibold text-slate-400"
                                                    title={FOREVER_PLAN_TOOLTIP}
                                                >
                                                    ?
                                                </span>
                                            ) : null}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            const next = new URLSearchParams(searchParams);
                                            next.set('tab', 'deals');
                                            setSearchParams(next, { replace: true });
                                            setActiveTab('deals');
                                        }}
                                        className="crm-btn-secondary"
                                    >
                                        Open subscriptions tab
                                    </button>
                                </div>
                            ) : null}

                            {profileSection === 'media' ? (
                                <div className="space-y-3">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <div className="grid gap-2 md:grid-cols-2">
                                            <input
                                                ref={mediaUploadInputRef}
                                                type="file"
                                                multiple
                                                accept="image/jpeg,image/png,image/webp,video/mp4"
                                                onChange={(event) => {
                                                    const selectedFiles = Array.from(event.target.files || []);
                                                    setMediaUploadFiles(selectedFiles);
                                                    if (selectedFiles.length !== 1 || selectedFiles.some((file) => isVideoUploadFile(file))) {
                                                        setMediaUploadSetMain(false);
                                                    }
                                                }}
                                                className="crm-input"
                                            />
                                            {!mediaUploadHasMultiple && !mediaUploadIsVideo ? (
                                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={mediaUploadSetMain}
                                                        onChange={(event) => setMediaUploadSetMain(event.target.checked)}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    Set uploaded image as main
                                                </label>
                                            ) : null}
                                        </div>
                                        {mediaUploadHasSelection ? (
                                            <p className="mt-2 text-xs text-slate-500">{mediaUploadSelectionLabel}</p>
                                        ) : null}
                                        <p className="mt-2 text-xs text-slate-500">
                                            Images: JPG, PNG, or WEBP up to 5MB. Video: single MP4 up to 50MB.
                                        </p>
                                        {mediaUploadPreflight.errors.map((message) => (
                                            <p key={message} className="mt-2 text-xs text-rose-700">{message}</p>
                                        ))}
                                        {mediaUploadPreflight.guidance.map((message) => (
                                            <p key={message} className="mt-2 text-xs text-amber-700">{message}</p>
                                        ))}
                                        <div className="mt-2 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (!mediaUploadHasSelection) return;
                                                    const result = startClientMediaUpload({
                                                        clientId: id,
                                                        clientName: client?.name || '',
                                                        files: mediaUploadFiles,
                                                        setMain: mediaUploadHasMultiple || mediaUploadIsVideo ? false : mediaUploadSetMain,
                                                    });
                                                    if (result?.queued) {
                                                        setMediaUploadFiles([]);
                                                        setMediaUploadSetMain(false);
                                                        if (mediaUploadInputRef.current) {
                                                            mediaUploadInputRef.current.value = '';
                                                        }
                                                    }
                                                }}
                                                disabled={!mediaUploadHasSelection || mediaUploadHasInvalidBatch}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Upload in background
                                            </button>
                                        </div>
                                    </div>

                                    {hasBackgroundMediaUploads ? (
                                        <div className="rounded-md border border-slate-200 bg-white px-3 py-2" aria-live="polite">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Upload status</p>
                                                <p className="text-xs text-slate-500">You can keep working while media finishes.</p>
                                            </div>
                                            <div className="mt-2 space-y-2">
                                                {backgroundMediaUploads.map((upload) => (
                                                    <div key={upload.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 px-3 py-2 text-sm">
                                                        <div className="min-w-0">
                                                            <p className="font-medium text-slate-700">{upload.label}</p>
                                                            <p className={
                                                                upload.status === 'success'
                                                                    ? 'text-emerald-700'
                                                                    : upload.status === 'failed'
                                                                        ? 'text-rose-700'
                                                                        : 'text-amber-700'
                                                            }>
                                                                {upload.message}
                                                            </p>
                                                        </div>
                                                        {upload.status === 'failed' ? (
                                                            <div className="flex gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => retryUpload(upload.id)}
                                                                    className="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                                                                >
                                                                    Retry
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => dismissUpload(upload.id)}
                                                                    className="rounded-md px-2.5 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100"
                                                                >
                                                                    Dismiss
                                                                </button>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}

                                    {mediaLoading ? (
                                        <p className="text-sm text-slate-500">Loading media...</p>
                                    ) : mediaErrorData ? (
                                        <div className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                                            <p className="font-semibold">
                                                {staleWpLink
                                                    ? 'Media could not be loaded because the WordPress profile link is stale.'
                                                    : 'Media could not be loaded from WordPress.'}
                                            </p>
                                            <p className="mt-1">{mediaErrorData.message || 'Failed to load WordPress media.'}</p>
                                            {repairableWpLink && !isReadOnly ? (
                                                <div className="mt-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => repairWpLinkMutation.mutate()}
                                                        disabled={repairWpLinkMutation.isPending}
                                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {repairWpLinkMutation.isPending ? 'Repairing WordPress link...' : 'Repair WordPress link'}
                                                    </button>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : mediaItems.length > 0 ? (
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {mediaItems.map((media) => (
                                                <ClientMediaCard
                                                    key={media.id}
                                                    media={media}
                                                    setMainPending={setMainMediaMutation.isPending}
                                                    deletePending={deleteMediaMutation.isPending}
                                                    onSetMain={() => setMainMediaMutation.mutate(media.id)}
                                                    onDelete={() => deleteMediaMutation.mutate(media.id)}
                                                />
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                            No media uploaded yet. Choose a file above to upload an image or MP4 video.
                                        </p>
                                    )}
                                </div>
                            ) : null}

                            {profileSection !== 'media' ? (
                                <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                    <textarea
                                        rows={2}
                                        value={profileReason}
                                        onChange={(event) => setProfileReason(event.target.value)}
                                        className="crm-input"
                                    />
                                    <div className="mt-2 flex justify-end">
                                        <button
                                            type="button"
                                            onClick={submitProfileUpdate}
                                            disabled={!profileForm?.name?.trim() || !profileReason.trim() || updateProfileMutation.isPending || (profileConflict && !profileForce)}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {updateProfileMutation.isPending ? 'Syncing to WordPress...' : 'Save profile changes'}
                                        </button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    )}
                </section>
            ) : null}

            {activeTab === 'profile_health' && !isReadOnly ? (
                <section className="crm-surface p-4">
                    {healthLoading ? (
                        <p className="text-sm text-slate-500">Loading profile health...</p>
                    ) : (
                        <div className="space-y-4">
                            <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                <p>Phone: <span className="crm-mono font-semibold text-slate-900">{healthData?.summary?.phone_normalized || '—'}</span></p>
                                <p className="mt-1">Duplicates: <span className="font-semibold text-slate-900">{healthData?.summary?.duplicate_count || 0}</span> • Lead matches: <span className="font-semibold text-slate-900">{healthData?.summary?.lead_matches || 0}</span></p>
                            </div>

                            {healthDuplicates.length > 0 ? (
                                <div className="space-y-2">
                                    {healthDuplicates.map((duplicate) => (
                                        <label key={duplicate.id} className="flex items-start gap-3 rounded-md border border-slate-200 bg-white p-3">
                                            <input
                                                type="checkbox"
                                                checked={selectedDuplicateIds.includes(String(duplicate.id))}
                                                onChange={(event) => {
                                                    setSelectedDuplicateIds((current) => {
                                                        if (event.target.checked) {
                                                            return [...current, String(duplicate.id)];
                                                        }
                                                        return current.filter((value) => value !== String(duplicate.id));
                                                    });
                                                }}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm font-semibold text-slate-900">{duplicate.name || `Client #${duplicate.id}`}</p>
                                                <p className="text-xs text-slate-500">
                                                    CRM #{duplicate.id} • WP #{duplicate.wp_post_id || '—'} • status {duplicate.profile_status} • active deals {duplicate.active_deals_count}
                                                </p>
                                                <p className="text-xs text-slate-500">Last payment: {formatDateTime(duplicate.last_payment_at)}</p>
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
                                    No duplicate profiles detected for this phone number.
                                </p>
                            )}

                            <div className="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Resolution action</label>
                                    <select value={healthAction} onChange={(event) => setHealthAction(event.target.value)} className="crm-select">
                                        <option value="keep_primary">Keep primary (move deals/payments)</option>
                                        <option value="merge_into_primary">Merge into primary</option>
                                        <option value="archive_duplicate">Archive selected duplicates</option>
                                        <option value="update_phone">Update duplicate phone</option>
                                    </select>
                                </div>
                                {healthAction === 'update_phone' ? (
                                    <>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">Duplicate profile</label>
                                            <select value={updatePhoneTargetId} onChange={(event) => setUpdatePhoneTargetId(event.target.value)} className="crm-select">
                                                <option value="">Select duplicate</option>
                                                {healthDuplicates.map((duplicate) => (
                                                    <option key={duplicate.id} value={duplicate.id}>
                                                        {duplicate.name || `Client #${duplicate.id}`} (CRM #{duplicate.id})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700">New phone</label>
                                            <input
                                                value={updatePhoneValue}
                                                onChange={(event) => setUpdatePhoneValue(event.target.value)}
                                                className="crm-input"
                                                placeholder={`e.g. ${platformPhonePrefix}712345678`}
                                            />
                                        </div>
                                    </>
                                ) : null}
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Resolution note</label>
                                    <textarea
                                        rows={3}
                                        value={healthReason}
                                        onChange={(event) => setHealthReason(event.target.value)}
                                        className="crm-input"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end">
                                <button
                                    type="button"
                                    onClick={applyHealthResolution}
                                    disabled={
                                        !healthReason.trim()
                                        || resolveHealthMutation.isPending
                                        || (
                                            healthAction === 'update_phone'
                                                ? (!updatePhoneTargetId || !updatePhoneValue.trim())
                                                : !selectedDuplicateIds.length
                                        )
                                    }
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {resolveHealthMutation.isPending ? 'Applying...' : 'Apply resolution'}
                                </button>
                            </div>
                        </div>
                    )}
                </section>
            ) : null}

            {activationDialog.open ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={closeActivationDialog}>
                    <div className="flex w-full max-w-lg flex-col rounded-lg border border-slate-200 bg-white shadow-xl max-h-[90vh]" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header shrink-0">
                            <div>
                                <h3 className="crm-panel-title">Activate Subscription</h3>
                                <p className="crm-panel-subtitle">{client.name} • {activationDialog.dealLabel}</p>
                            </div>
                        </header>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                        <div className="space-y-4 p-4">
                            <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                <p className="text-sm font-semibold text-slate-800">Payment Method</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {activationPaymentMethods.map((method) => (
                                        <button
                                            key={method}
                                            type="button"
                                            onClick={() => {
                                                setActivationPaymentMethod(method);
                                                if (method === 'free_trial') {
                                                    setActivationApplyDiscount(false);
                                                    setActivationDiscountPercentage('');
                                                    setActivationDiscountPayableAmount('');
                                                    setActivationDiscountPin('');
                                                }
                                            }}
                                            className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                activationPaymentMethod === method
                                                    ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                            }`}
                                        >
                                            {method === 'manual'
                                                ? 'Manual Payment'
                                                : method === 'stk'
                                                    ? 'STK Push'
                                                    : method === 'link'
                                                        ? 'Payment Link'
                                                : 'Free Trial'}
                                        </button>
                                    ))}
                                </div>
                                {activationPaymentMethods.length === 0 ? (
                                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                        No CRM payment methods are enabled for this market on activation.
                                    </div>
                                ) : null}

                                {activationPaymentMethod === 'manual' ? (
                                    <div>
                                        <label htmlFor="client-detail-payment-reference" className="mb-1 block text-sm font-medium text-slate-700">
                                            MPESA / Transaction Reference
                                        </label>
                                        <input
                                            id="client-detail-payment-reference"
                                            type="text"
                                            value={activationPaymentReference}
                                            onChange={(event) => setActivationPaymentReference(event.target.value)}
                                            className="crm-input"
                                            placeholder="e.g. MPESA123ABC"
                                        />
                                    </div>
                                ) : null}

                                {activationPaymentMethod === 'link' && canOverridePaymentLinkProvider ? (
                                    <div className="space-y-2">
                                        <label htmlFor="client-detail-payment-link-provider" className="mb-1 block text-sm font-medium text-slate-700">
                                            Link provider
                                        </label>
                                        <select
                                            id="client-detail-payment-link-provider"
                                            value={activationPaymentLinkProvider}
                                            onChange={(event) => setActivationPaymentLinkProvider(event.target.value)}
                                            className="crm-select"
                                            disabled={!paymentLinkProviderOptions.length}
                                        >
                                            <option value="">{paymentLinkProviderOptions.length ? 'Choose link provider' : 'No enabled provider available'}</option>
                                            {paymentLinkProviderOptions.map((provider) => (
                                                <option key={provider.key} value={provider.key}>
                                                    {provider.optionLabel}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-xs text-slate-500">
                                            Choose who sends the payment link.
                                        </p>
                                    </div>
                                ) : null}

                                {activationPaymentMethod === 'link' && !canOverridePaymentLinkProvider ? (
                                    <div className="space-y-2">
                                        <p className="mb-1 block text-sm font-medium text-slate-700">Link provider</p>
                                        <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                            {paymentLinkProviderOptions.length
                                                ? 'Billing policy will use the market active provider for this activation.'
                                                : 'No enabled payment-link provider is configured for this market yet.'}
                                        </div>
                                        <p className="text-xs text-slate-500">
                                            Operators follow the market billing policy. Admins can override the provider in Billing settings.
                                        </p>
                                    </div>
                                ) : null}

                                {activationPaymentMethod === 'free_trial' ? (
                                    <div className="space-y-2">
                                        <label htmlFor="client-detail-free-trial-pin" className="mb-1 block text-sm font-medium text-slate-700">
                                            Free-trial PIN
                                        </label>
                                        <input
                                            id="client-detail-free-trial-pin"
                                            type="password"
                                            inputMode="numeric"
                                            maxLength={6}
                                            value={activationFreeTrialPin}
                                            onChange={(event) => setActivationFreeTrialPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                            className="crm-input"
                                            placeholder="Enter free-trial PIN"
                                        />
                                        <p className="text-xs text-slate-500">
                                            Enter the free-trial PIN from Settings.
                                        </p>
                                    </div>
                                ) : null}

                                {(activationPaymentMethod === 'stk' || activationPaymentMethod === 'link') ? (
                                    <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                        {activationPaymentMethod === 'stk'
                                            ? 'We’ll send an STK push to this phone. The subscription starts after payment is confirmed.'
                                            : paymentLinkProviderOptions.length
                                                ? 'We’ll send a payment link to this phone. The subscription starts after payment is confirmed.'
                                                : 'No payment link provider is set up for this market yet.'}
                                        <span className="mt-1 block crm-mono text-[11px] text-slate-500">
                                            Phone: {activationTargetPhone || 'Unavailable'}
                                        </span>
                                    </div>
                                ) : null}
                                {activationDiscountAllowed ? (
                                    <DiscountPricingEditor
                                        idPrefix="client-detail-activation"
                                        applyDiscount={activationApplyDiscount}
                                        onToggle={(checked) => {
                                            setActivationApplyDiscount(checked);
                                            if (!checked) {
                                                setActivationDiscountPercentage('');
                                                setActivationDiscountPayableAmount('');
                                                setActivationDiscountPin('');
                                            }
                                        }}
                                        baseAmount={activationBaseAmount}
                                        currency={activationDeal?.currency || 'KES'}
                                        payableAmount={activationDiscountPayableAmount}
                                        discountPercentage={activationDiscountPercentage}
                                        discountPin={activationDiscountPin}
                                        discountedTotal={activationDiscountedTotal}
                                        savingsAmount={activationSavingsAmount}
                                        onPayableChange={syncActivationDiscountFromPayable}
                                        onPayableBlur={normalizeActivationPayableOnBlur}
                                        onPercentageChange={syncActivationDiscountFromPercentage}
                                        onPinChange={setActivationDiscountPin}
                                    />
                                ) : null}

                                <div className="space-y-3 rounded-md border border-slate-200 bg-white p-3">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-800">Subscriber Type</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {subscriptionLifecycleHelperText(resolveDialogPredictedLifecycle('activate', activationDeal))}
                                        </p>
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {['new', 'renewal'].map((option) => (
                                            <button
                                                key={option}
                                                type="button"
                                                onClick={() => setActivationSubscriptionLifecycle(option)}
                                                className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                    activationSubscriptionLifecycle === option
                                                        ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                                }`}
                                            >
                                                {option === 'new' ? 'New' : 'Renewal'}
                                            </button>
                                        ))}
                                    </div>
                                    {activationSubscriptionLifecycle !== resolveDialogPredictedLifecycle('activate', activationDeal) ? (
                                        <div>
                                            <label htmlFor="client-detail-lifecycle-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                                Override reason
                                            </label>
                                            <textarea
                                                id="client-detail-lifecycle-reason"
                                                rows={2}
                                                value={activationSubscriptionLifecycleReason}
                                                onChange={(event) => setActivationSubscriptionLifecycleReason(event.target.value)}
                                                className="crm-input"
                                                placeholder="Explain why this should be classified differently"
                                            />
                                        </div>
                                    ) : null}
                                </div>

                            </div>

                            <div>
                                <label htmlFor="client-detail-activation-reason" className="mb-1 block text-sm font-medium text-slate-700">
                                    Reason
                                </label>
                                <textarea
                                    id="client-detail-activation-reason"
                                    rows={3}
                                    value={activationReason}
                                    onChange={(event) => setActivationReason(event.target.value)}
                                    className="crm-input"
                                />
                            </div>
                        </div>
                        </div>
                        <footer className="flex shrink-0 items-center justify-end gap-2 border-t border-slate-100 px-4 py-3">
                            <button
                                type="button"
                                onClick={closeActivationDialog}
                                className="crm-btn-secondary"
                                disabled={activateDealMutation.isPending}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={submitActivation}
                                disabled={activationSubmitDisabled}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {activateDealMutation.isPending
                                    ? 'Submitting...'
                                    : activationPaymentMethod === 'stk'
                                        ? 'Send STK push'
                                        : activationPaymentMethod === 'link'
                                            ? 'Send payment link'
                                        : 'Activate subscription'}
                            </button>
                        </footer>
                    </div>
                </div>
            ) : null}

            {!isReadOnly && showPaymentLinkModal ? (
                <PaymentLinkModal
                    client={client}
                    products={products}
                    deals={paymentLinkEligibleDeals}
                    providerOptions={paymentLinkProviderOptions}
                    defaultProvider={defaultPaymentLinkProvider}
                    canOverridePaymentLinkProvider={canOverridePaymentLinkProvider}
                    result={paymentLinkResult}
                    isPending={sendPaymentLinkMutation.isPending}
                    onClose={closePaymentLinkModal}
                    onCopyLink={copyPaymentLinkUrl}
                    onSendQuickSubscribe={(payload) => sendPaymentLinkMutation.mutate(payload)}
                    onSendExistingDeal={(payload) => sendPaymentLinkMutation.mutate(payload)}
                />
            ) : null}

            {!isReadOnly && showDealModal ? (
                <DealModal
                    client={client}
                    products={products}
                    onClose={() => setShowDealModal(false)}
                    onSubmit={(deal) => createDealMutation.mutate(deal)}
                    isPending={createDealMutation.isPending}
                    error={createDealMutation.error}
                />
            ) : null}

            {!isReadOnly ? (
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
            ) : null}

            {canDeleteClient ? (
                <DeleteClientDialog
                    open={showDeleteDialog}
                    client={client}
                    preview={deletePreview}
                    confirmText={deleteConfirmText}
                    reason={deleteReason}
                    previewPending={deletePreviewMutation.isPending}
                    deletePending={deleteClientMutation.isPending}
                    onCancel={closeDeleteDialog}
                    onConfirmTextChange={setDeleteConfirmText}
                    onReasonChange={setDeleteReason}
                    onConfirm={() => deleteClientMutation.mutate({
                        confirm: deleteConfirmText,
                        reason: deleteReason.trim() || 'Client deleted from CRM',
                    })}
                />
            ) : null}

            {!isReadOnly ? (
                <CredentialDispatchDrawer
                    open={showCredentialDrawer}
                    client={client}
                    defaultSource="client_detail"
                    defaultReason="Client access from client detail"
                    onClose={() => setShowCredentialDrawer(false)}
                    onSuccess={() => {
                        queryClient.invalidateQueries({ queryKey: ['client-timeline', id] });
                        queryClient.invalidateQueries({ queryKey: ['client', id] });
                    }}
                />
            ) : null}

            {/* ── NEW Badge Dialog ─────────────────────────────────────────── */}
            {!isReadOnly && showNewBadgeDialog ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white shadow-xl">
                        <div className="border-b border-slate-200 px-6 py-4">
                            <h2 className="text-base font-semibold text-slate-900">Set NEW badge behavior</h2>
                            <p className="mt-1 text-sm text-slate-500">
                                Choose whether the public profile should follow the normal NEW rule, stay pinned on, or be forced off.
                            </p>
                        </div>
                        <div className="space-y-3 px-6 py-5">
                            {NEW_BADGE_MODE_OPTIONS.map((option) => {
                                const isActive = currentNewBadgeMode === option.mode;
                                return (
                                    <button
                                        key={option.mode}
                                        type="button"
                                        disabled={updateNewBadgeMutation.isPending}
                                        onClick={() => updateNewBadgeMutation.mutate(option.mode)}
                                        className={`w-full rounded-xl border px-4 py-3 text-left transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                            isActive
                                                ? 'border-violet-300 bg-violet-50 shadow-sm'
                                                : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <div className="text-sm font-semibold text-slate-900">{option.title}</div>
                                                <div className="mt-1 text-sm text-slate-500">{option.description}</div>
                                            </div>
                                            {isActive ? (
                                                <span className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-violet-700">
                                                    Current
                                                </span>
                                            ) : null}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                        <div className="flex items-center justify-between border-t border-slate-200 px-6 py-4">
                            <p className="text-xs text-slate-500">
                                Automatic uses the site&apos;s normal publish-date window for NEW.
                            </p>
                            <button
                                type="button"
                                onClick={() => setShowNewBadgeDialog(false)}
                                disabled={updateNewBadgeMutation.isPending}
                                className="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {updateNewBadgeMutation.isPending ? 'Updating…' : 'Close'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            {/* ── Add Tour Modal ───────────────────────────────────────────── */}
            {!isReadOnly && showTourModal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white shadow-xl">
                        <div className="border-b border-slate-200 px-6 py-4">
                            <h2 className="text-base font-semibold text-slate-900">Add Tour</h2>
                            <p className="mt-0.5 text-sm text-slate-500">Schedule an appearance on the client's WordPress profile.</p>
                        </div>
                        <div className="space-y-4 px-6 py-5">
                            <label className="block space-y-1.5">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Destination city <span className="text-rose-500">*</span></span>
                                <input
                                    type="text"
                                    value={tourForm.city}
                                    onChange={(e) => setTourForm((f) => ({ ...f, city: e.target.value }))}
                                    className="crm-input"
                                    placeholder="e.g. Nairobi"
                                />
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <label className="block space-y-1.5">
                                    <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Start date <span className="text-rose-500">*</span></span>
                                    <input
                                        type="date"
                                        value={tourForm.start}
                                        onChange={(e) => setTourForm((f) => ({ ...f, start: e.target.value }))}
                                        className="crm-input"
                                    />
                                </label>
                                <label className="block space-y-1.5">
                                    <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">End date <span className="text-rose-500">*</span></span>
                                    <input
                                        type="date"
                                        value={tourForm.end}
                                        min={tourForm.start}
                                        onChange={(e) => setTourForm((f) => ({ ...f, end: e.target.value }))}
                                        className="crm-input"
                                    />
                                </label>
                            </div>
                            <label className="block space-y-1.5">
                                <span className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Contact phone <span className="text-rose-500">*</span></span>
                                <input
                                    type="tel"
                                    value={tourForm.phone}
                                    onChange={(e) => setTourForm((f) => ({ ...f, phone: e.target.value }))}
                                    className="crm-input"
                                    placeholder={client?.phone_normalized || 'e.g. 254712345678'}
                                />
                            </label>
                            {addTourMutation.isError ? (
                                <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                    {addTourMutation.error?.response?.data?.message || 'Failed to add tour.'}
                                </p>
                            ) : null}
                        </div>
                        <div className="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
                            <button
                                type="button"
                                onClick={() => { setShowTourModal(false); setTourForm({ city: '', start: '', end: '', phone: '' }); addTourMutation.reset(); }}
                                className="crm-btn-secondary"
                                disabled={addTourMutation.isPending}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={addTourMutation.isPending || !tourForm.city || !tourForm.start || !tourForm.end || !tourForm.phone}
                                onClick={() => addTourMutation.mutate(tourForm)}
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {addTourMutation.isPending ? 'Adding…' : 'Add Tour'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function resolveMediaUnavailableMessage(status) {
    if (status === 404) {
        return 'File missing on market site';
    }

    if (status === 403 || status === 502 || status === 503 || status === 504) {
        return 'File blocked or unavailable';
    }

    return 'File unavailable';
}

function resolveMediaKind(media) {
    const mimeType = String(media?.mime_type || '').toLowerCase();
    if (mimeType.startsWith('video/')) {
        return 'video';
    }

    if (mimeType.startsWith('image/')) {
        return 'image';
    }

    const url = String(media?.url || '').toLowerCase();
    if (/\.(mp4|m4v|mov|webm|ogg)(?:$|[?#])/.test(url)) {
        return 'video';
    }

    return 'image';
}

function isVideoUploadFile(file) {
    if (!file) {
        return false;
    }

    const mimeType = String(file.type || '').toLowerCase();
    if (mimeType.startsWith('video/')) {
        return true;
    }

    return /\.mp4$/i.test(String(file.name || ''));
}

function ClientMediaCard({
    media,
    setMainPending,
    deletePending,
    onSetMain,
    onDelete,
}) {
    const displayName = media.file_name || media.filename || 'Untitled media';
    const mediaKind = resolveMediaKind(media);
    const proxiedUrl = proxyImageUrl(media?.url || '');
    const hasAssetUrl = proxiedUrl.trim() !== '';
    const cachedAvailability = hasAssetUrl ? mediaProxyAvailabilityCache.get(proxiedUrl) : null;
    const [assetState, setAssetState] = useState(() => {
        if (!hasAssetUrl) {
            return 'unavailable';
        }

        return cachedAvailability?.state || 'checking';
    });
    const [assetStatus, setAssetStatus] = useState(() => {
        if (!hasAssetUrl) {
            return 404;
        }

        return cachedAvailability?.status ?? null;
    });

    useEffect(() => {
        let cancelled = false;

        if (!hasAssetUrl) {
            setAssetState('unavailable');
            setAssetStatus(404);
            return () => {
                cancelled = true;
            };
        }

        const cachedResult = mediaProxyAvailabilityCache.get(proxiedUrl);
        if (cachedResult) {
            setAssetState(cachedResult.state);
            setAssetStatus(cachedResult.status ?? null);

            return () => {
                cancelled = true;
            };
        }

        setAssetState('checking');
        setAssetStatus(null);

        fetch(proxiedUrl, {
            method: 'HEAD',
            cache: 'no-store',
            credentials: 'same-origin',
        })
            .then((response) => {
                if (cancelled) {
                    return;
                }

                if (response.ok) {
                    mediaProxyAvailabilityCache.set(proxiedUrl, {
                        state: 'ready',
                        status: response.status,
                    });
                    setAssetState('ready');
                    setAssetStatus(response.status);
                    return;
                }

                mediaProxyAvailabilityCache.set(proxiedUrl, {
                    state: 'unavailable',
                    status: response.status,
                });
                setAssetState('unavailable');
                setAssetStatus(response.status);
            })
            .catch(() => {
                if (cancelled) {
                    return;
                }

                mediaProxyAvailabilityCache.set(proxiedUrl, {
                    state: 'unavailable',
                    status: null,
                });
                setAssetState('unavailable');
                setAssetStatus(null);
            });

        return () => {
            cancelled = true;
        };
    }, [hasAssetUrl, proxiedUrl]);

    const unavailableMessage = resolveMediaUnavailableMessage(assetStatus);

    return (
        <div className={`rounded-lg border p-3 ${media.is_main ? 'border-amber-300 bg-amber-50/30' : 'border-slate-200 bg-white'}`}>
            <div className="overflow-hidden rounded-md border border-slate-200 bg-slate-100">
                <div className="aspect-[4/3]">
                    {assetState === 'checking' ? (
                        <div className="flex h-full items-center justify-center bg-slate-100 text-sm text-slate-500">
                            Checking file…
                        </div>
                    ) : assetState === 'ready' && mediaKind === 'video' ? (
                        <video
                            src={proxiedUrl}
                            controls
                            playsInline
                            preload="metadata"
                            className="h-full w-full bg-slate-950 object-contain"
                            onError={() => {
                                mediaProxyAvailabilityCache.set(proxiedUrl, {
                                    state: 'unavailable',
                                    status: null,
                                });
                                setAssetState('unavailable');
                                setAssetStatus(null);
                            }}
                        />
                    ) : assetState === 'ready' ? (
                        <img
                            src={proxiedUrl}
                            alt={displayName}
                            className="h-full w-full object-cover"
                            loading="lazy"
                            onError={() => {
                                mediaProxyAvailabilityCache.set(proxiedUrl, {
                                    state: 'unavailable',
                                    status: null,
                                });
                                setAssetState('unavailable');
                                setAssetStatus(null);
                            }}
                        />
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-2 bg-slate-100 px-4 text-center">
                            <span className="text-sm font-medium text-slate-700">{unavailableMessage}</span>
                            <span className="text-xs text-slate-500">
                                {mediaKind === 'video' ? 'The video could not be loaded from the market site.' : 'The image could not be loaded from the market site.'}
                            </span>
                        </div>
                    )}
                </div>
            </div>

            <div className="mt-3 space-y-3">
                <div>
                    <p className="truncate text-sm font-medium text-slate-900">{displayName}</p>
                    <p className="mt-1 text-xs text-slate-500">
                        {media.mime_type || (mediaKind === 'video' ? 'video' : 'image')}
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {media.is_main ? (
                        <span className="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                            Main image
                        </span>
                    ) : (
                        <button
                            type="button"
                            onClick={onSetMain}
                            disabled={setMainPending}
                            className="rounded-md border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm font-medium text-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Set main
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={onDelete}
                        disabled={deletePending}
                        className="rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>
    );
}

function DeleteClientDialog({
    open,
    client,
    preview,
    confirmText,
    reason,
    previewPending,
    deletePending,
    onCancel,
    onConfirmTextChange,
    onReasonChange,
    onConfirm,
}) {
    const confirmMatches = confirmText.trim() === String(client?.name || '').trim();
    const confirmDisabled = previewPending
        || deletePending
        || !preview
        || !confirmMatches
        || !reason.trim();

    return (
        <ConfirmDialog
            open={open}
            title="Delete Client"
            message="This permanently removes the CRM client record, deletes linked subscriptions, and prevents WordPress re-import for the same profile."
            confirmLabel={deletePending ? 'Deleting...' : 'Delete client'}
            tone="danger"
            onCancel={onCancel}
            onConfirm={onConfirm}
            confirmDisabled={confirmDisabled}
            isPending={deletePending}
        >
            {previewPending ? (
                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                    Loading deletion impact…
                </div>
            ) : preview ? (
                <div className="space-y-3">
                    <div className="grid gap-2 sm:grid-cols-2">
                        <ImpactPill label="Subscriptions" value={preview.deals_count} />
                        <ImpactPill label="Payments" value={preview.payments_count} />
                        <ImpactPill label="Notes" value={preview.notes_count} />
                        <ImpactPill label="Leads" value={preview.leads_count} />
                        <ImpactPill label="Timeline events" value={preview.timeline_events_count} />
                        <ImpactPill label="WP profile" value={preview.wp_post_id ? `#${preview.wp_post_id}` : 'None'} />
                    </div>

                    {preview.has_active_deal ? (
                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            This client currently has an active subscription. Deleting will remove the CRM client record and its linked deal history.
                        </div>
                    ) : null}

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            Reason
                        </span>
                        <textarea
                            value={reason}
                            onChange={(event) => onReasonChange(event.target.value)}
                            rows={3}
                            className="crm-input min-h-[96px] w-full"
                            placeholder="Why is this client being deleted?"
                        />
                    </label>

                    <label className="block">
                        <span className="mb-1 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">
                            Type the client name to confirm
                        </span>
                        <input
                            type="text"
                            value={confirmText}
                            onChange={(event) => onConfirmTextChange(event.target.value)}
                            className="crm-input"
                            placeholder={client?.name || 'Client name'}
                        />
                    </label>
                </div>
            ) : null}
        </ConfirmDialog>
    );
}

function ImpactPill({ label, value }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">{value}</p>
        </div>
    );
}

function DealModal({ client, products, onClose, onSubmit, isPending, error }) {
    const platformCurrency = client?.platform?.currency_code || 'KES';
    const [form, setForm] = useState({
        client_id: client.id,
        product_id: '',
        product_price_id: '',
    });

    const selectedProduct = products?.find((p) => String(p.id) === String(form.product_id));
    const availablePrices = selectedProduct?.active_prices || [];
    const selectedPrice = availablePrices.find((p) => String(p.id) === String(form.product_price_id));

    const handleProductChange = (e) => {
        const productId = e.target.value;
        const product = products?.find((p) => String(p.id) === String(productId));
        const prices = product?.active_prices || [];
        setForm({
            ...form,
            product_id: productId,
            product_price_id: prices.length === 1 ? String(prices[0].id) : '',
        });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        onSubmit(form);
    };

    const canSubmit = form.product_id && form.product_price_id && !isPending;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Create New Subscription</h3>
                        <p className="crm-panel-subtitle">{client.name}</p>
                    </div>
                </header>

                <form onSubmit={handleSubmit} className="space-y-4 p-4">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Package</label>
                        <select
                            value={form.product_id}
                            onChange={handleProductChange}
                            required
                            className="crm-select w-full"
                        >
                            <option value="">Select a package...</option>
                            {products?.map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.display_name || product.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {form.product_id && availablePrices.length > 0 ? (
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Duration &amp; Price</label>
                            <select
                                value={form.product_price_id}
                                onChange={(e) => setForm({ ...form, product_price_id: e.target.value })}
                                required
                                className="crm-select w-full"
                            >
                                <option value="">Select a duration...</option>
                                {availablePrices.map((price) => (
                                    <option key={price.id} value={price.id}>
                                        {price.duration_label} — {formatCurrency(price.price, price.currency || platformCurrency)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ) : form.product_id ? (
                        <p className="text-sm text-amber-600">No active pricing options for this package.</p>
                    ) : null}

                    {selectedPrice ? (
                        <div className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <span className="font-medium">{selectedProduct?.display_name || selectedProduct?.name}</span>
                            {' · '}
                            {selectedPrice.duration_label}
                            {' · '}
                            <span className="font-semibold text-slate-900">{formatCurrency(selectedPrice.price, selectedPrice.currency || platformCurrency)}</span>
                            {selectedPrice.duration_days ? <span className="text-slate-400"> ({selectedPrice.duration_days} days)</span> : null}
                        </div>
                    ) : null}

                    {error ? <p className="text-sm text-rose-700">Failed to create subscription. {error.response?.data?.message || 'Please try again.'}</p> : null}

                    <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-3">
                        <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                        <button type="submit" disabled={!canSubmit} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                            {isPending ? 'Creating...' : 'Create subscription'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function PaymentLinkModal({
    client,
    products,
    deals,
    providerOptions,
    defaultProvider,
    canOverridePaymentLinkProvider,
    result,
    isPending,
    onClose,
    onCopyLink,
    onSendQuickSubscribe,
    onSendExistingDeal,
}) {
    const platformCurrency = client?.platform?.currency_code || 'KES';
    const [quickForm, setQuickForm] = useState({
        product_id: '',
        product_price_id: '',
        payment_link_provider: defaultProvider || '',
    });
    const [existingProvider, setExistingProvider] = useState(defaultProvider || '');

    useEffect(() => {
        if (!quickForm.payment_link_provider && defaultProvider) {
            setQuickForm((current) => ({
                ...current,
                payment_link_provider: defaultProvider,
            }));
        }

        if (!existingProvider && defaultProvider) {
            setExistingProvider(defaultProvider);
        }
    }, [defaultProvider, existingProvider, quickForm.payment_link_provider]);

    const selectedProduct = products?.find((product) => String(product.id) === String(quickForm.product_id));
    const availablePrices = selectedProduct?.active_prices || [];
    const selectedPrice = availablePrices.find((price) => String(price.id) === String(quickForm.product_price_id));
    const smsStatus = result?.sms_result?.status || '';
    const smsFailed = result?.sms_result?.success === false;
    const smsDisabled = smsStatus === 'disabled';
    const smsStatusClasses = smsFailed
        ? 'bg-rose-50 text-rose-700 ring-rose-200'
        : smsDisabled
            ? 'bg-amber-50 text-amber-700 ring-amber-200'
            : 'bg-emerald-50 text-emerald-700 ring-emerald-200';

    const handleQuickProductChange = (event) => {
        const productId = event.target.value;
        const product = products?.find((entry) => String(entry.id) === String(productId));
        const prices = product?.active_prices || [];

        setQuickForm((current) => ({
            ...current,
            product_id: productId,
            product_price_id: prices.length === 1 ? String(prices[0].id) : '',
        }));
    };

    const handleQuickSubmit = (event) => {
        event.preventDefault();

        if (!quickForm.product_id || !quickForm.product_price_id) {
            return;
        }

        onSendQuickSubscribe({
            mode: 'quick_subscribe',
            product_id: Number(quickForm.product_id),
            product_price_id: Number(quickForm.product_price_id),
            ...(canOverridePaymentLinkProvider && quickForm.payment_link_provider ? { payment_link_provider: quickForm.payment_link_provider } : {}),
            reason: 'Create and send payment link from client profile',
        });
    };

    const handleExistingDealSend = (dealId) => {
        if (canOverridePaymentLinkProvider && !existingProvider) {
            return;
        }

        onSendExistingDeal({
            mode: 'existing_deal',
            deal_id: Number(dealId),
            ...(canOverridePaymentLinkProvider && existingProvider ? { payment_link_provider: existingProvider } : {}),
            reason: 'Resend payment link from client profile',
        });
    };

    const canSendQuick = Boolean(
        quickForm.product_id
        && quickForm.product_price_id
        && (!canOverridePaymentLinkProvider || quickForm.payment_link_provider)
        && !isPending
    );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-3xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Payment Link</h3>
                        <p className="crm-panel-subtitle">{client.name} • {client.platform?.name || 'Market'}</p>
                    </div>
                </header>

                <div className="max-h-[80vh] space-y-5 overflow-y-auto p-4">
                    {result ? (
                        <section className="space-y-4 rounded-lg border border-teal-200 bg-teal-50/70 p-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-teal-700">Latest link</p>
                                    <h4 className="mt-1 text-lg font-semibold text-slate-900">Ready to share</h4>
                                    <p className="mt-1 text-sm text-slate-600">{result.message}</p>
                                </div>
                                <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${smsStatusClasses}`}>
                                    {smsFailed ? 'SMS failed' : smsDisabled ? 'SMS disabled' : 'SMS sent'}
                                </span>
                            </div>

                            <div className="space-y-2">
                                <label className="block text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Payment URL</label>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <input
                                        type="text"
                                        readOnly
                                        value={result.payment_url || ''}
                                        className="crm-input crm-mono flex-1 text-xs"
                                    />
                                    <button type="button" onClick={onCopyLink} className="crm-btn-primary whitespace-nowrap">
                                        Copy Link
                                    </button>
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-md border border-white/70 bg-white/80 px-3 py-2">
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Subscription</p>
                                    <p className="mt-1 text-sm font-medium text-slate-900">{result.deal?.product?.name || result.deal?.plan_type || 'Subscription'}</p>
                                </div>
                                <div className="rounded-md border border-white/70 bg-white/80 px-3 py-2">
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Amount</p>
                                    <p className="mt-1 text-sm font-medium text-slate-900">{formatCurrency(result.deal?.amount, result.deal?.currency || platformCurrency)}</p>
                                </div>
                                <div className="rounded-md border border-white/70 bg-white/80 px-3 py-2">
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Phone</p>
                                    <p className="mt-1 text-sm font-medium text-slate-900">{result.phone || 'Unavailable'}</p>
                                </div>
                            </div>

                            {smsFailed ? (
                                <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                    SMS failed but the link is ready. Copy and share it manually.
                                </p>
                            ) : null}
                        </section>
                    ) : null}

                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr),minmax(0,1.1fr)]">
                        <section className="rounded-lg border border-slate-200 p-4">
                            <div className="mb-4">
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Quick Subscribe</p>
                                <h4 className="mt-1 text-base font-semibold text-slate-900">Create a new pending subscription and send a link</h4>
                            </div>

                            <form className="space-y-4" onSubmit={handleQuickSubmit}>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Package</label>
                                    <select
                                        value={quickForm.product_id}
                                        onChange={handleQuickProductChange}
                                        className="crm-select w-full"
                                    >
                                        <option value="">Select a package...</option>
                                        {products?.map((product) => (
                                            <option key={product.id} value={product.id}>
                                                {product.display_name || product.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {quickForm.product_id && availablePrices.length > 0 ? (
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Duration &amp; Price</label>
                                        <select
                                            value={quickForm.product_price_id}
                                            onChange={(event) => setQuickForm((current) => ({ ...current, product_price_id: event.target.value }))}
                                            className="crm-select w-full"
                                        >
                                            <option value="">Select a duration...</option>
                                            {availablePrices.map((price) => (
                                                <option key={price.id} value={price.id}>
                                                    {price.duration_label} — {formatCurrency(price.price, price.currency || platformCurrency)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ) : quickForm.product_id ? (
                                    <p className="text-sm text-amber-600">No active pricing options are available for this package.</p>
                                ) : null}

                                {canOverridePaymentLinkProvider ? (
                                    <div>
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Link provider</label>
                                        <select
                                            value={quickForm.payment_link_provider}
                                            onChange={(event) => setQuickForm((current) => ({ ...current, payment_link_provider: event.target.value }))}
                                            className="crm-select w-full"
                                            disabled={!providerOptions.length}
                                        >
                                            <option value="">{providerOptions.length ? 'Choose link provider' : 'No enabled provider available'}</option>
                                            {providerOptions.map((provider) => (
                                                <option key={provider.key} value={provider.key}>
                                                    {provider.optionLabel}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ) : (
                                    <div className="space-y-2">
                                        <p className="mb-1 block text-sm font-medium text-slate-700">Link provider</p>
                                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                            {providerOptions.length
                                                ? 'Billing policy will use the market active provider when this link is sent.'
                                                : 'No enabled payment-link provider is configured for this market yet.'}
                                        </div>
                                    </div>
                                )}

                                {selectedPrice ? (
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                        <span className="font-medium">{selectedProduct?.display_name || selectedProduct?.name}</span>
                                        {' · '}
                                        {selectedPrice.duration_label}
                                        {' · '}
                                        <span className="font-semibold text-slate-900">{formatCurrency(selectedPrice.price, selectedPrice.currency || platformCurrency)}</span>
                                    </div>
                                ) : null}

                                <button type="submit" disabled={!canSendQuick} className="crm-btn-primary w-full disabled:cursor-not-allowed disabled:opacity-50">
                                    {isPending ? 'Preparing...' : 'Send & Get Link'}
                                </button>
                            </form>
                        </section>

                        <section className="rounded-lg border border-slate-200 p-4">
                            <div className="mb-4">
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Existing Deals</p>
                                <h4 className="mt-1 text-base font-semibold text-slate-900">Resend a link for pending checkout</h4>
                            </div>

                            {canOverridePaymentLinkProvider ? (
                                <div className="mb-4">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Link provider</label>
                                    <select
                                        value={existingProvider}
                                        onChange={(event) => setExistingProvider(event.target.value)}
                                        className="crm-select w-full"
                                        disabled={!providerOptions.length}
                                    >
                                        <option value="">{providerOptions.length ? 'Choose link provider' : 'No enabled provider available'}</option>
                                        {providerOptions.map((provider) => (
                                            <option key={provider.key} value={provider.key}>
                                                {provider.optionLabel}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            ) : (
                                <div className="mb-4 space-y-2">
                                    <p className="mb-1 block text-sm font-medium text-slate-700">Link provider</p>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                        {providerOptions.length
                                            ? 'Billing policy will use the market active provider when this link is resent.'
                                            : 'No enabled payment-link provider is configured for this market yet.'}
                                    </div>
                                </div>
                            )}

                            {deals.length ? (
                                <div className="space-y-3">
                                    {deals.map((deal) => (
                                        <div key={deal.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                <div className="space-y-1">
                                                    <p className="text-sm font-semibold text-slate-900">{deal.product?.name || deal.plan_type || 'Subscription'}</p>
                                                    <p className="text-xs text-slate-500">
                                                        {deal.status === 'awaiting_payment' ? 'Awaiting payment' : 'Pending activation'}
                                                        {' · '}
                                                        {formatCurrency(deal.amount, deal.currency || platformCurrency)}
                                                    </p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => handleExistingDealSend(deal.id)}
                                                    disabled={isPending || (canOverridePaymentLinkProvider && !existingProvider)}
                                                    className="crm-btn-secondary whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    {isPending ? 'Preparing...' : 'Resend Link'}
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                                    No pending or awaiting-payment deals are available for link resend.
                                </div>
                            )}
                        </section>
                    </div>
                </div>

                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 px-4 py-3">
                    <button type="button" onClick={onClose} className="crm-btn-secondary" disabled={isPending}>
                        Close
                    </button>
                </footer>
            </div>
        </div>
    );
}
