import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import ProgressRing from '../../components/University/ProgressRing';
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

    const courses = useMemo(() => {
        const term = search.trim().toLowerCase();
        return (coursesQuery.data?.courses || []).filter((course) => {
            const matchesSearch = !term || `${course.title} ${course.summary || ''}`.toLowerCase().includes(term);
            const matchesFilter = filter === 'all' || course.visibility === filter || (course.required_for_roles || []).includes(filter);
            return matchesSearch && matchesFilter;
        });
    }, [coursesQuery.data, filter, search]);

    const continueCourses = courses.filter((course) => Number(course.progress_pct || 0) > 0 && Number(course.progress_pct || 0) < 100);

    return (
        <div className="space-y-6">
            <PageHeader
                title="University"
                subtitle="Coursework, certifications, and training analytics for the CRM team."
                actions={isAdmin ? <Link to="/university/manage" className="crm-btn-primary px-3 py-2 text-sm">Manage content</Link> : null}
            />

            <section className="rounded-lg border border-slate-200 bg-white px-5 py-5">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-slate-950">Learning catalog</h3>
                        <p className="text-sm text-slate-500">{courses.length} courses available</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {['all', 'sales', 'cs'].map((item) => (
                            <button key={item} type="button" onClick={() => setFilter(item)} className={`rounded-lg px-3 py-2 text-sm font-semibold ${filter === item ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}>
                                {item === 'all' ? 'All' : item.toUpperCase()}
                            </button>
                        ))}
                        <input value={search} onChange={(event) => setSearch(event.target.value)} className="crm-input min-w-[220px]" placeholder="Search courses" />
                    </div>
                </div>
            </section>

            {continueCourses.length ? (
                <section className="space-y-3">
                    <h3 className="text-sm font-bold uppercase tracking-[0.16em] text-slate-500">Continue learning</h3>
                    <div className="grid gap-3 lg:grid-cols-3">
                        {continueCourses.map((course) => <CourseCard key={course.id} course={course} compact />)}
                    </div>
                </section>
            ) : null}

            <section className="grid gap-4 xl:grid-cols-3">
                {coursesQuery.isLoading ? <p className="text-sm text-slate-500">Loading courses...</p> : null}
                {courses.map((course) => <CourseCard key={course.id} course={course} />)}
            </section>
        </div>
    );
}

function CourseCard({ course, compact = false }) {
    const certification = (course.certifications || [])[0];

    return (
        <Link to={`/university/courses/${course.slug}`} className="group overflow-hidden rounded-lg border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:border-teal-300 hover:shadow-sm">
            <div className="h-28 bg-gradient-to-br from-slate-900 via-teal-900 to-slate-800" style={course.cover_image_url ? { backgroundImage: `url(${course.cover_image_url})`, backgroundSize: 'cover', backgroundPosition: 'center' } : undefined} />
            <div className="p-5">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.16em] text-teal-700">{course.visibility || 'all'}</p>
                        <h3 className="mt-1 text-lg font-semibold text-slate-950 group-hover:text-teal-800">{course.title}</h3>
                    </div>
                    <ProgressRing value={course.progress_pct} size={compact ? 44 : 52} />
                </div>
                <p className="mt-3 line-clamp-2 text-sm text-slate-500">{course.summary || 'No summary yet.'}</p>
                <div className="mt-4 flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-500">
                    <span>{course.lesson_count || 0} lessons</span>
                    <span>{course.duration_minutes || 0} min</span>
                    {certification?.certificate ? <span className="text-emerald-700">Certified</span> : null}
                </div>
            </div>
        </Link>
    );
}
