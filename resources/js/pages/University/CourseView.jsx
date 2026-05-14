import React, { useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import ReactMarkdown from 'react-markdown';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import LessonMediaViewer from '../../components/University/LessonMediaViewer';
import ProgressRing from '../../components/University/ProgressRing';
import universityApi from '../../services/universityApi';
import { useToast } from '../../components/ToastProvider';

export default function CourseView() {
    const { slug } = useParams();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [activeLessonId, setActiveLessonId] = useState(null);
    const courseQuery = useQuery({
        queryKey: ['university-course', slug],
        queryFn: () => universityApi.getCourse(slug),
    });

    const course = courseQuery.data?.course;
    const lessons = useMemo(() => (course?.modules || []).flatMap((module) => module.lessons || []), [course]);
    const activeLesson = lessons.find((lesson) => Number(lesson.id) === Number(activeLessonId)) || lessons[0];
    const activeIndex = lessons.findIndex((lesson) => Number(lesson.id) === Number(activeLesson?.id));
    const certification = (course?.certifications || [])[0];

    const progressMutation = useMutation({
        mutationFn: (payload) => universityApi.markProgress(activeLesson.id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-course', slug] });
            queryClient.invalidateQueries({ queryKey: ['university-courses'] });
            toast.success('Lesson progress saved.');
        },
    });

    if (courseQuery.isLoading) {
        return <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">Loading course...</div>;
    }

    if (!course) {
        return <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">Course unavailable.</div>;
    }

    return (
        <div className="grid gap-5 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside className="rounded-lg border border-slate-200 bg-white p-4 lg:sticky lg:top-4 lg:self-start">
                <Link to="/university" className="text-sm font-semibold text-teal-700">Back to University</Link>
                <div className="mt-5 flex items-center gap-3">
                    <ProgressRing value={course.progress_pct} />
                    <div>
                        <h2 className="font-semibold text-slate-950">{course.title}</h2>
                        <p className="text-xs text-slate-500">{course.completed_lessons}/{course.lesson_count} lessons complete</p>
                    </div>
                </div>
                <div className="mt-5 space-y-4">
                    {(course.modules || []).map((module) => (
                        <div key={module.id}>
                            <p className="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">{module.title}</p>
                            <div className="mt-2 space-y-1">
                                {(module.lessons || []).map((lesson) => (
                                    <button
                                        key={lesson.id}
                                        type="button"
                                        onClick={() => setActiveLessonId(lesson.id)}
                                        className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm ${Number(activeLesson?.id) === Number(lesson.id) ? 'bg-teal-50 font-semibold text-teal-900' : 'text-slate-600 hover:bg-slate-50'}`}
                                    >
                                        <span>{lesson.title}</span>
                                        {lesson.progress?.completed_at ? <span className="text-emerald-600">Done</span> : null}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </aside>

            <main className="min-w-0 rounded-lg border border-slate-200 bg-white">
                <div className="sticky top-0 z-10 h-1 bg-slate-100">
                    <div className="h-full bg-teal-600 transition-all" style={{ width: `${course.progress_pct || 0}%` }} />
                </div>
                <article className="px-5 py-6 lg:px-10 lg:py-9">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">{activeLesson?.duration_minutes || 0} min</p>
                            <h1 className="mt-1 text-2xl font-semibold text-slate-950">{activeLesson?.title}</h1>
                        </div>
                        <button type="button" onClick={() => progressMutation.mutate({ completed: true, seconds_spent: Math.max(60, Number(activeLesson?.duration_minutes || 1) * 60) })} className="crm-btn-primary px-3 py-2 text-sm">
                            Mark complete
                        </button>
                    </div>
                    <div className="prose prose-slate mt-8 max-w-[70ch]">
                        <ReactMarkdown>{activeLesson?.body || activeLesson?.body_draft || 'No lesson content yet.'}</ReactMarkdown>
                    </div>
                    <LessonMediaViewer media={activeLesson?.media || []} />
                </article>
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
                    <button type="button" disabled={activeIndex <= 0} onClick={() => setActiveLessonId(lessons[activeIndex - 1]?.id)} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Previous</button>
                    {certification ? <Link to={`/university/certifications/${certification.id}`} className="crm-btn-primary px-3 py-2 text-sm">Certification</Link> : null}
                    <button type="button" disabled={activeIndex < 0 || activeIndex >= lessons.length - 1} onClick={() => setActiveLessonId(lessons[activeIndex + 1]?.id)} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Next</button>
                </footer>
            </main>
        </div>
    );
}
