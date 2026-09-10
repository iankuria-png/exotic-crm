import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import AiStateBlock from '../ai/AiStateBlock';

const PANELS = [
    { id: 'overview', label: 'Overview' },
    { id: 'tokens', label: 'Tokens' },
    { id: 'tools', label: 'Tools' },
    { id: 'privacy', label: 'Data & Privacy' },
    { id: 'limits', label: 'Limits' },
    { id: 'activity', label: 'Activity' },
    { id: 'guide', label: 'Guide' },
];

export default function McpWorkspacePanel() {
    const [panel, setPanel] = useState('overview');
    const queryClient = useQueryClient();
    const settingsQuery = useQuery({
        queryKey: ['mcp-settings'],
        queryFn: () => api.get('/crm/settings/mcp').then((response) => response.data),
        staleTime: 15000,
    });
    const activityQuery = useQuery({
        queryKey: ['mcp-activity'],
        queryFn: () => api.get('/crm/settings/mcp/activity').then((response) => response.data),
        enabled: panel === 'overview' || panel === 'activity',
        refetchInterval: panel === 'overview' ? 5000 : false,
    });
    const tokensQuery = useQuery({
        queryKey: ['mcp-tokens'],
        queryFn: () => api.get('/crm/settings/mcp/tokens').then((response) => response.data),
        enabled: panel === 'tokens',
    });
    const updateMutation = useMutation({
        mutationFn: (payload) => api.put('/crm/settings/mcp', payload).then((response) => response.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['mcp-settings'] }),
    });
    const selfTestMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/self-test').then((response) => response.data),
    });
    const mintMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/mcp/tokens', payload).then((response) => response.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['mcp-tokens'] }),
    });

    if (settingsQuery.isLoading) return <AiStateBlock variant="loading" message="Loading MCP control station..." />;
    if (settingsQuery.isError) return <AiStateBlock variant="error" message="Could not load MCP settings." onRetry={() => settingsQuery.refetch()} />;

    const settings = settingsQuery.data?.settings || {};
    const activity = activityQuery.data || {};
    const tools = settingsQuery.data?.tools || [];
    const enabled = Boolean(settings.enabled);
    const tabClass = (active) => 'rounded-md px-3 py-2 text-sm font-medium transition ' + (active ? 'bg-white text-slate-900 ring-1 ring-slate-200' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700');

    return (
        <div className="space-y-4" data-testid="mcp-workspace">
            <nav className="flex flex-wrap gap-1 border-b border-slate-200 pb-2" aria-label="MCP settings sections">
                {PANELS.map((item) => <button key={item.id} type="button" onClick={() => setPanel(item.id)} aria-current={panel === item.id ? 'page' : undefined} className={tabClass(panel === item.id)}>{item.label}</button>)}
            </nav>
            {panel === 'overview' ? <Overview settings={settings} enabled={enabled} activity={activity} onToggle={() => updateMutation.mutate({ enabled: !enabled })} onSelfTest={() => selfTestMutation.mutate()} selfTest={selfTestMutation.data} /> : null}
            {panel === 'tokens' ? <Tokens tokens={tokensQuery.data?.tokens || []} mintMutation={mintMutation} /> : null}
            {panel === 'tools' ? <Tools tools={tools} settings={settings} onUpdate={(payload) => updateMutation.mutate(payload)} /> : null}
            {panel === 'privacy' ? <Privacy settings={settings} /> : null}
            {panel === 'limits' ? <Limits settings={settings} onUpdate={(payload) => updateMutation.mutate(payload)} /> : null}
            {panel === 'activity' ? <Activity rows={activity.rows || []} summary={activity.summary || {}} /> : null}
            {panel === 'guide' ? <Guide endpoint={settingsQuery.data?.endpoint || '/api/mcp'} tools={tools} /> : null}
        </div>
    );
}

function Section({ title, children, action }) {
    return <section className="crm-surface p-4"><div className="flex flex-wrap items-start justify-between gap-3"><h2 className="text-base font-semibold text-slate-900">{title}</h2>{action}</div><div className="mt-4">{children}</div></section>;
}

function Overview({ settings, enabled, activity, onToggle, onSelfTest, selfTest }) {
    const summary = activity.summary || {};
    const stateClass = enabled ? 'text-emerald-700' : 'text-amber-700';
    const dotClass = enabled ? 'bg-emerald-500' : 'bg-amber-500';
    return <div className="space-y-4">
        <Section title="MCP endpoint" action={<button type="button" onClick={onToggle} className={'rounded-md px-3 py-2 text-sm font-semibold ' + (enabled ? 'bg-rose-600 text-white' : 'bg-teal-700 text-white')}>{enabled ? 'Disable server' : 'Enable server'}</button>}>
            <div className="flex flex-wrap items-center gap-3 text-sm text-slate-600"><span className={'inline-flex items-center gap-2 font-semibold ' + stateClass}><span className={'h-2 w-2 rounded-full ' + dotClass} />{enabled ? 'Server enabled' : 'Server disabled'}</span><code className="rounded bg-slate-100 px-2 py-1">{window.location.origin}{settings.endpoint || '/api/mcp'}</code></div>
            <div className="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4"><Metric label="Calls today" value={summary.calls_today || 0} /><Metric label="Rows returned" value={summary.rows_today || 0} /><Metric label="Payload out" value={Math.round((summary.bytes_today || 0) / 1024) + ' KB'} /><Metric label="Refusals" value={summary.refusals_today || 0} /></div>
        </Section>
        <Section title="Self-test" action={<button type="button" onClick={onSelfTest} className="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Run self-test</button>}>
            {selfTest ? <div className="grid gap-2 text-sm text-slate-700 md:grid-cols-2">{(selfTest.checks || []).map((check) => <div key={check.key} className="rounded-md bg-slate-50 px-3 py-2"><strong>{check.status === 'ok' ? 'Pass' : 'Fail'}</strong> · {check.message}</div>)}</div> : <p className="text-sm text-slate-500">Check configuration, database reachability and the active tool registry.</p>}
        </Section>
    </div>;
}

function Metric({ label, value }) {
    return <div className="rounded-md border border-slate-200 bg-white p-3"><p className="text-xs uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 text-xl font-semibold text-slate-900">{value}</p></div>;
}

function Tokens({ tokens, mintMutation }) {
    const [label, setLabel] = useState('');
    const [ttl, setTtl] = useState(90);
    const [issued, setIssued] = useState(null);
    const mint = () => mintMutation.mutate({ label, ttl_days: Number(ttl) }, { onSuccess: (data) => { setIssued(data); setLabel(''); } });
    return <Section title="Access tokens" action={<button type="button" onClick={mint} disabled={!label || mintMutation.isPending} className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">Mint token</button>}>
        <div className="grid gap-3 md:grid-cols-[1fr_120px]"><input value={label} onChange={(event) => setLabel(event.target.value)} placeholder="Token label" className="rounded-md border border-slate-300 px-3 py-2 text-sm" /><input type="number" min="1" max="365" value={ttl} onChange={(event) => setTtl(event.target.value)} className="rounded-md border border-slate-300 px-3 py-2 text-sm" aria-label="Token lifetime days" /></div>
        {issued ? <div className="mt-4 rounded-md border border-teal-200 bg-teal-50 p-3 text-sm"><p className="font-semibold text-teal-900">Token minted. Copy it now; it will not be shown again.</p><code className="mt-2 block break-all text-xs text-teal-950">{issued.token}</code></div> : null}
        <div className="mt-4 divide-y divide-slate-200 border-y border-slate-200">{tokens.map((token) => <div key={token.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"><div><p className="font-medium text-slate-900">{token.label}</p><p className="text-xs text-slate-500">{token.owner || 'Unknown owner'} · {token.expires_at || 'No expiry'}</p></div><span className="text-xs text-slate-500">{(token.abilities || []).length} abilities</span></div>)}</div>
    </Section>;
}

function Tools({ tools, settings, onUpdate }) {
    return <Section title="Tool registry"><div className="divide-y divide-slate-200 border-y border-slate-200">{tools.map((tool) => { const configured = settings.tools?.[tool.name]; const isOn = configured?.enabled !== false; return <div key={tool.name} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-mono text-sm text-slate-900">{tool.name}</p><p className="text-xs text-slate-500">{tool.description}</p></div><button type="button" onClick={() => onUpdate({ tools: { [tool.name]: { ...(configured || {}), enabled: !isOn } } })} className={'rounded-md px-3 py-1.5 text-xs font-semibold ' + (isOn ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600')}>{isOn ? 'Enabled' : 'Disabled'}</button></div>; })}</div></Section>;
}

function Privacy({ settings }) {
    return <Section title="Data & Privacy"><div className="grid gap-3 md:grid-cols-2"><div className="rounded-md border border-teal-200 bg-teal-50 p-4"><p className="font-semibold text-teal-900">{settings.pii_mode === 'aggregate_only' ? 'Aggregate only' : 'Pseudonymous'}</p><p className="mt-1 text-sm text-teal-800">No names, phones, emails, bios or raw entity URLs are returned.</p></div><div className="rounded-md border border-slate-200 p-4"><p className="font-semibold text-slate-900">SQL hatch</p><p className="mt-1 text-sm text-slate-500">{settings.sql_hatch?.enabled ? 'Enabled for aggregate wrappers.' : 'Disabled until explicitly enabled.'}</p></div></div></Section>;
}

function Limits({ settings, onUpdate }) {
    const limits = settings.limits || {};
    return <Section title="Limits"><div className="grid gap-3 md:grid-cols-3">{[['rate_per_minute', 'Calls / minute'], ['daily_row_budget', 'Daily rows'], ['daily_bytes_budget', 'Daily bytes']].map(([key, label]) => <label key={key} className="text-sm text-slate-600">{label}<input type="number" value={limits[key] || 0} onChange={(event) => onUpdate({ limits: { [key]: Number(event.target.value) } })} className="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm" /></label>)}</div></Section>;
}

function Activity({ rows, summary }) {
    return <Section title="Activity"><div className="mb-3 grid grid-cols-2 gap-3 md:grid-cols-4"><Metric label="Calls" value={summary.calls_today || 0} /><Metric label="Rows" value={summary.rows_today || 0} /><Metric label="Bytes" value={summary.bytes_today || 0} /><Metric label="Refusals" value={summary.refusals_today || 0} /></div><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-200 text-xs uppercase text-slate-500"><tr><th className="py-2 pr-3">Tool</th><th className="py-2 pr-3">Status</th><th className="py-2 pr-3">Reason</th><th className="py-2">When</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className="border-b border-slate-100"><td className="py-2 pr-3 font-mono text-xs">{row.tool}</td><td className="py-2 pr-3">{row.status}</td><td className="py-2 pr-3 text-slate-500">{row.refusal_reason || '—'}</td><td className="py-2 text-slate-500">{row.created_at}</td></tr>)}</tbody></table></div></Section>;
}

function Guide({ endpoint, tools }) {
    const command = 'claude mcp add --transport http exotic ' + endpoint + ' --header "Authorization: Bearer $EXOTIC_MCP_TOKEN"';
    return <Section title="Guide"><div className="space-y-4"><p className="text-sm text-slate-600">Connect a terminal client with an expiring token. The visible tool list is generated from the current server settings.</p><pre className="overflow-x-auto rounded-md bg-slate-950 p-3 text-xs text-slate-100">{command}</pre><p className="text-sm text-slate-500">{tools.length} tools currently visible to this account.</p></div></Section>;
}
