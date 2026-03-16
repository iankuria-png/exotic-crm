import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';

function statusChip(status) {
    if (['connected', 'healthy', 'success', 'complete'].includes(status)) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (['configured_disabled', 'partial', 'degraded', 'pending', 'stale', 'missing', 'skipped'].includes(status)) {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function formatDateTime(value) {
    if (!value) return 'Never';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Never';
    return date.toLocaleString();
}

function extractProfileCount(latestResult, platform) {
    const candidates = [
        latestResult?.stats?.total_profiles,
        latestResult?.stats?.profiles_total,
        latestResult?.stats?.profiles?.total,
        latestResult?.stats?.counts?.profiles,
        latestResult?.stats?.data?.total_profiles,
        latestResult?.stats?.total,
        platform?.sync?.last_result?.clients?.total,
    ];

    const match = candidates.find((candidate) => Number.isFinite(Number(candidate)));
    return match !== undefined ? Number(match) : null;
}

function CheckRow({ label, ok, detail }) {
    return (
        <div className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2">
            <div>
                <p className="text-sm font-medium text-slate-900">{label}</p>
                {detail ? <p className="text-xs text-slate-500">{detail}</p> : null}
            </div>
            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(ok ? 'healthy' : 'error')}`}>
                {ok ? 'Pass' : 'Fail'}
            </span>
        </div>
    );
}

export default function SystemHealthWorkspace({
    canCreateMarkets,
    canManageMarkets,
    canManageSms,
    onOpenMarketSetup,
}) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [baseline, setBaseline] = useState({
        mode: 'fresh_start',
        cutoff_date: new Date().toISOString().slice(0, 10),
    });
    const [platformResults, setPlatformResults] = useState({});
    const [testingPlatformId, setTestingPlatformId] = useState(null);
    const [syncingPlatformId, setSyncingPlatformId] = useState(null);
    const [copiedCron, setCopiedCron] = useState(false);
    const [smsTestForm, setSmsTestForm] = useState({
        phone: '',
        message: 'This is a test message from ExoticCRM System Health.',
        reason: 'System Health SMS test',
    });

    const settingsQuery = useQuery({
        queryKey: ['settings-integrations'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const envQuery = useQuery({
        queryKey: ['setup-check-env'],
        queryFn: () => api.post('/crm/setup/check-env').then((response) => response.data),
    });

    const databaseQuery = useQuery({
        queryKey: ['setup-check-database'],
        queryFn: () => api.post('/crm/setup/check-database').then((response) => response.data),
    });

    const diagnosticsQuery = useQuery({
        queryKey: ['setup-diagnostics-global'],
        queryFn: () => api.post('/crm/setup/run-diagnostics').then((response) => response.data),
    });

    useEffect(() => {
        if (diagnosticsQuery.data?.data_baseline) {
            setBaseline(diagnosticsQuery.data.data_baseline);
        }
    }, [diagnosticsQuery.data]);

    const platformRows = settingsQuery.data?.platforms || [];
    const smsGateway = diagnosticsQuery.data?.sms || null;
    const paymentProxy = diagnosticsQuery.data?.payment_proxy || null;
    const scheduler = diagnosticsQuery.data?.scheduler || null;

    const platformTestMutation = useMutation({
        mutationFn: (platformId) => api.post(`/crm/settings/integrations/platforms/${platformId}/test-connection`, {
            reason: 'System Health market connection test',
        }).then((response) => response.data),
        onSuccess: (data, platformId) => {
            setPlatformResults((current) => ({
                ...current,
                [platformId]: {
                    ...(current[platformId] || {}),
                    test: data,
                },
            }));
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            toast.success('Market connection test passed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Market connection test failed.');
        },
        onSettled: () => {
            setTestingPlatformId(null);
        },
    });

    const platformSyncMutation = useMutation({
        mutationFn: (platformId) => api.post(`/crm/settings/integrations/platforms/${platformId}/sync`, {
            scope: 'clients',
            mode: 'full',
            per_page: 100,
            reason: 'System Health manual sync',
        }).then((response) => response.data),
        onSuccess: (data, platformId) => {
            setPlatformResults((current) => ({
                ...current,
                [platformId]: {
                    ...(current[platformId] || {}),
                    sync: data,
                },
            }));
            queryClient.invalidateQueries({ queryKey: ['settings-integrations'] });
            toast.success(data?.status === 'partial' ? 'Sync completed with warnings.' : 'Market sync completed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Market sync failed.');
        },
        onSettled: () => {
            setSyncingPlatformId(null);
        },
    });

    const smsTestMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/integrations/sms-provider/test', payload).then((response) => response.data),
        onSuccess: () => {
            diagnosticsQuery.refetch();
            toast.success('SMS test dispatched.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'SMS test failed.');
        },
    });

    const baselineSaveMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/setup/complete', payload).then((response) => response.data),
        onSuccess: (data) => {
            if (data?.data_baseline) {
                setBaseline(data.data_baseline);
            }
            diagnosticsQuery.refetch();
            toast.success('Data baseline saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to save the data baseline.');
        },
    });

    const loading = settingsQuery.isLoading || envQuery.isLoading || databaseQuery.isLoading || diagnosticsQuery.isLoading;

    const environmentChecks = envQuery.data?.checks || {};
    const database = databaseQuery.data || {};

    const cards = useMemo(() => ([
        {
            key: 'payment_proxy',
            label: 'Payment Proxy',
            status: paymentProxy?.status || 'pending',
            content: (
                <div className="space-y-2 text-sm text-slate-600">
                    <p>Base URL: <span className="font-medium text-slate-900">{paymentProxy?.base_url || 'Not configured'}</span></p>
                    <p>Message: <span className="font-medium text-slate-900">{paymentProxy?.message || 'No diagnostic available yet.'}</span></p>
                    {'http_status' in (paymentProxy || {}) ? <p>HTTP status: <span className="font-medium text-slate-900">{paymentProxy?.http_status}</span></p> : null}
                </div>
            ),
        },
        {
            key: 'scheduler',
            label: 'Cron Heartbeat',
            status: scheduler?.status || 'missing',
            content: (
                <div className="space-y-3 text-sm text-slate-600">
                    <p>Last heartbeat: <span className="font-medium text-slate-900">{formatDateTime(scheduler?.last_ran_at)}</span></p>
                    <p>{scheduler?.message || 'No scheduler heartbeat recorded yet.'}</p>
                    <div className="rounded-lg bg-slate-950 p-3">
                        <p className="break-all font-mono text-xs text-emerald-200">{scheduler?.cron_command}</p>
                    </div>
                    <button
                        type="button"
                        onClick={async () => {
                            try {
                                await navigator.clipboard.writeText(scheduler?.cron_command || '');
                                setCopiedCron(true);
                                toast.success('Cron command copied.');
                                window.setTimeout(() => setCopiedCron(false), 1800);
                            } catch {
                                toast.error('Unable to copy the cron command.');
                            }
                        }}
                        className="crm-btn-secondary px-3 py-2"
                    >
                        {copiedCron ? 'Copied' : 'Copy cron command'}
                    </button>
                </div>
            ),
        },
    ]), [copiedCron, paymentProxy, scheduler, toast]);

    if (loading) {
        return (
            <div className="crm-surface p-6 text-center">
                <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
                <p className="mt-4 text-sm text-slate-600">Loading system diagnostics...</p>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <section className="grid gap-4 xl:grid-cols-2">
                <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">Environment</h3>
                            <p className="text-xs text-slate-500">The same prerequisites the setup wizard checks before first launch.</p>
                        </div>
                        <button type="button" onClick={() => envQuery.refetch()} className="crm-btn-secondary px-3 py-2">
                            Refresh
                        </button>
                    </div>
                    <div className="space-y-2 p-4">
                        <CheckRow label="PHP version" ok={environmentChecks.php_version?.ok} detail={`Actual: ${environmentChecks.php_version?.actual || 'Unknown'}`} />
                        <CheckRow label="Required extensions" ok={environmentChecks.extensions?.ok} detail={environmentChecks.extensions?.missing?.length ? `Missing: ${environmentChecks.extensions.missing.join(', ')}` : 'All required extensions are loaded.'} />
                        <CheckRow label="Storage & cache writable" ok={environmentChecks.storage_writable?.ok} detail="Checks storage/ and bootstrap/cache permissions." />
                        <CheckRow label="APP_KEY configured" ok={environmentChecks.app_key?.ok} detail="Encryption key must be present before launch." />
                    </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">Database</h3>
                            <p className="text-xs text-slate-500">Connection health, pending migrations, and current table count.</p>
                        </div>
                        <button type="button" onClick={() => databaseQuery.refetch()} className="crm-btn-secondary px-3 py-2">
                            Refresh
                        </button>
                    </div>
                    <div className="grid gap-3 p-4 sm:grid-cols-3">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Connection</p>
                            <p className="mt-2 text-lg font-semibold text-slate-900">{database.connected ? 'Connected' : 'Offline'}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Pending migrations</p>
                            <p className="mt-2 text-lg font-semibold text-slate-900">{database.pending_migrations ?? 'Unknown'}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Table count</p>
                            <p className="mt-2 text-lg font-semibold text-slate-900">{database.table_count ?? 0}</p>
                        </div>
                    </div>
                    {database.error ? (
                        <p className="border-t border-slate-100 px-4 py-3 text-sm text-rose-700">{database.error}</p>
                    ) : null}
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                        <div>
                            <h3 className="text-base font-semibold text-slate-900">SMS Gateway</h3>
                            <p className="text-xs text-slate-500">Current routing status plus an optional live test dispatch.</p>
                        </div>
                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(smsGateway?.status || 'pending')}`}>
                            {(smsGateway?.status || 'pending').replaceAll('_', ' ')}
                        </span>
                    </div>
                    <div className="space-y-3 p-4 text-sm text-slate-600">
                        <p>Active provider: <span className="font-medium text-slate-900">{smsGateway?.active_provider || 'Not configured'}</span></p>
                        <p>Dispatch: <span className="font-medium text-slate-900">{smsGateway?.enabled ? 'Enabled' : 'Disabled'}</span></p>
                        {canManageSms ? (
                            <>
                                <input
                                    value={smsTestForm.phone}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, phone: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Test phone number"
                                />
                                <textarea
                                    rows={3}
                                    value={smsTestForm.message}
                                    onChange={(event) => setSmsTestForm((current) => ({ ...current, message: event.target.value }))}
                                    className="crm-input"
                                    placeholder="Test message"
                                />
                                <button
                                    type="button"
                                    onClick={() => smsTestMutation.mutate(smsTestForm)}
                                    disabled={smsTestMutation.isPending || !smsTestForm.phone.trim() || !smsTestForm.message.trim()}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {smsTestMutation.isPending ? 'Sending test...' : 'Send SMS test'}
                                </button>
                            </>
                        ) : (
                            <p className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                Admin access is required for live SMS tests.
                            </p>
                        )}
                    </div>
                </div>

                {cards.map((card) => (
                    <div key={card.key} className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">{card.label}</h3>
                            </div>
                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(card.status)}`}>
                                {card.status.replaceAll('_', ' ')}
                            </span>
                        </div>
                        <div className="p-4">{card.content}</div>
                    </div>
                ))}
            </section>

            <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h3 className="text-base font-semibold text-slate-900">Data Baseline</h3>
                        <p className="text-xs text-slate-500">Switch between fresh-start reporting and legacy shared-database history.</p>
                    </div>
                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(baseline.mode === 'include_legacy' ? 'healthy' : 'pending')}`}>
                        {baseline.mode === 'include_legacy' ? 'Include legacy' : 'Fresh start'}
                    </span>
                </div>
                <div className="grid gap-4 p-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div className="space-y-4">
                        <div className="flex flex-wrap gap-3">
                            {[
                                ['fresh_start', 'Fresh Start'],
                                ['include_legacy', 'Include Legacy'],
                            ].map(([value, label]) => (
                                <label key={value} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="settings-baseline-mode"
                                        value={value}
                                        checked={baseline.mode === value}
                                        onChange={() => setBaseline((current) => ({ ...current, mode: value }))}
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        <div className="max-w-xs">
                            <label className="mb-1 block text-sm font-medium text-slate-700">Cutoff date</label>
                            <input
                                type="date"
                                value={baseline.cutoff_date}
                                onChange={(event) => setBaseline((current) => ({ ...current, cutoff_date: event.target.value }))}
                                className="crm-input"
                                disabled={baseline.mode !== 'fresh_start'}
                            />
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => baselineSaveMutation.mutate({ data_baseline: baseline })}
                        disabled={baselineSaveMutation.isPending}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {baselineSaveMutation.isPending ? 'Saving...' : 'Save baseline'}
                    </button>
                </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h3 className="text-base font-semibold text-slate-900">Markets</h3>
                        <p className="text-xs text-slate-500">Per-market WordPress status, last sync state, and quick actions.</p>
                    </div>
                    {canCreateMarkets ? (
                        <button type="button" onClick={onOpenMarketSetup} className="crm-btn-primary px-3 py-2">
                            Add Market
                        </button>
                    ) : null}
                </div>

                {platformRows.length === 0 ? (
                    <div className="p-4">
                        <p className="rounded-lg border border-dashed border-slate-300 px-4 py-5 text-sm text-slate-500">
                            No markets configured yet.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 p-4 xl:grid-cols-2">
                        {platformRows.map((platform) => {
                            const latest = platformResults[platform.platform_id] || {};
                            const profileCount = extractProfileCount(latest.test, platform);

                            return (
                                <div key={platform.platform_id} className="rounded-lg border border-slate-200 bg-slate-50/70 p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <h4 className="text-sm font-semibold text-slate-900">{platform.platform_name}</h4>
                                            <p className="mt-1 text-xs text-slate-500">{platform.domain || 'No domain set'} • {platform.country || 'Unknown market'}</p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(platform.wp_sync?.status || 'pending')}`}>
                                            {(platform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                                        </span>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                        <div className="rounded-md border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">WP connection</p>
                                            <p className="mt-2 text-sm font-semibold text-slate-900">{platform.wp_sync?.credentials_ready ? 'Configured' : 'Needs credentials'}</p>
                                        </div>
                                        <div className="rounded-md border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Last sync</p>
                                            <p className="mt-2 text-sm font-semibold text-slate-900">{formatDateTime(platform.sync?.last_synced_at)}</p>
                                        </div>
                                        <div className="rounded-md border border-slate-200 bg-white p-3">
                                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Profiles</p>
                                            <p className="mt-2 text-sm font-semibold text-slate-900">{profileCount ?? 'Unknown'}</p>
                                        </div>
                                    </div>

                                    {platform.wp_sync?.last_error ? (
                                        <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                            {platform.wp_sync.last_error}
                                        </p>
                                    ) : null}

                                    <div className="mt-4 flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setTestingPlatformId(platform.platform_id);
                                                platformTestMutation.mutate(platform.platform_id);
                                            }}
                                            disabled={!canManageMarkets || testingPlatformId === platform.platform_id}
                                            className="crm-btn-secondary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {testingPlatformId === platform.platform_id ? 'Testing...' : 'Test'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSyncingPlatformId(platform.platform_id);
                                                platformSyncMutation.mutate(platform.platform_id);
                                            }}
                                            disabled={!canManageMarkets || syncingPlatformId === platform.platform_id}
                                            className="crm-btn-secondary px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {syncingPlatformId === platform.platform_id ? 'Syncing...' : 'Sync'}
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </section>
        </div>
    );
}
