import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import CourseCard from '../../components/University/CourseCard';
import DailyDrillCard from '../../components/University/DailyDrillCard';
import QuoteOfTheDay from '../../components/University/QuoteOfTheDay';
import StreakFlame from '../../components/University/StreakFlame';
import universityApi from '../../services/universityApi';
import { useAuth } from '../../hooks/useAuth';

export default function UniversityHome() {
    const { user } = useAuth();
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const isAdmin = ['admin', 'sub_admin'].includes(user?.role);

    const coursesQuery = useQuery({
        queryKey: ['university-courses'],
        queryFn: () => universityApi.listCourses(),
    });
    const engagementQuery = useQuery({
        queryKey: ['university-engagement-me'],
        queryFn: () => universityApi.engagementMe(),
        staleTime: 60_000,
    });
    const leaderboardQuery = useQuery({
        queryKey: ['university-leaderboard'],
        queryFn: () => universityApi.leaderboard(),
        staleTime: 60_000,
    });

    const courses = useMemo(() => {
        const term = search.trim().toLowerCase();
        return (coursesQuery.data?.courses || []).filter((course) => {
            const matchesSearch = !term || `${course.title} ${course.summary || ''}`.toLowerCase().includes(term);
            const matchesFilter = filter === 'all' || course.visibility === filter || (course.required_for_roles || []).includes(filter);
            return matchesSearch && matchesFilter;
        });
    }, [coursesQuery.data, filter, search]);

    const continueCourses = courses.filter((course) => Number(course.progress_pct || 0) > 0 && Number(course.progress_pct || 0) < 100);
    const engagement = engagementQuery.data || {};
    const leaderboard = leaderboardQuery.data?.leaderboard || [];

    const stats = engagement.stats || { lessons_completed: 0, active_certificates: 0, badges_earned: 0, badge_points: 0 };

    return (
        <div className="space-y-6">
            <PageHeader
                title="Exotic Online University"
                subtitle="Coursework, certifications, and training analytics for the sales and CS team."
                actions={
                    <div className="flex items-center gap-2">
                        <a href="https://exoticonline.mintlify.app/" target="_blank" rel="noopener noreferrer" className="crm-btn-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                            Playbook
                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.5 6h2.25a.75.75 0 0 1 .75.75v2.25M21 3l-9 9m6.75 4.5v3a.75.75 0 0 1-.75.75H4.5a.75.75 0 0 1-.75-.75V6.75A.75.75 0 0 1 4.5 6h3" /></svg>
                        </a>
                        {isAdmin ? <Link to="/university/manage" className="crm-btn-primary px-3 py-2 text-sm">Manage content</Link> : null}
                    </div>
                }
            />

            {/* Quote of the Day — full width hero */}
            <QuoteOfTheDay />

            {/* HERO row: streak + stats + daily drill */}
            <section className="grid gap-4 lg:grid-cols-[1.1fr_1.4fr]">
                <div className="space-y-3">
                    <div className="rounded-2xl border border-slate-200 bg-gradient-to-br from-teal-700 via-slate-900 to-slate-950 p-6 text-white shadow-sm">
                        <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-teal-200">Welcome back, {user?.name?.split(' ')[0] || 'there'}</p>
                        <h2 className="mt-1 text-2xl font-bold leading-tight">The CRM is the front door to every Exotic Online play.</h2>
                        <p className="mt-2 text-sm text-slate-300">Lessons, certifications, glossary, daily drills — everything you need to sell and support is right here.</p>
                        <div className="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <Stat label="Lessons" value={stats.lessons_completed} />
                            <Stat label="Certificates" value={stats.active_certificates} />
                            <Stat label="Badges" value={stats.badges_earned} />
                            <Stat label="Points" value={stats.badge_points} />
                        </div>
                    </div>
                    <StreakFlame current={engagement.streak?.current || 0} longest={engagement.streak?.longest || 0} />
                </div>
                <DailyDrillCard />
            </section>

            {/* Catalog header */}
            <section className="rounded-2xl border border-slate-200 bg-white px-5 py-5">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-950">Learning catalog</h3>
                        <p className="text-sm text-slate-500">{courses.length} courses · grounded in real Exotic Online plays</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {['all', 'sales', 'cs'].map((item) => (
                            <button key={item} type="button" onClick={() => setFilter(item)} className={`rounded-lg px-3 py-2 text-sm font-semibold transition ${filter === item ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}>
                                {item === 'all' ? 'All' : item.toUpperCase()}
                            </button>
                        ))}
                        <input value={search} onChange={(event) => setSearch(event.target.value)} className="crm-input min-w-[220px]" placeholder="Search courses" />
                    </div>
                </div>
            </section>

            {/* Continue learning rail */}
            {continueCourses.length ? (
                <section className="space-y-3">
                    <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Continue learning</h3>
                    <div className="grid gap-3 lg:grid-cols-3">
                        {continueCourses.map((course) => <CourseCard key={course.id} course={course} compact />)}
                    </div>
                </section>
            ) : null}

            {/* Course grid */}
            <section className="grid gap-4 xl:grid-cols-3">
                {coursesQuery.isLoading ? <p className="text-sm text-slate-500">Loading courses…</p> : null}
                {courses.map((course) => <CourseCard key={course.id} course={course} />)}
            </section>

            {/* Two-up bottom row: badges + leaderboard preview */}
            <section className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                {engagement.badges_catalog?.length ? (
                    <div className="rounded-2xl border border-slate-200 bg-white p-5">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Trophy case</h3>
                            <span className="text-xs text-slate-500">{stats.badges_earned}/{engagement.badges_catalog.length} earned</span>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-4">
                            {engagement.badges_catalog.map((b) => (
                                <div key={b.code} title={b.description} className={`flex flex-col items-center gap-1.5 rounded-xl border px-2 py-3 text-center text-[11px] ${b.earned ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50/50 opacity-50'}`}>
                                    <span className={`flex h-10 w-10 items-center justify-center rounded-full ${b.earned ? 'bg-amber-500 text-white shadow' : 'bg-slate-200 text-slate-500'}`}>
                                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0" /></svg>
                                    </span>
                                    <span className="font-semibold text-slate-700">{b.title}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                <div className="rounded-2xl border border-slate-200 bg-white p-5">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Leaderboard</h3>
                        <Link to="/university/leaderboard" className="text-xs font-semibold text-teal-700 hover:text-teal-800">View all →</Link>
                    </div>
                    <ol className="mt-3 space-y-1.5">
                        {leaderboard.slice(0, 6).map((row, idx) => (
                            <li key={row.user_id} className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                                <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${idx === 0 ? 'bg-amber-500 text-white' : idx === 1 ? 'bg-slate-400 text-white' : idx === 2 ? 'bg-orange-400 text-white' : 'bg-slate-100 text-slate-600'}`}>{idx + 1}</span>
                                <span className="flex-1 truncate text-slate-700">{row.name}</span>
                                <span className="text-xs text-slate-500">{row.lessons_completed}L · {row.certificates}C</span>
                                <span className="w-12 text-right font-semibold text-teal-700">{row.score}</span>
                            </li>
                        ))}
                        {!leaderboard.length ? <li className="text-sm text-slate-500">No activity yet — be the first.</li> : null}
                    </ol>
                </div>
            </section>
        </div>
    );
}

function Stat({ label, value }) {
    return (
        <div className="rounded-xl bg-white/5 px-3 py-2 ring-1 ring-inset ring-white/10">
            <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">{label}</p>
            <p className="mt-0.5 text-xl font-bold text-white">{value}</p>
        </div>
    );
}
