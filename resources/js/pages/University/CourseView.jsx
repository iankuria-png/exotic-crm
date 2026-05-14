import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import LessonBody, { extractHeadings } from '../../components/University/LessonBody';
import LessonMediaViewer from '../../components/University/LessonMediaViewer';
import ProgressRing from '../../components/University/ProgressRing';
import universityApi from '../../services/universityApi';
import { useToast } from '../../components/ToastProvider';

export default function CourseView() {
    const { slug } = useParams();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [activeLessonId, setActiveLessonId] = useState(null);
    const [scrollPct, setScrollPct] = useState(0);
    const [feedbackOpen, setFeedbackOpen] = useState(false);
    const mainRef = useRef(null);

    const courseQuery = useQuery({
        queryKey: ['university-course', slug],
        queryFn: () => universityApi.getCourse(slug),
    });

    const course = courseQuery.data?.course;
    const lessons = useMemo(() => (course?.modules || []).flatMap((module) => module.lessons || []), [course]);
    const activeLesson = useMemo(
        () => lessons.find((lesson) => Number(lesson.id) === Number(activeLessonId)) || lessons[0],
        [lessons, activeLessonId]
    );
    const activeIndex = lessons.findIndex((lesson) => Number(lesson.id) === Number(activeLesson?.id));
    const certification = (course?.certifications || [])[0];

    const headings = useMemo(() => extractHeadings(activeLesson?.body || activeLesson?.body_draft), [activeLesson]);

    const progressMutation = useMutation({
        mutationFn: (payload) => universityApi.markProgress(activeLesson.id, payload),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['university-course', slug] });
            queryClient.invalidateQueries({ queryKey: ['university-courses'] });
            queryClient.invalidateQueries({ queryKey: ['university-engagement-me'] });
            if (data?.newly_earned_badges?.length) {
                toast.success(`Badge earned: ${data.newly_earned_badges.join(', ')}`);
            } else {
                toast.success('Lesson progress saved.');
            }
        },
    });

    const feedbackMutation = useMutation({
        mutationFn: ({ rating, comment }) => universityApi.submitLessonFeedback(activeLesson.id, { rating, comment }),
        onSuccess: () => {
            toast.success('Thanks — feedback recorded.');
            setFeedbackOpen(false);
        },
    });

    // Track scroll progress on the article
    useEffect(() => {
        function onScroll() {
            const article = mainRef.current?.querySelector('article');
            if (!article) return;
            const start = article.getBoundingClientRect().top + window.scrollY - 80;
            const end = start + article.offsetHeight - window.innerHeight + 200;
            const here = window.scrollY;
            const pct = Math.max(0, Math.min(100, ((here - start) / Math.max(1, end - start)) * 100));
            setScrollPct(Math.round(pct));
        }
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, [activeLesson?.id]);

    // Reset scroll + progress when switching lesson
    useEffect(() => { window.scrollTo({ top: 0, behavior: 'smooth' }); }, [activeLesson?.id]);

    if (courseQuery.isLoading) {
        return <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading course…</div>;
    }
    if (!course) {
        return <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">Course unavailable.</div>;
    }

    const accent = course.accent_color || 'teal';
    const accentBar = {
        teal: 'from-teal-500 to-cyan-500',
        indigo: 'from-indigo-500 to-violet-500',
        amber: 'from-amber-500 to-orange-500',
        rose: 'from-rose-500 to-pink-500',
    }[accent] || 'from-teal-500 to-cyan-500';

    return (
        <div className="grid gap-5 lg:grid-cols-[280px_minmax(0,1fr)_280px]">
            {/* LEFT — module / lesson sidebar */}
            <aside className="rounded-2xl border border-slate-200 bg-white p-4 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:self-start lg:overflow-y-auto">
                <Link to="/university" className="text-sm font-semibold text-teal-700 hover:text-teal-800">← Back to University</Link>
                <div className="mt-4 flex items-center gap-3">
                    <ProgressRing value={course.progress_pct} />
                    <div>
                        <h2 className="font-semibold leading-tight text-slate-950">{course.title}</h2>
                        <p className="text-xs text-slate-500">{course.completed_lessons}/{course.lesson_count} lessons · {course.duration_minutes} min</p>
                    </div>
                </div>
                <div className="mt-5 space-y-4">
                    {(course.modules || []).map((module) => (
                        <div key={module.id}>
                            <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{module.title}</p>
                            <div className="mt-1.5 space-y-1">
                                {(module.lessons || []).map((lesson) => {
                                    const isActive = Number(activeLesson?.id) === Number(lesson.id);
                                    const isDone = !!lesson.progress?.completed_at;
                                    return (
                                        <button
                                            key={lesson.id}
                                            type="button"
                                            onClick={() => setActiveLessonId(lesson.id)}
                                            className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] transition ${isActive ? 'bg-teal-50 font-semibold text-teal-900' : 'text-slate-600 hover:bg-slate-50'}`}
                                        >
                                            <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] ${isDone ? 'border-emerald-500 bg-emerald-500 text-white' : isActive ? 'border-teal-400 bg-white text-teal-700' : 'border-slate-300 text-slate-400'}`}>
                                                {isDone ? '✓' : ''}
                                            </span>
                                            <span className="flex-1 truncate">{lesson.title}</span>
                                            <span className="text-[11px] text-slate-400">{lesson.duration_minutes}m</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </aside>

            {/* CENTER — lesson reader */}
            <main ref={mainRef} className="min-w-0 rounded-2xl border border-slate-200 bg-white">
                <div className="pointer-events-none sticky top-0 z-10 h-1 overflow-hidden rounded-t-2xl">
                    <div className={`h-full bg-gradient-to-r ${accentBar} transition-all`} style={{ width: `${scrollPct}%` }} />
                </div>
                <article className="px-5 py-8 lg:px-12 lg:py-10">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0">
                            <div className="flex items-center gap-3 text-xs font-bold uppercase tracking-[0.18em] text-teal-700">
                                <span>{activeLesson?.duration_minutes || 0} min</span>
                                {activeLesson?.kind && activeLesson.kind !== 'lesson' ? (
                                    <span className="rounded-full bg-slate-900 px-2 py-0.5 text-[10px] tracking-wider text-white">{activeLesson.kind}</span>
                                ) : null}
                            </div>
                            <h1 className="mt-1.5 text-3xl font-bold tracking-tight text-slate-950">{activeLesson?.title}</h1>
                            {activeLesson?.subtitle ? <p className="mt-1.5 text-base text-slate-500">{activeLesson.subtitle}</p> : null}
                            {activeLesson?.playbook_url ? (
                                <a href={activeLesson.playbook_url} target="_blank" rel="noopener noreferrer" className="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                                    View source on Playbook
                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.5 6h2.25a.75.75 0 0 1 .75.75v2.25M21 3l-9 9m6.75 4.5v3a.75.75 0 0 1-.75.75H4.5a.75.75 0 0 1-.75-.75V6.75A.75.75 0 0 1 4.5 6h3" /></svg>
                                </a>
                            ) : null}
                        </div>
                        <button
                            type="button"
                            onClick={() => progressMutation.mutate({ completed: true, seconds_spent: Math.max(60, Number(activeLesson?.duration_minutes || 1) * 60) })}
                            className={`crm-btn-primary shrink-0 px-4 py-2 text-sm ${activeLesson?.progress?.completed_at ? 'opacity-60' : ''}`}
                        >
                            {activeLesson?.progress?.completed_at ? '✓ Completed' : 'Mark complete'}
                        </button>
                    </div>

                    <div className="prose prose-slate mt-8 max-w-none">
                        <LessonBody body={activeLesson?.body || activeLesson?.body_draft} />
                    </div>

                    <LessonMediaViewer media={activeLesson?.media || []} />

                    {/* Lesson feedback */}
                    <div className="mt-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-5 py-4">
                        <p className="text-sm font-semibold text-slate-700">Was this lesson helpful?</p>
                        <div className="flex items-center gap-2">
                            <button type="button" onClick={() => feedbackMutation.mutate({ rating: 1 })} className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm hover:border-emerald-300 hover:bg-emerald-50">👍 Yes</button>
                            <button type="button" onClick={() => setFeedbackOpen((v) => !v)} className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm hover:border-rose-300 hover:bg-rose-50">👎 Needs work</button>
                        </div>
                    </div>
                    {feedbackOpen ? (
                        <form
                            className="mt-3 rounded-xl border border-rose-200 bg-rose-50/50 p-4"
                            onSubmit={(e) => { e.preventDefault(); const fd = new FormData(e.currentTarget); feedbackMutation.mutate({ rating: -1, comment: fd.get('comment') }); }}
                        >
                            <textarea name="comment" required maxLength={1000} className="crm-input min-h-20 w-full" placeholder="What would make this lesson more useful?" />
                            <div className="mt-2 flex justify-end gap-2">
                                <button type="button" onClick={() => setFeedbackOpen(false)} className="crm-btn-secondary px-3 py-1.5 text-sm">Cancel</button>
                                <button type="submit" className="crm-btn-primary px-3 py-1.5 text-sm">Send feedback</button>
                            </div>
                        </form>
                    ) : null}
                </article>

                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" disabled={activeIndex <= 0} onClick={() => setActiveLessonId(lessons[activeIndex - 1]?.id)} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">← Previous</button>
                    {certification ? <Link to={`/university/certifications/${certification.id}`} className="crm-btn-primary px-3 py-2 text-sm">Take Certification</Link> : <span />}
                    <button type="button" disabled={activeIndex < 0 || activeIndex >= lessons.length - 1} onClick={() => setActiveLessonId(lessons[activeIndex + 1]?.id)} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Next →</button>
                </footer>
            </main>

            {/* RIGHT — TOC + Quick Reference */}
            <aside className="hidden lg:block lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:self-start lg:overflow-y-auto">
                {headings.length ? (
                    <div className="rounded-2xl border border-slate-200 bg-white p-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">On this lesson</p>
                        <ul className="mt-2 space-y-1.5 text-sm">
                            {headings.map((h) => (
                                <li key={h.id} className={h.level === 3 ? 'pl-3' : ''}>
                                    <a href={`#${h.id}`} className="block rounded-md px-2 py-1 text-slate-600 hover:bg-slate-50 hover:text-teal-700">{h.text}</a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {activeLesson?.quick_reference ? (
                    <div className="mt-4 rounded-2xl border border-teal-200 bg-gradient-to-br from-teal-50 to-white p-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-teal-700">Quick reference</p>
                        <pre className="mt-2 whitespace-pre-wrap text-[13px] leading-6 text-slate-700">{activeLesson.quick_reference}</pre>
                    </div>
                ) : null}

                {course.learning_outcomes?.length ? (
                    <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Course outcomes</p>
                        <ul className="mt-2 space-y-1.5 text-sm text-slate-600">
                            {course.learning_outcomes.map((outcome, i) => (
                                <li key={i} className="flex gap-2"><span className="text-teal-600">✓</span><span>{outcome}</span></li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </aside>
        </div>
    );
}
