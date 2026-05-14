import React, { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminNav } from './CourseEditor';
import PageHeader from '../../../components/PageHeader';
import universityApi from '../../../services/universityApi';
import { getMediaUploadPreflight } from '../../../components/MediaUploadProvider';
import { useToast } from '../../../components/ToastProvider';

export default function LessonEditor() {
    const [searchParams] = useSearchParams();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [lessonForm, setLessonForm] = useState({ title: '', body_draft: '', status: 'draft', duration_minutes: 10 });
    const [selectedModuleId, setSelectedModuleId] = useState(null);
    const [selectedLessonId, setSelectedLessonId] = useState(null);
    const [embedUrl, setEmbedUrl] = useState('');
    const coursesQuery = useQuery({ queryKey: ['university-admin-courses'], queryFn: () => universityApi.listCourses({ status: 'all' }) });
    const courses = coursesQuery.data?.courses || [];
    const course = courses.find((item) => Number(item.id) === Number(searchParams.get('course'))) || courses[0];
    const modules = course?.modules || [];
    const module = modules.find((item) => Number(item.id) === Number(selectedModuleId)) || modules[0];
    const lessons = useMemo(() => module?.lessons || [], [module]);
    const selectedLesson = lessons.find((lesson) => Number(lesson.id) === Number(selectedLessonId));

    const saveLesson = useMutation({
        mutationFn: () => lessonForm.id ? universityApi.updateLesson(lessonForm.id, lessonForm) : universityApi.createLesson(module.id, lessonForm),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-admin-courses'] });
            toast.success('Lesson saved.');
        },
    });
    const uploadMedia = useMutation({
        mutationFn: (formData) => universityApi.uploadLessonMedia(selectedLesson.id, formData),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-admin-courses'] });
            setEmbedUrl('');
            toast.success('Media added.');
        },
    });

    function handleFile(event) {
        const file = event.target.files?.[0];
        if (!file || !selectedLesson) return;
        const preflight = getMediaUploadPreflight([file], false);
        if (!preflight.ok) {
            toast.warning(preflight.errors[0]);
            return;
        }
        const formData = new FormData();
        formData.append('file', file);
        uploadMedia.mutate(formData);
    }

    return (
        <div className="space-y-5">
            <PageHeader title="Lesson Editor" subtitle="Draft lesson content, publish updates, and attach media." actions={<AdminNav />} />
            <section className="grid gap-5 xl:grid-cols-[320px_minmax(0,1fr)]">
                <aside className="rounded-lg border border-slate-200 bg-white p-4">
                    <select value={module?.id || ''} onChange={(event) => setSelectedModuleId(event.target.value)} className="crm-input w-full">
                        {modules.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                    </select>
                    <div className="mt-4 space-y-2">
                        {lessons.map((lesson) => (
                            <button key={lesson.id} type="button" onClick={() => { setSelectedLessonId(lesson.id); setLessonForm({ ...lesson }); }} className="block w-full rounded-lg border border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-50">
                                <span className="font-semibold text-slate-800">{lesson.title}</span>
                                <span className="ml-2 text-xs text-slate-400">{lesson.status}</span>
                            </button>
                        ))}
                    </div>
                </aside>

                <main className="rounded-lg border border-slate-200 bg-white p-5">
                    <form onSubmit={(event) => { event.preventDefault(); saveLesson.mutate(); }} className="space-y-3">
                        <input value={lessonForm.title} onChange={(event) => setLessonForm((current) => ({ ...current, title: event.target.value }))} className="crm-input w-full" placeholder="Lesson title" />
                        <textarea value={lessonForm.body_draft || ''} onChange={(event) => setLessonForm((current) => ({ ...current, body_draft: event.target.value }))} className="crm-input min-h-72 w-full font-mono text-sm" placeholder="# Lesson content" />
                        <div className="grid gap-3 sm:grid-cols-3">
                            <input type="number" value={lessonForm.duration_minutes} onChange={(event) => setLessonForm((current) => ({ ...current, duration_minutes: Number(event.target.value) }))} className="crm-input" />
                            <select value={lessonForm.status} onChange={(event) => setLessonForm((current) => ({ ...current, status: event.target.value }))} className="crm-input">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="archived">Archived</option>
                            </select>
                            <button type="submit" disabled={!module || !lessonForm.title.trim()} className="crm-btn-primary px-3 py-2 disabled:opacity-50">Save lesson</button>
                        </div>
                    </form>

                    {selectedLesson ? (
                        <div className="mt-6 rounded-lg bg-slate-50 p-4">
                            <h3 className="font-semibold text-slate-950">Media</h3>
                            <div className="mt-3 flex flex-wrap gap-2">
                                <input type="file" accept="image/*,video/mp4,application/pdf" onChange={handleFile} className="crm-input" />
                                <input value={embedUrl} onChange={(event) => setEmbedUrl(event.target.value)} className="crm-input min-w-[280px]" placeholder="YouTube, Vimeo, or Loom URL" />
                                <button type="button" onClick={() => { const data = new FormData(); data.append('embed_url', embedUrl); uploadMedia.mutate(data); }} disabled={!embedUrl.trim()} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Add embed</button>
                            </div>
                        </div>
                    ) : null}
                </main>
            </section>
        </div>
    );
}
