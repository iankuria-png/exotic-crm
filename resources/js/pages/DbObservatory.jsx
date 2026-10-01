import React, { useCallback, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHeader from '../components/PageHeader';
import { useAuth } from '../hooks/useAuth';
import { useToast } from '../components/ToastProvider';
import dbObservatory from '../services/dbObservatory';
import OverviewTab from '../components/db-observatory/OverviewTab';
import RunsTab from '../components/db-observatory/RunsTab';
import FindingsTab from '../components/db-observatory/FindingsTab';
import MarketsTab from '../components/db-observatory/MarketsTab';
import RulesTab from '../components/db-observatory/RulesTab';
import SchedulesTab from '../components/db-observatory/SchedulesTab';
import AuditTab from '../components/db-observatory/AuditTab';
import { apiError, Drawer, PROFILE_HINT } from '../components/db-observatory/shared';

const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'runs', label: 'Runs' },
    { id: 'findings', label: 'Findings' },
    { id: 'markets', label: 'Markets' },
    { id: 'rules', label: 'Rules' },
    { id: 'schedules', label: 'Schedules & limits' },
    { id: 'audit', label: 'Logs & audit' },
];

function ScanNowDialog({ open, onClose, markets, preselect, onStarted }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const eligible = markets.filter((m) => m.connection?.configured && m.connection.enabled && m.connection.preflight_status === 'passed');
    const [profile, setProfile] = useState('quick');
    const [selected, setSelected] = useState([]);
    const [verbose, setVerbose] = useState(false);
    const [blocked, setBlocked] = useState(null);
    const key = useMemo(() => `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    React.useEffect(() => {
        if (open) {
            setSelected(preselect ? [preselect] : []);
            setBlocked(null);
        }
    }, [open, preselect]);

    const start = useMutation({
        mutationFn: () => dbObservatory.startPass({ profile, markets: selected.length === eligible.length && eligible.length > 1 ? 'all' : selected, verbose, idempotency_key: key }),
        onSuccess: (res) => {
            toast.success(`Pass ${res.pass_id} queued for ${res.markets} market(s).`);
            queryClient.invalidateQueries({ queryKey: ['dbo'] });
            onStarted(res);
        },
        onError: (error) => {
            if (error?.response?.status === 423 && error.response.data?.blocked) {
                setBlocked(error.response.data.blocked);
            }
            toast.error(apiError(error));
        },
    });

    const allOn = eligible.length > 0 && selected.length === eligible.length;

    return (
        <Drawer
            open={open}
            onClose={onClose}
            title="Scan now"
            subtitle="Runs within the same load, health, credential and per-host limits as scheduled scans."
            width="max-w-xl"
            footer={(
                <div className="flex items-center justify-between gap-3">
                    <span className="text-xs text-slate-500">{selected.length} of {eligible.length} eligible markets</span>
                    <button type="button" className="crm-btn-primary" disabled={selected.length === 0 || start.isPending} onClick={() => start.mutate()}>
                        {start.isPending ? 'Queuing…' : `Start ${profile} scan`}
                    </button>
                </div>
            )}
        >
            <fieldset className="space-y-2">
                <legend className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Profile</legend>
                {['quick', 'standard', 'deep'].map((p) => (
                    <label key={p} className={`flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 ${profile === p ? 'border-teal-400 bg-teal-50' : 'border-slate-200'}`}>
                        <input type="radio" name="profile" className="mt-1" checked={profile === p} onChange={() => setProfile(p)} />
                        <span><span className="block text-sm font-semibold capitalize text-slate-900">{p}</span><span className="text-xs text-slate-500">{PROFILE_HINT[p]}</span></span>
                    </label>
                ))}
            </fieldset>

            <fieldset className="mt-5">
                <div className="flex items-center justify-between">
                    <legend className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Markets</legend>
                    <button type="button" className="text-xs font-semibold text-teal-700 hover:underline" onClick={() => setSelected(allOn ? [] : eligible.map((m) => m.platform_id))}>{allOn ? 'Clear' : 'Select all eligible'}</button>
                </div>
                {eligible.length === 0 ? (
                    <p className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">No market has an enabled reader connection with a passing preflight yet. Configure one in Markets → Connection.</p>
                ) : (
                    <div className="mt-2 grid max-h-72 gap-1.5 overflow-y-auto sm:grid-cols-2">
                        {eligible.map((m) => {
                            const on = selected.includes(m.platform_id);
                            const block = blocked?.find((b) => b.platform_id === m.platform_id);
                            return (
                                <label key={m.platform_id} className={`flex items-start gap-2 rounded-md border px-2.5 py-1.5 text-sm ${on ? 'border-teal-300 bg-white' : 'border-slate-200'} ${block ? 'border-rose-300' : ''}`}>
                                    <input type="checkbox" className="mt-0.5" checked={on} onChange={() => setSelected(on ? selected.filter((x) => x !== m.platform_id) : [...selected, m.platform_id])} />
                                    <span>
                                        <span className="font-medium text-slate-800">{m.market}</span>
                                        {block ? <span className="block text-xs text-rose-700">{block.message}</span> : null}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                )}
                <p className="mt-3 text-xs text-slate-500">Markets on the same database host run one at a time. A market that already has an active scan cannot be selected twice; the whole request is refused instead.</p>
            </fieldset>

            <label className="mt-4 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" checked={verbose} onChange={(e) => setVerbose(e.target.checked)} /> Verbose debug events
            </label>
        </Drawer>
    );
}

export default function DbObservatory() {
    const { user } = useAuth();
    const [params, setParams] = useSearchParams();
    const tab = TABS.some((t) => t.id === params.get('tab')) ? params.get('tab') : 'overview';
    const runId = params.get('run') ? Number(params.get('run')) : null;
    const [findingFilters, setFindingFilters] = useState(null);
    const [scanOpen, setScanOpen] = useState(false);
    const [preselect, setPreselect] = useState(null);

    const isAdmin = user?.role === 'admin';
    const canView = ['admin', 'sub_admin'].includes(user?.role);

    const marketsQuery = useQuery({ queryKey: ['dbo', 'markets'], queryFn: dbObservatory.markets, enabled: canView, staleTime: 15_000 });
    const markets = marketsQuery.data?.data || [];

    const go = useCallback((nextTab, extra = {}) => {
        const next = new URLSearchParams();
        next.set('tab', nextTab);
        Object.entries(extra).forEach(([k, v]) => v !== null && v !== undefined && next.set(k, String(v)));
        setParams(next);
    }, [setParams]);

    const openFindings = useCallback((filters) => {
        setFindingFilters({ ...filters, _t: Date.now() });
        go('findings');
    }, [go]);
    const openRun = useCallback((id) => go('runs', { run: id }), [go]);

    if (!canView) {
        return (
            <div className="space-y-4">
                <PageHeader title="Database Observatory" subtitle="Market database malware and integrity scanning" />
                <div className="crm-surface px-5 py-6 text-sm text-slate-600">The Database Observatory is available to administrators and sub-administrators.</div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <PageHeader
                title="Database Observatory"
                subtitle="Read-only malware, persistence and integrity scanning of every market's WordPress database — with explicit coverage."
                actions={isAdmin ? (
                    <>
                        <button type="button" className="crm-btn-secondary" onClick={() => go('schedules')}>Schedules</button>
                        <button type="button" className="crm-btn-primary" onClick={() => { setPreselect(null); setScanOpen(true); }}>Scan now</button>
                    </>
                ) : <span className="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">Read-only · your assigned markets</span>}
            />

            <nav className="crm-surface flex gap-1.5 overflow-x-auto px-3 py-2.5" aria-label="Observatory sections">
                {TABS.map((t) => (
                    <button
                        key={t.id}
                        type="button"
                        onClick={() => go(t.id)}
                        aria-current={tab === t.id ? 'page' : undefined}
                        className={`min-h-10 shrink-0 rounded-lg border px-3 py-1.5 text-sm font-semibold transition ${tab === t.id ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-transparent text-slate-600 hover:border-slate-200 hover:bg-slate-50'}`}
                    >
                        {t.label}
                    </button>
                ))}
            </nav>

            {tab === 'overview' ? <OverviewTab onOpenFindings={openFindings} onOpenRun={openRun} onConfigure={() => go('schedules')} /> : null}
            {tab === 'runs' ? <RunsTab canOperate={isAdmin} onOpenRun={openRun} openRunId={runId} onCloseRun={() => go('runs')} onOpenFindings={openFindings} /> : null}
            {tab === 'findings' ? <FindingsTab initialFilters={findingFilters} canOperate={isAdmin} canConfigure={isAdmin} markets={markets} /> : null}
            {tab === 'markets' ? <MarketsTab canConfigure={isAdmin} canOperate={isAdmin} onOpenFindings={openFindings} onOpenRun={openRun} onScanMarket={(id) => { setPreselect(id); setScanOpen(true); }} /> : null}
            {tab === 'rules' ? <RulesTab canConfigure={isAdmin} markets={markets} onOpenRun={openRun} /> : null}
            {tab === 'schedules' ? <SchedulesTab canConfigure={isAdmin} markets={markets} /> : null}
            {tab === 'audit' ? <AuditTab /> : null}

            {isAdmin ? (
                <ScanNowDialog
                    open={scanOpen}
                    onClose={() => setScanOpen(false)}
                    markets={markets}
                    preselect={preselect}
                    onStarted={(res) => { setScanOpen(false); go('runs'); return res; }}
                />
            ) : null}
        </div>
    );
}
