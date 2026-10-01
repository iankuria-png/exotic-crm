import React, { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { Empty, ErrorState, fmtDateTime, humanize, Loading, Panel } from './shared';

function Diff({ before, after }) {
    if (!before && !after) return null;
    const keys = Array.from(new Set([...Object.keys(before || {}), ...Object.keys(after || {})]));
    return (
        <dl className="crm-mono mt-1 grid grid-cols-[minmax(0,10rem)_1fr] gap-x-3 text-[11px]">
            {keys.map((k) => {
                const b = before?.[k];
                const a = after?.[k];
                const changed = JSON.stringify(a) !== JSON.stringify(b);
                return (
                    <React.Fragment key={k}>
                        <dt className="truncate text-slate-500">{k}</dt>
                        <dd className="break-all">
                            {before ? <span className={changed ? 'text-rose-700 line-through' : 'text-slate-500'}>{JSON.stringify(b ?? null)}</span> : null}
                            {before && changed ? ' → ' : ''}
                            {changed || !before ? <span className="text-emerald-700">{JSON.stringify(a ?? null)}</span> : null}
                        </dd>
                    </React.Fragment>
                );
            })}
        </dl>
    );
}

export default function AuditTab() {
    const [filters, setFilters] = useState({ entity: '', page: 1 });
    const query = useQuery({
        queryKey: ['dbo', 'audit', filters],
        queryFn: () => dbObservatory.audit({ entity: filters.entity || undefined, page: filters.page, per_page: 50 }),
        placeholderData: keepPreviousData,
    });

    return (
        <Panel
            title="Logs & audit"
            subtitle="Every scanner control, configuration change, triage action and preflight, with redacted before/after. Kept for 365 days."
            action={(
                <select aria-label="Entity" className="crm-select py-1.5 text-sm" value={filters.entity} onChange={(e) => setFilters({ entity: e.target.value, page: 1 })}>
                    <option value="">Everything</option>
                    {['pass', 'finding', 'suppression', 'rule', 'list', 'schedule', 'settings', 'connection'].map((e) => <option key={e} value={e}>{humanize(e)}</option>)}
                </select>
            )}
        >
            {query.isLoading ? <Loading rows={6} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : query.data.data.length === 0 ? <Empty title="No audit entries yet" /> : (
                <>
                    <ul className="divide-y divide-slate-100">
                        {query.data.data.map((e) => (
                            <li key={e.id} className="px-4 py-2.5">
                                <p className="text-sm text-slate-800">
                                    <span className="font-semibold">{e.actor}</span> · {humanize(e.action)} {e.entity} <span className="crm-mono text-xs text-slate-500">{e.entity_id}</span>
                                    <span className="ml-2 text-xs text-slate-400">{e.scope_key} · {fmtDateTime(e.created_at)}</span>
                                </p>
                                <Diff before={e.before} after={e.after} />
                            </li>
                        ))}
                    </ul>
                    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                        <span>{query.data.meta.total} entries</span>
                        <div className="flex gap-2">
                            <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Previous</button>
                            <button type="button" className="crm-btn-secondary px-3 py-1" disabled={filters.page >= query.data.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Next</button>
                        </div>
                    </div>
                </>
            )}
        </Panel>
    );
}
