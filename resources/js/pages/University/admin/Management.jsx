import React from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../../components/PageHeader';
import universityApi from '../../../services/universityApi';

export default function Management() {
    const { data, isLoading } = useQuery({
        queryKey: ['university-management-dashboard'],
        queryFn: () => universityApi.managementDashboard(),
    });

    const totals = data?.totals || {};
    const weakest = data?.weakest_topics || [];
    const failed = data?.failed_modules || [];
    const readiness = data?.department_readiness || [];
    const notStarted = data?.staff_not_started || [];
    const expired = data?.staff_expired || [];

    return (
        <div className="space-y-6">
            <PageHeader
                title="Management Dashboard"
                subtitle="Org-wide training health, weakness signals, and enforcement gaps."
                actions={
                    <div className="flex items-center gap-2">
                        <Link to="/university" className="crm-btn-secondary px-3 py-2 text-sm">← Back to University</Link>
                        <Link to="/university/manage/analytics" className="crm-btn-secondary px-3 py-2 text-sm">Team analytics</Link>
                    </div>
                }
            />

            {isLoading ? <p className="text-sm text-slate-500">Loading dashboard…</p> : null}

            {/* Top metrics */}
            <section className="grid gap-4 lg:grid-cols-6">
                <KpiCard label="Staff" value={totals.staff || 0} accent="slate" />
                <KpiCard label="Team completion" value={`${totals.team_completion_pct || 0}%`} accent="teal" emphasised />
                <KpiCard label="Certified" value={`${totals.certified_staff || 0}/${totals.staff || 0}`} sub={`${totals.certified_pct || 0}% of staff`} accent="emerald" />
                <KpiCard label="Expired certs" value={totals.expired_staff || 0} accent="rose" />
                <KpiCard label="Drill accuracy (30d)" value={`${totals.daily_drill_accuracy_pct || 0}%`} accent="amber" />
                <KpiCard label="Not started" value={notStarted.length} accent="indigo" />
            </section>

            <section className="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                {/* Weakest topics */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Weakest topics</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Lowest average cert score across all submitted attempts.</p>
                    {weakest.length ? (
                        <ul className="mt-3 space-y-2">
                            {weakest.map((t) => (
                                <li key={t.topic}>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="font-semibold text-slate-800">{t.topic}</span>
                                        <span className="text-slate-500">{t.average_score_pct}% · {t.attempts} attempts</span>
                                    </div>
                                    <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div className={`h-full rounded-full ${t.average_score_pct < 60 ? 'bg-rose-500' : t.average_score_pct < 80 ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${Math.min(100, t.average_score_pct)}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : <p className="mt-3 text-sm text-slate-500">No certification attempts yet.</p>}
                </div>

                {/* Department readiness */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5">
                    <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Department readiness</h3>
                    <p className="mt-0.5 text-xs text-slate-500">Average completion and certification rate per role.</p>
                    <table className="mt-3 w-full text-sm">
                        <thead className="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr><th className="pb-2">Role</th><th className="pb-2 text-right">Staff</th><th className="pb-2 text-right">Completion</th><th className="pb-2 text-right">Certified</th></tr>
                        </thead>
                        <tbody>
                            {readiness.map((r) => (
                                <tr key={r.role} className="border-t border-slate-100">
                                    <td className="py-2 font-semibold text-slate-800">{r.role}</td>
                                    <td className="py-2 text-right">{r.staff}</td>
                                    <td className="py-2 text-right">
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold ${r.avg_completion_pct >= 70 ? 'bg-emerald-100 text-emerald-800' : r.avg_completion_pct >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800'}`}>{r.avg_completion_pct}%</span>
                                    </td>
                                    <td className="py-2 text-right">
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold ${r.certified_pct >= 70 ? 'bg-emerald-100 text-emerald-800' : r.certified_pct >= 40 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{r.certified_pct}%</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* Failed modules */}
            <section className="rounded-2xl border border-slate-200 bg-white p-5">
                <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Failed modules</h3>
                <p className="mt-0.5 text-xs text-slate-500">Lessons with the most "needs work" feedback. Candidates to rewrite or replace.</p>
                {failed.length ? (
                    <ul className="mt-3 divide-y divide-slate-100">
                        {failed.map((f) => (
                            <li key={f.lesson_id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-slate-800">{f.lesson_title}</p>
                                    <p className="text-xs text-slate-500">{f.course_title}</p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2 text-xs font-bold">
                                    <span className="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-rose-800">👎 {f.down}</span>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-emerald-800">👍 {f.up}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : <p className="mt-3 text-sm text-slate-500">No negative feedback recorded yet.</p>}
            </section>

            <section className="grid gap-4 lg:grid-cols-2">
                <StaffList title="Staff who have not started" rows={notStarted} emptyText="Everyone has started at least one lesson." extra={(r) => null} />
                <StaffList title="Staff with expired certification" rows={expired} emptyText="No expired certs." extra={(r) => r.expired_on ? <span className="text-xs text-rose-700">Expired {r.expired_on}</span> : null} />
            </section>
        </div>
    );
}

function KpiCard({ label, value, sub, accent = 'slate', emphasised = false }) {
    const ACCENT = {
        slate: 'from-slate-100 to-slate-50',
        teal: 'from-teal-50 to-cyan-50',
        emerald: 'from-emerald-50 to-emerald-100',
        rose: 'from-rose-50 to-rose-100',
        amber: 'from-amber-50 to-amber-100',
        indigo: 'from-indigo-50 to-indigo-100',
    }[accent] || 'from-slate-100 to-slate-50';

    return (
        <div className={`rounded-2xl border border-slate-200 bg-gradient-to-br ${ACCENT} p-4 ${emphasised ? 'ring-2 ring-teal-300' : ''}`}>
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-bold text-slate-950">{value}</p>
            {sub ? <p className="mt-0.5 text-[11px] text-slate-500">{sub}</p> : null}
        </div>
    );
}

function StaffList({ title, rows, emptyText, extra }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-5">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">{title}</h3>
                <span className="text-xs text-slate-500">{rows.length}</span>
            </div>
            {rows.length ? (
                <ul className="mt-3 max-h-72 space-y-1 overflow-y-auto">
                    {rows.map((r) => (
                        <li key={r.id} className="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                            <div className="min-w-0">
                                <p className="truncate font-semibold text-slate-800">{r.name}</p>
                                <p className="truncate text-xs text-slate-500">{r.email} · {r.role}</p>
                            </div>
                            {extra ? extra(r) : null}
                        </li>
                    ))}
                </ul>
            ) : <p className="mt-3 text-sm text-slate-500">{emptyText}</p>}
        </div>
    );
}
