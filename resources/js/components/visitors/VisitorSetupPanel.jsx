import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import SectionFrame from '../SectionFrame';
import StatusBadge from '../StatusBadge';
import contactUnlocks from '../../services/contactUnlocks';
import { SCOPE_OPTIONS, compactNumber, titleize } from './visitorFormat';

function blankRule(markets) {
    const market = markets?.[0] || {};
    return {
        id: null,
        platform_id: market.id || '',
        scope: 'single_profile',
        label: 'Unlock this profile',
        currency: market.currency_code || 'KES',
        amount: '',
        duration_days: 1,
        is_active: true,
    };
}

function normalizeRule(rule) {
    return {
        id: rule.id ?? null,
        platform_id: rule.platform_id || '',
        scope: rule.scope || 'single_profile',
        label: rule.label || '',
        currency: rule.currency || 'KES',
        amount: rule.amount ?? '',
        duration_days: rule.duration_days || 1,
        is_active: rule.is_active !== false,
    };
}

function firstErrorMessage(error) {
    const validation = error?.response?.data?.errors;
    if (validation && typeof validation === 'object') {
        const first = Object.values(validation).flat()[0];
        if (first) return String(first);
    }

    return error?.response?.data?.message || 'CRM could not save contact unlock settings.';
}

export default function VisitorSetupPanel({ data = {}, markets = [], pageParams = {}, toast }) {
    const queryClient = useQueryClient();
    const [enabled, setEnabled] = useState(false);
    const [sandboxOnly, setSandboxOnly] = useState(true);
    const [marketIds, setMarketIds] = useState([]);
    const [rules, setRules] = useState([]);
    const [readinessMarketId, setReadinessMarketId] = useState('all');
    const readinessKey = ['contact-unlock-readiness', readinessMarketId];
    const readinessResult = queryClient.getQueryData(readinessKey);

    useEffect(() => {
        setEnabled(Boolean(data.settings?.enabled));
        setSandboxOnly(data.settings?.sandbox_only !== false);
        setMarketIds((data.settings?.market_ids || []).map(Number));
        setRules((data.pricing_rules || []).map(normalizeRule));
    }, [data.pricing_rules, data.settings]);

    const saveMutation = useMutation({
        mutationFn: () => contactUnlocks.updateSettings({
            enabled,
            sandbox_only: sandboxOnly,
            market_ids: marketIds,
            pricing_rules: rules
                .filter((rule) => rule.platform_id && rule.label && rule.amount)
                .map((rule) => ({
                    ...rule,
                    platform_id: Number(rule.platform_id),
                    amount: Number(rule.amount),
                    duration_days: Number(rule.duration_days || 1),
                    currency: String(rule.currency || '').toUpperCase(),
                })),
        }),
        onSuccess: (payload) => {
            queryClient.setQueryData(['contact-unlocks', pageParams], payload);
            queryClient.invalidateQueries({ queryKey: ['contact-unlocks'] });
            toast.success('Contact unlock settings saved.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const deleteMutation = useMutation({
        mutationFn: (ruleId) => contactUnlocks.deleteRule(ruleId),
        onSuccess: (payload) => {
            queryClient.setQueryData(['contact-unlocks', pageParams], payload);
            queryClient.invalidateQueries({ queryKey: ['contact-unlocks'] });
            toast.success('Pricing rule removed.');
        },
        onError: (error) => toast.error(firstErrorMessage(error)),
    });

    const readinessMutation = useMutation({
        mutationFn: () => contactUnlocks.runReadiness({
            ...(readinessMarketId !== 'all' ? { platform_id: Number(readinessMarketId) } : {}),
        }),
        onMutate: () => {
            queryClient.setQueryData(readinessKey, {
                running: true,
                last_run_at: new Date().toISOString(),
                markets: [],
                summary: { markets_checked: 0, ready: 0, warning: 0, blocked: 0 },
            });
        },
        onSuccess: (payload) => {
            queryClient.setQueryData(readinessKey, { ...payload, running: false, last_run_at: new Date().toISOString() });
            const blocked = Number(payload?.summary?.blocked || 0);
            const warnings = Number(payload?.summary?.warning || 0);
            if (blocked > 0) {
                toast.error(`${blocked} market${blocked === 1 ? '' : 's'} blocked contact unlock readiness.`);
            } else if (warnings > 0) {
                toast.info(`Contact unlock readiness completed with ${warnings} warning${warnings === 1 ? '' : 's'}.`);
            } else {
                toast.success('Contact unlock readiness passed.');
            }
        },
        onError: (error) => {
            queryClient.setQueryData(readinessKey, { running: false, error: error?.response?.data?.message || 'CRM could not run contact unlock readiness checks.' });
            toast.error(error?.response?.data?.message || 'CRM could not run contact unlock readiness checks.');
        },
    });

    const rulesSummary = useMemo(() => `${rules.filter((rule) => rule.is_active).length} active rules across ${new Set(rules.map((rule) => rule.platform_id).filter(Boolean)).size} markets`, [rules]);

    function updateRule(index, patch) {
        setRules((current) => current.map((rule, ruleIndex) => (ruleIndex === index ? { ...rule, ...patch } : rule)));
    }

    function toggleMarket(id) {
        const marketId = Number(id);
        setMarketIds((current) => (current.includes(marketId) ? current.filter((item) => item !== marketId) : [...current, marketId]));
    }

    function removeRule(index, rule) {
        if (rule.id) {
            deleteMutation.mutate(rule.id);
            return;
        }
        setRules((current) => current.filter((_, ruleIndex) => ruleIndex !== index));
    }

    return (
        <div className="space-y-4">
            <SectionFrame
                title="Readiness"
                subtitle="Verify CRM availability, pricing, provider runtime, inactive profile sample, and WordPress proxy."
                action={(
                    <div className="flex flex-wrap items-center gap-2">
                        <select className="crm-select-enhanced" value={readinessMarketId} onChange={(event) => setReadinessMarketId(event.target.value)}>
                            <option value="all">All active markets</option>
                            {markets.map((market) => <option key={market.id} value={market.id}>{market.name}</option>)}
                        </select>
                        <button type="button" className="crm-btn-primary" disabled={readinessMutation.isPending} onClick={() => readinessMutation.mutate()}>
                            {readinessMutation.isPending || readinessResult?.running ? 'Checking...' : 'Run readiness'}
                        </button>
                    </div>
                )}
            >
                {readinessResult?.error ? <p className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{readinessResult.error}</p> : null}
                {readinessResult ? (
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-2 text-xs font-semibold">
                            <span className="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-600">{compactNumber(readinessResult.summary?.markets_checked)} checked</span>
                            <span className="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-700">{compactNumber(readinessResult.summary?.ready)} ready</span>
                            <span className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-700">{compactNumber(readinessResult.summary?.warning)} warnings</span>
                            <span className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-rose-700">{compactNumber(readinessResult.summary?.blocked)} blocked</span>
                            {readinessResult.last_run_at ? <span className="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-slate-500">Last run {new Date(readinessResult.last_run_at).toLocaleString()}</span> : null}
                        </div>
                        {(readinessResult.markets || []).map((market) => (
                            <div key={market.platform_id} className="rounded-lg border border-slate-200 bg-white p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold text-slate-950">{market.name}</p>
                                        <p className="mt-1 text-xs text-slate-500">{market.domain || 'No domain'} | {market.currency_code || 'No currency'}</p>
                                    </div>
                                    <StatusBadge status={market.status} label={titleize(market.status)} />
                                </div>
                                <div className="mt-3 grid gap-2 lg:grid-cols-2">
                                    {(market.checks || []).map((check) => (
                                        <div key={check.key} className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="font-semibold text-slate-800">{check.label}</span>
                                                <StatusBadge status={check.status} label={titleize(check.status)} />
                                            </div>
                                            <p className="mt-1 text-slate-600">{check.message}</p>
                                            {check.hint ? <p className="mt-2 text-xs font-semibold text-slate-500">{check.hint}</p> : null}
                                            {check.endpoint ? <p className="mt-2 break-all font-mono text-[11px] text-slate-500">{check.endpoint}</p> : null}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">Run readiness to see pass, warning, and blocked checks.</p>
                )}
            </SectionFrame>

            <SectionFrame
                title="Availability"
                subtitle={`${marketIds.length} of ${markets.length} markets enabled. ${sandboxOnly ? 'Sandbox only' : 'Production/default'} checkout mode.`}
                action={(
                    <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                        <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-600" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} />
                        Feature enabled
                    </label>
                )}
            >
                <div className="mb-4 inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 text-sm font-semibold">
                    <button type="button" className={`rounded-md px-4 py-2 ${!sandboxOnly ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-white'}`} onClick={() => setSandboxOnly(false)}>Production/default</button>
                    <button type="button" className={`rounded-md px-4 py-2 ${sandboxOnly ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-white'}`} onClick={() => setSandboxOnly(true)}>Sandbox only</button>
                </div>
                <div className="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
                    {markets.map((market) => (
                        <label key={market.id} className={`flex min-w-0 cursor-pointer items-center justify-between gap-3 rounded-lg border px-3 py-3 text-sm transition ${marketIds.includes(Number(market.id)) ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}>
                            <span className="min-w-0">
                                <span className="block truncate font-semibold text-slate-900">{market.name}</span>
                                <span className="block truncate text-xs text-slate-500">{market.currency_code || 'No currency'} | {market.country || 'Market'}</span>
                            </span>
                            <input type="checkbox" className="h-4 w-4 shrink-0 rounded border-slate-300 text-teal-600" checked={marketIds.includes(Number(market.id))} onChange={() => toggleMarket(market.id)} />
                        </label>
                    ))}
                </div>
            </SectionFrame>

            <SectionFrame
                title="Pricing Rules"
                subtitle={rulesSummary}
                action={<button type="button" className="crm-btn-secondary" onClick={() => setRules((current) => [...current, blankRule(markets)])}>Add rule</button>}
                footer={<div className="flex justify-end"><button type="button" className="crm-btn-primary" disabled={saveMutation.isPending} onClick={() => saveMutation.mutate()}>{saveMutation.isPending ? 'Saving...' : 'Save contact unlock'}</button></div>}
            >
                <div className="space-y-3">
                    {rules.map((rule, index) => (
                        <div key={`${rule.id || 'new'}-${index}`} className="grid gap-3 rounded-lg border border-slate-200 p-4 xl:grid-cols-[1.15fr_1fr_.7fr_.7fr_.55fr_auto]">
                            <select className="crm-select-enhanced" value={rule.platform_id} onChange={(event) => {
                                const market = markets.find((item) => Number(item.id) === Number(event.target.value));
                                updateRule(index, { platform_id: event.target.value, currency: market?.currency_code || rule.currency });
                            }}>
                                {markets.map((market) => <option key={market.id} value={market.id}>{market.name}</option>)}
                            </select>
                            <select className="crm-select-enhanced" value={rule.scope} onChange={(event) => updateRule(index, { scope: event.target.value })}>
                                {SCOPE_OPTIONS.map((scope) => <option key={scope.value} value={scope.value}>{scope.label}</option>)}
                            </select>
                            <input className="crm-input" value={rule.label} onChange={(event) => updateRule(index, { label: event.target.value })} placeholder="Display label" />
                            <div className="grid grid-cols-[72px_1fr] gap-2">
                                <input className="crm-input uppercase" value={rule.currency} onChange={(event) => updateRule(index, { currency: event.target.value.toUpperCase().slice(0, 3) })} aria-label="Currency" />
                                <input className="crm-input" type="number" min="1" value={rule.amount} onChange={(event) => updateRule(index, { amount: event.target.value })} placeholder="Amount" />
                            </div>
                            <input className="crm-input" type="number" min="1" max="366" value={rule.duration_days} onChange={(event) => updateRule(index, { duration_days: event.target.value })} aria-label="Duration days" />
                            <div className="flex items-center gap-3">
                                <label className="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-600" checked={Boolean(rule.is_active)} onChange={(event) => updateRule(index, { is_active: event.target.checked })} />
                                    On
                                </label>
                                <button type="button" className="crm-btn-danger px-3 py-2 text-sm" onClick={() => removeRule(index, rule)}>Remove</button>
                            </div>
                        </div>
                    ))}
                    {!rules.length ? <p className="rounded-lg border border-dashed border-slate-300 p-6 text-sm text-slate-500">No contact unlock pricing has been configured yet.</p> : null}
                </div>
            </SectionFrame>
        </div>
    );
}
