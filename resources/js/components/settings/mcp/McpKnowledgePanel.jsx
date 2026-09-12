import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import { useToast } from '../../ToastProvider';

export default function McpKnowledgePanel({ canManage }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [stageError, setStageError] = useState(null);
    const query = useQuery({ queryKey: ['mcp-knowledge'], queryFn: () => api.get('/crm/settings/mcp/knowledge').then((r) => r.data) });
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['mcp-knowledge'] });
    const bootstrap = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/knowledge/bootstrap'),
        onSuccess: () => { toast.success('Ontology v1 is active. You can now stage approved documentation.'); refresh(); },
    });
    const stage = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/knowledge/stage', { idempotency_key: crypto.randomUUID() }),
        onSuccess: () => { setStageError(null); toast.success('Knowledge snapshot staged. Review it, then promote it.'); refresh(); },
        onError: (error) => {
            const response = error.response?.data || {};
            setStageError({
                code: response.code || 'sync_failed',
                message: response.message || 'Knowledge staging could not complete. Check the latest run and try again.',
            });
            refresh();
        },
    });
    const promote = useMutation({
        mutationFn: (id) => api.post(`/crm/settings/mcp/knowledge/versions/${id}/promote`),
        onSuccess: () => { toast.success('Knowledge release promoted.'); refresh(); },
    });

    if (query.isLoading) return <div className="crm-surface p-6 text-sm text-slate-500">Loading knowledge provenance…</div>;

    const data = query.data || {};
    const active = data.active_release;
    const latestRun = data.runs?.[0];
    const snapshots = data.versions || [];

    return <section className="crm-surface overflow-hidden" data-testid="mcp-knowledge-panel">
        <div className="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Approved knowledge</p>
                <h3 className="mt-1 text-lg font-semibold text-slate-950">Versioned reference context</h3>
                <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Remote documentation is staged first. Only a reviewed immutable snapshot reaches MCP.</p>
            </div>
            {canManage && !active ? <button className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" disabled={bootstrap.isPending} onClick={() => bootstrap.mutate()}>{bootstrap.isPending ? 'Activating…' : 'Activate ontology v1'}</button> : null}
            {canManage && active ? <button className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" disabled={stage.isPending} onClick={() => stage.mutate()}>{stage.isPending ? 'Staging…' : 'Stage approved docs'}</button> : null}
        </div>
        <div className="grid gap-px bg-slate-200 sm:grid-cols-3">
            <Fact label="Active release" value={active?.ontology_version || 'Not activated'} />
            <Fact label="Approved snapshots" value={String(snapshots.filter((version) => version.status === 'ready').length)} />
            <Fact label="Latest run" value={latestRun?.status || 'None'} />
        </div>
        {stageError ? <FailureNotice error={stageError} /> : null}
        {!stageError && latestRun?.status === 'failed' ? <FailureNotice error={{ code: latestRun.error_code || 'sync_failed', message: 'The previous staging run did not complete. Review the cause below, then stage again.' }} /> : null}
        <div className="divide-y divide-slate-100">
            {snapshots.map((version) => <div className="flex flex-wrap items-center justify-between gap-3 p-4" key={version.id}>
                <div><p className="font-medium text-slate-800">{version.version}</p><p className="mt-1 text-xs text-slate-500">{version.status} · {version.content_sha256?.slice(0, 12)}…</p></div>
                {canManage && version.status === 'ready' ? <button className="text-sm font-semibold text-teal-700 hover:text-teal-900" onClick={() => promote.mutate(version.id)}>Promote</button> : null}
            </div>)}
            {!snapshots.length ? <div className="p-6 text-sm text-slate-500">{active ? 'No staged reference snapshot. Stage the approved documentation to create a reviewable baseline.' : 'Activate ontology v1 to establish the immutable contract before staging documentation.'}</div> : null}
        </div>
    </section>;
}

function FailureNotice({ error }) {
    return <div className="border-b border-rose-100 bg-rose-50 px-5 py-4 text-sm text-rose-950" role="alert">
        <p className="font-semibold">Knowledge staging needs attention</p>
        <p className="mt-1 leading-6">{error.message}</p>
        <p className="mt-1 text-xs font-medium uppercase tracking-[0.1em] text-rose-700">Run code: {error.code}</p>
    </div>;
}

function Fact({ label, value }) {
    return <div className="bg-white p-4"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p><p className="mt-1 text-sm font-semibold text-slate-800">{value}</p></div>;
}
