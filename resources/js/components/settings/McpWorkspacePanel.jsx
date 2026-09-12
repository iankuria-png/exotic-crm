import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import AiStateBlock from '../ai/AiStateBlock';
import ConfirmDialog from '../ConfirmDialog';
import { useToast } from '../ToastProvider';
import exportRowsToCsv from '../../utils/csvExport';
import McpKnowledgePanel from './mcp/McpKnowledgePanel';
import McpQualityPanel from './mcp/McpQualityPanel';

const PANELS = [
    { id: 'overview', label: 'Overview', summary: 'Live health' },
    { id: 'tokens', label: 'Tokens', summary: 'Credentials' },
    { id: 'tools', label: 'Tools', summary: 'Registry' },
    { id: 'knowledge', label: 'Knowledge', summary: 'Provenance' },
    { id: 'quality', label: 'Quality', summary: 'Drift & evaluation' },
    { id: 'privacy', label: 'Data & Privacy', summary: 'Payload policy' },
    { id: 'limits', label: 'Limits', summary: 'Budgets' },
    { id: 'activity', label: 'Activity', summary: 'Audit trail' },
    { id: 'guide', label: 'Guide', summary: 'Connect' },
];

const DOMAIN_LABELS = {
    catalog: 'Catalog',
    revenue: 'Revenue',
    lifecycle: 'Lifecycle',
    clients: 'Clients',
    operations: 'Operations',
    schema: 'Schema',
};

const ROLE_LABELS = {
    sales: 'Sales',
    sub_admin: 'Sub-admin',
    admin: 'Admin',
};

const inputClass = 'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-2 focus:ring-teal-100';
const buttonClass = 'inline-flex min-h-9 items-center justify-center rounded-md px-3 py-2 text-sm font-semibold transition active:translate-y-px focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-50';
const secondaryButtonClass = `${buttonClass} border border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50`;
const primaryButtonClass = `${buttonClass} bg-teal-700 text-white hover:bg-teal-800`;

export default function McpWorkspacePanel({ userRole = '' }) {
    const [panel, setPanel] = useState('overview');
    const [activityFilters, setActivityFilters] = useState({ tool: '', status: '', refusal_reason: '', from: '', to: '' });
    const [selfTest, setSelfTest] = useState(null);
    const [confirmDisable, setConfirmDisable] = useState(false);
    const queryClient = useQueryClient();
    const toast = useToast();
    const canManage = userRole === 'admin';
    const canManageTokens = canManage;

    const settingsQuery = useQuery({
        queryKey: ['mcp-settings'],
        queryFn: () => api.get('/crm/settings/mcp').then((response) => response.data),
        staleTime: 15000,
    });

    const activityKey = JSON.stringify(activityFilters);
    const activityQuery = useQuery({
        queryKey: ['mcp-activity', activityKey],
        queryFn: () => {
            const params = new URLSearchParams({ limit: panel === 'overview' ? '20' : '200' });
            if (panel === 'activity') {
                Object.entries(activityFilters).forEach(([key, value]) => { if (value) params.set(key, value); });
            }
            return api.get(`/crm/settings/mcp/activity?${params.toString()}`).then((response) => response.data);
        },
        enabled: ['overview', 'activity', 'limits'].includes(panel),
        refetchInterval: panel === 'overview' ? 5000 : false,
        staleTime: panel === 'overview' ? 0 : 15000,
    });

    const tokensQuery = useQuery({
        queryKey: ['mcp-tokens'],
        queryFn: () => api.get('/crm/settings/mcp/tokens').then((response) => response.data),
        enabled: panel === 'tokens' && canManageTokens,
    });

    const updateMutation = useMutation({
        mutationFn: (payload) => api.put('/crm/settings/mcp', payload).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['mcp-settings'] });
            queryClient.invalidateQueries({ queryKey: ['mcp-activity'] });
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not save MCP settings.'),
    });

    const selfTestMutation = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/self-test').then((response) => response.data),
        onSuccess: (data) => { setSelfTest(data); toast.success('MCP self-test completed.'); },
        onError: (error) => toast.error(error?.response?.data?.message || 'MCP self-test failed.'),
    });

    const mintMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/settings/mcp/tokens', payload).then((response) => response.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['mcp-tokens'] }),
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not mint token.'),
    });

    const revokeMutation = useMutation({
        mutationFn: (tokenId) => api.delete(`/crm/settings/mcp/tokens/${tokenId}`).then((response) => response.data),
        onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['mcp-tokens'] }); toast.success('Token revoked.'); },
        onError: (error) => toast.error(error?.response?.data?.message || 'Could not revoke token.'),
    });

    const previewMutation = useMutation({
        mutationFn: ({ tool, arguments: args }) => api.post(`/crm/settings/mcp/tools/${encodeURIComponent(tool)}/preview`, { arguments: args }).then((response) => response.data),
        onError: (error) => toast.error(error?.response?.data?.message || 'Preview failed.'),
    });

    if (settingsQuery.isLoading) return <AiStateBlock variant="loading" title="Loading MCP control station" message="Reading the current endpoint, policy and registry." />;
    if (settingsQuery.isError) return <AiStateBlock variant="error" title="MCP settings unavailable" message="The control station could not load its configuration." onRetry={() => settingsQuery.refetch()} />;

    const settings = settingsQuery.data?.settings || {};
    const tools = settingsQuery.data?.tools || [];
    const activity = activityQuery.data || {};
    const enabled = Boolean(settings.enabled);
    const endpoint = settingsQuery.data?.endpoint || `${window.location.origin}${settings.endpoint || '/api/mcp'}`;
    const saveSettings = (payload, message = 'MCP settings saved.') => {
        if (!canManage) return;
        updateMutation.mutate(payload, { onSuccess: () => toast.success(message) });
    };

    const toggleServer = () => {
        if (!canManage) return;
        if (enabled) {
            setConfirmDisable(true);
            return;
        }
        saveSettings({ enabled: true }, 'MCP server enabled.');
    };

    return (
        <div className="space-y-4" data-testid="mcp-workspace">
            <McpWorkspaceHeader
                endpoint={endpoint}
                enabled={enabled}
                canManage={canManage}
                isSaving={updateMutation.isPending}
                onToggle={toggleServer}
                onSelfTest={() => selfTestMutation.mutate()}
                isSelfTesting={selfTestMutation.isPending}
                protocol={settings.protocol_versions?.[0] || '2025-06-18'}
            />

            <nav className="crm-surface overflow-x-auto p-1" aria-label="MCP control station sections">
                <div className="flex min-w-max gap-1">
                    {PANELS.map((item) => {
                        const locked = item.id === 'tokens' && !canManageTokens;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => !locked && setPanel(item.id)}
                                disabled={locked}
                                aria-current={panel === item.id ? 'page' : undefined}
                                aria-label={locked ? `${item.label}, admin only` : item.label}
                                className={`${buttonClass} min-h-10 gap-2 whitespace-nowrap px-3 ${panel === item.id ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'} disabled:bg-slate-50 disabled:text-slate-400`}
                            >
                                <span>{item.label}</span>
                                <span className={`hidden text-[11px] font-normal sm:inline ${panel === item.id ? 'text-slate-300' : 'text-slate-400'}`}>{locked ? 'Admin only' : item.summary}</span>
                            </button>
                        );
                    })}
                </div>
            </nav>

            {panel === 'overview' ? (
                <Overview
                settings={settings}
                    tools={tools}
                    activity={activity}
                    selfTest={selfTest}
                    onSelfTest={() => selfTestMutation.mutate()}
                    isSelfTesting={selfTestMutation.isPending}
                />
            ) : null}
            {panel === 'tokens' && canManageTokens ? (
                <Tokens tools={tools} tokens={tokensQuery.data?.tokens || []} isLoading={tokensQuery.isLoading} isError={tokensQuery.isError} mintMutation={mintMutation} revokeMutation={revokeMutation} />
            ) : null}
            {panel === 'tools' ? (
                <Tools tools={tools} activity={activity} canManage={canManage} isSaving={updateMutation.isPending} onUpdate={(payload) => saveSettings(payload, 'Tool registry updated.')} previewMutation={previewMutation} />
            ) : null}
            {panel === 'knowledge' ? <McpKnowledgePanel canManage={canManage} /> : null}
            {panel === 'quality' ? <McpQualityPanel canManage={canManage} /> : null}
            {panel === 'privacy' ? (
                <Privacy settings={settings} tools={tools} canManage={canManage} isSaving={updateMutation.isPending} onSave={saveSettings} previewMutation={previewMutation} />
            ) : null}
            {panel === 'limits' ? (
                <Limits settings={settings} activity={activity} canManage={canManage} isSaving={updateMutation.isPending} onSave={saveSettings} />
            ) : null}
            {panel === 'activity' ? (
                <Activity rows={activity.rows || []} summary={activity.summary || {}} tools={tools} filters={activityFilters} setFilters={setActivityFilters} isLoading={activityQuery.isLoading} isFetching={activityQuery.isFetching} onRefresh={() => activityQuery.refetch()} />
            ) : null}
            {panel === 'guide' ? (
                <Guide endpoint={endpoint} tools={tools} selfTest={selfTest} onSelfTest={() => selfTestMutation.mutate()} isSelfTesting={selfTestMutation.isPending} />
            ) : null}

            <ConfirmDialog
                open={confirmDisable}
                title="Disable the MCP server?"
                message="New client requests will be rejected until an administrator enables the endpoint again. Existing audit data is retained."
                confirmLabel="Disable server"
                tone="danger"
                onCancel={() => setConfirmDisable(false)}
                onConfirm={() => { setConfirmDisable(false); saveSettings({ enabled: false }, 'MCP server disabled.'); }}
                isPending={updateMutation.isPending}
            />
        </div>
    );
}

function McpWorkspaceHeader({ endpoint, enabled, canManage, isSaving, onToggle, onSelfTest, isSelfTesting, protocol }) {
    return (
        <section className="crm-surface overflow-hidden">
            <div className="flex flex-col gap-4 border-b border-slate-200 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">MCP control station</p>
                        <StatusPill tone={enabled ? 'success' : 'warning'} label={enabled ? 'Server enabled' : 'Server disabled'} />
                    </div>
                    <h2 className="mt-2 text-xl font-semibold text-slate-950">Govern the CRM data boundary</h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Manage the read-only endpoint, credentials, tool access, privacy posture and audit trail from one place.</p>
                    <div className="mt-4 flex min-w-0 flex-wrap items-center gap-2 text-xs text-slate-500">
                        <code className="max-w-full break-all rounded bg-slate-100 px-2 py-1 font-mono text-[11px] text-slate-700">{endpoint}</code>
                        <CopyButton value={endpoint} label="Copy endpoint" />
                    </div>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <button type="button" onClick={onSelfTest} disabled={isSelfTesting} className={secondaryButtonClass}>{isSelfTesting ? 'Testing...' : 'Run self-test'}</button>
                    <button type="button" onClick={onToggle} disabled={!canManage || isSaving} className={`${buttonClass} ${enabled ? 'bg-rose-700 text-white hover:bg-rose-800' : 'bg-teal-700 text-white hover:bg-teal-800'}`}>
                        {enabled ? 'Disable server' : 'Enable server'}
                    </button>
                </div>
            </div>
            {!canManage ? <div className="border-b border-amber-200 bg-amber-50 px-5 py-2.5 text-xs text-amber-900">Read-only view. An administrator is required to change MCP settings or credentials.</div> : null}
            <div className="grid grid-cols-2 divide-x divide-y divide-slate-200 sm:grid-cols-4 sm:divide-y-0">
                <HeaderFact label="Protocol" value={protocol} />
                <HeaderFact label="Transport" value="Stateless HTTP" />
                <HeaderFact label="Write access" value="Never" />
                <HeaderFact label="Policy" value="Pseudonymous" />
            </div>
        </section>
    );
}

function HeaderFact({ label, value }) {
    return <div className="px-5 py-3"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p><p className="mt-1 text-sm font-semibold text-slate-800">{value}</p></div>;
}

function Overview({ settings, tools, activity, selfTest, onSelfTest, isSelfTesting }) {
    const summary = activity.summary || {};
    const enabledTools = tools.filter((tool) => tool.enabled).length;
    const totalTools = tools.length;
    const rowBudget = Number(settings.limits?.daily_row_budget || 0);
    const byteBudget = Number(settings.limits?.daily_bytes_budget || 0);
    const rowUsage = rowBudget ? Math.min(100, (Number(summary.rows_today || 0) / rowBudget) * 100) : 0;
    const byteUsage = byteBudget ? Math.min(100, (Number(summary.bytes_today || 0) / byteBudget) * 100) : 0;

    return (
        <div className="space-y-4">
            <MetricGrid metrics={[
                { label: 'Calls today', value: formatNumber(summary.calls_today), detail: 'All MCP requests' },
                { label: 'Rows returned', value: formatNumber(summary.rows_today), detail: `${formatPercent(rowUsage)} of daily budget`, tone: rowUsage > 80 ? 'warning' : 'default' },
                { label: 'Payload out', value: formatBytes(summary.bytes_today), detail: `${formatPercent(byteUsage)} of daily budget`, tone: byteUsage > 80 ? 'warning' : 'default' },
                { label: 'P95 latency', value: `${formatNumber(summary.p95_latency_ms)} ms`, detail: 'Today, successful and refused' },
                { label: 'Refusals', value: formatNumber(summary.refusals_today), detail: summary.refusals_today ? 'Review Activity' : 'No refusals today', tone: summary.refusals_today ? 'warning' : 'success' },
                { label: 'Active tokens', value: formatNumber(summary.active_tokens), detail: summary.expiring_tokens ? `${summary.expiring_tokens} expire within 7 days` : 'No near-expiry tokens', tone: summary.expiring_tokens ? 'warning' : 'default' },
            ]} />

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(300px,0.8fr)]">
                <LiveCallFeed rows={activity.rows || []} />
                <SelfTest selfTest={selfTest} onSelfTest={onSelfTest} isPending={isSelfTesting} />
            </div>

            <Section title="Current exposure" description="The active registry is what appears in tools/list for an eligible client.">
                <div className="grid gap-3 sm:grid-cols-3">
                    <ExposureFact label="Tools visible" value={`${enabledTools} of ${totalTools}`} detail="Enabled for this account" />
                    <ExposureFact label="Default row cap" value={formatNumber(settings.sql_hatch?.default_row_limit)} detail="SQL hatch only" />
                    <ExposureFact label="Retention" value={`${formatNumber(settings.audit?.retain_days)} days`} detail="Audit records" />
                </div>
            </Section>
        </div>
    );
}

function MetricGrid({ metrics }) {
    return <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3 xl:grid-cols-6">{metrics.map((metric) => <Metric key={metric.label} {...metric} />)}</div>;
}

function Metric({ label, value, detail, tone = 'default' }) {
    const valueClass = tone === 'warning' ? 'text-amber-700' : tone === 'success' ? 'text-emerald-700' : 'text-slate-950';
    return <div className="min-h-[104px] bg-white px-4 py-3"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p><p className={`mt-2 font-mono text-xl font-semibold ${valueClass}`}>{value ?? 0}</p><p className="mt-1 text-xs text-slate-500">{detail}</p></div>;
}

function ExposureFact({ label, value, detail }) {
    return <div className="border-l-2 border-teal-500 pl-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-semibold text-slate-900">{value}</p><p className="mt-1 text-xs text-slate-400">{detail}</p></div>;
}

function LiveCallFeed({ rows }) {
    return (
        <Section title="Live call feed" description="Recent requests refresh every five seconds while Overview is open." action={<span className="text-xs text-slate-400">{rows.length ? `${rows.length} recent` : 'Waiting for first call'}</span>}>
            {rows.length ? <CallTable rows={rows.slice(0, 8)} compact /> : <EmptyState title="No MCP calls yet" message="Once a client connects, the latest requests, latency and refusal reasons will appear here." />}
        </Section>
    );
}

function SelfTest({ selfTest, onSelfTest, isPending }) {
    return (
        <Section title="Connection health" description="Each check includes the next operator action when something fails." action={<button type="button" onClick={onSelfTest} disabled={isPending} className={`${secondaryButtonClass} text-xs`}>{isPending ? 'Running...' : 'Run self-test'}</button>}>
            {selfTest?.checks?.length ? <div className="space-y-2">{selfTest.checks.map((check) => <div key={check.key} className="flex gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><StatusDot ok={check.status === 'ok'} /><div><p className="font-medium text-slate-800">{check.message}</p><p className="mt-0.5 text-xs text-slate-500">{check.status === 'ok' ? 'Ready' : check.remediation || 'Review the MCP settings.'}</p></div></div>)}</div> : <EmptyState title="Self-test has not run" message="Verify configuration, database reachability and the active registry before connecting a client." />}
        </Section>
    );
}

function Tokens({ tools, tokens, isLoading, isError, mintMutation, revokeMutation }) {
    const [mintOpen, setMintOpen] = useState(false);
    const [issued, setIssued] = useState(null);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('active');
    const [revokeToken, setRevokeToken] = useState(null);
    const filtered = tokens.filter((token) => {
        const matchesQuery = !query || `${token.label} ${token.owner || ''}`.toLowerCase().includes(query.toLowerCase());
        const matchesStatus = status === 'all' || (token.status || 'active') === status;
        return matchesQuery && matchesStatus;
    });

    const mint = (payload) => mintMutation.mutate(payload, {
        onSuccess: (data) => { setIssued(data); setMintOpen(false); },
    });

    return (
        <div className="space-y-4">
            <Section title="Access tokens" description="Read-only credentials expire automatically. The plaintext token is shown once and cannot be recovered." action={<button type="button" onClick={() => setMintOpen(true)} className={primaryButtonClass}>Mint token</button>}>
                <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center">
                    <label className="flex-1"><span className="sr-only">Search tokens</span><input value={query} onChange={(event) => setQuery(event.target.value)} className={inputClass} placeholder="Search label or owner" /></label>
                    <label className="sm:w-44"><span className="sr-only">Token status</span><select value={status} onChange={(event) => setStatus(event.target.value)} className={inputClass}><option value="active">Active tokens</option><option value="expired">Expired tokens</option><option value="all">All statuses</option></select></label>
                    <button type="button" onClick={() => exportRowsToCsv('mcp-tokens', tokenCsvColumns, filtered)} disabled={!filtered.length} className={`${secondaryButtonClass} shrink-0`}>Export CSV</button>
                </div>
                {issued ? <IssuedToken token={issued} /> : null}
                {!isLoading && !isError ? <TokenUsageSummary tokens={filtered} /> : null}
                {isLoading ? <ListSkeleton rows={3} /> : isError ? <EmptyState title="Tokens are unavailable" message="Only administrators can inspect and manage MCP credentials." /> : filtered.length ? <div className="divide-y divide-slate-200">{filtered.map((token) => <TokenRow key={token.id} token={token} onRevoke={() => setRevokeToken(token)} />)}</div> : <EmptyState title="No matching tokens" message="Mint an expiring token for a client, then return here to monitor its use." />}
            </Section>
            <McpDialog open={mintOpen} title="Mint an MCP token" onClose={() => setMintOpen(false)}>
                <MintTokenForm tools={tools} onCancel={() => setMintOpen(false)} onSubmit={mint} isPending={mintMutation.isPending} />
            </McpDialog>
            <ConfirmDialog open={Boolean(revokeToken)} title="Revoke this token?" message={revokeToken ? `The ${revokeToken.label} credential will stop working immediately.` : ''} confirmLabel="Revoke token" tone="danger" onCancel={() => setRevokeToken(null)} onConfirm={() => { const id = revokeToken?.id; setRevokeToken(null); if (id) revokeMutation.mutate(id); }} isPending={revokeMutation.isPending} />
        </div>
    );
}

function MintTokenForm({ tools, onCancel, onSubmit, isPending }) {
    const [label, setLabel] = useState('');
    const [ttl, setTtl] = useState(90);
    const [scoped, setScoped] = useState(false);
    const [selectedTools, setSelectedTools] = useState([]);
    const enabledTools = tools.filter((tool) => tool.enabled);
    const submit = (event) => {
        event.preventDefault();
        onSubmit({ label: label.trim(), ttl_days: Number(ttl), tools: scoped ? selectedTools : [] });
    };
    return <form onSubmit={submit} className="space-y-4"><Field label="Token label" hint="Use a device or client name you will recognize later."><input required value={label} onChange={(event) => setLabel(event.target.value)} className={inputClass} placeholder="e.g. Ian MacBook" autoFocus /></Field><Field label="Lifetime" hint="Maximum 365 days."><div className="flex items-center gap-2"><input required type="number" min="1" max="365" value={ttl} onChange={(event) => setTtl(event.target.value)} className={`${inputClass} max-w-32`} /><span className="text-sm text-slate-500">days</span></div></Field><label className="flex items-start gap-2 text-sm text-slate-700"><input type="checkbox" checked={scoped} onChange={(event) => setScoped(event.target.checked)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" /><span><span className="font-medium">Limit this token to selected tools</span><span className="mt-0.5 block text-xs text-slate-500">Leave off to follow the owner's full eligible tool set.</span></span></label>{scoped ? <div className="grid max-h-48 gap-2 overflow-y-auto rounded-md border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">{enabledTools.map((tool) => <label key={tool.name} className="flex items-center gap-2 text-xs text-slate-700"><input type="checkbox" checked={selectedTools.includes(tool.name)} onChange={(event) => setSelectedTools((current) => event.target.checked ? [...current, tool.name] : current.filter((name) => name !== tool.name))} className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />{tool.name}</label>)}</div> : null}<div className="flex justify-end gap-2 border-t border-slate-200 pt-4"><button type="button" onClick={onCancel} className={secondaryButtonClass}>Cancel</button><button type="submit" disabled={isPending || !label.trim()} className={primaryButtonClass}>{isPending ? 'Minting...' : 'Mint token'}</button></div></form>;
}

function TokenRow({ token, onRevoke }) {
    const expired = token.status === 'expired';
    return <div className="flex flex-col gap-3 py-4 lg:flex-row lg:items-center lg:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><p className="font-semibold text-slate-900">{token.label}</p><StatusPill tone={expired ? 'neutral' : 'success'} label={expired ? 'Expired' : 'Active'} /></div><p className="mt-1 text-xs text-slate-500">{token.owner || 'Unknown owner'} · {ROLE_LABELS[token.role] || token.role || 'Unknown role'} · {token.abilities?.length || 0} tool scopes</p><p className="mt-1 text-xs text-slate-400">Expires {formatDate(token.expires_at)} · Last used {formatDate(token.last_used_at, 'Never')}</p></div><div className="flex flex-wrap items-center gap-4 text-xs text-slate-500"><span><strong className="font-mono text-slate-800">{formatNumber(token.calls_7d)}</strong> calls / 7d</span><span><strong className="font-mono text-slate-800">{formatBytes(token.bytes_7d)}</strong> out</span><span><strong className="font-mono text-slate-800">{formatCostRange(token.bytes_7d)}</strong> rough cost</span><button type="button" onClick={onRevoke} className="font-semibold text-rose-700 hover:text-rose-800">{expired ? 'Delete' : 'Revoke'}</button></div></div>;
}

function TokenUsageSummary({ tokens }) {
    const bytes = tokens.reduce((total, token) => total + Number(token.bytes_7d || 0), 0);
    const calls = tokens.reduce((total, token) => total + Number(token.calls_7d || 0), 0);
    return <div className="mb-4 grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-3"><MiniFact label="Visible tokens" value={formatNumber(tokens.length)} /><MiniFact label="Calls / 7d" value={formatNumber(calls)} /><div className="bg-white px-3 py-2"><p className="text-[10px] uppercase tracking-[0.1em] text-slate-400">Rough model cost / 7d</p><p className="mt-1 font-mono text-sm font-semibold text-slate-800">{formatCostRange(bytes)}</p><p className="mt-1 text-[11px] leading-4 text-slate-400">Payload-only estimate using about 4 bytes/token and $3-$15 per million output tokens.</p></div></div>;
}

function IssuedToken({ token }) {
    const endpoint = `${window.location.origin}/api/mcp`;
    const macClaude = `export EXOTIC_MCP_TOKEN='${token.token}'\nclaude mcp add --transport http exotic ${endpoint} --header "Authorization: Bearer $EXOTIC_MCP_TOKEN"`;
    const windowsClaude = `$env:EXOTIC_MCP_TOKEN = '${token.token}'\nclaude mcp add --transport http exotic ${endpoint} --header "Authorization: Bearer $env:EXOTIC_MCP_TOKEN"`;
    const codex = `[mcp_servers.exotic]\nurl = "${endpoint}"\nbearer_token_env_var = "EXOTIC_MCP_TOKEN"`;
    const bundle = `Exotic CRM MCP setup\n\nEndpoint: ${endpoint}\nToken: ${token.token}\n\nmacOS / Linux (Terminal)\n${macClaude}\n\nWindows (PowerShell, then Git Bash or WSL)\n${windowsClaude}\n\nCodex CLI config.toml\n${codex}\n\nVerify in Claude Code\nclaude mcp list`;
    return <div className="my-4 rounded-md border border-teal-200 bg-teal-50 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-semibold text-teal-950">Token minted: copy the full setup now</p><p className="mt-1 text-xs text-teal-800">This plaintext credential will not be shown again. Expires {formatDate(token.expires_at)}.</p></div><div className="flex flex-wrap gap-2"><CopyButton value={token.token} label="Copy token" /><CopyButton value={bundle} label="Copy full setup bundle" /></div></div><code className="mt-3 block max-h-20 overflow-auto break-all rounded bg-white/70 p-2 font-mono text-xs text-teal-950">{token.token}</code><div className="mt-3 grid gap-2 md:grid-cols-2"><CommandBlock label="macOS / Linux" value={macClaude} /><CommandBlock label="Windows PowerShell" value={windowsClaude} /><CommandBlock label="Codex CLI" value={codex} /></div></div>;
}

function Tools({ tools, activity, canManage, isSaving, onUpdate, previewMutation }) {
    const [domain, setDomain] = useState('all');
    const [status, setStatus] = useState('all');
    const [selectedName, setSelectedName] = useState(tools[0]?.name || '');
    const [previewOpen, setPreviewOpen] = useState(false);
    useEffect(() => { if (!tools.some((tool) => tool.name === selectedName)) setSelectedName(tools[0]?.name || ''); }, [selectedName, tools]);
    const filtered = tools.filter((tool) => (domain === 'all' || tool.domain === domain) && (status === 'all' || (status === 'enabled' ? tool.enabled : !tool.enabled)));
    const selected = tools.find((tool) => tool.name === selectedName) || filtered[0];
    const stats = selected ? activity.tool_stats?.[selected.name] || {} : {};
    return <div className="space-y-4"><Section title="Tool registry" description={`${tools.filter((tool) => tool.enabled).length} of ${tools.length} tools enabled. Disabled tools disappear from the next tools/list response.`} action={<button type="button" onClick={() => exportRowsToCsv('mcp-tools', toolCsvColumns, tools)} className={secondaryButtonClass}>Export CSV</button>}><div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row"><label className="sm:w-48"><span className="sr-only">Tool domain</span><select value={domain} onChange={(event) => setDomain(event.target.value)} className={inputClass}><option value="all">All domains</option>{Object.entries(DOMAIN_LABELS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label><label className="sm:w-48"><span className="sr-only">Tool status</span><select value={status} onChange={(event) => setStatus(event.target.value)} className={inputClass}><option value="all">All statuses</option><option value="enabled">Enabled</option><option value="disabled">Disabled</option></select></label></div><div className="grid gap-4 pt-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.8fr)]"><div className="overflow-hidden rounded-md border border-slate-200"><div className="hidden grid-cols-[minmax(220px,2fr)_110px_80px_80px_72px] gap-3 border-b border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500 md:grid"><span>Tool</span><span>Minimum role</span><span>Calls / 7d</span><span>Avg ms</span><span>Status</span></div>{filtered.map((tool) => { const toolStats = activity.tool_stats?.[tool.name] || {}; const active = selected?.name === tool.name; return <button type="button" key={tool.name} onClick={() => setSelectedName(tool.name)} className={`grid w-full gap-2 border-b border-slate-200 px-3 py-3 text-left transition last:border-b-0 md:grid-cols-[minmax(220px,2fr)_110px_80px_80px_72px] md:items-center md:gap-3 ${active ? 'bg-teal-50/70' : 'bg-white hover:bg-slate-50'}`}><span className="min-w-0"><span className="flex flex-wrap items-center gap-2"><span className="truncate font-mono text-xs font-semibold text-slate-900">{tool.name}</span><span className="text-[10px] text-slate-400">{DOMAIN_LABELS[tool.domain] || tool.domain}</span></span><span className="mt-1 block text-xs text-slate-500">{tool.description}</span></span><span className="text-xs text-slate-500"><span className="md:hidden">Role: </span>{ROLE_LABELS[tool.configured_role] || tool.configured_role}</span><span className="font-mono text-xs text-slate-700"><span className="md:hidden">7d: </span>{formatNumber(toolStats.calls_7d)}</span><span className="font-mono text-xs text-slate-700"><span className="md:hidden">Avg: </span>{formatNumber(toolStats.avg_latency_ms)}</span><span><StatusPill tone={tool.enabled ? 'success' : 'neutral'} label={tool.enabled ? 'On' : 'Off'} /></span></button>; })}</div>{selected ? <ToolInspector tool={selected} stats={stats} canManage={canManage} isSaving={isSaving} onToggle={() => onUpdate({ tools: { [selected.name]: { enabled: !selected.enabled, min_role: selected.configured_role } } })} onPreview={() => setPreviewOpen(true)} /> : <EmptyState title="No tools match" message="Change the domain or status filters." />}</div></Section><McpDialog open={previewOpen} title={`Preview ${selected?.name || 'tool'}`} onClose={() => setPreviewOpen(false)}><ToolPreviewForm tool={selected} previewMutation={previewMutation} onClose={() => setPreviewOpen(false)} /></McpDialog></div>;
}

function ToolInspector({ tool, stats, canManage, isSaving, onToggle, onPreview }) {
    return <aside className="rounded-md border border-slate-200 bg-slate-50 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-mono text-sm font-semibold text-slate-950">{tool.name}</p><p className="mt-1 text-sm leading-6 text-slate-600">{tool.description}</p></div><StatusPill tone={tool.enabled ? 'success' : 'neutral'} label={tool.enabled ? 'Enabled' : 'Disabled'} /></div><div className="mt-4 grid grid-cols-2 gap-2"><MiniFact label="Min role" value={ROLE_LABELS[tool.configured_role] || tool.configured_role} /><MiniFact label="Calls / 7d" value={formatNumber(stats.calls_7d)} /><MiniFact label="Average" value={`${formatNumber(stats.avg_latency_ms)} ms`} /><MiniFact label="Errors" value={formatNumber(stats.errors_7d)} /></div><div className="mt-4 border-t border-slate-200 pt-4"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Arguments</p><pre className="mt-2 max-h-32 overflow-auto rounded-md bg-white p-3 font-mono text-[11px] leading-5 text-slate-700">{JSON.stringify(tool.inputSchema?.properties || {}, null, 2)}</pre></div><div className="mt-4"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Reads</p><p className="mt-2 text-xs text-slate-600">{tool.backing_service}{tool.views?.length ? ` · ${tool.views.join(', ')}` : ''}</p><p className="mt-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Returns no</p><p className="mt-2 text-xs leading-5 text-slate-600">{(tool.returns_no || []).join(' · ')}</p></div><div className="mt-4 flex flex-wrap gap-2"><button type="button" onClick={onPreview} disabled={!tool.enabled} className={secondaryButtonClass}>Preview payload</button><button type="button" onClick={onToggle} disabled={!canManage || isSaving} className={`${buttonClass} ${tool.enabled ? 'border border-rose-200 bg-white text-rose-700 hover:bg-rose-50' : 'bg-teal-700 text-white hover:bg-teal-800'}`}>{tool.enabled ? 'Disable tool' : 'Enable tool'}</button></div>{!canManage ? <p className="mt-3 text-xs text-amber-700">Admin permission is required to change the registry.</p> : null}</aside>;
}

function ToolPreviewForm({ tool, previewMutation, onClose }) {
    const [argsText, setArgsText] = useState('{}');
    const [error, setError] = useState('');
    const result = previewMutation.data;
    const submit = () => {
        try { setError(''); previewMutation.mutate({ tool: tool.name, arguments: JSON.parse(argsText || '{}') }); } catch { setError('Arguments must be valid JSON.'); }
    };
    return <div className="space-y-4"><p className="text-sm text-slate-600">This executes the selected tool with the same governance and sanitizer used by a real client, then discards the result after previewing it.</p><label className="block"><span className="mb-1 block text-sm font-medium text-slate-700">Arguments JSON</span><textarea value={argsText} onChange={(event) => setArgsText(event.target.value)} className={`${inputClass} min-h-28 font-mono text-xs`} spellCheck="false" /></label>{error ? <p className="text-sm text-rose-700">{error}</p> : null}<div className="flex justify-end gap-2"><button type="button" onClick={onClose} className={secondaryButtonClass}>Close</button><button type="button" onClick={submit} disabled={previewMutation.isPending || !tool.enabled} className={primaryButtonClass}>{previewMutation.isPending ? 'Running...' : 'Run preview'}</button></div>{result ? <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-semibold text-emerald-900">PII scan passed</p><span className="text-xs text-emerald-800">{formatBytes(result.bytes)} · {formatNumber(result.row_count)} rows</span></div><pre className="mt-3 max-h-72 overflow-auto rounded bg-white/70 p-3 font-mono text-[11px] leading-5 text-slate-800">{JSON.stringify(result.payload, null, 2)}</pre><p className="mt-2 text-xs text-emerald-800">Checked: {(result.pii_scan?.fields_checked || []).join(', ')}.</p></div> : null}</div>;
}

function Privacy({ settings, tools, canManage, isSaving, onSave, previewMutation }) {
    const [mode, setMode] = useState(settings.pii_mode || 'pseudonymous');
    const [sqlEnabled, setSqlEnabled] = useState(Boolean(settings.sql_hatch?.enabled));
    const [views, setViews] = useState(settings.sql_hatch?.views || []);
    const [newView, setNewView] = useState('');
    const [truncateChars, setTruncateChars] = useState(settings.sanitisation?.truncate_chars || 500);
    const [stripUrls, setStripUrls] = useState(settings.sanitisation?.strip_urls !== false);
    const [stripCredentials, setStripCredentials] = useState(settings.sanitisation?.strip_credentials !== false);
    const [previewTool, setPreviewTool] = useState('exotic_catalog');
    const activeTools = tools.filter((tool) => tool.enabled);
    useEffect(() => { setMode(settings.pii_mode || 'pseudonymous'); setSqlEnabled(Boolean(settings.sql_hatch?.enabled)); setViews(settings.sql_hatch?.views || []); setTruncateChars(settings.sanitisation?.truncate_chars || 500); setStripUrls(settings.sanitisation?.strip_urls !== false); setStripCredentials(settings.sanitisation?.strip_credentials !== false); }, [settings]);
    const save = () => onSave({ pii_mode: mode, sql_hatch: { ...settings.sql_hatch, enabled: sqlEnabled, views }, sanitisation: { ...settings.sanitisation, truncate_chars: Number(truncateChars), strip_urls: stripUrls, strip_credentials: stripCredentials } }, 'Privacy policy saved.');
    const addView = () => { const value = newView.trim(); if (value && !views.includes(value)) setViews((current) => [...current, value]); setNewView(''); };
    return <div className="space-y-4"><Section title="Privacy posture" description="Choose whether the model can reason over pseudonymous entity rows or only aggregate results."><div className="grid gap-3 lg:grid-cols-2"><ChoiceCard active={mode === 'pseudonymous'} title="Pseudonymous" description="Stable handles and per-entity rows are allowed. Names, contact details, bios and raw URLs never cross the boundary." onClick={() => setMode('pseudonymous')} /><ChoiceCard active={mode === 'aggregate_only'} title="Aggregate only" description="Counts and sums only. Per-entity tools such as client snapshots are removed from the visible registry." onClick={() => setMode('aggregate_only')} /></div></Section><Section title="Reporting views and SQL-safe wrappers" description="Only explicitly allow-listed views may be queried through the SQL hatch." action={<button type="button" onClick={save} disabled={!canManage || isSaving} className={primaryButtonClass}>{isSaving ? 'Saving...' : 'Save privacy policy'}</button>}><div className="space-y-2">{views.map((view) => <div key={view} className="flex items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 py-2.5"><code className="font-mono text-xs text-slate-800">{view}</code><button type="button" onClick={() => setViews((current) => current.filter((item) => item !== view))} disabled={!canManage} className="text-xs font-semibold text-rose-700">Remove</button></div>)}<div className="flex gap-2"><input value={newView} onChange={(event) => setNewView(event.target.value)} className={inputClass} placeholder="vw_mcp_example_rollup" /><button type="button" onClick={addView} disabled={!canManage || !newView.trim()} className={secondaryButtonClass}>Add view</button></div></div><label className="mt-4 flex items-center gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700"><input type="checkbox" checked={sqlEnabled} onChange={(event) => setSqlEnabled(event.target.checked)} disabled={!canManage} className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" /><span><span className="font-semibold">Enable SQL hatch</span><span className="mt-0.5 block text-xs text-slate-500">Single-statement SELECTs only, with forced limits and forbidden output fields.</span></span></label></Section><Section title="Sanitisation rules" description="These rules are applied before a tool result leaves the CRM."><div className="grid gap-3 md:grid-cols-3"><Field label="Truncate text at" hint="characters"><input type="number" min="50" max="5000" value={truncateChars} onChange={(event) => setTruncateChars(event.target.value)} disabled={!canManage} className={inputClass} /></Field><CheckField label="Strip URLs" checked={stripUrls} onChange={setStripUrls} disabled={!canManage} /><CheckField label="Strip credential patterns" checked={stripCredentials} onChange={setStripCredentials} disabled={!canManage} /></div></Section><Section title="Payload preview" description="Run a real sanitized response and inspect the exact JSON the client would receive." action={<select value={previewTool} onChange={(event) => setPreviewTool(event.target.value)} className={`${inputClass} w-auto text-xs`}><option value="exotic_catalog">exotic_catalog</option>{activeTools.filter((tool) => tool.name !== 'exotic_catalog').map((tool) => <option key={tool.name} value={tool.name}>{tool.name}</option>)}</select>}><div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-600">Preview runs with empty arguments. Tools requiring arguments can be inspected from the Tools panel.</p><button type="button" onClick={() => previewMutation.mutate({ tool: previewTool, arguments: {} })} disabled={previewMutation.isPending} className={secondaryButtonClass}>{previewMutation.isPending ? 'Running...' : 'Run payload preview'}</button></div>{previewMutation.data ? <div className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-3"><div className="flex flex-wrap justify-between gap-2 text-sm"><strong className="text-emerald-900">PII scan passed</strong><span className="text-xs text-emerald-800">{formatBytes(previewMutation.data.bytes)} · {formatNumber(previewMutation.data.row_count)} rows</span></div><pre className="mt-3 max-h-72 overflow-auto rounded bg-white/70 p-3 font-mono text-[11px] leading-5 text-slate-800">{JSON.stringify(previewMutation.data.payload, null, 2)}</pre></div> : null}</Section></div>;
}

function Limits({ settings, activity, canManage, isSaving, onSave }) {
    const defaults = settings.limits || {};
    const [draft, setDraft] = useState({ rate_per_minute: defaults.rate_per_minute || 30, daily_row_budget: defaults.daily_row_budget || 200000, daily_bytes_budget: defaults.daily_bytes_budget || 50000000, on_exhaustion: defaults.on_exhaustion || 'throttle' });
    useEffect(() => setDraft({ rate_per_minute: defaults.rate_per_minute || 30, daily_row_budget: defaults.daily_row_budget || 200000, daily_bytes_budget: defaults.daily_bytes_budget || 50000000, on_exhaustion: defaults.on_exhaustion || 'throttle' }), [defaults.rate_per_minute, defaults.daily_row_budget, defaults.daily_bytes_budget, defaults.on_exhaustion]);
    const rows = Number(activity.summary?.rows_today || 0);
    const bytes = Number(activity.summary?.bytes_today || 0);
    const tokens = Object.values((activity.rows || []).reduce((acc, row) => { const key = row.token_label || 'Session'; acc[key] = acc[key] || { label: key, rows: 0, bytes: 0 }; acc[key].rows += Number(row.row_count || 0); acc[key].bytes += Number(row.bytes_out || 0); return acc; }, {})).sort((a, b) => b.bytes - a.bytes).slice(0, 6);
    const save = () => onSave({ limits: { ...draft, rate_per_minute: Number(draft.rate_per_minute), daily_row_budget: Number(draft.daily_row_budget), daily_bytes_budget: Number(draft.daily_bytes_budget) } }, 'Limits saved.');
    return <div className="space-y-4"><Section title="Budgets and throttling" description="Changes are staged locally and applied together, so a partially edited number never reaches the server." action={<div className="flex gap-2"><button type="button" onClick={() => setDraft({ rate_per_minute: defaults.rate_per_minute || 30, daily_row_budget: defaults.daily_row_budget || 200000, daily_bytes_budget: defaults.daily_bytes_budget || 50000000, on_exhaustion: defaults.on_exhaustion || 'throttle' })} className={secondaryButtonClass}>Cancel</button><button type="button" onClick={save} disabled={!canManage || isSaving} className={primaryButtonClass}>{isSaving ? 'Saving...' : 'Save limits'}</button></div>}><div className="grid gap-4 md:grid-cols-3"><Field label="Calls per minute" hint="Rate limit"><input type="number" min="1" max="600" value={draft.rate_per_minute} onChange={(event) => setDraft({ ...draft, rate_per_minute: event.target.value })} disabled={!canManage} className={inputClass} /></Field><Field label="Daily rows" hint="Maximum returned rows"><input type="number" min="1" max="10000000" value={draft.daily_row_budget} onChange={(event) => setDraft({ ...draft, daily_row_budget: event.target.value })} disabled={!canManage} className={inputClass} /></Field><Field label="Daily bytes" hint="Maximum payload out"><input type="number" min="1" max="1000000000" value={draft.daily_bytes_budget} onChange={(event) => setDraft({ ...draft, daily_bytes_budget: event.target.value })} disabled={!canManage} className={inputClass} /></Field></div><div className="mt-4 max-w-sm"><Field label="When a budget is exhausted"><select value={draft.on_exhaustion} onChange={(event) => setDraft({ ...draft, on_exhaustion: event.target.value })} disabled={!canManage} className={inputClass}><option value="throttle">Throttle with 429</option><option value="refuse">Refuse and audit</option></select></Field></div></Section><Section title="Today's consumption" description="Usage is based on recorded MCP calls and resets at the application timezone boundary."><BudgetBar label="Rows returned" used={rows} total={Number(draft.daily_row_budget)} /><BudgetBar label="Payload out" used={bytes} total={Number(draft.daily_bytes_budget)} bytes /><div className="mt-5 border-t border-slate-200 pt-4"><p className="text-sm font-semibold text-slate-800">Top token consumption</p>{tokens.length ? <div className="mt-3 space-y-3">{tokens.map((token) => <div key={token.label}><div className="flex justify-between gap-3 text-xs"><span className="truncate text-slate-600">{token.label}</span><span className="font-mono text-slate-800">{formatBytes(token.bytes)} · {formatNumber(token.rows)} rows</span></div><div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-200"><div className="h-full bg-teal-600" style={{ width: `${Math.min(100, (token.bytes / Math.max(1, bytes)) * 100)}%` }} /></div></div>)}</div> : <EmptyState title="No token usage today" message="Consumption will appear after the first recorded call." compact />}</div></Section></div>;
}

function BudgetBar({ label, used, total, bytes = false }) {
    const percent = total ? Math.min(100, (used / total) * 100) : 0;
    return <div className="mb-4 last:mb-0"><div className="flex justify-between gap-3 text-sm"><span className="text-slate-700">{label}</span><span className="font-mono text-xs text-slate-500">{bytes ? formatBytes(used) : formatNumber(used)} / {bytes ? formatBytes(total) : formatNumber(total)}</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"><div className={`h-full ${percent > 80 ? 'bg-amber-500' : 'bg-teal-600'}`} style={{ width: `${percent}%` }} /></div><p className="mt-1 text-xs text-slate-400">{formatPercent(percent)} used</p></div>;
}

function Activity({ rows, summary, tools, filters, setFilters, isLoading, isFetching, onRefresh }) {
    const exportActivity = () => exportRowsToCsv('mcp-activity', activityCsvColumns, rows);
    return <div className="space-y-4"><Section title="Activity" description="Searchable audit trail for successful, refused and failed MCP requests." action={<div className="flex flex-wrap gap-2"><button type="button" onClick={onRefresh} disabled={isFetching} className={secondaryButtonClass}>{isFetching ? 'Refreshing...' : 'Refresh'}</button><button type="button" onClick={exportActivity} disabled={!rows.length} className={secondaryButtonClass}>Export CSV</button></div>}><MetricGrid metrics={[{ label: 'Calls today', value: formatNumber(summary.calls_today), detail: 'All requests' }, { label: 'Rows today', value: formatNumber(summary.rows_today), detail: 'Returned rows' }, { label: 'Payload today', value: formatBytes(summary.bytes_today), detail: 'Bytes out' }, { label: 'P95 latency', value: `${formatNumber(summary.p95_latency_ms)} ms`, detail: 'Today' }]} /><div className="mt-4 grid gap-3 md:grid-cols-5"><label><span className="mb-1 block text-xs font-medium text-slate-600">Tool</span><select value={filters.tool} onChange={(event) => setFilters({ ...filters, tool: event.target.value })} className={inputClass}><option value="">All tools</option>{tools.map((tool) => <option key={tool.name} value={tool.name}>{tool.name}</option>)}</select></label><label><span className="mb-1 block text-xs font-medium text-slate-600">Status</span><select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })} className={inputClass}><option value="">All statuses</option><option value="success">Success</option><option value="refused">Refused</option><option value="failed">Failed</option></select></label><label><span className="mb-1 block text-xs font-medium text-slate-600">Reason</span><input value={filters.refusal_reason} onChange={(event) => setFilters({ ...filters, refusal_reason: event.target.value })} className={inputClass} placeholder="e.g. sql_validation" /></label><label><span className="mb-1 block text-xs font-medium text-slate-600">From</span><input type="date" value={filters.from} onChange={(event) => setFilters({ ...filters, from: event.target.value })} className={inputClass} /></label><label><span className="mb-1 block text-xs font-medium text-slate-600">To</span><input type="date" value={filters.to} onChange={(event) => setFilters({ ...filters, to: event.target.value })} className={inputClass} /></label></div><div className="mt-4">{isLoading ? <ListSkeleton rows={5} /> : rows.length ? <CallTable rows={rows} /> : <EmptyState title="No calls match these filters" message="Try a wider date range or clear the status and reason filters." />}</div></Section></div>;
}

function CallTable({ rows, compact = false }) {
    const [expanded, setExpanded] = useState(new Set());
    const rowKey = (row) => row.id || `${row.tool}-${row.created_at}`;
    const toggle = (key) => setExpanded((current) => {
        const next = new Set(current);
        if (next.has(key)) next.delete(key); else next.add(key);
        return next;
    });
    return <div className="overflow-x-auto rounded-md border border-slate-200"><table className="w-full min-w-[760px] text-left text-sm"><thead className="bg-slate-50 text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500"><tr><th className="px-3 py-2">Tool</th><th className="px-3 py-2">Token</th><th className="px-3 py-2">Rows</th><th className="px-3 py-2">Bytes</th><th className="px-3 py-2">Latency</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">When</th></tr></thead><tbody>{rows.map((row) => { const key = rowKey(row); const isExpanded = expanded.has(key); return <React.Fragment key={key}><tr onClick={() => toggle(key)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle(key); } }} tabIndex={0} aria-expanded={isExpanded} className={`cursor-pointer border-t border-slate-200 bg-white outline-none transition hover:bg-slate-50 focus-visible:bg-teal-50 ${isExpanded ? 'bg-slate-50' : ''}`}><td className="max-w-[260px] px-3 py-2.5 font-mono text-xs text-slate-800"><span className="mr-2 inline-block w-3 text-slate-400" aria-hidden="true">{isExpanded ? '-' : '+'}</span>{row.tool}</td><td className="px-3 py-2.5 text-xs text-slate-500">{row.token_label || 'Session'}</td><td className="px-3 py-2.5 font-mono text-xs text-slate-700">{formatNumber(row.row_count)}</td><td className="px-3 py-2.5 font-mono text-xs text-slate-700">{formatBytes(row.bytes_out)}</td><td className="px-3 py-2.5 font-mono text-xs text-slate-700">{formatNumber(row.latency_ms)} ms</td><td className="px-3 py-2.5"><StatusPill tone={row.status === 'success' ? 'success' : row.status === 'refused' ? 'warning' : 'danger'} label={row.status} />{row.refusal_reason ? <p className="mt-1 text-[11px] text-slate-400">{row.refusal_reason}</p> : null}</td><td className="whitespace-nowrap px-3 py-2.5 text-xs text-slate-500">{formatRelative(row.created_at)}</td></tr>{isExpanded ? <tr className="border-t border-slate-200 bg-slate-50"><td colSpan="7" className="px-4 py-4"><div className="grid gap-3 text-xs sm:grid-cols-2"><DetailFact label="Request ID" value={row.request_id || 'Not recorded'} /><DetailFact label="Recorded at" value={formatDate(row.created_at)} /><DetailFact label="Refusal reason" value={row.refusal_reason || 'None'} /><DetailFact label="Platform scope" value={formatJson(row.platform_scope)} /><div className="sm:col-span-2"><p className="font-semibold uppercase tracking-[0.1em] text-slate-400">Argument summary</p><pre className="mt-1 max-h-32 overflow-auto rounded bg-white p-2 font-mono text-[11px] leading-5 text-slate-700">{formatJson(row.argument_summary)}</pre></div>{row.generated_sql_redacted ? <div className="sm:col-span-2"><p className="font-semibold uppercase tracking-[0.1em] text-slate-400">Redacted SQL preview</p><pre className="mt-1 max-h-32 overflow-auto whitespace-pre-wrap rounded bg-white p-2 font-mono text-[11px] leading-5 text-slate-700">{row.generated_sql_redacted}</pre></div> : null}</div></td></tr> : null}</React.Fragment>; })}</tbody></table>{!compact ? <p className="border-t border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-500">Select any request to inspect its redacted arguments, scope and refusal details.</p> : null}</div>;
}

function Guide({ endpoint, tools, selfTest, onSelfTest, isSelfTesting }) {
    const [client, setClient] = useState('claude');
    const [platform, setPlatform] = useState('mac');
    const [question, setQuestion] = useState('Why did new-user revenue fall this window?');
    const [copied, setCopied] = useState(false);
    const claudeMac = `claude mcp add --transport http exotic ${endpoint} --header "Authorization: Bearer $EXOTIC_MCP_TOKEN"`;
    const claudeWindows = `claude mcp add --transport http exotic ${endpoint} --header "Authorization: Bearer $env:EXOTIC_MCP_TOKEN"`;
    const codex = `[mcp_servers.exotic]\nurl = "${endpoint}"\nbearer_token_env_var = "EXOTIC_MCP_TOKEN"`;
    const examples = [['Why did new-user revenue fall this window?', 'revenue_summary -> market_breakdown -> churn_analysis'], ['Which markets have profiles expiring in 7 days?', 'lifecycle_summary'], ['What errors started after the last deploy?', 'error_digest -> system_vitals']];
    const setupCommand = client === 'claude' ? (platform === 'windows' ? claudeWindows : claudeMac) : codex;
    const copyQuestion = async () => { try { await copyText(question); setCopied(true); window.setTimeout(() => setCopied(false), 1600); } catch { setCopied(false); } };
    return <div className="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.8fr)]"><div className="space-y-4"><Section title="Connect your client" description="Follow these steps in order. Mint a token in Tokens first, then use the setup commands below on your own computer."><div className="space-y-5"><GuideStep number="1" title="Install the Claude Code command line tool"><p className="text-sm leading-6 text-slate-600">The Claude desktop app or an IDE extension does not necessarily install a <code className="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">claude</code> command in Terminal. If <code className="rounded bg-slate-100 px-1 py-0.5 font-mono text-xs">which claude</code> says it is missing, install the CLI separately.</p><div className="grid gap-3 sm:grid-cols-2"><CommandBlock label="macOS / Linux" value={'curl -fsSL https://claude.ai/install.sh | bash\nexec zsh\nwhich claude\nclaude --version'} /><CommandBlock label="Windows PowerShell" value={'npm install -g @anthropic-ai/claude-code\nGet-Command claude\nclaude --version'} /></div><p className="text-xs leading-5 text-slate-500">On Windows, run Claude Code from Git Bash or WSL. Anthropic documents both routes; Git for Windows is required for Git Bash.</p></GuideStep><GuideStep number="2" title="Choose your client and operating system"><div className="flex flex-wrap gap-1 border-b border-slate-200"><button type="button" onClick={() => setClient('claude')} className={`${buttonClass} rounded-b-none ${client === 'claude' ? 'border-b-2 border-teal-700 text-slate-900' : 'text-slate-500'}`}>Claude Code</button><button type="button" onClick={() => setClient('codex')} className={`${buttonClass} rounded-b-none ${client === 'codex' ? 'border-b-2 border-teal-700 text-slate-900' : 'text-slate-500'}`}>Codex CLI</button>{client === 'claude' ? <><span className="mx-1 hidden text-slate-300 sm:inline">|</span><button type="button" onClick={() => setPlatform('mac')} className={`${buttonClass} rounded-b-none ${platform === 'mac' ? 'border-b-2 border-teal-700 text-slate-900' : 'text-slate-500'}`}>macOS / Linux</button><button type="button" onClick={() => setPlatform('windows')} className={`${buttonClass} rounded-b-none ${platform === 'windows' ? 'border-b-2 border-teal-700 text-slate-900' : 'text-slate-500'}`}>Windows</button></> : null}</div><CommandBlock label={client === 'claude' ? `${platform === 'windows' ? 'Windows' : 'macOS / Linux'} connection command` : 'Codex config.toml'} value={setupCommand} /><p className="text-xs leading-5 text-slate-500">For Claude Code, set <code className="rounded bg-slate-100 px-1 py-0.5 font-mono">EXOTIC_MCP_TOKEN</code> in the same shell before running this command. For Codex, keep the token in your local environment; never commit it.</p></GuideStep><GuideStep number="3" title="Set the token, then verify the server"><div className="grid gap-3 sm:grid-cols-2"><CommandBlock label="macOS / Linux" value={'export EXOTIC_MCP_TOKEN=\'PASTE_TOKEN_HERE\'\nclaude mcp list'} /><CommandBlock label="Windows PowerShell" value={'$env:EXOTIC_MCP_TOKEN = \'PASTE_TOKEN_HERE\'\nclaude mcp list'} /></div><p className="text-xs leading-5 text-slate-500">Replace the placeholder only on your machine. A token is a password: do not paste it into Git, shared screenshots or a shared config file.</p></GuideStep><GuideStep number="4" title="Run your first useful request"><p className="text-sm leading-6 text-slate-600">After Claude Code starts, paste one of the prompts below. The model will discover the visible MCP tools and use the appropriate read-only reporting tools.</p><div className="space-y-2">{examples.map(([prompt, path]) => <button type="button" key={prompt} onClick={() => setQuestion(prompt)} className="block w-full rounded-md border border-slate-200 px-3 py-2.5 text-left transition hover:border-teal-300 hover:bg-teal-50/50"><p className="text-sm font-medium text-slate-800">{prompt}</p><p className="mt-1 font-mono text-[11px] text-slate-400">Likely path: {path}</p></button>)}</div></GuideStep></div></Section><Section title="Ask something useful" description={`${tools.filter((tool) => tool.enabled).length} tools are currently visible. Write a question, then copy it into your connected client.`}><textarea value={question} onChange={(event) => setQuestion(event.target.value)} className={`${inputClass} min-h-24 resize-y`} placeholder="Ask about revenue, lifecycle, markets or system health" /><div className="mt-3 flex flex-wrap items-center justify-between gap-3"><p className="text-xs text-slate-500">This control prepares the prompt; Claude Code or Codex runs it after you connect.</p><button type="button" onClick={copyQuestion} disabled={!question.trim()} className={primaryButtonClass}>{copied ? 'Prompt copied' : 'Copy prompt'}</button></div></Section></div><div className="space-y-4"><Section title="Verify connection" action={<button type="button" onClick={onSelfTest} disabled={isSelfTesting} className={secondaryButtonClass}>{isSelfTesting ? 'Running...' : 'Run self-test'}</button>}>{selfTest?.checks?.length ? <div className="space-y-2">{selfTest.checks.map((check) => <div key={check.key} className="flex gap-2 text-sm"><StatusDot ok={check.status === 'ok'} /><span className="text-slate-700">{check.message}</span></div>)}</div> : <p className="text-sm text-slate-500">Run the self-test to verify the endpoint, registry and database path.</p>}</Section><Section title="Troubleshooting"><div className="space-y-3 text-sm"><Trouble title="The claude command is missing" detail="The desktop app and the CLI are separate installs. Install the CLI above, restart your shell, then run which claude or Get-Command claude." /><Trouble title="401 unauthorized" detail="The token is expired, revoked or missing the MCP read ability. Mint a new token and reconnect." /><Trouble title="Tool missing from the list" detail="The tool is disabled or your role is below its minimum role. Check Tools." /><Trouble title="429 throttled" detail="A rate or daily budget was reached. Check Limits for the reset window." /><Trouble title="SQL refused" detail="Only allow-listed reporting views are accepted. Check Data & Privacy." /></div></Section><Section title="What MCP can never do"><p className="text-sm leading-6 text-slate-600">Write anything, read names, phones, emails or bios, read a base table, send a message, or cross into markets the token owner cannot see.</p></Section></div></div>;
}

function GuideStep({ number, title, children }) {
    return <div className="border-l-2 border-teal-500 pl-4"><div className="flex items-center gap-2"><span className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-teal-700 text-xs font-bold text-white">{number}</span><h3 className="text-sm font-semibold text-slate-900">{title}</h3></div><div className="mt-3 space-y-3">{children}</div></div>;
}

function Section({ title, description, action, children }) {
    return <section className="crm-surface overflow-hidden"><div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5"><div><h2 className="text-base font-semibold text-slate-950">{title}</h2>{description ? <p className="mt-1 max-w-3xl text-sm leading-5 text-slate-500">{description}</p> : null}</div>{action ? <div className="shrink-0">{action}</div> : null}</div><div className="p-4 sm:p-5">{children}</div></section>;
}

function Field({ label, hint, children }) {
    return <label className="block"><span className="flex items-baseline justify-between gap-2 text-sm font-medium text-slate-700"><span>{label}</span>{hint ? <span className="text-xs font-normal text-slate-400">{hint}</span> : null}</span><span className="mt-1.5 block">{children}</span></label>;
}

function CheckField({ label, checked, onChange, disabled }) {
    return <label className="flex min-h-10 items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-3 text-sm text-slate-700"><input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} disabled={disabled} className="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />{label}</label>;
}

function ChoiceCard({ active, title, description, onClick }) {
    return <button type="button" onClick={onClick} className={`w-full rounded-md border p-4 text-left transition ${active ? 'border-teal-500 bg-teal-50/70 ring-1 ring-teal-500' : 'border-slate-200 bg-white hover:border-slate-300'}`}><div className="flex items-center justify-between gap-3"><span className="font-semibold text-slate-900">{title}</span><StatusPill tone={active ? 'success' : 'neutral'} label={active ? 'Active' : 'Select'} /></div><p className="mt-2 text-sm leading-6 text-slate-600">{description}</p></button>;
}

function MiniFact({ label, value }) { return <div className="rounded-md border border-slate-200 bg-white px-3 py-2"><p className="text-[10px] uppercase tracking-[0.1em] text-slate-400">{label}</p><p className="mt-1 font-mono text-sm font-semibold text-slate-800">{value}</p></div>; }
function DetailFact({ label, value }) { return <div><p className="font-semibold uppercase tracking-[0.1em] text-slate-400">{label}</p><p className="mt-1 break-words text-slate-700">{value}</p></div>; }
function Trouble({ title, detail }) { return <div><p className="font-semibold text-slate-800">{title}</p><p className="mt-1 leading-5 text-slate-500">{detail}</p></div>; }
function StatusDot({ ok }) { return <span aria-hidden="true" className={`mt-1 h-2 w-2 shrink-0 rounded-full ${ok ? 'bg-emerald-500' : 'bg-rose-500'}`} />; }
function StatusPill({ tone, label }) { const classes = tone === 'success' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : tone === 'warning' ? 'bg-amber-50 text-amber-800 ring-amber-200' : tone === 'danger' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-slate-100 text-slate-600 ring-slate-200'; return <span className={`inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${classes}`}>{label}</span>; }
function EmptyState({ title, message, compact = false }) { return <div className={`rounded-md border border-dashed border-slate-300 bg-slate-50 text-center ${compact ? 'px-3 py-5' : 'px-4 py-8'}`}><p className="text-sm font-semibold text-slate-700">{title}</p><p className="mx-auto mt-1 max-w-md text-xs leading-5 text-slate-500">{message}</p></div>; }
function ListSkeleton({ rows = 3 }) { return <div className="space-y-3">{Array.from({ length: rows }, (_, index) => <div key={index} className="animate-pulse border-b border-slate-200 py-4"><div className="h-3 w-2/5 rounded bg-slate-200" /><div className="mt-2 h-2 w-3/5 rounded bg-slate-100" /></div>)}</div>; }

function CopyButton({ value, label }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => { try { await copyText(value); setCopied(true); window.setTimeout(() => setCopied(false), 1600); } catch { setCopied(false); } };
    return <button type="button" onClick={copy} className={`${secondaryButtonClass} min-h-8 px-2.5 py-1 text-xs`}>{copied ? 'Copied' : label}</button>;
}

function CommandBlock({ label, value }) { return <div className="mt-3 rounded-md border border-slate-200 bg-slate-950 p-3"><div className="flex items-center justify-between gap-3"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p><CopyButton value={value} label="Copy" /></div><pre className="mt-2 max-h-28 overflow-auto whitespace-pre-wrap break-words font-mono text-xs leading-5 text-slate-100">{value}</pre></div>; }

function McpDialog({ open, title, onClose, children }) {
    useEffect(() => { if (!open) return undefined; const onKeyDown = (event) => { if (event.key === 'Escape') onClose(); }; window.addEventListener('keydown', onKeyDown); return () => window.removeEventListener('keydown', onKeyDown); }, [onClose, open]);
    if (!open) return null;
    return <div className="fixed inset-0 z-[105] flex items-center justify-center bg-slate-950/40 p-4" onClick={onClose}><div role="dialog" aria-modal="true" aria-labelledby="mcp-dialog-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-2xl" onClick={(event) => event.stopPropagation()}><div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4"><h2 id="mcp-dialog-title" className="text-base font-semibold text-slate-950">{title}</h2><button type="button" onClick={onClose} className="rounded-md px-2 py-1 text-sm font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Close dialog">Close</button></div><div className="p-5">{children}</div></div></div>;
}

function formatNumber(value) { return Number(value || 0).toLocaleString(); }
function formatBytes(value) { const bytes = Number(value || 0); if (bytes < 1024) return `${bytes} B`; if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 10240 ? 1 : 0)} KB`; return `${(bytes / (1024 * 1024)).toFixed(1)} MB`; }
function formatCostRange(bytes) { const outputTokens = Math.ceil(Number(bytes || 0) / 4); const low = (outputTokens / 1000000) * 3; const high = (outputTokens / 1000000) * 15; if (high < 0.01) return '< $0.01'; return `$${low.toFixed(2)}-$${high.toFixed(2)}`; }
function formatPercent(value) { return `${Number(value || 0).toFixed(0)}%`; }
function formatDate(value, fallback = 'No activity') { if (!value) return fallback; const date = new Date(value); return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString(); }
function formatRelative(value) { if (!value) return 'Never'; const date = new Date(value); if (Number.isNaN(date.getTime())) return value; const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000)); if (seconds < 60) return `${seconds}s ago`; if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`; if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`; return date.toLocaleDateString(); }
function formatJson(value) { if (value === null || value === undefined || value === '') return 'None recorded'; if (typeof value === 'string') return value; try { return JSON.stringify(value, null, 2); } catch { return 'Unavailable'; } }
async function copyText(value) { if (navigator.clipboard?.writeText) { try { await navigator.clipboard.writeText(value); return; } catch { /* Fall through to the legacy copy path when browser permissions block the clipboard API. */ } } const area = document.createElement('textarea'); area.value = value; area.style.position = 'fixed'; area.style.opacity = '0'; document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove(); }

const tokenCsvColumns = [{ label: 'Label', value: (row) => row.label }, { label: 'Owner', value: (row) => row.owner }, { label: 'Status', value: (row) => row.status }, { label: 'Expires', value: (row) => row.expires_at }, { label: 'Last used', value: (row) => row.last_used_at }, { label: 'Calls 7d', value: (row) => row.calls_7d }, { label: 'Bytes 7d', value: (row) => row.bytes_7d }];
const toolCsvColumns = [{ label: 'Tool', value: (row) => row.name }, { label: 'Domain', value: (row) => row.domain }, { label: 'Minimum role', value: (row) => row.configured_role }, { label: 'Enabled', value: (row) => row.enabled ? 'yes' : 'no' }, { label: 'Description', value: (row) => row.description }];
const activityCsvColumns = [{ label: 'Tool', value: (row) => row.tool }, { label: 'Token', value: (row) => row.token_label }, { label: 'Status', value: (row) => row.status }, { label: 'Reason', value: (row) => row.refusal_reason }, { label: 'Rows', value: (row) => row.row_count }, { label: 'Bytes', value: (row) => row.bytes_out }, { label: 'Latency ms', value: (row) => row.latency_ms }, { label: 'Created at', value: (row) => row.created_at }];
