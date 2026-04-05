import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const ACTIVATION_OPTIONS = [
    { key: 'manual', label: 'Manual activation', hint: 'Operators can provision subscriptions directly from the CRM.' },
    { key: 'payment_link', label: 'Payment link', hint: 'Customers can activate through hosted payment links.' },
    { key: 'stk_push', label: 'STK push', hint: 'Customers can start activation using mobile-money prompts.' },
    { key: 'wallet_balance', label: 'Wallet balance', hint: 'Customers can activate using funded wallet balance.' },
];

const RENEWAL_OPTIONS = [
    { key: 'wallet_balance', label: 'Wallet balance', hint: 'Use stored wallet balance during renewals.' },
    { key: 'payment_link', label: 'Payment link', hint: 'Send renewal links when wallet charging is not available.' },
    { key: 'manual', label: 'Manual recovery', hint: 'Allow operators to intervene with manual renewal handling.' },
];

function normalizeMethods(input, mapLegacyKey) {
    if (Array.isArray(input)) {
        return input
            .map((value) => mapLegacyKey(value))
            .filter(Boolean)
            .filter((value, index, array) => array.indexOf(value) === index);
    }

    if (input && Array.isArray(input.methods)) {
        return input.methods
            .map((value) => mapLegacyKey(value))
            .filter(Boolean)
            .filter((value, index, array) => array.indexOf(value) === index);
    }

    if (input && typeof input === 'object') {
        return Object.entries(input)
            .filter(([, value]) => value === true || (value && typeof value === 'object' && value.enabled === true))
            .map(([key]) => mapLegacyKey(key))
            .filter(Boolean)
            .filter((value, index, array) => array.indexOf(value) === index);
    }

    return [];
}

function normalizeActivationMethod(key) {
    const normalized = String(key || '').trim().toLowerCase();

    const map = {
        link: 'payment_link',
        payment_link: 'payment_link',
        stk: 'stk_push',
        stk_push: 'stk_push',
        wallet: 'wallet_balance',
        wallet_balance: 'wallet_balance',
        manual: 'manual',
    };

    return map[normalized] || null;
}

function normalizeRenewalMethod(key) {
    const normalized = String(key || '').trim().toLowerCase();

    const map = {
        link: 'payment_link',
        payment_link: 'payment_link',
        wallet: 'wallet_balance',
        wallet_balance: 'wallet_balance',
        manual: 'manual',
    };

    return map[normalized] || null;
}

function normalizeRule(rule) {
    return {
        activationMethods: normalizeMethods(rule?.activation_method_json, normalizeActivationMethod),
        renewalMethods: normalizeMethods(rule?.renewal_method_json, normalizeRenewalMethod),
        walletAutoRenew:
            Boolean(rule?.renewal_method_json?.wallet_auto_renew) ||
            Boolean(rule?.renewal_method_json?.wallet?.enabled),
        freeTrialEnabled: Boolean(rule?.free_trial_json?.enabled),
        freeTrialDays:
            rule?.free_trial_json?.duration_days ??
            rule?.free_trial_json?.days ??
            '',
        discountEnabled: Boolean(rule?.discount_json?.enabled),
        discountPercent:
            rule?.discount_json?.max_percent ??
            rule?.discount_json?.percent ??
            '',
        discountRequiresPin:
            Boolean(rule?.discount_json?.requires_pin) ||
            Boolean(rule?.discount_json?.pin_required),
        gracePeriodDays:
            rule?.expiry_policy_json?.grace_period_days ??
            rule?.expiry_policy_json?.grace_days ??
            '',
        suspendAfterDays:
            rule?.expiry_policy_json?.suspend_after_days ??
            rule?.expiry_policy_json?.cleanup_after_days ??
            '',
    };
}

function buildPayload(form) {
    return {
        activation_method_json: {
            methods: form.activationMethods,
        },
        renewal_method_json: {
            methods: form.renewalMethods,
            wallet_auto_renew: Boolean(form.walletAutoRenew),
        },
        free_trial_json: {
            enabled: Boolean(form.freeTrialEnabled),
            duration_days: form.freeTrialDays === '' ? null : Number(form.freeTrialDays),
        },
        discount_json: {
            enabled: Boolean(form.discountEnabled),
            max_percent: form.discountPercent === '' ? null : Number(form.discountPercent),
            requires_pin: Boolean(form.discountRequiresPin),
        },
        expiry_policy_json: {
            grace_period_days: form.gracePeriodDays === '' ? null : Number(form.gracePeriodDays),
            suspend_after_days: form.suspendAfterDays === '' ? null : Number(form.suspendAfterDays),
        },
    };
}

function firstErrorMessage(error) {
    const validation = error?.response?.data?.errors;
    if (validation && typeof validation === 'object') {
        const first = Object.values(validation).flat()[0];
        if (first) {
            return String(first);
        }
    }

    return error?.response?.data?.message || 'CRM could not save subscription rules.';
}

export default function SubscriptionRulesTab({ platforms = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedMarket, setSelectedMarket] = useState(null);
    const [form, setForm] = useState(null);

    const marketId = selectedMarket?.id;

    const subscriptionRulesQuery = useQuery({
        queryKey: ['billing-subscription-rules', marketId],
        queryFn: () =>
            api.get(`/crm/settings/billing/subscription-rules/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000,
    });

    const saveMutation = useMutation({
        mutationFn: (payload) =>
            api.put(`/crm/settings/billing/subscription-rules/${marketId}`, payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['billing-subscription-rules', marketId] });
            toast.success('Subscription policy updated.');
        },
        onError: (error) => {
            toast.error(firstErrorMessage(error), {
                title: 'Subscription policy save failed',
            });
        },
    });

    const data = subscriptionRulesQuery.data || {};
    const subscriptionRule = data.subscription_rule || null;
    const editable = Boolean(data.editable);
    const market = data.market || selectedMarket;

    useEffect(() => {
        if (!subscriptionRule) {
            setForm(null);
            return;
        }

        setForm(normalizeRule(subscriptionRule));
    }, [subscriptionRule]);

    const selectedActivationMethods = useMemo(() => new Set(form?.activationMethods || []), [form?.activationMethods]);
    const selectedRenewalMethods = useMemo(() => new Set(form?.renewalMethods || []), [form?.renewalMethods]);

    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Subscription Rules"
                    title="No markets available"
                    message="Create or enable markets before configuring subscription activation and renewal policy."
                />
            </div>
        );
    }

    if (!selectedMarket) {
        return (
            <div className="space-y-5 p-5">
                <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-950/[0.02]">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Subscription Rules</p>
                    <h4 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                        Choose a market to author subscription policy
                    </h4>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Control how subscriptions are activated, renewed, discounted, and expired for each market.
                        This is the policy layer operators rely on before routing and diagnostics decisions kick in.
                    </p>
                </section>

                <div className="grid gap-4 xl:grid-cols-3">
                    {platforms.map((platform) => (
                        <button
                            key={platform.id}
                            type="button"
                            onClick={() => setSelectedMarket(platform)}
                            className="rounded-xl border border-slate-200 bg-white p-5 text-left shadow-sm shadow-slate-950/[0.02] transition hover:border-slate-300 hover:shadow-md hover:shadow-slate-950/[0.04]"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Market</p>
                                    <h5 className="mt-2 text-lg font-semibold text-slate-950">{platform.name}</h5>
                                </div>
                                <div className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                            </div>
                            {platform.country ? (
                                <p className="mt-3 text-sm text-slate-600">{platform.country}</p>
                            ) : null}
                            <p className="mt-5 text-sm font-medium text-slate-900">Open policy workspace</p>
                        </button>
                    ))}
                </div>
            </div>
        );
    }

    if (subscriptionRulesQuery.isLoading || !form) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-28 rounded-xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-64 rounded-xl border border-slate-200 bg-white" />
                </div>
            </div>
        );
    }

    if (subscriptionRulesQuery.isError) {
        if (isForbiddenQueryError(subscriptionRulesQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Subscription Rules"
                        title="Subscription policy access is restricted"
                        message="This role cannot inspect or author subscription policy for the selected market."
                    />
                    <BackButton onClick={() => setSelectedMarket(null)} />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Subscription Rules"
                    title="Subscription rules unavailable"
                    message="CRM could not load subscription policy for this market. Refresh the page to retry."
                />
                <BackButton onClick={() => setSelectedMarket(null)} />
            </div>
        );
    }

    return (
        <div className="space-y-5 p-5">
            <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="space-y-3">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Subscription Policy</p>
                        <h4 className="text-2xl font-semibold tracking-tight text-slate-950">
                            {market?.name || selectedMarket?.name} subscription rules
                        </h4>
                        <p className="max-w-3xl text-sm leading-6 text-slate-600">
                            Define how this market activates subscriptions, recovers renewals, handles free trials,
                            and applies discount or expiry posture. These controls should read clearly to operators and
                            finance admins alike.
                        </p>
                    </div>
                    <div className="flex flex-col items-stretch gap-3 xl:min-w-[240px]">
                        <button
                            type="button"
                            onClick={() => saveMutation.mutate(buildPayload(form))}
                            disabled={!editable || saveMutation.isPending}
                            className="crm-btn-primary justify-center px-4 py-3 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveMutation.isPending ? 'Saving policy…' : 'Save subscription rules'}
                        </button>
                        <BackButton onClick={() => setSelectedMarket(null)} />
                    </div>
                </div>
            </section>

            {!editable ? (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Read-only access"
                    title="You can review policy but not change it"
                    message="Only admin users can update subscription activation, renewal, and expiry rules."
                />
            ) : null}

            <div className="grid gap-4 xl:grid-cols-2">
                <PolicyPanel
                    eyebrow="Activation"
                    title="How customers start a subscription"
                    description="Choose the customer entry points that should stay available in this market."
                >
                    <OptionGrid
                        options={ACTIVATION_OPTIONS}
                        selected={selectedActivationMethods}
                        disabled={!editable}
                        onToggle={(key) =>
                            setForm((current) => ({
                                ...current,
                                activationMethods: toggleItem(current.activationMethods, key),
                            }))
                        }
                    />
                </PolicyPanel>

                <PolicyPanel
                    eyebrow="Renewal"
                    title="How subscriptions recover and renew"
                    description="Define the fallback posture when customers reach expiry or a balance top-up is required."
                >
                    <OptionGrid
                        options={RENEWAL_OPTIONS}
                        selected={selectedRenewalMethods}
                        disabled={!editable}
                        onToggle={(key) =>
                            setForm((current) => ({
                                ...current,
                                renewalMethods: toggleItem(current.renewalMethods, key),
                            }))
                        }
                    />
                    <ToggleRow
                        className="mt-4"
                        label="Wallet auto-renew"
                        description="Attempt renewal from wallet balance before falling back to manual or link recovery."
                        checked={Boolean(form.walletAutoRenew)}
                        disabled={!editable}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                walletAutoRenew: value,
                            }))
                        }
                    />
                </PolicyPanel>

                <PolicyPanel
                    eyebrow="Free Trial"
                    title="Introductory access posture"
                    description="Control whether this market offers free trial access and for how long."
                >
                    <ToggleRow
                        label="Enable free trial"
                        description="Allow customers in this market to start with a free trial period."
                        checked={Boolean(form.freeTrialEnabled)}
                        disabled={!editable}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                freeTrialEnabled: value,
                            }))
                        }
                    />
                    <NumberField
                        className="mt-4"
                        label="Trial duration"
                        suffix="days"
                        value={form.freeTrialDays}
                        disabled={!editable || !form.freeTrialEnabled}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                freeTrialDays: value,
                            }))
                        }
                    />
                </PolicyPanel>

                <PolicyPanel
                    eyebrow="Discounts"
                    title="Discount control and approval"
                    description="Define discount guardrails so operators know how far they can go without violating policy."
                >
                    <ToggleRow
                        label="Enable discounts"
                        description="Allow discounted subscription offers for this market."
                        checked={Boolean(form.discountEnabled)}
                        disabled={!editable}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                discountEnabled: value,
                            }))
                        }
                    />
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <NumberField
                            label="Maximum discount"
                            suffix="%"
                            value={form.discountPercent}
                            disabled={!editable || !form.discountEnabled}
                            onChange={(value) =>
                                setForm((current) => ({
                                    ...current,
                                    discountPercent: value,
                                }))
                            }
                        />
                        <ToggleRow
                            compact
                            label="Require PIN approval"
                            description="Enforce PIN approval for discounted subscription actions."
                            checked={Boolean(form.discountRequiresPin)}
                            disabled={!editable || !form.discountEnabled}
                            onChange={(value) =>
                                setForm((current) => ({
                                    ...current,
                                    discountRequiresPin: value,
                                }))
                            }
                        />
                    </div>
                </PolicyPanel>
            </div>

            <PolicyPanel
                eyebrow="Expiry posture"
                title="Grace period and suspension behavior"
                description="Keep operators aligned on what happens after expiry, and when a suspended account should stop waiting for recovery."
            >
                <div className="grid gap-4 md:grid-cols-2">
                    <NumberField
                        label="Grace period"
                        suffix="days"
                        value={form.gracePeriodDays}
                        disabled={!editable}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                gracePeriodDays: value,
                            }))
                        }
                    />
                    <NumberField
                        label="Suspend after"
                        suffix="days"
                        value={form.suspendAfterDays}
                        disabled={!editable}
                        onChange={(value) =>
                            setForm((current) => ({
                                ...current,
                                suspendAfterDays: value,
                            }))
                        }
                    />
                </div>
            </PolicyPanel>
        </div>
    );
}

function PolicyPanel({ eyebrow, title, description, children }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.02]">
            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">{eyebrow}</p>
            <h5 className="mt-2 text-lg font-semibold text-slate-950">{title}</h5>
            <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
            <div className="mt-5">{children}</div>
        </section>
    );
}

function OptionGrid({ options, selected, disabled, onToggle }) {
    return (
        <div className="grid gap-3 md:grid-cols-2">
            {options.map((option) => {
                const active = selected.has(option.key);

                return (
                    <button
                        key={option.key}
                        type="button"
                        disabled={disabled}
                        onClick={() => onToggle(option.key)}
                        className={`rounded-xl border px-4 py-4 text-left transition ${
                            active
                                ? 'border-slate-900 bg-slate-950 text-white shadow-lg shadow-slate-950/[0.08]'
                                : 'border-slate-200 bg-white text-slate-900 hover:border-slate-300'
                        } disabled:cursor-not-allowed disabled:opacity-60`}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <span className={`text-sm font-semibold ${active ? 'text-white' : 'text-slate-900'}`}>{option.label}</span>
                            <span
                                className={`h-2.5 w-2.5 rounded-full ${
                                    active ? 'bg-emerald-300' : 'bg-slate-300'
                                }`}
                            />
                        </div>
                        <p className={`mt-2 text-sm leading-6 ${active ? 'text-slate-100' : 'text-slate-600'}`}>{option.hint}</p>
                    </button>
                );
            })}
        </div>
    );
}

function ToggleRow({ label, description, checked, disabled, onChange, compact = false, className = '' }) {
    return (
        <div className={`rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-4 ${className}`}>
            <div className={`flex ${compact ? 'items-start' : 'items-center'} justify-between gap-4`}>
                <div>
                    <p className="text-sm font-semibold text-slate-900">{label}</p>
                    <p className="mt-1 text-sm leading-6 text-slate-600">{description}</p>
                </div>
                <button
                    type="button"
                    role="switch"
                    aria-checked={checked}
                    disabled={disabled}
                    onClick={() => onChange(!checked)}
                    className={`relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition ${
                        checked ? 'bg-slate-950' : 'bg-slate-300'
                    } disabled:cursor-not-allowed disabled:opacity-50`}
                >
                    <span
                        className={`inline-block h-5 w-5 transform rounded-full bg-white transition ${
                            checked ? 'translate-x-6' : 'translate-x-1'
                        }`}
                    />
                </button>
            </div>
        </div>
    );
}

function NumberField({ label, suffix, value, disabled, onChange, className = '' }) {
    return (
        <label className={`block ${className}`}>
            <span className="text-sm font-semibold text-slate-900">{label}</span>
            <div className="mt-2 flex items-center rounded-xl border border-slate-200 bg-white px-4">
                <input
                    type="number"
                    min="0"
                    value={value}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                    className="w-full bg-transparent py-3 text-sm text-slate-900 outline-none disabled:cursor-not-allowed disabled:text-slate-400"
                />
                <span className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{suffix}</span>
            </div>
        </label>
    );
}

function BackButton({ onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
        >
            Back to markets
        </button>
    );
}

function toggleItem(items, key) {
    const next = new Set(items || []);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    return Array.from(next);
}
