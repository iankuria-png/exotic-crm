import React from 'react';
import { Link } from 'react-router-dom';
import ProgressRing from './ProgressRing';

const ACCENT_BG = {
    teal: 'from-teal-700 via-teal-900 to-slate-900',
    indigo: 'from-indigo-700 via-indigo-900 to-slate-900',
    amber: 'from-amber-600 via-orange-800 to-slate-900',
    rose: 'from-rose-600 via-rose-900 to-slate-900',
};

const ACCENT_PILL = {
    teal: 'bg-teal-100 text-teal-800',
    indigo: 'bg-indigo-100 text-indigo-800',
    amber: 'bg-amber-100 text-amber-800',
    rose: 'bg-rose-100 text-rose-800',
};

const DIFFICULTY_LABEL = {
    beginner: 'Beginner',
    intermediate: 'Intermediate',
    advanced: 'Advanced',
};

export default function CourseCard({ course, compact = false }) {
    const certification = (course.certifications || [])[0];
    const accent = course.accent_color || 'teal';
    const cover = course.cover_image_url ? { backgroundImage: `url(${course.cover_image_url})`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined;
    const outcomes = (course.learning_outcomes || []).slice(0, 3);

    return (
        <Link
            to={`/university/courses/${course.slug}`}
            className="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-teal-300 hover:shadow-md"
        >
            <div className={`relative h-32 bg-gradient-to-br ${ACCENT_BG[accent] || ACCENT_BG.teal}`} style={cover}>
                <div className="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent" />
                <div className="absolute left-4 top-3 flex flex-wrap items-center gap-1.5">
                    <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] ${ACCENT_PILL[accent] || ACCENT_PILL.teal}`}>{course.visibility || 'all'}</span>
                    {course.difficulty ? <span className="rounded-full bg-white/90 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-700">{DIFFICULTY_LABEL[course.difficulty] || course.difficulty}</span> : null}
                </div>
                {certification?.certificate ? (
                    <span className="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white shadow">
                        ✓ Certified
                    </span>
                ) : null}
            </div>
            <div className="flex flex-1 flex-col p-5">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h3 className="truncate text-lg font-bold text-slate-950 group-hover:text-teal-800">{course.title}</h3>
                        {course.instructor_name ? <p className="mt-0.5 text-xs text-slate-500">By {course.instructor_name}</p> : null}
                    </div>
                    <ProgressRing value={course.progress_pct} size={compact ? 40 : 48} />
                </div>
                <p className="mt-2.5 line-clamp-2 text-sm text-slate-600">{course.summary || ''}</p>

                {outcomes.length && !compact ? (
                    <ul className="mt-3 space-y-1 text-[12.5px] text-slate-600">
                        {outcomes.map((o, i) => (
                            <li key={i} className="flex gap-1.5"><span className="text-teal-600">✓</span><span className="line-clamp-1">{o}</span></li>
                        ))}
                    </ul>
                ) : null}

                <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3 text-xs font-semibold text-slate-500">
                    <span>{course.lesson_count || 0} lessons</span>
                    <span>·</span>
                    <span>{course.duration_minutes || course.estimated_minutes || 0} min</span>
                    {course.progress_pct > 0 && course.progress_pct < 100 ? <span className="ml-auto text-teal-700">In progress</span> : null}
                    {course.progress_pct >= 100 ? <span className="ml-auto text-emerald-700">Completed</span> : null}
                </div>
            </div>
        </Link>
    );
}
