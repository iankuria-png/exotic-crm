import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import PbnSiteCreateModal from './PbnSiteCreateModal';
import PbnSeedWizard from './PbnSeedWizard';

function statusText(status) {
    return String(status || 'draft').replaceAll('_', ' ');
}

function buildEditor(site) {
    if (!site) return null;

    return {
        name: site.name || '',
        domain: site.domain || '',
        default_source_platform_id: site.default_source_platform_id ? String(site.default_source_platform_id) : '',
        source_platform_ids: (site.source_platform_ids || []).map(String),
        country: site.country || '',
        timezone: site.timezone || 'Africa/Nairobi',
        currency_code: site.currency_code || 'KES',
        phone_prefix: site.phone_prefix || '254',
        wp_api_url: site.wp_sync?.api_url || '',
        wp_api_user: site.wp_sync?.api_user || '',
        wp_api_password: '',
        db_host: site.wp_provisioning?.db_host || '',
        db_name: site.wp_provisioning?.db_name || '',
        db_user: site.wp_provisioning?.db_user || '',
        db_pass: '',
        db_prefix: site.wp_provisioning?.db_prefix || 'wp_',
        is_active: Boolean(site.is_active),
        copy_policy: {
            post_status: site.copy_policy?.post_status || 'publish',
            phone: site.copy_policy?.phone || 'copy',
            media: site.copy_policy?.media || 'two_stage',
            vip_flags: site.copy_policy?.vip_flags || 'strip',
            verification: site.copy_policy?.verification || 'strip',
            seo_fields: site.copy_policy?.seo_fields || 'copy',
            duplicate_policy: 'skip',
            update_policy: 'snapshot',
        },
        reason: 'Updated PBN site from Settings',
    };
}

export default function PbnSitesPanel({ currentUserRole, formatDateTime, statusChip }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedSiteId, setSelectedSiteId] = useState(null);
    const [editor, setEditor] = useState(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [seedOpen, setSeedOpen] = useState(false);

    const query = useQuery({
        queryKey: ['settings-pbn-sites'],
        queryFn: () => api.get('/crm/settings/integrations/pbn-sites').then((response) => response.data),
    });

    const sites = query.data?.sites || [];
    const platforms = query.data?.platforms || [];
    const selectedSite = sites.find((site) => Number(site.id) === Number(selectedSiteId)) || null;
    const canConfigure = ['admin', 'sub_admin'].includes(currentUserRole || '') && Boolean(selectedSite?.can_configure ?? true);

    useEffect(() => {
        if (!sites.length) {
            setSelectedSiteId(null);
            setEditor(null);
            return;
        }
        if (!selectedSiteId || !sites.some((site) => Number(site.id) === Number(selectedSiteId))) {
            setSelectedSiteId(sites[0].id);
        }
    }, [sites, selectedSiteId]);

    useEffect(() => {
        setEditor(buildEditor(selectedSite));
    }, [selectedSiteId, selectedSite]);

    const updateMutation = useMutation({
        mutationFn: ({ siteId, payload }) => api.patch(`/crm/settings/integrations/pbn-sites/${siteId}`, payload).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
            setEditor(buildEditor(response?.site));
            toast.success(response?.message || 'PBN site updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not update PBN site.');
        },
    });

    const readinessMutation = useMutation({
        mutationFn: (siteId) => api.post(`/crm/settings/integrations/pbn-sites/${siteId}/test-connection`, { reason: 'PBN readiness test from Settings' }).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
            toast.success(response?.status === 'ready' ? 'PBN readiness passed.' : 'PBN readiness finished with warnings.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'PBN readiness test failed.');
        },
    });

    const latestBatchId = selectedSite?.latest_seed?.id || null;
    const retryMutation = useMutation({
        mutationFn: (batchId) => api.post(`/crm/settings/integrations/pbn-seed-batches/${batchId}/retry`).then((response) => response.data),
        onSuccess: (response) => {
            queryClient.invalidateQueries({ queryKey: ['settings-pbn-sites'] });
            toast.success(response?.message || 'PBN retry queued.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not retry PBN batch.');
        },
    });

    const updateEditor = (key, value) => setEditor((current) => ({ ...current, [key]: value }));
    const toggleSource = (platformId) => {
        setEditor((current) => {
            const set = new Set((current?.source_platform_ids || []).map(String));
            const id = String(platformId);
            if (set.has(id)) {
                set.delete(id);
            } else if (set.size < 5) {
                set.add(id);
            }
            const sourceIds = Array.from(set);
            return {
                ...current,
                source_platform_ids: sourceIds,
                default_source_platform_id: sourceIds.includes(String(current.default_source_platform_id))
                    ? current.default_source_platform_id
                    : (sourceIds[0] || ''),
            };
        });
    };
    const save = () => {
        if (!selectedSite || !editor) return;
        updateMutation.mutate({
            siteId: selectedSite.id,
            payload: {
                ...editor,
                default_source_platform_id: editor.default_source_platform_id ? Number(editor.default_source_platform_id) : null,
                source_platform_ids: editor.source_platform_ids.map(Number),
                currency_code: editor.currency_code.toUpperCase(),
            },
        });
    };

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">PBN Websites</h3>
                    <p className="crm-panel-subtitle">Configure destination WordPress sites separately from main Exotic markets.</p>
                </div>
                {['admin', 'sub_admin'].includes(currentUserRole || '') ? (
                    <button type="button" onClick={() => setCreateOpen(true)} className="crm-btn-primary min-h-11 px-3 py-2">Add PBN site</button>
                ) : null}
            </header>

            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <aside className="space-y-3 xl:col-span-4">
                    <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-1">
                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase text-slate-500">Configured</p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">{sites.length}</p>
                        </div>
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase text-emerald-700">Ready</p>
                            <p className="mt-1 text-lg font-semibold text-emerald-800">{sites.filter((site) => site.status === 'ready').length}</p>
                        </div>
                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase text-amber-700">Blocked</p>
                            <p className="mt-1 text-lg font-semibold text-amber-800">{sites.filter((site) => ['failed', 'draft', 'warning'].includes(site.status)).length}</p>
                        </div>
                    </div>

                    <section className="rounded-lg border border-slate-200 bg-white">
                        <div className="border-b border-slate-100 px-3 py-2">
                            <p className="text-sm font-semibold text-slate-900">Sites</p>
                        </div>
                        <div className="max-h-80 space-y-2 overflow-auto p-2">
                            {query.isLoading ? <p className="px-2 py-4 text-sm text-slate-500">Loading PBN sites...</p> : null}
                            {!query.isLoading && sites.length === 0 ? (
                                <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-6 text-sm text-slate-500">No PBN sites configured.</p>
                            ) : null}
                            {sites.map((site) => (
                                <button
                                    key={site.id}
                                    type="button"
                                    onClick={() => setSelectedSiteId(site.id)}
                                    className={`w-full rounded-md border px-3 py-3 text-left transition ${Number(selectedSiteId) === Number(site.id) ? 'border-teal-300 bg-teal-50' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{site.name}</p>
                                            <p className="text-xs text-slate-500">{site.domain}</p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(site.status || 'draft')}`}>
                                            {statusText(site.status)}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-slate-500">{(site.source_platform_ids || []).length} sources • Last seed: {site.latest_seed ? statusText(site.latest_seed.status) : 'none'}</p>
                                </button>
                            ))}
                        </div>
                    </section>
                </aside>

                <main className="space-y-3 xl:col-span-8">
                    {!selectedSite || !editor ? (
                        <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            Select a PBN site to manage credentials and seed profiles.
                        </div>
                    ) : (
                        <>
                            {selectedSite.status !== 'ready' ? (
                                <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                    Readiness is {statusText(selectedSite.status)}. Queueing is blocked until REST, DB schema, provisioning table, and locations pass.
                                </p>
                            ) : null}
                            {selectedSite.latest_seed?.status === 'partial' || selectedSite.latest_seed?.status === 'failed' ? (
                                <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                                    <span>Latest seed {statusText(selectedSite.latest_seed.status)}: {selectedSite.latest_seed.created_count}/{selectedSite.latest_seed.selected_count} created.</span>
                                    <button type="button" className="crm-btn-secondary min-h-11 px-3 py-2" onClick={() => retryMutation.mutate(latestBatchId)} disabled={!latestBatchId || retryMutation.isPending}>
                                        {retryMutation.isPending ? 'Retrying...' : 'Retry failed'}
                                    </button>
                                </div>
                            ) : null}

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Site Profile</h4>
                                        <p className="mt-1 text-xs text-slate-500">PBN sites stay outside platform rows and billing selectors.</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        {canConfigure ? (
                                            <button type="button" className="crm-btn-secondary min-h-11 px-3 py-2" onClick={() => readinessMutation.mutate(selectedSite.id)} disabled={readinessMutation.isPending}>
                                                {readinessMutation.isPending ? 'Testing...' : 'Test readiness'}
                                            </button>
                                        ) : null}
                                        <button type="button" className="crm-btn-primary min-h-11 px-3 py-2 disabled:cursor-not-allowed disabled:opacity-60" onClick={() => setSeedOpen(true)} disabled={!selectedSite.is_active}>
                                            Seed profiles
                                        </button>
                                    </div>
                                </div>

                                <fieldset disabled={!canConfigure || updateMutation.isPending} className={!canConfigure ? 'opacity-80' : ''}>
                                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                                        <input value={editor.name} onChange={(event) => updateEditor('name', event.target.value)} className="crm-input" placeholder="Site label" />
                                        <input value={editor.domain} onChange={(event) => updateEditor('domain', event.target.value)} className="crm-input" placeholder="domain.com" />
                                        <input value={editor.country} onChange={(event) => updateEditor('country', event.target.value)} className="crm-input" placeholder="Country" />
                                        <input value={editor.timezone} onChange={(event) => updateEditor('timezone', event.target.value)} className="crm-input" placeholder="Africa/Nairobi" />
                                        <input value={editor.currency_code} onChange={(event) => updateEditor('currency_code', event.target.value.toUpperCase())} className="crm-input" placeholder="KES" maxLength={3} />
                                        <input value={editor.phone_prefix} onChange={(event) => updateEditor('phone_prefix', event.target.value)} className="crm-input" placeholder="254" />
                                        <input value={editor.wp_api_url} onChange={(event) => updateEditor('wp_api_url', event.target.value)} className="crm-input md:col-span-2" placeholder="WP API URL" />
                                        <input value={editor.wp_api_user} onChange={(event) => updateEditor('wp_api_user', event.target.value)} className="crm-input" placeholder="WP API user" />
                                        <input type="password" value={editor.wp_api_password} onChange={(event) => updateEditor('wp_api_password', event.target.value)} className="crm-input" placeholder={selectedSite.wp_sync?.credentials_ready ? 'Leave blank to keep current API password' : 'WP API password'} />
                                        <input value={editor.db_host} onChange={(event) => updateEditor('db_host', event.target.value)} className="crm-input" placeholder="DB host" />
                                        <input value={editor.db_name} onChange={(event) => updateEditor('db_name', event.target.value)} className="crm-input" placeholder="DB name" />
                                        <input value={editor.db_user} onChange={(event) => updateEditor('db_user', event.target.value)} className="crm-input" placeholder="DB user" />
                                        <input type="password" value={editor.db_pass} onChange={(event) => updateEditor('db_pass', event.target.value)} className="crm-input" placeholder={selectedSite.wp_provisioning?.db_pass_configured ? 'Leave blank to keep current DB password' : 'DB password'} />
                                        <input value={editor.db_prefix} onChange={(event) => updateEditor('db_prefix', event.target.value)} className="crm-input" placeholder="wp_" />
                                        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                                            <input type="checkbox" checked={editor.is_active} onChange={(event) => updateEditor('is_active', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                            Active for seed previews
                                        </label>
                                    </div>
                                </fieldset>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Sources and Copy Policy</h4>
                                <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                    {platforms.map((platform) => (
                                        <label key={platform.platform_id} className="flex min-h-11 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                            <input type="checkbox" checked={editor.source_platform_ids.includes(String(platform.platform_id))} onChange={() => toggleSource(platform.platform_id)} disabled={!canConfigure} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                                            <span>{platform.platform_name}</span>
                                        </label>
                                    ))}
                                </div>
                                <div className="mt-3 grid gap-3 md:grid-cols-3">
                                    <select value={editor.default_source_platform_id} onChange={(event) => updateEditor('default_source_platform_id', event.target.value)} className="crm-select" disabled={!canConfigure}>
                                        {editor.source_platform_ids.map((id) => {
                                            const platform = platforms.find((row) => String(row.platform_id) === String(id));
                                            return <option key={id} value={id}>{platform?.platform_name || `Market #${id}`}</option>;
                                        })}
                                    </select>
                                    <select value={editor.copy_policy.post_status} onChange={(event) => updateEditor('copy_policy', { ...editor.copy_policy, post_status: event.target.value })} className="crm-select" disabled={!canConfigure}>
                                        <option value="publish">Publish</option>
                                        <option value="private">Private</option>
                                        <option value="draft">Draft</option>
                                    </select>
                                    <select value={editor.copy_policy.media} onChange={(event) => updateEditor('copy_policy', { ...editor.copy_policy, media: event.target.value })} className="crm-select" disabled={!canConfigure}>
                                        <option value="two_stage">Two-stage media</option>
                                        <option value="none">No media copy</option>
                                    </select>
                                </div>
                                {canConfigure ? (
                                    <div className="mt-3 flex justify-end">
                                        <button type="button" className="crm-btn-primary min-h-11 px-3 py-2" onClick={save} disabled={updateMutation.isPending || !editor.name.trim() || !editor.domain.trim() || editor.source_platform_ids.length < 1}>
                                            {updateMutation.isPending ? 'Saving...' : 'Save PBN site'}
                                        </button>
                                    </div>
                                ) : null}
                            </section>

                            <section className="grid gap-3 md:grid-cols-3">
                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p className="text-[11px] font-semibold uppercase text-slate-500">Last Checked</p>
                                    <p className="mt-1 text-sm font-medium text-slate-800">{formatDateTime(selectedSite.wp_sync?.last_checked_at)}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p className="text-[11px] font-semibold uppercase text-slate-500">Latest Seed</p>
                                    <p className="mt-1 text-sm font-medium text-slate-800">{selectedSite.latest_seed ? `${selectedSite.latest_seed.created_count}/${selectedSite.latest_seed.selected_count} created` : 'None'}</p>
                                </div>
                                <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                    <p className="text-[11px] font-semibold uppercase text-slate-500">Credentials</p>
                                    <p className="mt-1 text-sm font-medium text-slate-800">{selectedSite.wp_sync?.credentials_ready && selectedSite.wp_provisioning?.credentials_ready ? 'REST + DB ready' : 'Incomplete'}</p>
                                </div>
                            </section>
                        </>
                    )}
                </main>
            </div>

            <PbnSiteCreateModal open={createOpen} onClose={() => setCreateOpen(false)} platforms={platforms} onCreated={(site) => setSelectedSiteId(site?.id || null)} />
            <PbnSeedWizard open={seedOpen} onClose={() => setSeedOpen(false)} site={selectedSite} platforms={platforms} />
        </section>
    );
}
