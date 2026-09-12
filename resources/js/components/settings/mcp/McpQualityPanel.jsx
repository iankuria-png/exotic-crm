import React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import { useToast } from '../../ToastProvider';

export default function McpQualityPanel({ canManage }) {
    const toast = useToast(); const client = useQueryClient(); const query = useQuery({ queryKey: ['mcp-quality'], queryFn: () => api.get('/crm/settings/mcp/quality').then((r) => r.data) });
    const audit = useMutation({ mutationFn: () => api.post('/crm/settings/mcp/quality/audit'), onSuccess: () => { toast.success('Schema audit completed.'); client.invalidateQueries({ queryKey: ['mcp-quality'] }); }, onError: () => toast.error('Schema audit failed.') });
    const latest = query.data?.latest_audit;
    return <section className="crm-surface p-5 sm:p-6" data-testid="mcp-quality-panel"><div className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Quality gate</p><h3 className="mt-1 text-lg font-semibold text-slate-950">Schema drift and evidence health</h3><p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Audits inspect only declared MCP dependencies. They never retain SQL, DDL, connection details, or data samples.</p></div>{canManage ? <button className="rounded-md border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" onClick={() => audit.mutate()} disabled={audit.isPending}>{audit.isPending ? 'Auditing…' : 'Run schema audit'}</button> : null}</div><div className="mt-6 border border-slate-200 bg-slate-50 p-4"><p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Latest result</p><p className={`mt-1 text-xl font-semibold ${latest?.status === 'pass' ? 'text-emerald-700' : latest?.status === 'drift' ? 'text-amber-700' : 'text-slate-700'}`}>{latest?.status || 'No audit recorded'}</p><p className="mt-2 text-sm text-slate-500">{latest?.completed_at ? `Completed ${new Date(latest.completed_at).toLocaleString()}` : 'Run an audit after deploy or when a dependent schema changes.'}</p></div></section>;
}
