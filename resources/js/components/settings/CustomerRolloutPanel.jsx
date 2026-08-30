import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

/**
 * My Exotic rollout control.
 *
 * The flags live in each market's WordPress options table and are read by the
 * theme on nearly every front-end request. This panel is a remote control over
 * those options, not a mirror of them: nothing is cached CRM-side, so what you
 * see is what the site will do on its next request.
 *
 * Two ideas the UI has to carry, because getting them wrong is how this tool
 * would create the problem it exists to solve:
 *
 *   1. Effective vs configured. A feature can be configured on and still be
 *      dark because the master switch is off, the market is not in the enabled
 *      list, or a rollback is engaged. Every row states which.
 *   2. Pinned vs default. Writing any value stores it, and a stored value beats
 *      the theme's code default forever after. "Following code default" and
 *      "Pinned" are therefore different states, and Reset deletes the stored
 *      key rather than writing false.
 */

const REASON_COPY = {
    live: { label: 'Live', tone: 'ok' },
    master_off: { label: 'Master switch off', tone: 'warn' },
    market_not_enabled: { label: 'Market not enabled', tone: 'warn' },
    rolled_back: { label: 'Rolled back', tone: 'danger' },
    flag_off: { label: 'Flag off', tone: 'muted' },
    off: { label: 'Off', tone: 'muted' },
};

const FEATURE_COPY = {
    pages: 'Private page provisioning',
    shell: 'Workspace shell',
    favorites: 'Saved profiles',
    recent_views: 'Recently viewed',
    compare: 'Compare',
    follows: 'Follows',
    saved_searches: 'Saved searches',
    unlock_claims: 'Unlocked contacts',
    notifications: 'Notifications',
    safety_centre: 'Safety centre',
    member_reports: 'Member reports',
    review_history: 'Review history',
    reachability_feedback: 'Reachability feedback',
    recommendations: 'Your type recommendations',
    anonymous_recommendations: 'Recommendations (signed out)',
};

function humanise(key) {
    return FEATURE_COPY[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function Pill({ tone = 'muted', children }) {
    const tones = {
        ok: 'bg-teal-50 text-teal-700 ring-teal-200',
        warn: 'bg-amber-50 text-amber-800 ring-amber-300',
        danger: 'bg-rose-50 text-rose-700 ring-rose-200',
        muted: 'bg-slate-50 text-slate-600 ring-slate-200',
        info: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    };
    return (
        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${tones[tone] || tones.muted}`}>
            {children}
        </span>
    );
}

function Toggle({ checked, disabled, onChange, label }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-6 w-11 flex-none items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                checked ? 'bg-teal-600' : 'bg-slate-300'
            }`}
        >
            <span
                className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                    checked ? 'translate-x-6' : 'translate-x-1'
                }`}
            />
        </button>
    );
}

export default function CustomerRolloutPanel({ canWrite = false }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [expanded, setExpanded] = useState(null);
    const [note, setNote] = useState('');
    const [pendingKey, setPendingKey] = useState(null);

    const rolloutQuery = useQuery({
        queryKey: ['customer-rollout'],
        queryFn: () => api.get('/crm/settings/customer-rollout').then((r) => r.data),
        staleTime: 15_000,
    });

    const markets = rolloutQuery.data?.markets || [];

    const summary = useMemo(() => {
        const reachable = markets.filter((m) => m.reachable);
        return {
            total: markets.length,
            reachable: reachable.length,
            unreachable: markets.length - reachable.length,
            liveMarkets: reachable.filter((m) => m.rollout?.market_enabled).length,
            pagesMissing: reachable.filter((m) => m.rollout && m.rollout.pages && !m.rollout.pages.ready).length,
            pinned: reachable.filter((m) => (m.rollout?.pinned_flags || []).length > 0).length,
            rolledBack: reachable.filter((m) => (m.rollout?.pinned_rollbacks || []).length > 0).length,
        };
    }, [markets]);

    const mutation = useMutation({
        mutationFn: (payload) => api.patch('/crm/settings/customer-rollout', payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['customer-rollout'] });
            setNote('');
            toast.success('Rollout updated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not update the rollout.');
        },
        onSettled: () => setPendingKey(null),
    });

    const provisionMutation = useMutation({
        mutationFn: (platformId) => api.post('/crm/settings/customer-rollout/provision', { platform_id: platformId }).then((r) => r.data),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['customer-rollout'] });
            const created = data?.created || [];
            toast.success(created.length ? `Provisioned ${created.length} page${created.length === 1 ? '' : 's'}.` : 'Pages already in place.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Could not provision pages.');
        },
    });

    const send = (platformId, body, key) => {
        setPendingKey(key);
        mutation.mutate({ platform_id: platformId, note: note || undefined, ...body });
    };

    if (rolloutQuery.isLoading) {
        return <div className="p-6 text-sm text-slate-500">Loading rollout…</div>;
    }

    if (rolloutQuery.isError) {
        return (
            <div className="m-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                Could not load the rollout. {rolloutQuery.error?.response?.data?.message || ''}
            </div>
        );
    }

    return (
        <div className="space-y-4 p-4">
            <div className="rounded-lg border border-slate-200 bg-white p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-base font-semibold text-slate-900">My Exotic rollout</h2>
                        <p className="mt-1 max-w-3xl text-sm text-slate-600">
                            Feature flags read straight from each market&apos;s WordPress site. WordPress stays the source of
                            truth — this panel writes to it, so nothing here is cached and a CRM outage cannot change what a
                            member sees.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => rolloutQuery.refetch()}
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Refresh
                    </button>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    <Pill tone="info">{summary.reachable} of {summary.total} markets reachable</Pill>
                    <Pill tone={summary.liveMarkets ? 'ok' : 'muted'}>{summary.liveMarkets} enabled</Pill>
                    {summary.pagesMissing > 0 ? <Pill tone="warn">{summary.pagesMissing} missing pages</Pill> : null}
                    {summary.pinned > 0 ? <Pill tone="warn">{summary.pinned} pinned off code defaults</Pill> : null}
                    {summary.rolledBack > 0 ? <Pill tone="danger">{summary.rolledBack} with rollbacks</Pill> : null}
                    {summary.unreachable > 0 ? <Pill tone="danger">{summary.unreachable} unreachable</Pill> : null}
                </div>

                {canWrite ? (
                    <div className="mt-4">
                        <label className="block text-xs font-medium uppercase tracking-wide text-slate-500" htmlFor="rollout-note">
                            Note (recorded with the next change)
                        </label>
                        <input
                            id="rollout-note"
                            type="text"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder="Why are you flipping this?"
                            className="mt-1 w-full max-w-xl rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </div>
                ) : (
                    <p className="mt-3 text-xs text-slate-500">Read-only: only admin users can change the rollout.</p>
                )}
            </div>

            {markets.map((market) => {
                const isOpen = expanded === market.platform_id;
                const rollout = market.rollout;

                return (
                    <div key={market.platform_id} className="rounded-lg border border-slate-200 bg-white">
                        <button
                            type="button"
                            onClick={() => setExpanded(isOpen ? null : market.platform_id)}
                            className="flex w-full flex-wrap items-center justify-between gap-3 p-4 text-left"
                            aria-expanded={isOpen}
                        >
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold text-slate-900">{market.name}</span>
                                    {!market.reachable ? (
                                        <Pill tone="danger">Unreachable</Pill>
                                    ) : rollout?.market_enabled ? (
                                        <Pill tone="ok">Enabled</Pill>
                                    ) : (
                                        <Pill tone="warn">Not enabled</Pill>
                                    )}
                                    {market.reachable && rollout?.pages && !rollout.pages.ready ? (
                                        <Pill tone="warn">Pages missing</Pill>
                                    ) : null}
                                    {market.reachable && (rollout?.pinned_rollbacks || []).length > 0 ? (
                                        <Pill tone="danger">Rollback engaged</Pill>
                                    ) : null}
                                </div>
                                <p className="mt-1 truncate text-xs text-slate-500">
                                    {market.reachable
                                        ? `${rollout?.market_key || '—'} · ${rollout?.site_url || market.domain}`
                                        : market.error}
                                </p>
                            </div>
                            <span className="text-sm text-slate-400">{isOpen ? 'Hide' : 'Manage'}</span>
                        </button>

                        {isOpen && market.reachable && rollout ? (
                            <div className="border-t border-slate-200 p-4">
                                {/* Anything below is dark while these two are off, so they lead. */}
                                <div className="mb-4 flex flex-wrap items-center gap-4 rounded-md bg-slate-50 p-3">
                                    <div className="flex items-center gap-2">
                                        <Toggle
                                            checked={!!rollout.master_enabled}
                                            disabled={!canWrite || mutation.isPending}
                                            label="Master switch"
                                            onChange={(next) => send(market.platform_id, { master_enabled: next }, `${market.platform_id}:master`)}
                                        />
                                        <span className="text-sm font-medium text-slate-700">Master switch</span>
                                    </div>
                                    <span className="text-xs text-slate-500">
                                        Market key <code className="rounded bg-white px-1 py-0.5">{rollout.market_key}</code>
                                        {' · '}
                                        enabled: {(rollout.enabled_markets || []).join(', ') || '—'}
                                    </span>
                                </div>

                                {rollout.pages && !rollout.pages.ready ? (
                                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 p-3">
                                        <div className="text-sm text-amber-900">
                                            <strong>{rollout.pages.missing.length} private page(s) missing.</strong>{' '}
                                            Provisioning only runs when an admin loads wp-admin, so the workspace renders
                                            empty with no error until it does.
                                        </div>
                                        {canWrite ? (
                                            <button
                                                type="button"
                                                disabled={provisionMutation.isPending}
                                                onClick={() => provisionMutation.mutate(market.platform_id)}
                                                className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                                            >
                                                {provisionMutation.isPending ? 'Provisioning…' : 'Provision now'}
                                            </button>
                                        ) : null}
                                    </div>
                                ) : null}

                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                            <th className="py-2">Feature</th>
                                            <th className="py-2">State</th>
                                            <th className="py-2">Source</th>
                                            <th className="py-2 text-right">Flag</th>
                                            <th className="py-2 text-right">Kill</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(rollout.features || []).map((feature) => {
                                            const reason = REASON_COPY[feature.reason] || REASON_COPY.off;
                                            const busy = mutation.isPending && pendingKey === `${market.platform_id}:${feature.key}`;

                                            return (
                                                <tr key={feature.key} className="border-b border-slate-100 last:border-0">
                                                    <td className="py-2 pr-3">
                                                        <span className="font-medium text-slate-800">{humanise(feature.key)}</span>
                                                        <span className="ml-2 text-xs text-slate-400">{feature.key}</span>
                                                    </td>
                                                    <td className="py-2 pr-3"><Pill tone={reason.tone}>{reason.label}</Pill></td>
                                                    <td className="py-2 pr-3">
                                                        {feature.source === 'stored' ? (
                                                            <span className="flex items-center gap-2">
                                                                <Pill tone="warn">Pinned</Pill>
                                                                {canWrite ? (
                                                                    <button
                                                                        type="button"
                                                                        disabled={busy}
                                                                        onClick={() => send(market.platform_id, { reset_flags: [feature.key] }, `${market.platform_id}:${feature.key}`)}
                                                                        className="text-xs font-medium text-indigo-600 underline hover:text-indigo-800"
                                                                    >
                                                                        Reset
                                                                    </button>
                                                                ) : null}
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-slate-500">
                                                                Code default ({feature.code_default ? 'on' : 'off'})
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="py-2 pr-3 text-right">
                                                        <Toggle
                                                            checked={!!feature.configured}
                                                            disabled={!canWrite || busy}
                                                            label={`${feature.key} flag`}
                                                            onChange={(next) => send(market.platform_id, { flags: { [feature.key]: next } }, `${market.platform_id}:${feature.key}`)}
                                                        />
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        {/* Distinct from the flag: a rollback wins regardless of how the
                                                            flag is configured, and is meant to be temporary. */}
                                                        <button
                                                            type="button"
                                                            disabled={!canWrite || busy}
                                                            onClick={() => {
                                                                if (!feature.rollback && !window.confirm(`Kill "${humanise(feature.key)}" on ${market.name}? This overrides the flag immediately.`)) {
                                                                    return;
                                                                }
                                                                send(
                                                                    market.platform_id,
                                                                    feature.rollback
                                                                        ? { reset_rollbacks: [feature.key] }
                                                                        : { rollbacks: { [feature.key]: true } },
                                                                    `${market.platform_id}:${feature.key}`
                                                                );
                                                            }}
                                                            className={`rounded-md px-2 py-1 text-xs font-medium disabled:opacity-40 ${
                                                                feature.rollback
                                                                    ? 'bg-rose-600 text-white hover:bg-rose-700'
                                                                    : 'border border-slate-300 text-slate-600 hover:bg-slate-50'
                                                            }`}
                                                        >
                                                            {feature.rollback ? 'Restore' : 'Kill'}
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>

                                {(rollout.audit || []).length > 0 ? (
                                    <details className="mt-4">
                                        <summary className="cursor-pointer text-xs font-medium text-slate-500">
                                            Recent changes on this market
                                        </summary>
                                        <ul className="mt-2 space-y-1 text-xs text-slate-600">
                                            {rollout.audit.slice(0, 10).map((entry, index) => (
                                                <li key={`${entry.at}-${index}`}>
                                                    <span className="text-slate-400">{entry.at}</span>{' '}
                                                    <strong>{entry.actor}</strong>{' '}
                                                    {(entry.changes || []).map((c) => `${c.key}→${JSON.stringify(c.to)}`).join(', ')}
                                                    {entry.note ? ` — ${entry.note}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                ) : null}
                            </div>
                        ) : null}

                        {isOpen && !market.reachable ? (
                            <div className="border-t border-slate-200 p-4 text-sm text-rose-700">
                                {market.error || 'This market could not be reached.'}
                            </div>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}
