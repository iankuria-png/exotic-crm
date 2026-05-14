import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import universityApi from '../../services/universityApi';

export default function Leaderboard() {
    const [roleFilter, setRoleFilter] = useState('all');
    const lbQuery = useQuery({
        queryKey: ['university-leaderboard-full'],
        queryFn: () => universityApi.leaderboard(),
    });
    const rows = (lbQuery.data?.leaderboard || []).filter((r) => roleFilter === 'all' || r.role === roleFilter);

    return (
        <div className="space-y-6">
            <PageHeader
                title="University Leaderboard"
                subtitle="Score = badge points + 5/lesson + 50/active certification. Highest scorers across the team."
                actions={<Link to="/university" className="crm-btn-secondary px-3 py-2 text-sm">← Back to University</Link>}
            />
            <section className="rounded-2xl border border-slate-200 bg-white p-5">
                <div className="flex flex-wrap gap-2">
                    {['all', 'sales', 'sub_admin', 'admin', 'marketing'].map((r) => (
                        <button key={r} type="button" onClick={() => setRoleFilter(r)} className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${roleFilter === r ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}>
                            {r === 'all' ? 'All roles' : r}
                        </button>
                    ))}
                </div>
            </section>
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-bold uppercase tracking-[0.14em] text-slate-600">
                        <tr>
                            <th className="px-4 py-3">Rank</th>
                            <th className="px-4 py-3">Agent</th>
                            <th className="px-4 py-3">Role</th>
                            <th className="px-4 py-3 text-right">Lessons</th>
                            <th className="px-4 py-3 text-right">Certs</th>
                            <th className="px-4 py-3 text-right">Streak</th>
                            <th className="px-4 py-3 text-right">Points</th>
                            <th className="px-4 py-3 text-right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r, i) => (
                            <tr key={r.user_id} className="border-t border-slate-100 hover:bg-slate-50">
                                <td className="px-4 py-3">
                                    <span className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ${i === 0 ? 'bg-amber-500 text-white' : i === 1 ? 'bg-slate-400 text-white' : i === 2 ? 'bg-orange-400 text-white' : 'bg-slate-100 text-slate-600'}`}>{i + 1}</span>
                                </td>
                                <td className="px-4 py-3 font-semibold text-slate-900">{r.name}</td>
                                <td className="px-4 py-3 text-slate-600">{r.role}</td>
                                <td className="px-4 py-3 text-right">{r.lessons_completed}</td>
                                <td className="px-4 py-3 text-right">{r.certificates}</td>
                                <td className="px-4 py-3 text-right">{r.current_streak}🔥</td>
                                <td className="px-4 py-3 text-right">{r.badge_points}</td>
                                <td className="px-4 py-3 text-right font-bold text-teal-700">{r.score}</td>
                            </tr>
                        ))}
                        {!rows.length ? <tr><td colSpan={8} className="px-4 py-10 text-center text-sm text-slate-500">No activity yet.</td></tr> : null}
                    </tbody>
                </table>
            </section>
        </div>
    );
}
