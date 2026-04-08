import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

const DETAIL_FIELD_META = {
    network: {
        label: 'Network',
        placeholder: 'MTN, Airtel, Vodafone',
    },
    phone_number: {
        label: 'Phone number',
        placeholder: '+233541442622',
    },
    recipient_name: {
        label: 'Recipient name',
        placeholder: 'Julian Papa',
    },
    collector_label: {
        label: 'Collector label',
        placeholder: 'Collector X or sales alias',
    },
    provider_name: {
        label: 'Provider name',
        placeholder: 'M-PESA Paybill / Airtel Money',
    },
    business_number: {
        label: 'Business / paybill number',
        placeholder: '400200',
    },
    account_reference_hint: {
        label: 'Reference hint',
        placeholder: 'Tell customers what to enter as account/reference',
    },
    bank_name: {
        label: 'Bank name',
        placeholder: 'United Bank For Africa PLC (UBA PLC)',
    },
    account_number: {
        label: 'Account number',
        placeholder: '2110085665',
    },
    account_name: {
        label: 'Account name',
        placeholder: 'Julian Papa',
    },
    branch: {
        label: 'Branch',
        placeholder: 'Optional branch name',
    },
};

function firstErrorMessage(error) {
    const validation = error?.response?.data?.errors;
    if (validation && typeof validation === 'object') {
        const first = Object.values(validation).flat()[0];
        if (first) {
            return String(first);
        }
    }

    return error?.response?.data?.message || 'CRM could not save manual payment settings.';
}

function normalizeMethods(input = []) {
    return input.map((method) => ({
        id: method?.id ?? null,
        market_id: method?.market_id ?? null,
        method_key: String(method?.method_key || '').trim().toLowerCase(),
        enabled: Boolean(method?.enabled),
        display_name: method?.display_name || '',
        instruction_intro: method?.instruction_intro || '',
        instruction_footer: method?.instruction_footer || '',
        proof_required: method?.proof_required !== false,
        sender_name_required: method?.sender_name_required !== false,
        transaction_id_required: method?.transaction_id_required !== false,
        auto_activate_on_submission: Boolean(method?.auto_activate_on_submission),
        details: typeof method?.details === 'object' && method.details !== null ? { ...method.details } : {},
    }));
}

function buildPayload(methods) {
    return {
        methods: methods.map((method) => ({
            method_key: method.method_key,
            enabled: Boolean(method.enabled),
            display_name: method.display_name,
            instruction_intro: method.instruction_intro,
            instruction_footer: method.instruction_footer,
            proof_required: Boolean(method.proof_required),
            sender_name_required: Boolean(method.sender_name_required),
            transaction_id_required: Boolean(method.transaction_id_required),
            auto_activate_on_submission: Boolean(method.auto_activate_on_submission),
            details: method.details || {},
        })),
    };
}

export default function ManualPaymentsTab({ platforms = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedMarket, setSelectedMarket] = useState(null);
    const [formMethods, setFormMethods] = useState(null);

    const marketId = selectedMarket?.id;

    const methodsQuery = useQuery({
        queryKey: ['billing-manual-payment-methods', marketId],
        queryFn: () =>
            api.get(`/crm/settings/billing/manual-payment-methods/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 10 * 60 * 1000,
    });

    const saveMutation = useMutation({
        mutationFn: (payload) =>
            api.put(`/crm/settings/billing/manual-payment-methods/${marketId}`, payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['billing-manual-payment-methods', marketId] });
            setFormMethods(normalizeMethods(result?.manual_methods || []));
            toast.success('Manual payment methods updated.');
        },
        onError: (error) => {
            toast.error(firstErrorMessage(error), {
                title: 'Manual payment save failed',
            });
        },
    });

    useEffect(() => {
        if (!methodsQuery.data?.manual_methods) {
            setFormMethods(null);
            return;
        }

        setFormMethods(normalizeMethods(methodsQuery.data.manual_methods));
    }, [methodsQuery.data]);

    const supportedMethods = methodsQuery.data?.supported_methods || [];
    const editable = Boolean(methodsQuery.data?.editable);
    const market = methodsQuery.data?.market || selectedMarket;

    const enabledCount = useMemo(
        () => (formMethods || []).filter((method) => method.enabled).length,
        [formMethods],
    );

    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Manual Payments"
                    title="No markets available"
                    message="Create or enable markets before configuring collector, paybill, or bank instructions."
                />
            </div>
        );
    }

    if (!selectedMarket) {
        return (
            <div className="space-y-5 p-5">
                <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-950/[0.02]">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Manual Payments</p>
                    <h4 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                        Choose a market to configure off-platform payment instructions
                    </h4>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Publish the exact collector, paybill, and bank details customers should use when hosted checkout
                        is unavailable or secondary in a market. These instructions power the public checkout and the
                        CRM review queue.
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
                            {platform.country ? <p className="mt-3 text-sm text-slate-600">{platform.country}</p> : null}
                            <p className="mt-5 text-sm font-medium text-slate-900">Open manual payment workspace</p>
                        </button>
                    ))}
                </div>
            </div>
        );
    }

    if (methodsQuery.isLoading || !formMethods) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-28 rounded-xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-72 rounded-xl border border-slate-200 bg-white" />
                    <div className="h-72 rounded-xl border border-slate-200 bg-white" />
                </div>
            </div>
        );
    }

    if (methodsQuery.isError) {
        if (isForbiddenQueryError(methodsQuery.error)) {
            return (
                <div className="space-y-4 p-5">
                    <BillingStateNotice
                        state="forbidden"
                        eyebrow="Manual Payments"
                        title="Manual payment access is restricted"
                        message="This role can’t review or configure manual payment instructions for the selected market."
                    />
                    <BackButton onClick={() => setSelectedMarket(null)} />
                </div>
            );
        }

        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="degraded"
                    eyebrow="Manual Payments"
                    title="Manual payment settings unavailable"
                    message="CRM could not load manual payment instructions for this market. Refresh and retry."
                />
                <BackButton onClick={() => setSelectedMarket(null)} />
            </div>
        );
    }

    const updateMethod = (methodKey, updater) => {
        setFormMethods((current) => current.map((method) => (
            method.method_key === methodKey ? updater(method) : method
        )));
    };

    return (
        <div className="space-y-5 p-5">
            <section className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-950/[0.02]">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div className="space-y-3">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Manual Payment Workspace</p>
                        <h4 className="text-2xl font-semibold tracking-tight text-slate-950">
                            {market?.name || selectedMarket?.name} manual payment methods
                        </h4>
                        <p className="max-w-3xl text-sm leading-6 text-slate-600">
                            Configure the exact destination details customers see during checkout, the evidence fields
                            they must submit, and whether this market can go live before a sales agent verifies the
                            proof.
                        </p>
                    </div>
                    <div className="flex flex-col items-stretch gap-3 xl:min-w-[280px]">
                        <button
                            type="button"
                            onClick={() => saveMutation.mutate(buildPayload(formMethods))}
                            disabled={!editable || saveMutation.isPending}
                            className="crm-btn-primary justify-center px-4 py-3 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {saveMutation.isPending ? 'Saving methods…' : 'Save manual payment methods'}
                        </button>
                        <BackButton onClick={() => setSelectedMarket(null)} />
                    </div>
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-3">
                <MetricCard
                    label="Methods enabled"
                    value={enabledCount}
                    hint="Visible in public checkout for this market."
                />
                <MetricCard
                    label="Proof posture"
                    value="Required"
                    hint="Screenshot proof, sender name, and transaction ID stay required in v1."
                />
                <MetricCard
                    label="Auto-activation"
                    value={formMethods.some((method) => method.auto_activate_on_submission) ? 'Active' : 'Deferred'}
                    hint="Benefit-of-doubt activation can be enabled per method."
                />
            </section>

            {!editable ? (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Read-only access"
                    title="You can review these instructions but not change them"
                    message="Only admin users can update manual payment instructions, evidence rules, or activation posture."
                />
            ) : null}

            <div className="space-y-4">
                {supportedMethods.map((supportedMethod) => {
                    const method = formMethods.find((item) => item.method_key === supportedMethod.key);

                    if (!method) {
                        return null;
                    }

                    return (
                        <section
                            key={method.method_key}
                            className={`rounded-xl border bg-white p-5 shadow-sm shadow-slate-950/[0.02] ${
                                method.enabled ? 'border-slate-200' : 'border-slate-200/80'
                            }`}
                        >
                            <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                            {supportedMethod.label}
                                        </span>
                                        <span
                                            className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] ${
                                                method.enabled
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                    : 'border-slate-200 bg-slate-50 text-slate-500'
                                            }`}
                                        >
                                            {method.enabled ? 'Enabled' : 'Disabled'}
                                        </span>
                                        {method.auto_activate_on_submission ? (
                                            <span className="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-teal-700">
                                                Auto-activate on submit
                                            </span>
                                        ) : null}
                                    </div>
                                    <h5 className="text-lg font-semibold text-slate-950">{method.display_name || supportedMethod.label}</h5>
                                    <p className="max-w-3xl text-sm leading-6 text-slate-600">
                                        Publish the destination details customers should copy exactly, then define the
                                        evidence fields agents expect during review.
                                    </p>
                                </div>

                                <TogglePill
                                    checked={method.enabled}
                                    disabled={!editable}
                                    label={method.enabled ? 'Method on' : 'Method off'}
                                    onChange={(value) => updateMethod(method.method_key, (current) => ({
                                        ...current,
                                        enabled: value,
                                    }))}
                                />
                            </div>

                            <div className="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(300px,0.9fr)]">
                                <div className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <TextField
                                            label="Display name"
                                            value={method.display_name}
                                            disabled={!editable}
                                            placeholder={supportedMethod.label}
                                            onChange={(value) => updateMethod(method.method_key, (current) => ({
                                                ...current,
                                                display_name: value,
                                            }))}
                                        />

                                        {supportedMethod.detail_fields.map((fieldKey) => {
                                            const meta = DETAIL_FIELD_META[fieldKey] || {
                                                label: fieldKey.replaceAll('_', ' '),
                                                placeholder: '',
                                            };

                                            return (
                                                <TextField
                                                    key={fieldKey}
                                                    label={meta.label}
                                                    value={method.details?.[fieldKey] || ''}
                                                    disabled={!editable}
                                                    placeholder={meta.placeholder}
                                                    onChange={(value) => updateMethod(method.method_key, (current) => ({
                                                        ...current,
                                                        details: {
                                                            ...(current.details || {}),
                                                            [fieldKey]: value,
                                                        },
                                                    }))}
                                                />
                                            );
                                        })}
                                    </div>

                                    <div className="grid gap-4 xl:grid-cols-2">
                                        <TextAreaField
                                            label="Instruction intro"
                                            value={method.instruction_intro}
                                            disabled={!editable}
                                            rows={4}
                                            placeholder="Make payment and send the payment screenshot with the sender's name and transaction ID."
                                            onChange={(value) => updateMethod(method.method_key, (current) => ({
                                                ...current,
                                                instruction_intro: value,
                                            }))}
                                        />
                                        <TextAreaField
                                            label="Instruction footer"
                                            value={method.instruction_footer}
                                            disabled={!editable}
                                            rows={4}
                                            placeholder="Optional reassurance or support note shown after the instructions."
                                            onChange={(value) => updateMethod(method.method_key, (current) => ({
                                                ...current,
                                                instruction_footer: value,
                                            }))}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-4">
                                    <PreviewCard method={method} />

                                    <div className="grid gap-3">
                                        <ToggleRow
                                            label="Require screenshot proof"
                                            description="Locked on in v1 so every submission includes a screenshot for review."
                                            checked
                                            disabled
                                            onChange={() => {}}
                                        />
                                        <ToggleRow
                                            label="Require sender name"
                                            description="Locked on in v1 so agents can verify the payer details quickly."
                                            checked
                                            disabled
                                            onChange={() => {}}
                                        />
                                        <ToggleRow
                                            label="Require transaction ID"
                                            description="Locked on in v1 so every submission includes a transfer reference."
                                            checked
                                            disabled
                                            onChange={() => {}}
                                        />
                                        <ToggleRow
                                            label="Auto-activate on submission"
                                            description="Provision immediately, then leave the payment in verification pending until reviewed."
                                            checked={Boolean(method.auto_activate_on_submission)}
                                            disabled={!editable}
                                            onChange={(value) => updateMethod(method.method_key, (current) => ({
                                                ...current,
                                                auto_activate_on_submission: value,
                                            }))}
                                        />
                                    </div>
                                </div>
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function MetricCard({ label, value, hint }) {
    return (
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm shadow-slate-950/[0.02]">
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-2 text-[1.8rem] font-semibold tracking-tight text-slate-950">{value}</p>
            <p className="mt-2 text-sm leading-6 text-slate-600">{hint}</p>
        </section>
    );
}

function PreviewCard({ method }) {
    const detailEntries = Object.entries(method.details || {}).filter(([, value]) => String(value || '').trim() !== '');

    return (
        <section className="rounded-xl border border-slate-200 bg-slate-50/70 p-4">
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Customer preview</p>
            <h6 className="mt-2 text-base font-semibold text-slate-950">{method.display_name || 'Payment method name'}</h6>
            {detailEntries.length > 0 ? (
                <div className="mt-3 space-y-2">
                    {detailEntries.map(([fieldKey, value]) => (
                        <div key={fieldKey} className="rounded-lg border border-slate-200 bg-white px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">
                                {DETAIL_FIELD_META[fieldKey]?.label || fieldKey.replaceAll('_', ' ')}
                            </p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">{value}</p>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="mt-3 text-sm text-slate-500">Add the destination details to see how this method will read in checkout.</p>
            )}

            {method.instruction_intro ? (
                <p className="mt-4 text-sm leading-6 text-slate-700">{method.instruction_intro}</p>
            ) : null}
            {method.instruction_footer ? (
                <p className="mt-3 text-xs leading-6 text-slate-500">{method.instruction_footer}</p>
            ) : null}
        </section>
    );
}

function TogglePill({ checked, disabled, label, onChange }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`inline-flex h-12 items-center gap-3 rounded-full border px-4 text-sm font-semibold transition ${
                checked
                    ? 'border-slate-900 bg-slate-950 text-white'
                    : 'border-slate-300 bg-white text-slate-700'
            } disabled:cursor-not-allowed disabled:opacity-60`}
        >
            <span
                className={`relative inline-flex h-6 w-11 shrink-0 items-center rounded-full ${
                    checked ? 'bg-white/20' : 'bg-slate-300'
                }`}
            >
                <span
                    className={`inline-block h-5 w-5 transform rounded-full bg-white transition ${
                        checked ? 'translate-x-5' : 'translate-x-0.5'
                    }`}
                />
            </span>
            {label}
        </button>
    );
}

function ToggleRow({ label, description, checked, disabled, onChange }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white px-4 py-4">
            <div className="flex items-start justify-between gap-4">
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

function TextField({ label, value, disabled, placeholder, onChange }) {
    return (
        <label className="block">
            <span className="text-sm font-semibold text-slate-900">{label}</span>
            <input
                type="text"
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
                className="crm-input mt-2"
            />
        </label>
    );
}

function TextAreaField({ label, value, disabled, placeholder, rows = 3, onChange }) {
    return (
        <label className="block">
            <span className="text-sm font-semibold text-slate-900">{label}</span>
            <textarea
                rows={rows}
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
                className="crm-input mt-2"
            />
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
