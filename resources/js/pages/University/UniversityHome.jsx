import React, { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import CourseCard from '../../components/University/CourseCard';
import DailyDrillCard from '../../components/University/DailyDrillCard';
import QuoteOfTheDay from '../../components/University/QuoteOfTheDay';
import StreakFlame from '../../components/University/StreakFlame';
import universityApi from '../../services/universityApi';
import { useAuth } from '../../hooks/useAuth';
import { useToast } from '../../components/ToastProvider';

const emptyQuoteDraft = { quote: '', author: '', source_label: '', category: 'training' };

export default function UniversityHome() {
    const { user } = useAuth();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('all');
    const [quoteDraft, setQuoteDraft] = useState(emptyQuoteDraft);
    const isAdmin = ['admin', 'sub_admin'].includes(user?.role);

    const coursesQuery = useQuery({
        queryKey: ['university-courses'],
        queryFn: () => universityApi.listCourses(),
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
        </div>
    );
}

function DailyQuotePanel({ canSubmit, quote, draft, isLoading, isRefreshing, isSubmitting, onDraftChange, onRefresh, onSubmit }) {
    const currentQuote = quote || {};
    const draftReady = draft.quote.trim().length >= 3;

    return (
        <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-teal-50 text-teal-700">
                            <QuoteMarkIcon />
                        </span>
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">Quote of the day</p>
                            <p className="text-sm text-slate-500">{currentQuote.quote_date || 'Today'}</p>
                        </div>
                    </div>
                    <blockquote className="mt-5 max-w-4xl text-2xl font-semibold leading-snug text-slate-950 sm:text-3xl">
                        {isLoading ? 'Loading today quote...' : `“${currentQuote.quote || 'Akili ni mali.'}”`}
                    </blockquote>
                    <div className="mt-4 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                        <span className="font-semibold text-slate-700">{currentQuote.author || 'Exotic Online University'}</span>
                        {currentQuote.source_label ? <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">{currentQuote.source_label}</span> : null}
                        {currentQuote.category ? <span className="rounded-full bg-teal-50 px-2.5 py-1 text-xs font-bold uppercase tracking-[0.12em] text-teal-700">{currentQuote.category}</span> : null}
                    </div>
                </div>

                <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Tomorrow</h3>
                            <p className="mt-1 text-sm text-slate-600">Prepare the next daily quote before the team starts learning.</p>
                        </div>
                        <button
                            type="button"
                            onClick={onRefresh}
                            disabled={isRefreshing}
                            className="crm-btn-secondary gap-2 px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <RefreshIcon className={isRefreshing ? 'animate-spin' : ''} />
                            {isRefreshing ? 'Refreshing' : 'Refresh'}
                        </button>
                    </div>

                    <div className="mt-4 space-y-3">
                        <textarea
                            value={draft.quote}
                            onChange={(event) => onDraftChange((current) => ({ ...current, quote: event.target.value }))}
                            className="crm-input min-h-28 w-full resize-y"
                            placeholder="Tomorrow quote"
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <input
                                value={draft.author}
                                onChange={(event) => onDraftChange((current) => ({ ...current, author: event.target.value }))}
                                className="crm-input"
                                placeholder="Author"
                            />
                            <input
                                value={draft.source_label}
                                onChange={(event) => onDraftChange((current) => ({ ...current, source_label: event.target.value }))}
                                className="crm-input"
                                placeholder="Source"
                            />
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <input
                                value={draft.category}
                                onChange={(event) => onDraftChange((current) => ({ ...current, category: event.target.value }))}
                                className="crm-input sm:max-w-[180px]"
                                placeholder="Category"
                            />
                            {canSubmit ? (
                                <button
                                    type="button"
                                    onClick={onSubmit}
                                    disabled={!draftReady || isSubmitting}
                                    className="crm-btn-primary gap-2 px-4 py-2 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <SubmitIcon />
                                    {isSubmitting ? 'Submitting' : 'Submit tomorrow'}
                                </button>
                            ) : (
                                <p className="text-sm font-medium text-slate-500">Admins can submit tomorrow quote.</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </section>
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

function QuoteMarkIcon() {
    return (
        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 11h3v7H5v-7c0-3.3 1.4-5.6 4.2-7M18 11h3v7h-6v-7c0-3.3 1.4-5.6 4.2-7" />
        </svg>
    );
}

function RefreshIcon({ className = '' }) {
    return (
        <svg className={`h-4 w-4 ${className}`} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v6h6M20 20v-6h-6M5.5 14.5A7.5 7.5 0 0 0 18.7 17M18.5 9.5A7.5 7.5 0 0 0 5.3 7" />
        </svg>
    );
}

function SubmitIcon() {
    return (
        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.5 12.75 10 18.25 20 6.75" />
        </svg>
    );
}
