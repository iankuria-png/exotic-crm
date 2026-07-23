import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { flaggedPlatformLabel } from '../../utils/flags';

// trigger_days convention: negative = days BEFORE expiry, 0 = on expiry day,
// positive = days AFTER expiry.
function offsetLabel(triggerDays) {
    const days = Number(triggerDays);
    if (days === 0) return 'On expiry day';
    if (days < 0) return `${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} before expiry`;
    return `${days} day${days === 1 ? '' : 's'} after expiry`;
}

function triggerFromForm(timing, days) {
    const n = Math.max(0, Math.min(60, parseInt(days, 10) || 0));
    if (timing === 'on') return 0;
    if (timing === 'after') return n;
    return -n;
}

const EMPTY_ADD = { timing: 'before', days: '3', template_id: '' };

export default function RenewalCadenceModal({ open, onClose, platformOptions = [], defaultPlatformId = '', onChanged }) {
    const queryClient = useQueryClient();
    // '' means the global default set; otherwise a platform id string.
    const [scope, setScope] = useState(defaultPlatformId || '');
    const [addForm, setAddForm] = useState(EMPTY_ADD);
    const [feedback, setFeedback] = useState(null);

    const scopeKey = scope === '' ? 'global' : scope;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['renewal-cadence', scopeKey],
        queryFn: () =>
            api
                .get('/crm/renewals/cadence', {
                    params: scope === '' ? {} : { platform_id: Number(scope) },
                })
                .then((response) => response.data),
        enabled: open,
    });

    const isGlobalScope = scope === '';
    const hasOverride = !isGlobalScope && !!data?.has_market_override;
    // Rows that are editable in this scope: the global set (global scope) or the
    // market's own set once it has one. Otherwise we show the inherited global set.
    const editableCampaigns = isGlobalScope
        ? data?.global_campaigns || []
        : (hasOverride ? data?.market_campaigns : []) || [];
    const inheritedCampaigns = !isGlobalScope && !hasOverride ? data?.global_campaigns || [] : [];
    const templates = data?.templates || [];

    const marketName = useMemo(() => {
        if (isGlobalScope) return 'Global default';
        const match = platformOptions.find((p) => String(p.platform_id) === String(scope));
        return match ? flaggedPlatformLabel(match) : `Market #${scope}`;
    }, [isGlobalScope, platformOptions, scope]);

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['renewal-cadence', scopeKey] });
        queryClient.invalidateQueries({ queryKey: ['renewals-overview'] });
        if (onChanged) onChanged();
    };

    const createMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/renewals/campaigns', payload).then((r) => r.data),
        onSuccess: () => {
            setAddForm(EMPTY_ADD);
            setFeedback({ tone: 'success', text: 'Reminder added.' });
            invalidate();
        },
        onError: (error) =>
            setFeedback({ tone: 'error', text: error?.response?.data?.message || 'Could not add reminder.' }),
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, ...payload }) => api.patch(`/crm/renewals/campaigns/${id}`, payload).then((r) => r.data),
        onSuccess: () => {
            setFeedback({ tone: 'success', text: 'Reminder updated.' });
            invalidate();
        },
        onError: (error) =>
            setFeedback({ tone: 'error', text: error?.response?.data?.message || 'Could not update reminder.' }),
    });

    const deleteMutation = useMutation({
        mutationFn: (id) => api.delete(`/crm/renewals/campaigns/${id}`).then((r) => r.data),
        onSuccess: () => {
            setFeedback({ tone: 'success', text: 'Reminder removed.' });
            invalidate();
        },
        onError: (error) =>
            setFeedback({ tone: 'error', text: error?.response?.data?.message || 'Could not remove reminder.' }),
    });

    const guardMutation = useMutation({
        mutationFn: (enabled) =>
            api.post('/crm/renewals/guard', { platform_id: Number(scope), enabled }).then((r) => r.data),
        onSuccess: () => {
            setFeedback({ tone: 'success', text: 'Short-cycle guard updated.' });
            invalidate();
        },
        onError: (error) =>
            setFeedback({ tone: 'error', text: error?.response?.data?.message || 'Could not update the guard.' }),
    });

    if (!open) return null;

    const handleAdd = (event) => {
        event.preventDefault();
        if (!addForm.template_id) {
            setFeedback({ tone: 'error', text: 'Pick a template for this reminder.' });
            return;
        }
        createMutation.mutate({
            platform_id: isGlobalScope ? null : Number(scope),
            trigger_days: triggerFromForm(addForm.timing, addForm.days),
            template_id: Number(addForm.template_id),
            enabled: true,
        });
    };

    const seedFromGlobal = () => {
        // Copy the global default set into this market as its starting cadence.
        (inheritedCampaigns.length ? inheritedCampaigns : []).forEach((campaign) => {
            const template = templates.find((t) => t.channel === (campaign.template?.channel || 'sms')) || templates[0];
            if (!template) return;
            createMutation.mutate({
                platform_id: Number(scope),
                trigger_days: campaign.trigger_days,
                template_id: campaign.template?.id || template.id,
                enabled: !!campaign.enabled,
            });
        });
    };

    const renderRow = (campaign) => {
        const channel = campaign.template?.channel || campaign.channel || 'sms';
        return (
            <div
                key={campaign.id}
                className="flex flex-wrap items-center gap-3 rounded-md border border-slate-200 bg-white px-3 py-2.5"
            >
                <div className="min-w-[150px] flex-1">
                    <p className="text-sm font-semibold text-slate-900">{offsetLabel(campaign.trigger_days)}</p>
                    <p className="text-xs uppercase tracking-[0.08em] text-slate-400">{channel}</p>
                </div>
                <div className="min-w-[180px] flex-1">
                    <label className="sr-only" htmlFor={`tpl-${campaign.id}`}>Template</label>
                    <select
                        id={`tpl-${campaign.id}`}
                        value={campaign.template?.id || ''}
                        onChange={(event) =>
                            updateMutation.mutate({ id: campaign.id, template_id: Number(event.target.value) })
                        }
                        className="crm-select"
                    >
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.title} ({template.channel})
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex items-center gap-2 text-xs font-medium text-slate-600">
                    <input
                        type="checkbox"
                        checked={!!campaign.enabled}
                        onChange={(event) => updateMutation.mutate({ id: campaign.id, enabled: event.target.checked })}
                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                    />
                    {campaign.enabled ? 'On' : 'Off'}
                </label>
                <button
                    type="button"
                    onClick={() => deleteMutation.mutate(campaign.id)}
                    className="crm-btn-secondary px-2.5 py-1 text-xs text-rose-600"
                >
                    Remove
                </button>
            </div>
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div
                className="w-full max-w-3xl rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Reminder cadence</h3>
                        <p className="crm-panel-subtitle">
                            Configure how often automated renewal reminders are sent per market.
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5 text-xs">
                        Close
                    </button>
                </header>

                <div className="max-h-[72vh] space-y-4 overflow-y-auto p-4">
                    <section className="grid gap-3 md:grid-cols-2">
                        <div>
                            <label htmlFor="cadence-market" className="mb-1 block text-sm font-medium text-slate-700">
                                Market
                            </label>
                            <select
                                id="cadence-market"
                                value={scope}
                                onChange={(event) => {
                                    setScope(event.target.value);
                                    setFeedback(null);
                                    setAddForm(EMPTY_ADD);
                                }}
                                className="crm-select"
                            >
                                <option value="">{'\u{1F30D}'}  Global default (all other markets)</option>
                                {platformOptions.map((platform) => (
                                    <option key={platform.platform_id} value={platform.platform_id}>
                                        {flaggedPlatformLabel(platform)}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-end">
                            <span
                                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${
                                    isGlobalScope
                                        ? 'bg-slate-100 text-slate-600'
                                        : hasOverride
                                          ? 'bg-teal-50 text-teal-700'
                                          : 'bg-amber-50 text-amber-700'
                                }`}
                            >
                                {isGlobalScope
                                    ? 'Applies to every market without its own cadence'
                                    : hasOverride
                                      ? 'Custom cadence for this market'
                                      : 'Inheriting the global default'}
                            </span>
                        </div>
                    </section>

                    {!isGlobalScope ? (
                        <section className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-slate-900">Short-cycle guard</p>
                                <p className="text-xs text-slate-600">
                                    Skip any before-expiry reminder whose lead time is longer than the client&apos;s own
                                    subscription (e.g. a 7-day-before reminder on a weekly plan).
                                </p>
                            </div>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={!!data?.guard_enabled}
                                    disabled={isLoading || guardMutation.isPending}
                                    onChange={(event) => guardMutation.mutate(event.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                {data?.guard_enabled ? 'Enabled' : 'Disabled'}
                            </label>
                        </section>
                    ) : null}

                    <section className="space-y-2">
                        <p className="text-sm font-semibold text-slate-900">
                            Reminder schedule for {marketName}
                        </p>

                        {isLoading ? (
                            <p className="text-xs text-slate-500">Loading cadence…</p>
                        ) : isError ? (
                            <div className="flex items-center gap-3 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                                Could not load cadence.
                                <button type="button" onClick={() => refetch()} className="crm-btn-secondary px-2 py-1 text-xs">
                                    Retry
                                </button>
                            </div>
                        ) : !isGlobalScope && !hasOverride ? (
                            <div className="space-y-2">
                                <p className="text-xs text-slate-600">
                                    This market currently uses the global default reminders below. Add a reminder to give it
                                    its own schedule.
                                </p>
                                <div className="space-y-2 opacity-70">
                                    {inheritedCampaigns.length ? (
                                        inheritedCampaigns.map((campaign) => (
                                            <div
                                                key={`inherited-${campaign.id}`}
                                                className="flex items-center justify-between rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600"
                                            >
                                                <span className="font-medium text-slate-700">{offsetLabel(campaign.trigger_days)}</span>
                                                <span>{campaign.template?.title || 'N/A'} ({campaign.template?.channel || campaign.channel})</span>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-xs text-slate-500">No global default reminders configured yet.</p>
                                    )}
                                </div>
                                {inheritedCampaigns.length ? (
                                    <button
                                        type="button"
                                        onClick={seedFromGlobal}
                                        disabled={createMutation.isPending || !templates.length}
                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                    >
                                        Start from the global default
                                    </button>
                                ) : null}
                            </div>
                        ) : editableCampaigns.length ? (
                            <div className="space-y-2">{editableCampaigns.map(renderRow)}</div>
                        ) : (
                            <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">
                                No reminders configured{isGlobalScope ? '' : ' for this market'} yet. Add one below.
                            </p>
                        )}
                    </section>

                    {!templates.length && !isLoading ? (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                            No active renewal templates are available{isGlobalScope ? '' : ' for this market'}. Create a
                            renewal template before adding reminders.
                        </p>
                    ) : (
                        <form onSubmit={handleAdd} className="space-y-2 rounded-md border border-slate-200 p-3">
                            <p className="text-sm font-semibold text-slate-900">Add a reminder</p>
                            <div className="grid gap-2 sm:grid-cols-4">
                                <select
                                    value={addForm.timing}
                                    onChange={(event) => setAddForm((prev) => ({ ...prev, timing: event.target.value }))}
                                    className="crm-select"
                                    aria-label="Reminder timing"
                                >
                                    <option value="before">Before expiry</option>
                                    <option value="on">On expiry day</option>
                                    <option value="after">After expiry</option>
                                </select>
                                <input
                                    type="number"
                                    min="0"
                                    max="60"
                                    value={addForm.days}
                                    disabled={addForm.timing === 'on'}
                                    onChange={(event) => setAddForm((prev) => ({ ...prev, days: event.target.value }))}
                                    className="crm-input"
                                    aria-label="Days"
                                    placeholder="Days"
                                />
                                <select
                                    value={addForm.template_id}
                                    onChange={(event) => setAddForm((prev) => ({ ...prev, template_id: event.target.value }))}
                                    className="crm-select sm:col-span-2"
                                    aria-label="Template"
                                >
                                    <option value="">Select template…</option>
                                    {templates.map((template) => (
                                        <option key={template.id} value={template.id}>
                                            {template.title} ({template.channel})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-center justify-between">
                                <p className="text-xs text-slate-500">
                                    {offsetLabel(triggerFromForm(addForm.timing, addForm.days))}
                                </p>
                                <button
                                    type="submit"
                                    disabled={createMutation.isPending}
                                    className="crm-btn-primary px-3 py-1.5 text-xs"
                                >
                                    {createMutation.isPending ? 'Adding…' : 'Add reminder'}
                                </button>
                            </div>
                        </form>
                    )}

                    {feedback ? (
                        <p className={`text-xs font-medium ${feedback.tone === 'success' ? 'text-emerald-700' : 'text-amber-700'}`}>
                            {feedback.text}
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
