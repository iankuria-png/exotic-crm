import React from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';

export default function WhatsAppRoutingPanel({
    messageTypes,
    onMessageTypeChange,
    onMarketChange,
    onSave,
    platforms,
    saving,
    selectedMarketId,
    selectedMessageType,
    statusChip,
}) {
    const routingQuery = useQuery({
        queryKey: ['messaging-routing', selectedMarketId, selectedMessageType],
        queryFn: () => api.get(`/crm/messaging/whatsapp/routing/${selectedMarketId}/${selectedMessageType}`).then((response) => response.data),
        enabled: Boolean(selectedMarketId && selectedMessageType),
    });

    const rule = routingQuery.data?.rule || {};
    const profiles = routingQuery.data?.profiles || [];

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">Routing</h4>
                    <p className="mt-1 text-xs text-slate-500">Bind one message type per market to a primary Meta profile.</p>
                </div>
                <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(rule.enabled === false ? 'configured_disabled' : 'connected')}`}>
                    {rule.enabled === false ? 'disabled' : 'enabled'}
                </span>
            </div>
            <div className="mt-4 grid gap-3 lg:grid-cols-4">
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Market</span>
                    <select value={selectedMarketId || ''} onChange={(event) => onMarketChange(Number(event.target.value))} className="crm-select">
                        {platforms.map((platform) => (
                            <option key={platform.id} value={platform.id}>{platform.name}</option>
                        ))}
                    </select>
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Message type</span>
                    <select value={selectedMessageType} onChange={(event) => onMessageTypeChange(event.target.value)} className="crm-select">
                        {messageTypes.map((type) => (
                            <option key={type} value={type}>{type.replaceAll('_', ' ')}</option>
                        ))}
                    </select>
                </label>
                <label className="block lg:col-span-2">
                    <span className="mb-1 block text-xs font-medium text-slate-600">Primary profile</span>
                    <select
                        value={rule.primary_profile_id || ''}
                        onChange={(event) => onSave({
                            primary_profile_id: event.target.value ? Number(event.target.value) : null,
                            fallback_to_sms: rule.fallback_to_sms ?? true,
                            enabled: rule.enabled ?? true,
                        })}
                        disabled={routingQuery.isLoading || saving}
                        className="crm-select"
                    >
                        <option value="">No WhatsApp route</option>
                        {profiles.map((profile) => (
                            <option key={profile.id} value={profile.id}>{profile.profile_name}</option>
                        ))}
                    </select>
                </label>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-4 text-sm text-slate-700">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={Boolean(rule.fallback_to_sms ?? true)}
                        onChange={(event) => onSave({
                            primary_profile_id: rule.primary_profile_id || null,
                            fallback_to_sms: event.target.checked,
                            enabled: rule.enabled ?? true,
                        })}
                        disabled={routingQuery.isLoading || saving}
                        className="rounded border-slate-300"
                    />
                    Fallback to SMS when WhatsApp rejects
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={Boolean(rule.enabled ?? true)}
                        onChange={(event) => onSave({
                            primary_profile_id: rule.primary_profile_id || null,
                            fallback_to_sms: rule.fallback_to_sms ?? true,
                            enabled: event.target.checked,
                        })}
                        disabled={routingQuery.isLoading || saving}
                        className="rounded border-slate-300"
                    />
                    Route enabled
                </label>
            </div>
        </section>
    );
}
