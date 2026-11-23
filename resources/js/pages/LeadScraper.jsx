import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import { useToast } from '../components/ToastProvider';
import { flaggedPlatformLabel } from '../utils/flags';
import ProbeSummary from '../components/scraper/ProbeSummary';
import AddSiteDrawer from '../components/scraper/AddSiteDrawer';
import CapturePanel from '../components/scraper/CapturePanel';
import ConfigurePanel from '../components/scraper/ConfigurePanel';
import RunsPanel from '../components/scraper/RunsPanel';
import RunDialog from '../components/scraper/RunDialog';
import LocationGapsPanel from '../components/scraper/LocationGapsPanel';
import { HEALTH, HealthChip, RunChip, Spinner, TechChip, errorMessage, timeAgo } from '../components/scraper/scraperUi';

const TABS = [['overview', 'Overview'], ['configure', 'Configure'], ['runs', 'Runs & results']];
const CONFIG_FOCUS = { custom_headers: 'headers', manual_selectors: 'selectors', check_url: 'address' };

export default function LeadScraper() {
    const toast = useToast();
    const qc = useQueryClient();
    const [params, setParams] = useSearchParams();
    const [market, setMarket] = useState(params.get('platform_id') || '');
    const [search, setSearch] = useState('');
    const [healthFilter, setHealthFilter] = useState('all');
    const [addOpen, setAddOpen] = useState(false);
    const [captureOpen, setCaptureOpen] = useState(false);
    const [configFocus, setConfigFocus] = useState(null);
    const [selectedRunId, setSelectedRunId] = useState(null);
    const [runDialog, setRunDialog] = useState({ open: false, mode: 'dry_run', locations: null });

    const selectedId = params.get('site') ? Number(params.get('site')) : null;
    const tab = params.get('tab') || 'overview';
    const view = params.get('view') === 'gaps' ? 'gaps' : 'sites';
    const setQuery = (patch) => {
        const next = new URLSearchParams(params);
        Object.entries(patch).forEach(([k, v]) => (v === null || v === undefined || v === '' ? next.delete(k) : next.set(k, String(v))));
        setParams(next, { replace: true });
    };

    const overview = useQuery({
        queryKey: ['scraper-overview', market],
        queryFn: () => api.get('/crm/scraper/overview', { params: market ? { platform_id: market } : {} }).then((r) => r.data),
        refetchInterval: (q) => ((q.state.data?.sites || []).some((s) => ['queued', 'running'].includes(s.latest_run?.status)) ? 4000 : false),
    });
    const meta = overview.data || {};
    const sites = meta.sites || [];
    const canManage = !!meta.can_manage;

    const siteQuery = useQuery({
        queryKey: ['scraper-site', selectedId],
        enabled: !!selectedId,
        queryFn: () => api.get(`/crm/scraper/sites/${selectedId}`).then((r) => r.data.site),
    });
    const site = siteQuery.data;

    // Pick the first site on wide screens when nothing is selected.
    useEffect(() => {
        if (view === 'sites' && !selectedId && sites.length && window.matchMedia('(min-width: 1024px)').matches) setQuery({ site: sites[0].id });
    }, [sites.length, view]); // eslint-disable-line react-hooks/exhaustive-deps

    const refresh = (updatedSite) => {
        if (updatedSite) qc.setQueryData(['scraper-site', updatedSite.id], updatedSite);
        qc.invalidateQueries({ queryKey: ['scraper-overview'] });
    };

    const recheck = useMutation({
        mutationFn: (id) => api.post(`/crm/scraper/sites/${id}/probe`).then((r) => r.data),
        onSuccess: (data) => { refresh(data.site); toast.success(`Checked: ${HEALTH[data.profile.verdict]?.label || data.profile.verdict}.`); },
        onError: (e) => toast.error(errorMessage(e, 'Check failed.')),
    });

    const startRun = useMutation({
        mutationFn: ({ id, ...payload }) => api.post(`/crm/scraper/sites/${id}/runs`, payload).then((r) => r.data.run),
        onSuccess: (run, variables) => {
            setRunDialog((d) => ({ ...d, open: false }));
            if (variables.save_as_default) qc.invalidateQueries({ queryKey: ['scraper-site', run.scraper_source_id] });
            setSelectedRunId(run.id);
            setQuery({ tab: 'runs' });
            qc.invalidateQueries({ queryKey: ['scraper-runs', run.scraper_source_id] });
            qc.invalidateQueries({ queryKey: ['scraper-overview'] });
            const where = run.filters?.locations?.length ? ` in ${run.filters.locations.join(', ')}` : '';
            toast.success(run.mode === 'import' ? `Run started: new profiles${where} become leads.` : `Preview started${where}.`);
        },
        onError: (e) => toast.error(errorMessage(e, 'Could not start the run.')),
    });

    const [overrideBusy, setOverrideBusy] = useState(null);
    const patchSite = async (patch, successMessage) => {
        const { data } = await api.patch(`/crm/scraper/sites/${site.id}`, patch);
        refresh(data.site);
        toast.success(successMessage);
        const probe = await api.post(`/crm/scraper/sites/${site.id}/probe`);
        refresh(probe.data.site);
    };

    const applyOverride = async (option) => {
        if (option.kind === 'capture') { setCaptureOpen(true); return; }
        if (CONFIG_FOCUS[option.key]) { setConfigFocus(CONFIG_FOCUS[option.key]); setQuery({ tab: 'configure' }); return; }
        const crawl = site.crawl_settings || {};
        const actions = {
            render_worker: [{ fetch_driver: 'render_worker' }, 'Now fetching with the render worker.'],
            sitemap_route: [{ crawl_settings: { ...crawl, route: 'sitemap', max_profiles: Math.max(crawl.max_profiles || 0, 50) } }, 'Runs now read profile pages from the sitemap.'],
            follow_profiles: [{ crawl_settings: { ...crawl, follow_profiles: true, max_profiles: Math.max(crawl.max_profiles || 0, 40) } }, 'Runs now open profile pages for phone numbers.'],
            slow_down: [{ crawl_settings: { ...crawl, delay_ms: Math.max(crawl.delay_ms || 0, 5000) } }, 'Delay raised to 5 seconds between pages.'],
        };
        if (!actions[option.key]) return;
        setOverrideBusy(option.key);
        try {
            await patchSite(...actions[option.key]);
        } catch (e) {
            toast.error(errorMessage(e, 'Could not apply that.'));
        } finally {
            setOverrideBusy(null);
        }
    };

    const onCaptureReceived = async () => {
        setCaptureOpen(false);
        if (!site) return;
        try {
            if (canManage && site.fetch_driver !== 'capture') {
                await patchSite({ fetch_driver: 'capture' }, 'This site now uses pages you capture.');
            } else {
                const probe = await api.post(`/crm/scraper/sites/${site.id}/probe`, canManage ? {} : { test_only: true, driver: 'capture' });
                if (probe.data.site) refresh(probe.data.site);
            }
        } catch (e) {
            toast.error(errorMessage(e, 'Captured, but the check failed.'));
        }
    };

    const filteredSites = useMemo(() => {
        const q = search.trim().toLowerCase();
        return sites.filter((s) => {
            if (healthFilter !== 'all' && (s.health_status || 'unknown') !== healthFilter) return false;
            return !q || [s.name, s.host, s.template_label, ...(s.tech || []).map((t) => t.name)].some((v) => String(v || '').toLowerCase().includes(q));
        });
    }, [sites, search, healthFilter]);

    const healthCounts = useMemo(() => sites.reduce((acc, s) => { const k = s.health_status || 'unknown'; acc[k] = (acc[k] || 0) + 1; return acc; }, {}), [sites]);
    const activeRun = site && sites.find((s) => s.id === site.id)?.latest_run;
    const running = activeRun && ['queued', 'running'].includes(activeRun.status);
    const ready = site && site.is_active && site.compliance_ack_robots && site.compliance_ack_tos;

    return (
        <div className="relative space-y-4">
            <PageHeader
                title="Lead Scraper"
                subtitle="Check a directory at a glance, fix what blocks it, then collect and import leads."
                actions={(
                    <>
                        <div className="flex rounded-lg border border-slate-300 bg-white p-0.5" role="tablist" aria-label="Lead Scraper view">
                            {[['sites', 'Sites'], ['gaps', 'Location gaps']].map(([key, label]) => (
                                <button key={key} type="button" role="tab" aria-selected={view === key} onClick={() => setQuery({ view: key === 'gaps' ? 'gaps' : null })}
                                    className={`rounded-md px-3 py-1.5 text-sm font-semibold ${view === key ? 'bg-slate-800 text-white' : 'text-slate-600 hover:text-slate-900'}`}>{label}</button>
                            ))}
                        </div>
                        <Link to="/leads" className="crm-btn-secondary">Back to leads</Link>
                        <select aria-label="Market" className="crm-select-enhanced w-48" value={market} onChange={(e) => { setMarket(e.target.value); setQuery({ platform_id: e.target.value || null }); }}>
                            <option value="">All markets</option>
                            {(meta.platforms || []).map((p) => <option key={p.id} value={p.id}>{flaggedPlatformLabel({ name: p.name, country: p.country })}</option>)}
                        </select>
                        {canManage ? <button type="button" className="crm-btn-primary" onClick={() => setAddOpen(true)}>Add site</button> : null}
                    </>
                )}
            />

            {view === 'gaps' ? (
                <LocationGapsPanel
                    platformId={market || ((meta.platforms || []).length === 1 ? String(meta.platforms[0].id) : '')}
                    platforms={meta.platforms}
                    onPickMarket={(id) => { setMarket(id); setQuery({ platform_id: id }); }}
                    onOpenRun={(siteId, runId) => { setSelectedRunId(runId); setQuery({ view: null, site: siteId, tab: 'runs' }); }}
                    toast={toast}
                />
            ) : overview.isError ? (
                <div className="crm-surface p-6 text-sm text-rose-800">Could not load the scraper. <button type="button" className="font-semibold underline" onClick={() => overview.refetch()}>Retry</button></div>
            ) : (
                <div className="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
                    {/* Site list */}
                    <aside className={`crm-surface flex max-h-[calc(100vh-11rem)] flex-col overflow-hidden ${selectedId ? 'hidden lg:flex' : 'flex'}`} aria-label="Sites">
                        <div className="space-y-2 border-b border-slate-100 p-3">
                            <input className="crm-input" placeholder="Search sites, tech, template" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Search sites" />
                            <div className="flex flex-wrap gap-1">
                                {['all', 'ready', 'needs_config', 'blocked', 'unknown'].map((k) => (
                                    <button key={k} type="button" aria-pressed={healthFilter === k} onClick={() => setHealthFilter(k)}
                                        className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold ${healthFilter === k ? 'border-slate-800 bg-slate-800 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                                        {k === 'all' ? `All ${sites.length}` : `${HEALTH[k].label} ${healthCounts[k] || 0}`}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <ul className="relative flex-1 divide-y divide-slate-100 overflow-y-auto overscroll-contain">
                            {overview.isLoading ? [0, 1, 2, 3].map((i) => <li key={i} className="p-3"><div className="h-12 animate-pulse rounded bg-slate-100 motion-reduce:animate-none" /></li>) : null}
                            {!overview.isLoading && sites.length === 0 ? (
                                <li className="px-4 py-10 text-center">
                                    <p className="text-sm font-semibold text-slate-900">No sites yet</p>
                                    <p className="mt-1 text-sm text-slate-500">{canManage ? 'Add a directory to see its technology, blockers and leads.' : 'An admin needs to add a directory first.'}</p>
                                    {canManage ? <button type="button" className="crm-btn-primary mt-3" onClick={() => setAddOpen(true)}>Add site</button> : null}
                                </li>
                            ) : null}
                            {!overview.isLoading && sites.length > 0 && filteredSites.length === 0 ? <li className="px-4 py-8 text-center text-sm text-slate-500">No sites match.</li> : null}
                            {filteredSites.map((s) => (
                                <li key={s.id}>
                                    <button type="button" onClick={() => { setSelectedRunId(null); setQuery({ site: s.id, tab: 'overview' }); }} aria-current={s.id === selectedId ? 'true' : undefined}
                                        className={`block w-full px-3 py-2.5 text-left transition focus-visible:bg-slate-50 focus-visible:outline-none ${s.id === selectedId ? 'bg-teal-50/70 shadow-[inset_3px_0_0_#0f766e]' : 'hover:bg-slate-50'}`}>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="flex min-w-0 items-center gap-2">
                                                <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full ${(HEALTH[s.health_status] || HEALTH.unknown).dot}`} />
                                                <span className="truncate text-sm font-semibold text-slate-900">{s.name}</span>
                                            </span>
                                            {['queued', 'running'].includes(s.latest_run?.status) ? <span className="flex items-center gap-1 text-[11px] font-semibold text-sky-700"><Spinner className="h-3 w-3" />Running</span> : null}
                                        </div>
                                        <p className="crm-mono mt-0.5 truncate pl-4 text-[11px] text-slate-500">{s.host}{s.platform ? ` · ${s.platform.name}` : ''}</p>
                                        {s.default_locations?.length ? <p className="mt-0.5 truncate pl-4 text-[11px] font-medium text-teal-700">📍 {s.default_locations.join(', ')}</p> : null}
                                        <p className="mt-1 truncate pl-4 text-xs text-slate-600">
                                            <span className="sr-only">{(HEALTH[s.health_status] || HEALTH.unknown).label}: </span>
                                            {s.headline || 'Not checked yet'}
                                        </p>
                                        {(s.tech || []).length ? (
                                            <p className="mt-1 truncate pl-4 text-[11px] text-slate-400">{s.tech.filter((t) => !['plugin', 'analytics'].includes(t.category)).slice(0, 4).map((t) => t.name.replace(/^Theme: /, '')).join(' · ')}</p>
                                        ) : null}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </aside>

                    {/* Workspace */}
                    <main className={`crm-surface min-w-0 ${selectedId ? 'block' : 'hidden lg:block'}`}>
                        {!selectedId ? (
                            <div className="flex h-full min-h-[20rem] flex-col items-center justify-center px-6 py-16 text-center">
                                <p className="text-base font-semibold text-slate-900">Choose a site</p>
                                <p className="mt-1 max-w-sm text-sm text-slate-500">Each site shows its technology, what blocks scraping and how to get past it, then lets you preview and import leads.</p>
                            </div>
                        ) : siteQuery.isLoading || !site ? (
                            <div className="space-y-3 p-5">{[0, 1, 2].map((i) => <div key={i} className="h-20 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" />)}</div>
                        ) : (
                            <>
                                <header className="border-b border-slate-100 px-4 pt-4">
                                    <button type="button" className="mb-2 text-sm font-semibold text-teal-700 lg:hidden" onClick={() => setQuery({ site: null, tab: null })}>← All sites</button>
                                    <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="truncate text-xl font-semibold tracking-tight text-slate-900">{site.name}</h3>
                                                <HealthChip status={site.health_status} />
                                                {!site.is_active ? <span className="rounded-full border border-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-500">Paused</span> : null}
                                                {activeRun ? <RunChip status={activeRun.status} /> : null}
                                                {site.default_locations?.length ? (
                                                    <span title="Default places for runs" className="inline-flex items-center gap-1 rounded-full border border-teal-200 bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-800">
                                                        📍 {site.default_locations.slice(0, 2).join(', ')}{site.default_locations.length > 2 ? ` +${site.default_locations.length - 2}` : ''}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                                                <a href={site.source_url} target="_blank" rel="noreferrer noopener" className="crm-mono truncate text-teal-700 hover:underline">{site.source_url.replace(/^https?:\/\//, '')} ↗</a>
                                                <span>· {site.platform?.name}</span>
                                                <span>· checked {timeAgo(site.last_probed_at)}</span>
                                                {site.last_run_at ? <span>· last run {timeAgo(site.last_run_at)}</span> : null}
                                            </p>
                                            {(site.tech || []).length ? <div className="mt-2 flex flex-wrap gap-1">{site.tech.filter((t) => !['plugin', 'analytics'].includes(t.category)).slice(0, 6).map((t) => <TechChip key={t.key} tech={t} />)}</div> : null}
                                        </div>
                                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                                            <button type="button" className="crm-btn-secondary disabled:opacity-60" disabled={recheck.isPending} onClick={() => recheck.mutate(site.id)}>
                                                {recheck.isPending ? <><Spinner className="mr-2 h-4 w-4" />Checking…</> : 'Re-check'}
                                            </button>
                                            <button type="button" className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50" disabled={!ready || running || startRun.isPending} title={!ready ? 'Activate the site and confirm the robots/terms checks first' : undefined} onClick={() => setRunDialog({ open: true, mode: 'dry_run', locations: null })}>
                                                Preview run
                                            </button>
                                            <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!ready || running || startRun.isPending} title={!ready ? 'Activate the site and confirm the robots/terms checks first' : 'Collect and create leads for every new profile'} onClick={() => setRunDialog({ open: true, mode: 'import', locations: null })}>
                                                Run &amp; import
                                            </button>
                                        </div>
                                    </div>
                                    <nav className="mt-3 flex gap-1" role="tablist" aria-label="Site sections">
                                        {TABS.map(([key, label]) => (
                                            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setQuery({ tab: key })}
                                                className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold transition ${tab === key ? 'border-teal-700 text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>
                                                {label}
                                            </button>
                                        ))}
                                    </nav>
                                </header>
                                <div className="p-4">
                                    {!ready && tab !== 'configure' ? (
                                        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Runs are off until the site is active and the robots.txt and terms checks are confirmed.{' '}
                                            {canManage ? <button type="button" className="font-semibold underline" onClick={() => { setConfigFocus('import'); setQuery({ tab: 'configure' }); }}>Review in Configure</button> : 'Ask an admin to confirm them.'}
                                        </p>
                                    ) : null}
                                    {tab === 'overview' ? (
                                        site.site_profile ? (
                                            <ProbeSummary profile={site.site_profile} onOverride={applyOverride} overrideBusy={overrideBusy} canManage={canManage}
                                                onPickLocation={ready && !running ? (place) => setRunDialog({ open: true, mode: 'dry_run', locations: [place] }) : null}
                                                defaultLocations={site.default_locations} />
                                        ) : (
                                            <div className="rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
                                                <p className="text-sm font-semibold text-slate-900">Not checked yet</p>
                                                <p className="mt-1 text-sm text-slate-500">Check the site to see its technology, blockers and a sample of what the scraper reads.</p>
                                                <button type="button" className="crm-btn-primary mt-3" onClick={() => recheck.mutate(site.id)} disabled={recheck.isPending}>{recheck.isPending ? 'Checking…' : 'Check now'}</button>
                                            </div>
                                        )
                                    ) : null}
                                    {tab === 'configure' ? <ConfigurePanel site={site} meta={meta} canManage={canManage} focus={configFocus} toast={toast} onSaved={(s) => { refresh(s); setConfigFocus(null); }} /> : null}
                                    {tab === 'runs' ? <RunsPanel site={site} selectedRunId={selectedRunId} onSelectRun={setSelectedRunId} toast={toast} /> : null}
                                </div>
                            </>
                        )}
                    </main>
                </div>
            )}

            <AddSiteDrawer
                open={addOpen}
                onClose={() => setAddOpen(false)}
                platforms={meta.platforms}
                library={meta.library}
                defaultPlatformId={market}
                toast={toast}
                onCreated={(s) => { setAddOpen(false); refresh(s); setQuery({ site: s.id, tab: 'overview' }); }}
            />
            <RunDialog
                open={runDialog.open}
                site={site}
                initialMode={runDialog.mode}
                initialLocations={runDialog.locations}
                canManage={canManage}
                busy={startRun.isPending}
                onClose={() => setRunDialog((d) => ({ ...d, open: false }))}
                onStart={(payload) => startRun.mutate({ id: site.id, ...payload })}
            />
            <CapturePanel site={site} open={captureOpen} onClose={() => setCaptureOpen(false)} onReceived={onCaptureReceived} toast={toast} />
        </div>
    );
}
