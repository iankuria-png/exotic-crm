import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import ConfirmDialog from '../ConfirmDialog';
import { chip, formatDateTime } from '../clients/ClientStoriesTab';

function countdown(seconds) {
    const value = Math.max(0, Number(seconds) || 0);
    const days = Math.floor(value / 86400);
    const hours = Math.floor((value % 86400) / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    return days > 0 ? `${days}d ${hours}h` : `${hours}h ${minutes}m`;
}

function Name({ row }) {
    if (row.client_id) {
        return <Link to={`/clients/${row.client_id}?tab=stories`} className="truncate font-semibold text-slate-900 hover:text-teal-700">{row.name}</Link>;
    }
    return row.url
        ? <a href={row.url} target="_blank" rel="noopener noreferrer" className="truncate font-semibold text-slate-900 hover:text-teal-700">{row.name}</a>
        : <span className="truncate font-semibold text-slate-900">{row.name || 'Unknown'}</span>;
}

export default function HottestPanel({ platformId, canReward }) {
    const [confirm, setConfirm] = useState(null);
    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [feedback, setFeedback] = useState(null);

    const query = useQuery({
        queryKey: ['stories-hottest', platformId],
        queryFn: () => api.get(`/crm/stories/${platformId}/hottest`).then((r) => r.data),
        enabled: Boolean(platformId),
        refetchInterval: 60_000,
    });

    if (query.isLoading) {
        return <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]"><div className="h-80 animate-pulse rounded-xl bg-slate-100" /><div className="h-80 animate-pulse rounded-xl bg-slate-100" /></div>;
    }
    if (query.isError) {
        return (
            <div className="rounded-xl border border-rose-200 bg-rose-50 p-6 text-center">
                <p className="text-sm font-semibold text-rose-800">Could not load the leaderboard.</p>
                <p className="mt-1 text-sm text-rose-700">{query.error?.response?.data?.message || 'The market site did not respond.'}</p>
                <button type="button" className="crm-btn-secondary mt-3" onClick={() => query.refetch()}>Try again</button>
            </div>
        );
    }

    const data = query.data || {};
    const board = data.board || [];
    const leaderLikes = Math.max(1, ...board.map((row) => Number(row.likes || 0)));
    const previous = data.previous_week || {};
    const history = data.history || [];

    const submit = async () => {
        const target = confirm;
        setPending(true);
        setFeedback(null);
        try {
            if (target.type === 'award') {
                const { data: result } = await api.post(`/crm/stories/${platformId}/hottest/award`, { week: target.week });
                setFeedback({ ok: true, message: `Reward granted to ${result.name} (${result.likes} likes).` });
            } else {
                await api.post(`/crm/stories/${platformId}/hottest/revoke`, { week: target.week, reason: reason.trim() });
                setFeedback({ ok: true, message: `Reward for ${target.week} revoked and its placement paused.` });
            }
            setConfirm(null);
            query.refetch();
        } catch (error) {
            setFeedback({ ok: false, message: error?.response?.data?.message || 'WordPress could not update the reward.' });
            setConfirm(null);
        } finally {
            setPending(false);
        }
    };

    return (
        <div className="space-y-4">
            {feedback ? <p role="status" className={`rounded-lg px-3 py-2 text-sm ${feedback.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-700'}`}>{feedback.message}</p> : null}
            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <section className="crm-surface overflow-hidden">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">This week <span className="ml-1 text-xs font-medium text-slate-400">{data.week?.key}</span></h3>
                            <p className="crm-panel-subtitle">
                                The most-liked eligible advertiser with at least {data.min_likes} likes wins {data.reward_days} days of VVIP placement and the Hottest badge.
                                {data.auto_award ? ' Granted automatically when the week closes.' : ' Automatic awarding is off.'}
                            </p>
                        </div>
                        <div className="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-center text-white">
                            <p className="text-[10px] uppercase tracking-[0.14em] text-slate-400">Closes in</p>
                            <p className="font-mono text-lg font-semibold tabular-nums">{countdown(data.week?.ends_in)}</p>
                        </div>
                    </header>
                    {board.length === 0 ? (
                        <p className="p-8 text-center text-sm text-slate-500">No likes yet this week.</p>
                    ) : (
                        <ol className="divide-y divide-slate-100">
                            {board.map((row, index) => (
                                <li key={row.user_id} className={`flex items-center gap-3 px-4 py-3 ${index === 0 ? 'bg-gradient-to-r from-amber-50 to-transparent' : ''}`}>
                                    <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm font-bold ${index === 0 ? 'bg-amber-400 text-amber-950' : index < 3 ? 'bg-slate-200 text-slate-700' : 'text-slate-400'}`}>{index + 1}</span>
                                    <span className="shrink-0 rounded-full bg-gradient-to-tr from-amber-400 via-rose-500 to-fuchsia-600 p-[2px]">
                                        {row.image
                                            ? <img src={row.image} alt="" className="h-9 w-9 rounded-full border-2 border-white object-cover" loading="lazy" />
                                            : <span className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-slate-200 text-xs font-semibold">{(row.name || '?').charAt(0)}</span>}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2"><Name row={row} />
                                            {!row.eligible ? <span className={chip('bg-slate-100 text-slate-600 ring-slate-300')}>Not eligible</span> : null}
                                            {row.below_minimum ? <span className={chip('bg-amber-50 text-amber-800 ring-amber-200')}>Below minimum</span> : null}
                                        </div>
                                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                            <div className={`h-full rounded-full ${index === 0 ? 'bg-gradient-to-r from-amber-400 to-rose-500' : 'bg-teal-500'}`} style={{ width: `${(Number(row.likes || 0) / leaderLikes) * 100}%` }} />
                                        </div>
                                    </div>
                                    <span className="w-16 shrink-0 text-right text-sm font-semibold tabular-nums text-slate-900">{Number(row.likes).toLocaleString()} <span className="text-xs font-normal text-slate-500">likes</span></span>
                                </li>
                            ))}
                        </ol>
                    )}
                </section>

                <section className="crm-surface p-4">
                    <h3 className="crm-panel-title">Last week <span className="ml-1 text-xs font-medium text-slate-400">{previous.key}</span></h3>
                    {previous.awarded ? (
                        <p className="mt-3 text-sm text-slate-600">Awarded. See the history below.</p>
                    ) : previous.winner ? (
                        <div className="mt-3 space-y-3">
                            <p className="text-sm text-slate-700">Winner: <strong>{previous.winner.name}</strong> with {Number(previous.winner.likes).toLocaleString()} likes. Not yet awarded.</p>
                            {canReward ? (
                                <button type="button" className="inline-flex items-center rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800" onClick={() => setConfirm({ type: 'award', week: previous.key, name: previous.winner.name })}>
                                    Grant reward now
                                </button>
                            ) : <p className="text-xs text-slate-500">Only admins can grant the reward.</p>}
                        </div>
                    ) : (
                        <p className="mt-3 text-sm text-slate-500">No eligible winner last week.</p>
                    )}
                    {!data.campaigns_available ? (
                        <p className="mt-4 rounded-md bg-amber-50 p-2 text-xs text-amber-800">The Exotic Campaigns plugin is inactive on this market, so rewards grant the badge only, without homepage VVIP placement.</p>
                    ) : null}
                </section>
            </div>

            <section className="crm-surface overflow-hidden">
                <header className="crm-panel-header"><h3 className="crm-panel-title">Reward history</h3></header>
                {history.length === 0 ? (
                    <p className="p-6 text-center text-sm text-slate-500">No rewards yet.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm">
                            <thead className="bg-slate-50 text-left text-[11px] uppercase tracking-[0.12em] text-slate-500">
                                <tr>
                                    <th className="px-4 py-2.5 font-semibold">Week</th>
                                    <th className="px-4 py-2.5 font-semibold">Advertiser</th>
                                    <th className="px-4 py-2.5 font-semibold">Likes</th>
                                    <th className="px-4 py-2.5 font-semibold">Placement until</th>
                                    <th className="px-4 py-2.5 font-semibold">How</th>
                                    <th className="px-4 py-2.5 font-semibold">Status</th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {history.map((row) => (
                                    <tr key={row.week}>
                                        <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{row.week}</td>
                                        <td className="px-4 py-2.5"><Name row={row} /></td>
                                        <td className="px-4 py-2.5 tabular-nums">{Number(row.likes).toLocaleString()}</td>
                                        <td className="px-4 py-2.5 text-slate-600">{formatDateTime(row.until)}</td>
                                        <td className="px-4 py-2.5 text-slate-600">{row.mode === 'manual' ? 'Manual' : 'Automatic'}</td>
                                        <td className="px-4 py-2.5">
                                            <span className={chip(row.state === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : row.state === 'revoked' ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-slate-100 text-slate-600 ring-slate-300')}>
                                                {row.state === 'active' ? 'Active' : row.state === 'revoked' ? 'Revoked' : 'Ended'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            {row.state === 'active' && canReward ? (
                                                <button type="button" className="text-sm font-semibold text-rose-700 hover:underline" onClick={() => { setReason(''); setConfirm({ type: 'revoke', week: row.week, name: row.name }); }}>Revoke</button>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <ConfirmDialog
                open={Boolean(confirm)}
                title={confirm?.type === 'award' ? `Grant ${confirm?.week}'s reward?` : `Revoke the reward for ${confirm?.week}?`}
                message={confirm?.type === 'award'
                    ? `${confirm?.name} gets ${data.reward_days} days of VVIP placement and the Hottest badge. WordPress confirms the winner again before granting.`
                    : `${confirm?.name}'s Hottest badge is removed and the VVIP placement paused now.`}
                confirmLabel={confirm?.type === 'award' ? 'Grant reward' : 'Revoke reward'}
                tone={confirm?.type === 'award' ? 'default' : 'danger'}
                isPending={pending}
                confirmDisabled={confirm?.type === 'revoke' && !reason.trim()}
                onCancel={() => setConfirm(null)}
                onConfirm={submit}
            >
                {confirm?.type === 'revoke' ? (
                    <>
                        <label htmlFor="revoke-reason" className="mt-3 block text-xs font-semibold text-slate-700">Reason <span className="text-rose-600">*</span></label>
                        <textarea id="revoke-reason" className="crm-input mt-1 min-h-[56px] w-full text-sm" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} />
                    </>
                ) : null}
            </ConfirmDialog>
        </div>
    );
}
