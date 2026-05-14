import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AdminNav } from './CourseEditor';
import PageHeader from '../../../components/PageHeader';
import universityApi from '../../../services/universityApi';

export default function Analytics() {
    const teamQuery = useQuery({ queryKey: ['university-analytics-team'], queryFn: universityApi.teamAnalytics });
    const expiringQuery = useQuery({ queryKey: ['university-analytics-expiring'], queryFn: universityApi.expiringCertificates });
    const agents = teamQuery.data?.agents || [];
    const chartData = agents.map((agent) => ({ name: agent.name, score: Number(agent.best_score_pct || 0) })).slice(0, 12);

    return (
        <div className="space-y-5">
            <PageHeader title="University Analytics" subtitle="Certification status, score trends, and recertification risk." actions={<AdminNav />} />
            <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">Best scores</h2>
                    <div className="mt-4 h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={chartData}>
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis dataKey="name" hide />
                                <YAxis domain={[0, 100]} />
                                <Tooltip />
                                <Bar dataKey="score" fill="#0f766e" radius={[6, 6, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </div>
                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">Expiring soon</h2>
                    <div className="mt-4 space-y-3">
                        {(expiringQuery.data?.certificates || []).map((row) => (
                            <div key={row.certificate.id} className="rounded-lg bg-amber-50 px-3 py-3">
                                <p className="font-semibold text-slate-950">{row.user?.name}</p>
                                <p className="text-sm text-amber-800">{row.certification?.title}</p>
                            </div>
                        ))}
                        {!expiringQuery.data?.certificates?.length ? <p className="text-sm text-slate-500">No certificates expiring in 30 days.</p> : null}
                    </div>
                </div>
            </section>

            <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Agent</th>
                            <th className="px-4 py-3">Role</th>
                            <th className="px-4 py-3">Best score</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Valid until</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {agents.map((agent) => (
                            <tr key={agent.user_id}>
                                <td className="px-4 py-3 font-medium text-slate-950">{agent.name}</td>
                                <td className="px-4 py-3 text-slate-500">{agent.role}</td>
                                <td className="px-4 py-3 text-slate-700">{agent.best_score_pct ?? '-'}</td>
                                <td className="px-4 py-3 text-slate-700">{agent.cert_status}</td>
                                <td className="px-4 py-3 text-slate-500">{agent.validity_end ? new Date(agent.validity_end).toLocaleDateString() : '-'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </div>
    );
}
