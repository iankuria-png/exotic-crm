import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import BillingStateNotice from './BillingStateNotice';
import { isForbiddenQueryError } from './queryState';

function makeDraft(walletRule, market) {
    return {
        enabled: Boolean(walletRule?.enabled),
        currency_code: walletRule?.currency_code || market?.currency_code || '',
        topup_presets_text: Array.isArray(walletRule?.topup_preset_json)
            ? walletRule.topup_preset_json.join(', ')
            : '',
        max_single_topup: walletRule?.limit_json?.max_single_topup || walletRule?.limit_json?.max_single || '',
        max_wallet_balance:
            walletRule?.limit_json?.max_wallet_balance || walletRule?.limit_json?.max_balance || '',
        auto_renew_enabled: Boolean(walletRule?.auto_renew_json?.enabled),
        allow_combined_topup_subscribe: Boolean(walletRule?.ui_json?.allow_combined_topup_subscribe),
        show_refresh_button: Boolean(walletRule?.ui_json?.show_refresh_button),
        recent_transactions_limit: walletRule?.ui_json?.recent_transactions_limit || '',
        wallet_funding_label: walletRule?.ui_json?.wallet_funding_label || '',
    };
}

function normalizeDraft(draft) {
    return {
        enabled: Boolean(draft.enabled),
        currency_code: String(draft.currency_code || '').trim().toUpperCase(),
        topup_presets_text: String(draft.topup_presets_text || '')
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean)
            .join('|'),
        max_single_topup: String(draft.max_single_topup || '').trim(),
        max_wallet_balance: String(draft.max_wallet_balance || '').trim(),
        auto_renew_enabled: Boolean(draft.auto_renew_enabled),
        allow_combined_topup_subscribe: Boolean(draft.allow_combined_topup_subscribe),
        show_refresh_button: Boolean(draft.show_refresh_button),
        recent_transactions_limit: String(draft.recent_transactions_limit || '').trim(),
        wallet_funding_label: String(draft.wallet_funding_label || '').trim(),
    };
}

export default function WalletRulesTab({ platforms = [] }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedMarket, setSelectedMarket] = useState(null);
    const [draft, setDraft] = useState(() => makeDraft(null, null));
    const [initialDraft, setInitialDraft] = useState(() => makeDraft(null, null));

    const marketId = selectedMarket?.id || null;

    const walletRulesQuery = useQuery({
        queryKey: ['billing-wallet-rules', marketId],
        queryFn: () => api.get(`/crm/settings/billing/wallet-rules/${marketId}`).then((response) => response.data),
        enabled: Boolean(marketId),
        staleTime: 60_000,
    });

    useEffect(() => {
        if (!walletRulesQuery.data) {
            return;
        }

        const nextDraft = makeDraft(walletRulesQuery.data.wallet_rule, walletRulesQuery.data.market);
        setDraft(nextDraft);
        setInitialDraft(nextDraft);
    }, [walletRulesQuery.data]);

    const saveMutation = useMutation({
        mutationFn: (payload) =>
            api.put(`/crm/settings/billing/wallet-rules/${marketId}`, payload).then((response) => response.data),
        onSuccess: (payload) => {
            queryClient.setQueryData(['billing-wallet-rules', marketId], payload);
            const nextDraft = makeDraft(payload.wallet_rule, payload.market);
            setDraft(nextDraft);
            setInitialDraft(nextDraft);
            toast.success('Wallet rules saved.', {
                title: 'Billing policy updated',
            });
        },
        onError: (error) => {
            const message =
                error?.response?.data?.message
                || Object.values(error?.response?.data?.errors || {}).flat()[0]
                || 'CRM could not save the wallet policy.';

            toast.error(String(message), {
                title: 'Wallet policy save failed',
            });
        },
    });

    const walletRule = walletRulesQuery.data?.wallet_rule || null;
    const market = walletRulesQuery.data?.market || selectedMarket;
    const editable = Boolean(walletRulesQuery.data?.editable);

    const dirty = useMemo(() => {
        return JSON.stringify(normalizeDraft(draft)) !== JSON.stringify(normalizeDraft(initialDraft));
    }, [draft, initialDraft]);

    const handleSave = () => {
        const presets = String(draft.topup_presets_text || '')
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean);

        saveMutation.mutate({
            enabled: Boolean(draft.enabled),
            currency_code: String(draft.currency_code || '').trim().toUpperCase(),
            topup_preset_json: presets,
            limit_json: {
                max_single_topup: String(draft.max_single_topup || '').trim(),
                max_wallet_balance: String(draft.max_wallet_balance || '').trim(),
            },
            auto_renew_json: {
                enabled: Boolean(draft.auto_renew_enabled),
            },
            ui_json: {
                allow_combined_topup_subscribe: Boolean(draft.allow_combined_topup_subscribe),
                show_refresh_button: Boolean(draft.show_refresh_button),
                recent_transactions_limit: draft.recent_transactions_limit
                    ? Number(draft.recent_transactions_limit)
                    : null,
                wallet_funding_label: String(draft.wallet_funding_label || '').trim(),
            },
        });
    };

    if (!selectedMarket && platforms.length === 0) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state="empty"
                    eyebrow="Wallet Rules"
                    title="No markets available"
                    message="Create or enable markets before configuring wallet funding policy."
                />
            </div>
        );
    }

    if (!selectedMarket) {
        return (
            <div className="space-y-5 p-5">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.65fr)]">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                Wallet Rules
                            </p>
                            <h4 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">
                                Review wallet funding posture by market
                            </h4>
                            <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                                Control wallet enablement, top-up presets, customer-facing wallet labels, and
                                auto-renew posture from the billing workspace. Select a market to manage its
                                wallet funding policy.
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <MetricCard label="Markets" value={platforms.length} tone="slate" />
                            <MetricCard label="Editable" value="Admin" tone="emerald" />
                        </div>
                    </div>
                </section>

                <div className="grid gap-4 xl:grid-cols-3">
                    {platforms.map((platform) => (
                        <MarketCard key={platform.id} platform={platform} onSelect={() => setSelectedMarket(platform)} />
                    ))}
                </div>
            </div>
        );
    }

    if (walletRulesQuery.isLoading) {
        return (
            <div className="space-y-4 p-5 animate-pulse">
                <div className="h-28 rounded-3xl border border-slate-200 bg-white" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-64 rounded-3xl border border-slate-200 bg-white" />
                    <div className="h-64 rounded-3xl border border-slate-200 bg-white" />
                </div>
                <div className="h-52 rounded-3xl border border-slate-200 bg-white" />
            </div>
        );
    }

    if (walletRulesQuery.isError) {
        return (
            <div className="space-y-4 p-5">
                <BillingStateNotice
                    state={isForbiddenQueryError(walletRulesQuery.error) ? 'forbidden' : 'degraded'}
                    eyebrow="Wallet Rules"
                    title={
                        isForbiddenQueryError(walletRulesQuery.error)
                            ? 'Wallet policy access is restricted'
                            : 'Wallet policy unavailable'
                    }
                    message={
                        isForbiddenQueryError(walletRulesQuery.error)
                            ? 'This role cannot inspect wallet funding policy for the selected market.'
                            : 'CRM could not load the wallet policy for this market. Refresh the page to retry.'
                    }
                />
                <BackButton onClick={() => setSelectedMarket(null)} />
            </div>
        );
    }

    return (
        <div className="space-y-5 p-5">
            <section className="flex flex-col gap-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03] xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                            {market?.country || 'Market'}
                        </span>
                        <span className={`rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] ${draft.enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-600'}`}>
                            {draft.enabled ? 'Wallet enabled' : 'Wallet disabled'}
                        </span>
                    </div>
                    <h4 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                        {market?.name} wallet policy
                    </h4>
                    <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
                        Configure customer wallet posture, funding thresholds, presets, and renewal fallback
                        settings for this market. Keep the policy compact and predictable so operators can
                        understand it at a glance.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <BackButton onClick={() => setSelectedMarket(null)} />
                    {editable && (
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={!dirty || saveMutation.isPending}
                            className="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300"
                        >
                            {saveMutation.isPending ? 'Saving…' : 'Save wallet policy'}
                        </button>
                    )}
                </div>
            </section>

            {!editable && (
                <BillingStateNotice
                    state="forbidden"
                    eyebrow="Review Mode"
                    title="Wallet policy is view-only for this role"
                    message="Only administrators can update wallet funding posture. Sub-admins can still review the current live policy."
                />
            )}

            <div className="grid gap-4 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        Wallet Controls
                    </p>
                    <div className="mt-4 space-y-4">
                        <ToggleRow
                            label="Wallet funding enabled"
                            description="Controls whether customers can top up wallet balance in this market."
                            checked={draft.enabled}
                            disabled={!editable}
                            onChange={(checked) => setDraft((current) => ({ ...current, enabled: checked }))}
                        />
                        <ToggleRow
                            label="Wallet auto-renew"
                            description="Allow subscription fallback to charge customer wallet balance when policy requires it."
                            checked={draft.auto_renew_enabled}
                            disabled={!editable}
                            onChange={(checked) => setDraft((current) => ({ ...current, auto_renew_enabled: checked }))}
                        />
                        <ToggleRow
                            label="Combined top-up + subscribe"
                            description="Expose combined top-up and subscription funding in the customer experience."
                            checked={draft.allow_combined_topup_subscribe}
                            disabled={!editable}
                            onChange={(checked) =>
                                setDraft((current) => ({ ...current, allow_combined_topup_subscribe: checked }))
                            }
                        />
                        <ToggleRow
                            label="Show refresh button"
                            description="Display manual wallet refresh controls in the customer wallet experience."
                            checked={draft.show_refresh_button}
                            disabled={!editable}
                            onChange={(checked) =>
                                setDraft((current) => ({ ...current, show_refresh_button: checked }))
                            }
                        />
                    </div>
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-950/[0.03]">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                        Funding Parameters
                    </p>
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <Field
                            label="Currency"
                            value={draft.currency_code}
                            disabled={!editable}
                            onChange={(value) => setDraft((current) => ({ ...current, currency_code: value.toUpperCase() }))}
                        />
                        <Field
                            label="Recent transactions limit"
                            value={draft.recent_transactions_limit}
                            disabled={!editable}
                            onChange={(value) => setDraft((current) => ({ ...current, recent_transactions_limit: value }))}
                        />
                        <Field
                            label="Max single top-up"
                            value={draft.max_single_topup}
                            disabled={!editable}
                            onChange={(value) => setDraft((current) => ({ ...current, max_single_topup: value }))}
                        />
                        <Field
                            label="Max wallet balance"
                            value={draft.max_wallet_balance}
                            disabled={!editable}
                            onChange={(value) => setDraft((current) => ({ ...current, max_wallet_balance: value }))}
                        />
                        <div className="md:col-span-2">
                            <Field
                                label="Wallet funding label"
                                value={draft.wallet_funding_label}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, wallet_funding_label: value }))}
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Field
                                label="Top-up presets"
                                value={draft.topup_presets_text}
                                disabled={!editable}
                                onChange={(value) => setDraft((current) => ({ ...current, topup_presets_text: value }))}
                                placeholder="e.g. 500, 1000, 2500"
                                hint="Comma-separated wallet funding amounts shown in the wallet top-up experience."
                            />
                        </div>
                    </div>
                </section>
            </div>

            <section className="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                <div className="grid gap-4 xl:grid-cols-4">
                    <MetricCard label="Currency" value={draft.currency_code || '—'} tone="slate" />
                    <MetricCard label="Presets" value={String(String(draft.topup_presets_text || '').split(',').map((entry) => entry.trim()).filter(Boolean).length)} tone="slate" />
                    <MetricCard label="Auto-renew" value={draft.auto_renew_enabled ? 'On' : 'Off'} tone={draft.auto_renew_enabled ? 'emerald' : 'slate'} />
                    <MetricCard label="Workspace mode" value={editable ? 'Editable' : 'Review'} tone={editable ? 'emerald' : 'slate'} />
                </div>
            </section>

            {walletRule === null && (
                <BillingStateNotice
                    state="empty"
                    eyebrow={`${market?.name} Wallet Rules`}
                    title="No wallet policy exists yet"
                    message="Saving from this panel will create the first registry-backed wallet rule set for this market."
                />
            )}
        </div>
    );
}

function ToggleRow({ label, description, checked, onChange, disabled }) {
    return (
        <label className="flex items-start justify-between gap-4 rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
            <div>
                <p className="text-sm font-semibold text-slate-900">{label}</p>
                <p className="mt-1 text-xs leading-5 text-slate-600">{description}</p>
            </div>
            <input
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-1 h-5 w-5 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
            />
        </label>
    );
}

function Field({ label, value, onChange, disabled, placeholder = '', hint = '' }) {
    return (
        <label className="block">
            <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</span>
            <input
                value={value}
                disabled={disabled}
                placeholder={placeholder}
                onChange={(event) => onChange(event.target.value)}
                className="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-2 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-500"
            />
            {hint ? <p className="mt-2 text-xs leading-5 text-slate-500">{hint}</p> : null}
        </label>
    );
}

function BackButton({ onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
        >
            Back
        </button>
    );
}

function MetricCard({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'border-slate-200 bg-white text-slate-900',
        emerald: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };

    return (
        <div className={`rounded-2xl border px-4 py-3 ${tones[tone] || tones.slate}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight">{value}</p>
        </div>
    );
}

function MarketCard({ platform, onSelect }) {
    return (
        <button
            type="button"
            onClick={onSelect}
            className="rounded-3xl border border-slate-200 bg-white p-5 text-left transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg hover:shadow-slate-950/[0.05]"
        >
            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Market</p>
            <h5 className="mt-2 text-lg font-semibold tracking-tight text-slate-950">{platform.name}</h5>
            {platform.country ? <p className="mt-1 text-sm text-slate-600">{platform.country}</p> : null}
            <div className="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 text-sm text-slate-500">
                <span>Open wallet rules</span>
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                </svg>
            </div>
        </button>
    );
}
