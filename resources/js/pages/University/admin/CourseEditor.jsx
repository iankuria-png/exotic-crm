import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import PageHeader from '../../../components/PageHeader';
import universityApi from '../../../services/universityApi';
import { useToast } from '../../../components/ToastProvider';

const emptyCourse = { title: '', summary: '', status: 'draft', visibility: 'all', order: 0 };

export default function CourseEditor() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [form, setForm] = useState(emptyCourse);
    const [selectedCourseId, setSelectedCourseId] = useState(null);
    const [moduleTitle, setModuleTitle] = useState('');
    const coursesQuery = useQuery({
        queryKey: ['university-admin-courses'],
        queryFn: () => universityApi.listCourses(),
    });
    const courses = coursesQuery.data?.courses || [];
    const selectedCourse = useMemo(() => courses.find((course) => Number(course.id) === Number(selectedCourseId)) || courses[0], [courses, selectedCourseId]);

    const saveCourse = useMutation({
        mutationFn: () => form.id ? universityApi.updateCourse(form.id, form) : universityApi.createCourse(form),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-admin-courses'] });
            queryClient.invalidateQueries({ queryKey: ['university-courses'] });
            setForm(emptyCourse);
            toast.success('Course saved.');
        },
    });
    const addModule = useMutation({
        mutationFn: () => universityApi.createModule(selectedCourse.id, { title: moduleTitle }),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-admin-courses'] });
            queryClient.invalidateQueries({ queryKey: ['university-courses'] });
            setModuleTitle('');
            toast.success('Module added.');
        },
    });

    return (
        <div className="space-y-5">
            <PageHeader title="University Manager" subtitle="Author courses, modules, lessons, certifications, and analytics." actions={<AdminNav />} />
            <section className="grid gap-5 xl:grid-cols-[360px_minmax(0,1fr)]">
                <form onSubmit={(event) => { event.preventDefault(); saveCourse.mutate(); }} className="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-slate-950">{form.id ? 'Edit course' : 'New course'}</h2>
                    <div className="mt-4 space-y-3">
                        <input value={form.title} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} className="crm-input w-full" placeholder="Course title" />
                        <textarea value={form.summary || ''} onChange={(event) => setForm((current) => ({ ...current, summary: event.target.value }))} className="crm-input min-h-24 w-full" placeholder="Summary" />
                        <div className="grid grid-cols-2 gap-3">
                            <select value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))} className="crm-input">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select value={form.visibility} onChange={(event) => setForm((current) => ({ ...current, visibility: event.target.value }))} className="crm-input">
                                <option value="all">All</option>
                                <option value="sales">Sales</option>
                                <option value="cs">CS</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <button type="submit" disabled={saveCourse.isPending || !form.title.trim()} className="crm-btn-primary w-full px-3 py-2 disabled:opacity-50">Save course</button>
                    </div>
                </form>

                <div className="rounded-lg border border-slate-200 bg-white p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold text-slate-950">Courses</h2>
                        {selectedCourse ? <Link to={`/university/manage/lessons?course=${selectedCourse.id}`} className="crm-btn-secondary px-3 py-2 text-sm">Open lessons</Link> : null}
                    </div>
                    <div className="mt-4 grid gap-3">
                        {courses.map((course) => (
                            <button key={course.id} type="button" onClick={() => { setSelectedCourseId(course.id); setForm({ ...course }); }} className={`rounded-lg border px-4 py-3 text-left transition ${Number(selectedCourse?.id) === Number(course.id) ? 'border-teal-300 bg-teal-50' : 'border-slate-200 hover:bg-slate-50'}`}>
                                <div className="flex items-center justify-between gap-3">
                                    <p className="font-semibold text-slate-950">{course.title}</p>
                                    <span className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{course.status}</span>
                                </div>
                                <p className="mt-1 text-sm text-slate-500">{course.modules?.length || 0} modules</p>
                            </button>
                        ))}
                        {!coursesQuery.isLoading && courses.length === 0 ? (
                            <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                                <p className="font-semibold text-slate-900">No draft courses found</p>
                                <p className="mt-1 text-sm text-slate-500">Run the University seed migration or `php artisan crm:seed-university` to import the starter course and certification bank.</p>
                            </div>
                        ) : null}
                    </div>

                    {selectedCourse ? (
                        <div className="mt-6 rounded-lg bg-slate-50 p-4">
                            <h3 className="font-semibold text-slate-950">Add module to {selectedCourse.title}</h3>
                            <div className="mt-3 flex gap-2">
                                <input value={moduleTitle} onChange={(event) => setModuleTitle(event.target.value)} className="crm-input flex-1" placeholder="Module title" />
                                <button type="button" onClick={() => addModule.mutate()} disabled={!moduleTitle.trim()} className="crm-btn-primary px-3 py-2 text-sm disabled:opacity-50">Add</button>
                            </div>
                        </div>
                    ) : null}
                </div>
            </section>
        </div>
    );
}

export function AdminNav() {
    return (
        <>
            <Link to="/university/manage" className="crm-btn-secondary px-3 py-2 text-sm">Courses</Link>
            <Link to="/university/manage/lessons" className="crm-btn-secondary px-3 py-2 text-sm">Lessons</Link>
            <Link to="/university/manage/questions" className="crm-btn-secondary px-3 py-2 text-sm">Questions</Link>
            <Link to="/university/manage/analytics" className="crm-btn-secondary px-3 py-2 text-sm">Analytics</Link>
        </>
    );
}
