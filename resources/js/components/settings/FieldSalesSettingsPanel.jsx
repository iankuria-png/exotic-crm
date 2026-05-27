import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function numberValue(value, fallback = '') {
    if (value === null || value === undefined) {
        return fallback;
    }

    return String(value);
}

function ratePercent(value) {
    return numberValue(Number(value || 0) * 100, '0');
}

export default function FieldSalesSettingsPanel() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState({ platforms: [], globals: {} });

    const query = useQuery({
        queryKey: ['settings-field-sales'],
        queryFn: () => api.get('/crm/settings/field-sales').then((response) => response.data),
    });

    useEffect(() => {
        if (!query.data) {
            return;
        }

        setForm({
            globals: {
                deposit_poll_timeout_minutes: numberValue(query.data.globals?.deposit_poll_timeout_minutes, '10'),
            },
            platforms: (query.data.platforms || []).map((platform) => ({
                id: platform.id,
                name: platform.name,
                field_activation_deposit: numberValue(Number(platform.field_activation_deposit_minor || 0) / 100, '0'),
                field_trial_duration_days: numberValue(platform.field_trial_duration_days, ''),
                field_trial_product_id: platform.field_trial_product_id ? String(platform.field_trial_product_id) : '',
                field_activation_commission_rate: ratePercent(platform.field_activation_commission_rate),
                field_renewal_commission_rate: ratePercent(platform.field_renewal_commission_rate),
                field_renewal_commission_months: numberValue(platform.field_renewal_commission_months, '0'),
            })),
        });
    }, [query.data]);

    const updateMutation = useMutation({
        mutationFn: () => api.put('/crm/settings/field-sales', {
            globals: {
                deposit_poll_timeout_minutes: Number(form.globals.deposit_poll_timeout_minutes || 10),
            },
            platforms: form.platforms.map((platform) => ({
                id: platform.id,
                field_activation_deposit_minor: Math.round(Number(platform.field_activation_deposit || 0) * 100),
                field_trial_duration_days: platform.field_trial_duration_days === '' ? null : Number(platform.field_trial_duration_days),
                field_trial_product_id: platform.field_trial_product_id ? Number(platform.field_trial_product_id) : null,
                field_activation_commission_rate: Number(platform.field_activation_commission_rate || 0) / 100,
                field_renewal_commission_rate: Number(platform.field_renewal_commission_rate || 0) / 100,
                field_renewal_commission_months: Number(platform.field_renewal_commission_months || 0),
            })),
        }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['settings-field-sales'] });
            toast.success('Field sales settings saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not save field sales settings.');
        },
    });

    const products = query.data?.products || [];

    const updatePlatform = (platformId, key, value) => {
        setForm((current) => ({
            ...current,
            platforms: current.platforms.map((platform) => (
                Number(platform.id) === Number(platformId)
                    ? { ...platform, [key]: value }
                    : platform
            )),
        }));
    };

    if (query.isLoading) {
        return (
            <section className="crm-surface p-4">
                <p className="text-sm text-slate-500">Loading field sales settings...</p>
            </section>
        );
    }

    return (
        <div className="space-y-4">
            <section className="crm-surface p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="crm-panel-title">Field Sales Controls</h3>
                        <p className="crm-panel-subtitle">Configure deposit thresholds, trial defaults, and commission rates by market.</p>
                    </div>
                    <button
                        type="button"
                        className="crm-btn-primary"
                        disabled={updateMutation.isPending}
                        onClick={() => updateMutation.mutate()}
                    >
                        {updateMutation.isPending ? 'Saving...' : 'Save settings'}
                    </button>
                </div>

                <div className="mt-4 max-w-xs">
                    <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Deposit check timeout</span>
                        <input
                            type="number"
                            min="1"
                            value={form.globals.deposit_poll_timeout_minutes || ''}
                            onChange={(event) => setForm((current) => ({
                                ...current,
                                globals: { ...current.globals, deposit_poll_timeout_minutes: event.target.value },
                            }))}
                            className="crm-input"
                        />
                    </label>
                </div>
            </section>

            <section className="crm-surface overflow-hidden">
                <div className="max-h-[68vh] overflow-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Market</th>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Deposit</th>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Trial</th>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Activation %</th>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Renewal %</th>
                                <th className="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Renewal Months</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {form.platforms.map((platform) => (
                                <tr key={platform.id}>
                                    <td className="px-4 py-3">
                                        <p className="text-sm font-semibold text-slate-900">{platform.name}</p>
                                    </td>
                                    <td className="px-4 py-3">
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={platform.field_activation_deposit}
                                            onChange={(event) => updatePlatform(platform.id, 'field_activation_deposit', event.target.value)}
                                            className="crm-input min-w-[7rem]"
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="grid min-w-[16rem] gap-2">
                                            <select
                                                value={platform.field_trial_product_id}
                                                onChange={(event) => updatePlatform(platform.id, 'field_trial_product_id', event.target.value)}
                                                className="crm-select w-full"
                                            >
                                                <option value="">No trial product</option>
                                                {products
                                                    .filter((product) => Number(product.platform_id) === Number(platform.id))
                                                    .map((product) => (
                                                    <option key={product.id} value={product.id}>
                                                        {product.display_name || product.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <input
                                                type="number"
                                                min="1"
                                                placeholder="Days"
                                                value={platform.field_trial_duration_days}
                                                onChange={(event) => updatePlatform(platform.id, 'field_trial_duration_days', event.target.value)}
                                                className="crm-input"
                                            />
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            value={platform.field_activation_commission_rate}
                                            onChange={(event) => updatePlatform(platform.id, 'field_activation_commission_rate', event.target.value)}
                                            className="crm-input min-w-[6rem]"
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            value={platform.field_renewal_commission_rate}
                                            onChange={(event) => updatePlatform(platform.id, 'field_renewal_commission_rate', event.target.value)}
                                            className="crm-input min-w-[6rem]"
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <input
                                            type="number"
                                            min="0"
                                            value={platform.field_renewal_commission_months}
                                            onChange={(event) => updatePlatform(platform.id, 'field_renewal_commission_months', event.target.value)}
                                            className="crm-input min-w-[6rem]"
                                        />
                                    </td>
                                </tr>
                            ))}
                            {form.platforms.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-4 py-10 text-center text-sm text-slate-500">
                                        No markets are configured yet.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
