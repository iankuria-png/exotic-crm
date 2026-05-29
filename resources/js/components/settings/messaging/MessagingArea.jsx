import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import SuppressionsCard from './SuppressionsCard';
import TestSendModal from './TestSendModal';
import WhatsAppProfileForm from './WhatsAppProfileForm';
import WhatsAppProfilesTable from './WhatsAppProfilesTable';

const cockpitSections = [
    { id: 'overview', label: 'Overview' },
    { id: 'routes', label: 'Routes' },
    { id: 'profiles', label: 'Profiles' },
    { id: 'senders', label: 'Sender Pool' },
    { id: 'test', label: 'Test Console' },
    { id: 'suppressions', label: 'Suppressions' },
    { id: 'diagnostics', label: 'Diagnostics' },
    { id: 'activity', label: 'Activity' },
];

const cockpitMessageTypes = ['conversation', 'renewal', 'payment_link', 'credential', 'transactional', 'marketing'];

function formatDate(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? 'Never' : date.toLocaleString();
}

function timeAgo(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Never';

    const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 48) return `${hours}h ago`;
    const days = Math.round(hours / 24);
    return `${days}d ago`;
}

function engineLabel(engine) {
    if (engine === 'baileys') return 'Baileys';
    if (engine === 'sms') return 'SMS';
    return 'Meta';
}

function messageTypeLabel(type) {
    return String(type || '').replaceAll('_', ' ');
}

function chip(statusChip, status, label) {
    return (
        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold capitalize ring-1 ring-inset ${statusChip(status)}`}>
            {label}
        </span>
    );
}

function PanelShell({ actions = null, children, eyebrow = null, title }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div>
                    <h4 className="text-sm font-semibold text-slate-950">{title}</h4>
                    {eyebrow ? <p className="mt-1 text-xs text-slate-500">{eyebrow}</p> : null}
                </div>
                {actions}
            </header>
            {children}
        </section>
    );
}

function EmptyState({ action = null, description, title }) {
    return (
        <div className="px-4 py-8 text-center">
            <h5 className="text-sm font-semibold text-slate-900">{title}</h5>
            <p className="mx-auto mt-1 max-w-xl text-sm text-slate-500">{description}</p>
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}

function OperationalHeader({ platforms, profiles, routingRules, stats, statusChip }) {
    const metaProfiles = profiles.filter((profile) => profile.engine === 'meta_cloud_api');
    const baileysProfiles = profiles.filter((profile) => profile.engine === 'baileys');
    const readyMeta = metaProfiles.filter((profile) => profile.active && !profile.kill_switch_enabled && profile.tested_at).length;
    const smsFallbackRoutes = routingRules.filter((rule) => rule.fallback_to_sms && rule.enabled !== false).length;
    const anyKillSwitch = profiles.some((profile) => profile.kill_switch_enabled);

    const items = [
        {
            label: 'Meta Cloud API',
            value: `${readyMeta}/${metaProfiles.length}`,
            detail: metaProfiles.length ? 'tested profiles ready' : 'not configured',
            status: readyMeta ? 'connected' : 'pending',
        },
        {
            label: 'Baileys sidecar',
            value: baileysProfiles.length ? `${baileysProfiles.length}` : '0',
            detail: baileysProfiles.length ? 'profiles configured' : 'phase pending',
            status: baileysProfiles.length ? 'pending' : 'unknown',
        },
        {
            label: 'SMS fallback',
            value: smsFallbackRoutes,
            detail: 'enabled routes',
            status: smsFallbackRoutes ? 'connected' : 'pending',
        },
        {
            label: 'Markets',
            value: platforms.length,
            detail: `${stats?.enabled_routes || 0} active routes`,
            status: platforms.length ? 'connected' : 'pending',
        },
        {
            label: 'Suppressions',
            value: stats?.active_suppressions || 0,
            detail: 'active opt-outs',
            status: stats?.active_suppressions ? 'configured_disabled' : 'connected',
        },
        {
            label: 'Failed 24h',
            value: stats?.failed_messages_24h || 0,
            detail: 'outbound messages',
            status: stats?.failed_messages_24h ? 'failed' : 'connected',
        },
        {
            label: 'Last success',
            value: stats?.last_successful_send ? timeAgo(stats.last_successful_send.sent_at) : 'Never',
            detail: stats?.last_successful_send ? engineLabel(stats.last_successful_send.engine) : 'no successful send',
            status: stats?.last_successful_send ? 'success' : 'unknown',
        },
        {
            label: 'Kill switches',
            value: anyKillSwitch ? 'Review' : 'Clear',
            detail: anyKillSwitch ? 'one or more enabled' : 'none active',
            status: anyKillSwitch ? 'configured_disabled' : 'connected',
        },
    ];

    return (
        <div className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => (
                <div key={item.label} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-[11px] font-semibold uppercase text-slate-500">{item.label}</span>
                        <span className={`h-2 w-2 rounded-full ${item.status === 'connected' || item.status === 'success' ? 'bg-emerald-500' : item.status === 'failed' ? 'bg-rose-500' : item.status === 'configured_disabled' ? 'bg-amber-500' : 'bg-slate-400'}`} />
                    </div>
                    <div className="mt-1 flex items-end justify-between gap-2">
                        <p className="text-xl font-semibold text-slate-950">{item.value}</p>
                        <p className="text-right text-xs text-slate-500">{item.detail}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

function ReadinessChecklist({ onCreateProfile, onOpenSection, profiles, routingRules, stats, statusChip }) {
    const hasMeta = profiles.some((profile) => profile.engine === 'meta_cloud_api');
    const testedMeta = profiles.some((profile) => profile.engine === 'meta_cloud_api' && profile.tested_at);
    const configuredRoute = routingRules.some((rule) => rule.primary_profile_id && rule.enabled !== false);
    const smsFallback = routingRules.some((rule) => rule.fallback_to_sms && rule.enabled !== false);
    const hasBaileys = profiles.some((profile) => profile.engine === 'baileys');

    const steps = [
        { label: 'Add Meta profile', done: hasMeta, action: 'New profile', onClick: onCreateProfile },
        { label: 'Verify webhook credentials', done: profiles.some((profile) => profile.meta_webhook_verify_token_configured && profile.meta_app_secret_configured), action: 'Profiles', onClick: () => onOpenSection('profiles') },
        { label: 'Send test message', done: testedMeta, action: 'Test console', onClick: () => onOpenSection('test') },
        { label: 'Configure routing', done: configuredRoute, action: 'Routes', onClick: () => onOpenSection('routes') },
        { label: 'Enable one fallback route', done: smsFallback, action: 'Routes', onClick: () => onOpenSection('routes') },
        { label: 'Add Baileys profile', done: hasBaileys, optional: true, action: 'Sender pool', onClick: () => onOpenSection('senders') },
        { label: 'Pair sender', done: false, optional: true, action: 'Sender pool', onClick: () => onOpenSection('senders') },
        { label: 'Test fallback route', done: Boolean(stats?.last_successful_send), action: 'Test console', onClick: () => onOpenSection('test') },
    ];

    return (
        <PanelShell title="Gateway readiness" eyebrow="A guided rollout path for safe messaging operations.">
            <div className="divide-y divide-slate-100">
                {steps.map((step) => (
                    <div key={step.label} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ring-1 ring-inset ${step.done ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : step.optional ? 'bg-slate-100 text-slate-600 ring-slate-300' : 'bg-amber-50 text-amber-700 ring-amber-200'}`}>
                                {step.done ? '✓' : step.optional ? '·' : '!'}
                            </span>
                            <div>
                                <p className="text-sm font-medium text-slate-900">{step.label}</p>
                                <p className="text-xs text-slate-500">{step.done ? 'Complete' : step.optional ? 'Optional for Baileys rollout' : 'Needs attention'}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {chip(statusChip, step.done ? 'success' : step.optional ? 'unknown' : 'pending', step.done ? 'complete' : step.optional ? 'optional' : 'blocked')}
                            <button type="button" onClick={step.onClick} className="crm-btn-secondary px-3 py-1.5 text-xs">{step.action}</button>
                        </div>
                    </div>
                ))}
            </div>
        </PanelShell>
    );
}

function RouteCell({ onClick, rule, statusChip }) {
    if (!rule) {
        return (
            <button type="button" onClick={onClick} className="min-h-[84px] w-full rounded-md border border-dashed border-slate-200 bg-white px-2 py-2 text-left hover:border-teal-300 hover:bg-teal-50/30">
                <p className="text-xs font-semibold text-slate-500">No route</p>
                <p className="mt-1 text-[11px] text-slate-400">Click to configure</p>
            </button>
        );
    }

    const primary = rule.primary_profile;
    const fallback = rule.fallback_profile;
    const routeStatus = rule.enabled === false ? 'configured_disabled' : primary?.kill_switch_enabled ? 'pending' : primary ? 'connected' : rule.fallback_to_sms ? 'partial' : 'unknown';

    return (
        <button type="button" onClick={onClick} className="min-h-[84px] w-full rounded-md border border-slate-200 bg-white px-2 py-2 text-left hover:border-teal-300 hover:bg-teal-50/30">
            <div className="flex items-center justify-between gap-2">
                <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset ${statusChip(routeStatus)}`}>
                    {rule.enabled === false ? 'off' : primary ? engineLabel(primary.engine) : 'sms'}
                </span>
                <span className="text-[10px] font-semibold uppercase text-slate-400">{rule.fallback_to_sms ? 'SMS' : 'No SMS'}</span>
            </div>
            <p className="mt-1 truncate text-xs font-semibold text-slate-900">{primary?.profile_name || 'No WhatsApp route'}</p>
            <p className="mt-1 truncate text-[11px] text-slate-500">
                {fallback ? `Fallback: ${fallback.profile_name}` : rule.fallback_to_sms ? 'Fallback: SMS' : 'No fallback'}
            </p>
            <p className="mt-1 truncate text-[11px] text-slate-400">{primary?.kill_switch_enabled ? 'Kill switch active' : 'Last status available in Activity'}</p>
        </button>
    );
}

function RouteMatrix({ messageTypes, onEditRoute, platforms, routingRules, statusChip }) {
    const rulesByKey = useMemo(() => {
        const map = new Map();
        routingRules.forEach((rule) => map.set(`${rule.market_id}:${rule.message_type}`, rule));
        return map;
    }, [routingRules]);

    return (
        <PanelShell title="Route matrix" eyebrow="Primary WhatsApp route, fallback profile, and SMS escape path per market.">
            <div className="overflow-x-auto p-3">
                <table className="min-w-[980px] w-full border-separate border-spacing-2 text-sm">
                    <thead>
                        <tr>
                            <th className="w-44 px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">Market</th>
                            {messageTypes.map((type) => (
                                <th key={type} className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">{messageTypeLabel(type)}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {platforms.map((platform) => (
                            <tr key={platform.id}>
                                <td className="align-top">
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-3">
                                        <p className="text-sm font-semibold text-slate-900">{platform.name}</p>
                                        <p className="text-xs text-slate-500">{platform.country || 'Market'}</p>
                                    </div>
                                </td>
                                {messageTypes.map((type) => (
                                    <td key={`${platform.id}-${type}`} className="align-top">
                                        <RouteCell
                                            rule={rulesByKey.get(`${platform.id}:${type}`)}
                                            onClick={() => onEditRoute(platform.id, type)}
                                            statusChip={statusChip}
                                        />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!platforms.length ? (
                    <EmptyState title="No markets available" description="Create a market before configuring message routing." />
                ) : null}
            </div>
        </PanelShell>
    );
}

function RouteEditorDrawer({
    messageType,
    onClose,
    onSave,
    platforms,
    profiles,
    rule,
    saving,
    selectedMarketId,
}) {
    const [form, setForm] = useState({
        primary_profile_id: '',
        fallback_profile_id: '',
        fallback_to_sms: true,
        enabled: true,
    });

    useEffect(() => {
        setForm({
            primary_profile_id: rule?.primary_profile_id ? String(rule.primary_profile_id) : '',
            fallback_profile_id: rule?.fallback_profile_id ? String(rule.fallback_profile_id) : '',
            fallback_to_sms: rule?.fallback_to_sms ?? true,
            enabled: rule?.enabled ?? true,
        });
    }, [rule]);

    if (!selectedMarketId || !messageType) return null;

    const market = platforms.find((platform) => Number(platform.id) === Number(selectedMarketId));
    const marketProfiles = profiles.filter((profile) => Number(profile.market_id) === Number(selectedMarketId) && profile.active);

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/30">
            <aside className="flex h-full w-full max-w-xl flex-col bg-white shadow-xl">
                <header className="border-b border-slate-200 px-5 py-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase text-slate-500">Route editor</p>
                            <h3 className="mt-1 text-base font-semibold text-slate-950">{market?.name || 'Market'} · {messageTypeLabel(messageType)}</h3>
                        </div>
                        <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-xs">Close</button>
                    </div>
                </header>
                <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium text-slate-700">Primary WhatsApp profile</span>
                        <select value={form.primary_profile_id} onChange={(event) => setForm((current) => ({ ...current, primary_profile_id: event.target.value }))} className="crm-select w-full">
                            <option value="">No WhatsApp route</option>
                            {marketProfiles.map((profile) => (
                                <option key={profile.id} value={profile.id}>{engineLabel(profile.engine)} · {profile.profile_name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium text-slate-700">Fallback WhatsApp profile</span>
                        <select value={form.fallback_profile_id} onChange={(event) => setForm((current) => ({ ...current, fallback_profile_id: event.target.value }))} className="crm-select w-full">
                            <option value="">No WhatsApp fallback</option>
                            {marketProfiles.map((profile) => (
                                <option key={profile.id} value={profile.id} disabled={String(profile.id) === form.primary_profile_id}>
                                    {engineLabel(profile.engine)} · {profile.profile_name}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-xs text-slate-500">Runtime fallback tries the primary WhatsApp profile first, then this profile, then SMS when enabled.</p>
                    </label>
                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <p className="text-sm font-semibold text-slate-900">Fallback behavior</p>
                        <div className="mt-3 space-y-2">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" checked={form.fallback_to_sms} onChange={(event) => setForm((current) => ({ ...current, fallback_to_sms: event.target.checked }))} className="rounded border-slate-300" />
                                Send SMS if WhatsApp rejects or no WhatsApp route is configured
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" checked={form.enabled} onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))} className="rounded border-slate-300" />
                                Route enabled
                            </label>
                        </div>
                    </div>
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Keep SMS fallback enabled for high-risk operational sends while Baileys senders are warming up.
                    </div>
                </div>
                <footer className="border-t border-slate-200 px-5 py-4">
                    <button
                        type="button"
                        disabled={saving}
                        onClick={() => onSave({
                            marketId: selectedMarketId,
                            messageType,
                            payload: {
                                primary_profile_id: form.primary_profile_id ? Number(form.primary_profile_id) : null,
                                fallback_profile_id: form.fallback_profile_id ? Number(form.fallback_profile_id) : null,
                                fallback_to_sms: form.fallback_to_sms,
                                enabled: form.enabled,
                            },
                        })}
                        className="crm-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {saving ? 'Saving route...' : 'Save route'}
                    </button>
                </footer>
            </aside>
        </div>
    );
}

function SenderPoolPanel({ actionPending, baileysProfiles, isLoading, onLogout, onPair, onRepair, onRetire, onOpenSection, senders, statusChip }) {
    const [form, setForm] = useState({
        provider_profile_id: baileysProfiles[0]?.id ? String(baileysProfiles[0].id) : '',
        phone_e164: '',
        display_name: '',
    });

    useEffect(() => {
        if (!form.provider_profile_id && baileysProfiles[0]?.id) {
            setForm((current) => ({ ...current, provider_profile_id: String(baileysProfiles[0].id) }));
        }
    }, [baileysProfiles, form.provider_profile_id]);

    const connected = senders.filter((sender) => sender.connection_status === 'connected').length;
    const queueDepth = senders.reduce((total, sender) => total + Number(sender.queue_depth || 0), 0);
    const inFlight = senders.reduce((total, sender) => total + Number(sender.in_flight || 0), 0);

    const summaryRows = [
        { label: 'Sidecar', value: baileysProfiles.length ? 'Configured' : 'No profile', status: baileysProfiles.length ? 'pending' : 'unknown' },
        { label: 'Connected senders', value: connected, status: connected ? 'connected' : 'unknown' },
        { label: 'Queue depth', value: queueDepth, status: queueDepth ? 'pending' : 'connected' },
        { label: 'In-flight', value: inFlight, status: inFlight ? 'pending' : 'connected' },
    ];

    const submitPair = (event) => {
        event.preventDefault();
        if (!form.provider_profile_id || !form.phone_e164.trim()) return;
        onPair({
            profileId: form.provider_profile_id,
            payload: {
                phone_e164: form.phone_e164,
                display_name: form.display_name || null,
            },
        });
        setForm((current) => ({ ...current, phone_e164: '', display_name: '' }));
    };

    return (
        <PanelShell title="Baileys sender pool" eyebrow="Pair, monitor, repair, and retire WhatsApp Web senders.">
            <div className="grid gap-3 p-4 md:grid-cols-4">
                {summaryRows.map((row) => (
                    <div key={row.label} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <p className="text-xs font-semibold uppercase text-slate-500">{row.label}</p>
                        <div className="mt-2 flex items-center justify-between gap-2">
                            <p className="text-lg font-semibold text-slate-950">{row.value}</p>
                            {chip(statusChip, row.status, row.status)}
                        </div>
                    </div>
                ))}
            </div>
            <form onSubmit={submitPair} className="grid gap-3 border-t border-slate-200 p-4 lg:grid-cols-[minmax(0,1fr)_180px_160px]">
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Baileys profile</span>
                    <select value={form.provider_profile_id} onChange={(event) => setForm((current) => ({ ...current, provider_profile_id: event.target.value }))} className="crm-select w-full">
                        <option value="">Select profile</option>
                        {baileysProfiles.map((profile) => (
                            <option key={profile.id} value={profile.id}>{profile.profile_name}</option>
                        ))}
                    </select>
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Sender phone</span>
                    <input value={form.phone_e164} onChange={(event) => setForm((current) => ({ ...current, phone_e164: event.target.value }))} className="crm-input w-full" placeholder="2547..." />
                </label>
                <label className="block">
                    <span className="mb-1 block text-xs font-semibold uppercase text-slate-500">Display name</span>
                    <input value={form.display_name} onChange={(event) => setForm((current) => ({ ...current, display_name: event.target.value }))} className="crm-input w-full" placeholder="Support" />
                </label>
                <div className="lg:col-span-3">
                    <button type="submit" disabled={!form.provider_profile_id || !form.phone_e164.trim() || actionPending} className="crm-btn-primary px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60">
                        Pair sender
                    </button>
                </div>
            </form>
            <div className="border-t border-slate-200">
                {isLoading ? (
                    <div className="px-4 py-8 text-center text-sm text-slate-500">Loading sender pool...</div>
                ) : senders.length ? (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Sender</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Warmup</th>
                                    <th className="px-4 py-3">Daily</th>
                                    <th className="px-4 py-3">Queue</th>
                                    <th className="px-4 py-3">Last message</th>
                                    <th className="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {senders.map((sender) => (
                                    <tr key={sender.id}>
                                        <td className="px-4 py-3">
                                            <p className="font-semibold text-slate-900">{sender.display_name || sender.phone_e164}</p>
                                            <p className="text-xs text-slate-500">{sender.phone_e164} · {sender.profile?.profile_name || 'Profile removed'}</p>
                                        </td>
                                        <td className="px-4 py-3">{chip(statusChip, sender.connection_status === 'connected' ? 'connected' : sender.retired_at ? 'configured_disabled' : 'pending', sender.connection_status)}</td>
                                        <td className="px-4 py-3 text-slate-700">{String(sender.warmup_phase || '').replaceAll('_', ' ')}</td>
                                        <td className="px-4 py-3 text-slate-700">{sender.daily_sent_count}/{sender.daily_limit}</td>
                                        <td className="px-4 py-3 text-slate-700">{sender.queue_depth || 0} queued · {sender.in_flight || 0} live</td>
                                        <td className="px-4 py-3 text-slate-500">{timeAgo(sender.last_message_at)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <button type="button" onClick={() => onRepair(sender)} disabled={actionPending || sender.retired_at} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">Repair</button>
                                                <button type="button" onClick={() => onLogout(sender)} disabled={actionPending || sender.retired_at} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">Logout</button>
                                                <button type="button" onClick={() => onRetire(sender)} disabled={actionPending || sender.retired_at} className="rounded-md border border-rose-200 px-2 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">Retire</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        title="No Baileys senders paired"
                        description="Create a Baileys profile, add a sender phone, then complete pairing from the sidecar session flow before enabling Baileys routing."
                        action={<button type="button" onClick={() => onOpenSection('diagnostics')} className="crm-btn-secondary px-3 py-2 text-sm">View diagnostics</button>}
                    />
                )}
            </div>
        </PanelShell>
    );
}

function TestConsole({ lastResult, onSend, platforms, profiles, routingRules, sending, statusChip }) {
    const [form, setForm] = useState({
        platform_id: platforms[0]?.id ? String(platforms[0].id) : '',
        message_type: 'transactional',
        channel_preference: 'whatsapp_with_sms_fallback',
        phone: '',
        body: 'Exotic CRM route test message.',
    });

    useEffect(() => {
        if (!form.platform_id && platforms[0]?.id) {
            setForm((current) => ({ ...current, platform_id: String(platforms[0].id) }));
        }
    }, [form.platform_id, platforms]);

    const selectedRoute = routingRules.find((rule) => Number(rule.market_id) === Number(form.platform_id) && rule.message_type === form.message_type);
    const primary = selectedRoute?.primary_profile || profiles.find((profile) => Number(profile.id) === Number(selectedRoute?.primary_profile_id));
    const fallback = selectedRoute?.fallback_profile || profiles.find((profile) => Number(profile.id) === Number(selectedRoute?.fallback_profile_id));
    const suppressionState = form.phone.trim() ? 'Checked at dispatch' : 'Enter phone to evaluate';
    const killSwitchState = primary?.kill_switch_enabled ? 'Primary blocked' : primary ? 'Clear' : 'No primary route';

    const canSend = form.platform_id && form.phone.trim() && form.body.trim();

    return (
        <PanelShell title="Test console" eyebrow="Preview route behavior before sending an operational test.">
            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <div className="space-y-3 xl:col-span-5">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Market</span>
                        <select value={form.platform_id} onChange={(event) => setForm((current) => ({ ...current, platform_id: event.target.value }))} className="crm-select w-full">
                            {platforms.map((platform) => <option key={platform.id} value={platform.id}>{platform.name}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Message type</span>
                        <select value={form.message_type} onChange={(event) => setForm((current) => ({ ...current, message_type: event.target.value }))} className="crm-select w-full">
                            {cockpitMessageTypes.map((type) => <option key={type} value={type}>{messageTypeLabel(type)}</option>)}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Channel preference</span>
                        <select value={form.channel_preference} onChange={(event) => setForm((current) => ({ ...current, channel_preference: event.target.value }))} className="crm-select w-full">
                            <option value="whatsapp_with_sms_fallback">WhatsApp with SMS fallback</option>
                            <option value="whatsapp">WhatsApp only</option>
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Recipient phone</span>
                        <input value={form.phone} onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))} className="crm-input" placeholder="254748612016" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Sample body</span>
                        <textarea rows={4} value={form.body} onChange={(event) => setForm((current) => ({ ...current, body: event.target.value }))} className="crm-input" />
                    </label>
                    <button
                        type="button"
                        disabled={sending || !canSend}
                        onClick={() => onSend({
                            platform_id: Number(form.platform_id),
                            phone: form.phone,
                            body: form.body,
                            message_type: form.message_type,
                            channel_preference: form.channel_preference,
                        })}
                        className="crm-btn-primary w-full justify-center disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {sending ? 'Sending test...' : 'Send route test'}
                    </button>
                </div>
                <div className="space-y-3 xl:col-span-7">
                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <h5 className="text-sm font-semibold text-slate-900">Route preview</h5>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {[
                                ['Primary', primary ? `${engineLabel(primary.engine)} · ${primary.profile_name}` : 'No WhatsApp route'],
                                ['Fallback', fallback ? `${engineLabel(fallback.engine)} · ${fallback.profile_name}` : selectedRoute?.fallback_to_sms ? 'SMS fallback' : 'No fallback'],
                                ['Suppression', suppressionState],
                                ['Kill switch', killSwitchState],
                            ].map(([label, value]) => (
                                <div key={label} className="rounded-md border border-slate-200 bg-white px-3 py-2">
                                    <p className="text-[11px] font-semibold uppercase text-slate-500">{label}</p>
                                    <p className="mt-1 text-sm font-medium text-slate-900">{value}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                    {lastResult ? (
                        <div className="rounded-md border border-slate-200 bg-white p-3">
                            <div className="flex items-center justify-between gap-2">
                                <h5 className="text-sm font-semibold text-slate-900">Latest result</h5>
                                {chip(statusChip, lastResult.success ? 'success' : 'failed', lastResult.status || (lastResult.success ? 'success' : 'failed'))}
                            </div>
                            <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                <p><span className="font-semibold text-slate-800">Channel:</span> {lastResult.channel || 'unknown'}</p>
                                <p><span className="font-semibold text-slate-800">WhatsApp ID:</span> {lastResult.whatsapp_message_id || 'none'}</p>
                                <p><span className="font-semibold text-slate-800">Fallback:</span> {lastResult.fallback_attempted ? 'attempted' : 'not used'}</p>
                                <p><span className="font-semibold text-slate-800">Error:</span> {lastResult.error_code || 'none'}</p>
                            </div>
                            {lastResult.error_message ? <p className="mt-2 text-xs text-rose-700">{lastResult.error_message}</p> : null}
                        </div>
                    ) : (
                        <div className="rounded-md border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                            Send a controlled test to see provider response, fallback behavior, and linked WhatsApp message id.
                        </div>
                    )}
                </div>
            </div>
        </PanelShell>
    );
}

function DiagnosticsPanel({ messages, profiles, routingRules, stats, statusChip }) {
    const metaProfiles = profiles.filter((profile) => profile.engine === 'meta_cloud_api');
    const lastWebhookLike = messages.find((message) => message.direction === 'inbound' || ['delivered', 'read', 'failed'].includes(message.status));
    const lastFailed = messages.find((message) => ['failed', 'rejected', 'suppressed'].includes(message.status));
    const diagnostics = [
        { label: 'Meta webhook', value: metaProfiles.some((profile) => profile.meta_webhook_verify_token_configured && profile.meta_app_secret_configured) ? 'Configured' : 'Incomplete', status: metaProfiles.some((profile) => profile.meta_webhook_verify_token_configured && profile.meta_app_secret_configured) ? 'connected' : 'pending' },
        { label: 'Baileys sidecar', value: 'Not deployed', status: 'unknown' },
        { label: 'HMAC clock skew', value: 'Pending sidecar', status: 'unknown' },
        { label: 'Restore window', value: 'Inactive', status: 'unknown' },
        { label: 'Auth blob rate limit', value: 'Pending sidecar', status: 'unknown' },
        { label: 'Queue workers', value: 'Laravel queue active', status: 'pending' },
        { label: 'Last webhook/status', value: lastWebhookLike ? timeAgo(lastWebhookLike.created_at) : 'Never', status: lastWebhookLike ? 'connected' : 'unknown' },
        { label: 'Last provider error', value: lastFailed?.error_code || 'None', status: lastFailed ? 'failed' : 'connected' },
    ];

    return (
        <PanelShell title="Diagnostics" eyebrow="Operational checks that explain why a route can or cannot send.">
            <div className="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4">
                {diagnostics.map((item) => (
                    <div key={item.label} className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-semibold uppercase text-slate-500">{item.label}</p>
                            {chip(statusChip, item.status, item.status)}
                        </div>
                        <p className="mt-2 text-sm font-medium text-slate-900">{item.value}</p>
                    </div>
                ))}
            </div>
            <div className="border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                {stats?.failed_messages_24h || 0} failed outbound messages in the last 24 hours. {routingRules.length} route records configured.
            </div>
        </PanelShell>
    );
}

function ActivityPanel({ messages, profiles, routingRules, statusChip }) {
    const events = [
        ...profiles.slice(0, 6).map((profile) => ({
            id: `profile-${profile.id}`,
            label: `${engineLabel(profile.engine)} profile ${profile.profile_name}`,
            detail: profile.tested_at ? 'Profile tested and ready for controlled sends.' : 'Profile configured; test send still pending.',
            status: profile.tested_at ? 'success' : 'pending',
            at: profile.updated_at || profile.created_at,
        })),
        ...routingRules.slice(0, 6).map((rule) => ({
            id: `route-${rule.id}`,
            label: `Route updated for ${messageTypeLabel(rule.message_type)}`,
            detail: rule.primary_profile ? `${engineLabel(rule.primary_profile.engine)} · ${rule.primary_profile.profile_name}` : rule.fallback_to_sms ? 'No WhatsApp profile; SMS fallback allowed.' : 'Route has no active profile.',
            status: rule.enabled === false ? 'configured_disabled' : rule.primary_profile ? 'connected' : 'pending',
            at: rule.updated_at,
        })),
        ...messages.slice(0, 8).map((message) => ({
            id: `message-${message.id}`,
            label: `${engineLabel(message.engine)} ${message.direction} message`,
            detail: `${message.status || 'unknown'} · ${message.platform?.name || 'Global'} · ${message.phone_e164 || ''}`,
            status: ['sent', 'delivered', 'read'].includes(message.status) ? 'success' : ['failed', 'rejected', 'suppressed'].includes(message.status) ? 'failed' : 'pending',
            at: message.created_at,
        })),
    ].sort((a, b) => new Date(b.at || 0).getTime() - new Date(a.at || 0).getTime()).slice(0, 12);

    return (
        <PanelShell title="Messaging activity" eyebrow="Recent messaging configuration and delivery signals in one place.">
            <div className="divide-y divide-slate-100">
                {events.map((event) => (
                    <div key={event.id} className="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{event.label}</p>
                            <p className="mt-1 text-xs text-slate-500">{event.detail}</p>
                        </div>
                        <div className="flex items-center gap-2">
                            {chip(statusChip, event.status, event.status)}
                            <span className="text-xs text-slate-400">{timeAgo(event.at)}</span>
                        </div>
                    </div>
                ))}
                {!events.length ? (
                    <EmptyState title="No messaging activity yet" description="Profile changes, route edits, test sends, suppressions, and delivery status updates will appear here." />
                ) : null}
            </div>
        </PanelShell>
    );
}

export default function MessagingArea({ platformRows, statusChip, toast }) {
    const queryClient = useQueryClient();
    const platforms = useMemo(() => platformRows.map((platform) => ({
        id: platform.platform_id,
        name: platform.platform_name,
        country: platform.country,
    })), [platformRows]);

    const [activeSection, setActiveSection] = useState('overview');
    const [profileFormOpen, setProfileFormOpen] = useState(false);
    const [editingProfile, setEditingProfile] = useState(null);
    const [testingProfile, setTestingProfile] = useState(null);
    const [routeEditor, setRouteEditor] = useState(null);
    const [latestTestResult, setLatestTestResult] = useState(null);

    const profilesQuery = useQuery({
        queryKey: ['messaging-profiles'],
        queryFn: () => api.get('/crm/messaging/whatsapp/profiles').then((response) => response.data),
    });

    const messagesQuery = useQuery({
        queryKey: ['messaging-messages-overview'],
        queryFn: () => api.get('/crm/messaging/messages?per_page=12').then((response) => response.data),
    });

    const sendersQuery = useQuery({
        queryKey: ['messaging-whatsapp-senders'],
        queryFn: () => api.get('/crm/messaging/whatsapp/senders').then((response) => response.data),
    });

    const profiles = profilesQuery.data?.profiles || [];
    const baileysProfiles = profiles.filter((profile) => profile.engine === 'baileys');
    const routingRules = profilesQuery.data?.routing_rules || [];
    const stats = profilesQuery.data?.stats || {};
    const messageTypes = cockpitMessageTypes.filter((type) => (profilesQuery.data?.message_types || cockpitMessageTypes).includes(type));
    const messages = messagesQuery.data?.data || [];
    const senders = sendersQuery.data || [];

    const invalidateMessaging = () => {
        queryClient.invalidateQueries({ queryKey: ['messaging-profiles'] });
        queryClient.invalidateQueries({ queryKey: ['messaging-routing'] });
        queryClient.invalidateQueries({ queryKey: ['messaging-messages-overview'] });
        queryClient.invalidateQueries({ queryKey: ['messaging-whatsapp-senders'] });
    };

    const saveProfileMutation = useMutation({
        mutationFn: ({ profile, form }) => {
            const payload = Object.fromEntries(Object.entries({
                ...form,
                market_id: Number(form.market_id),
                meta_access_token: form.meta_access_token || undefined,
                meta_webhook_verify_token: form.meta_webhook_verify_token || undefined,
                meta_app_secret: form.meta_app_secret || undefined,
            }).filter(([, value]) => value !== undefined));

            if (profile) {
                return api.put(`/crm/messaging/whatsapp/profiles/${profile.id}`, payload).then((response) => response.data);
            }

            return api.post('/crm/messaging/whatsapp/profiles', payload).then((response) => response.data);
        },
        onSuccess: () => {
            toast?.success?.('WhatsApp profile saved.');
            setProfileFormOpen(false);
            setEditingProfile(null);
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not save WhatsApp profile.'),
    });

    const killSwitchMutation = useMutation({
        mutationFn: ({ profile, enabled }) => api.post(`/crm/messaging/whatsapp/profiles/${profile.id}/kill-switch`, { enabled }).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Kill switch updated.');
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not update kill switch.'),
    });

    const testProfileMutation = useMutation({
        mutationFn: ({ profile, form }) => api.post(`/crm/messaging/whatsapp/profiles/${profile.id}/test`, {
            ...form,
            template_name: form.template_name || null,
        }).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('WhatsApp test sent.');
            setTestingProfile(null);
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'WhatsApp test failed.'),
    });

    const routeTestMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/messaging/test/send', payload).then((response) => response.data),
        onSuccess: (data) => {
            setLatestTestResult(data);
            toast?.success?.('Route test completed.');
            invalidateMessaging();
        },
        onError: (error) => {
            const payload = error.response?.data || {};
            setLatestTestResult(payload);
            toast?.error?.(payload.message || payload.error_message || 'Route test failed.');
            invalidateMessaging();
        },
    });

    const saveRoutingMutation = useMutation({
        mutationFn: ({ marketId, messageType, payload }) => api.put(`/crm/messaging/whatsapp/routing/${marketId}/${messageType}`, payload).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Messaging route updated.');
            setRouteEditor(null);
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not update messaging route.'),
    });

    const pairSenderMutation = useMutation({
        mutationFn: ({ profileId, payload }) => api.post(`/crm/messaging/whatsapp/profiles/${profileId}/senders`, payload).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Sender added for pairing.');
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not add sender.'),
    });

    const senderActionMutation = useMutation({
        mutationFn: ({ sender, action, payload = {} }) => api.post(`/crm/messaging/whatsapp/senders/${sender.id}/${action}`, payload).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Sender updated.');
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not update sender.'),
    });

    const openNewProfile = () => {
        setEditingProfile(null);
        setProfileFormOpen(true);
    };

    const selectedRule = routeEditor
        ? routingRules.find((rule) => Number(rule.market_id) === Number(routeEditor.marketId) && rule.message_type === routeEditor.messageType)
        : null;

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Messaging Gateway</h3>
                    <p className="crm-panel-subtitle">Operate WhatsApp, Baileys readiness, SMS fallback, routing, tests, suppressions, and delivery diagnostics from one cockpit.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => { profilesQuery.refetch(); messagesQuery.refetch(); }} className="crm-btn-secondary px-3 py-2">Refresh</button>
                    <button type="button" onClick={openNewProfile} className="crm-btn-primary">New profile</button>
                </div>
            </header>

            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2">
                <div className="flex gap-2 overflow-x-auto">
                    {cockpitSections.map((section) => (
                        <button
                            key={section.id}
                            type="button"
                            onClick={() => setActiveSection(section.id)}
                            className={`shrink-0 rounded-md px-3 py-2 text-sm font-semibold ${activeSection === section.id ? 'bg-white text-slate-950 shadow-sm ring-1 ring-slate-200' : 'text-slate-600 hover:bg-white/70 hover:text-slate-900'}`}
                        >
                            {section.label}
                        </button>
                    ))}
                </div>
            </div>

            {profilesQuery.isError ? (
                <div className="m-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    Messaging configuration could not be loaded. Refresh the page or check the API logs before changing routing.
                </div>
            ) : null}

            <OperationalHeader
                platforms={platforms}
                profiles={profiles}
                routingRules={routingRules}
                stats={stats}
                statusChip={statusChip}
            />

            <div className="space-y-4 p-4">
                {activeSection === 'overview' ? (
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
                        <RouteMatrix
                            messageTypes={messageTypes}
                            onEditRoute={(marketId, messageType) => setRouteEditor({ marketId, messageType })}
                            platforms={platforms}
                            routingRules={routingRules}
                            statusChip={statusChip}
                        />
                        <ReadinessChecklist
                            onCreateProfile={openNewProfile}
                            onOpenSection={setActiveSection}
                            profiles={profiles}
                            routingRules={routingRules}
                            stats={stats}
                            statusChip={statusChip}
                        />
                    </div>
                ) : null}

                {activeSection === 'routes' ? (
                    <RouteMatrix
                        messageTypes={messageTypes}
                        onEditRoute={(marketId, messageType) => setRouteEditor({ marketId, messageType })}
                        platforms={platforms}
                        routingRules={routingRules}
                        statusChip={statusChip}
                    />
                ) : null}

                {activeSection === 'profiles' ? (
                    <WhatsAppProfilesTable
                        isLoading={profilesQuery.isLoading}
                        onCreate={openNewProfile}
                        onEdit={(profile) => {
                            setEditingProfile(profile);
                            setProfileFormOpen(true);
                        }}
                        onTest={setTestingProfile}
                        onToggleKillSwitch={(profile, enabled) => killSwitchMutation.mutate({ profile, enabled })}
                        profiles={profiles}
                        statusChip={statusChip}
                        toggling={killSwitchMutation.isPending}
                    />
                ) : null}

                {activeSection === 'senders' ? (
                    <SenderPoolPanel
                        actionPending={pairSenderMutation.isPending || senderActionMutation.isPending}
                        baileysProfiles={baileysProfiles}
                        isLoading={sendersQuery.isLoading}
                        onLogout={(sender) => senderActionMutation.mutate({ sender, action: 'logout' })}
                        onOpenSection={setActiveSection}
                        onPair={(payload) => pairSenderMutation.mutate(payload)}
                        onRepair={(sender) => senderActionMutation.mutate({ sender, action: 'repair' })}
                        onRetire={(sender) => {
                            if (window.confirm(`Retire sender ${sender.phone_e164}? This releases the phone for re-pairing but disables this sender record.`)) {
                                senderActionMutation.mutate({ sender, action: 'retire', payload: { confirmation: 'RETIRE', reason: 'admin_retired' } });
                            }
                        }}
                        senders={senders}
                        statusChip={statusChip}
                    />
                ) : null}

                {activeSection === 'test' ? (
                    <TestConsole
                        lastResult={latestTestResult}
                        onSend={(payload) => routeTestMutation.mutate(payload)}
                        platforms={platforms}
                        profiles={profiles}
                        routingRules={routingRules}
                        sending={routeTestMutation.isPending}
                        statusChip={statusChip}
                    />
                ) : null}

                {activeSection === 'suppressions' ? (
                    <SuppressionsCard statusChip={statusChip} toast={toast} />
                ) : null}

                {activeSection === 'diagnostics' ? (
                    <DiagnosticsPanel
                        messages={messages}
                        profiles={profiles}
                        routingRules={routingRules}
                        stats={stats}
                        statusChip={statusChip}
                    />
                ) : null}

                {activeSection === 'activity' ? (
                    <ActivityPanel
                        messages={messages}
                        profiles={profiles}
                        routingRules={routingRules}
                        statusChip={statusChip}
                    />
                ) : null}
            </div>

            {profileFormOpen ? (
                <WhatsAppProfileForm
                    isSaving={saveProfileMutation.isPending}
                    onClose={() => {
                        setProfileFormOpen(false);
                        setEditingProfile(null);
                    }}
                    onSave={(profile, form) => saveProfileMutation.mutate({ profile, form })}
                    platforms={platforms}
                    profile={editingProfile}
                />
            ) : null}

            <TestSendModal
                isSending={testProfileMutation.isPending}
                onClose={() => setTestingProfile(null)}
                onSend={(profile, form) => testProfileMutation.mutate({ profile, form })}
                profile={testingProfile}
            />

            <RouteEditorDrawer
                messageType={routeEditor?.messageType}
                onClose={() => setRouteEditor(null)}
                onSave={(payload) => saveRoutingMutation.mutate(payload)}
                platforms={platforms}
                profiles={profiles}
                rule={selectedRule}
                saving={saveRoutingMutation.isPending}
                selectedMarketId={routeEditor?.marketId}
            />
        </section>
    );
}
