import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminNav } from './CourseEditor';
import PageHeader from '../../../components/PageHeader';
import LessonBody from '../../../components/University/LessonBody';
import universityApi from '../../../services/universityApi';
import { getMediaUploadPreflight } from '../../../components/MediaUploadProvider';
import { useToast } from '../../../components/ToastProvider';

const CALLOUTS = [
    { kind: 'script', label: 'Script' },
    { kind: 'scenario', label: 'Scenario' },
    { kind: 'objection', label: 'Objection' },
    { kind: 'tip', label: 'Tip' },
    { kind: 'warning', label: 'Warning' },
    { kind: 'checklist', label: 'Checklist' },
];

const EMPTY_LESSON = { title: '', subtitle: '', body_draft: '', playbook_url: '', quick_reference: '', status: 'draft', duration_minutes: 10 };

export default function LessonEditor() {
    const [searchParams] = useSearchParams();
    const toast = useToast();
    const queryClient = useQueryClient();
    const [lessonForm, setLessonForm] = useState(EMPTY_LESSON);
    const [selectedModuleId, setSelectedModuleId] = useState(null);
    const [selectedLessonId, setSelectedLessonId] = useState(null);
    const [embedUrl, setEmbedUrl] = useState('');
    const [preview, setPreview] = useState(false);
    const bodyRef = useRef(null);

    const coursesQuery = useQuery({ queryKey: ['university-admin-courses'], queryFn: () => universityApi.listCourses({ status: 'all' }) });
    const courses = coursesQuery.data?.courses || [];
    const course = courses.find((item) => Number(item.id) === Number(searchParams.get('course'))) || courses[0];
    const modules = course?.modules || [];
    const module = modules.find((item) => Number(item.id) === Number(selectedModuleId)) || modules[0];
    const lessons = useMemo(() => module?.lessons || [], [module]);
    const selectedLesson = lessons.find((lesson) => Number(lesson.id) === Number(selectedLessonId));

    // When a lesson is picked from sidebar, hydrate the form
    useEffect(() => {
        if (selectedLesson) {
            setLessonForm({
                id: selectedLesson.id,
                title: selectedLesson.title || '',
                subtitle: selectedLesson.subtitle || '',
                body_draft: selectedLesson.body_draft || selectedLesson.body || '',
                playbook_url: selectedLesson.playbook_url || '',
                quick_reference: selectedLesson.quick_reference || '',
                status: selectedLesson.status || 'draft',
                duration_minutes: selectedLesson.duration_minutes || 10,
            });
        }
    }, [selectedLesson?.id]);

    const saveLesson = useMutation({
        mutationFn: () => lessonForm.id
            ? universityApi.updateLesson(lessonForm.id, lessonForm)
            : universityApi.createLesson(module.id, lessonForm),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['university-admin-courses'] });
            toast.success('Lesson saved.');
        },
        onError: () => toast.warning('Could not save lesson.'),
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
        if (!preflight.ok) { toast.warning(preflight.errors[0]); return; }
        const formData = new FormData();
        formData.append('file', file);
        uploadMedia.mutate(formData);
    }

    function insertCallout(kind) {
        const ta = bodyRef.current;
        if (!ta) return;
        const start = ta.selectionStart || 0;
        const end = ta.selectionEnd || 0;
        const before = lessonForm.body_draft.slice(0, start);
        const middle = lessonForm.body_draft.slice(start, end);
        const after = lessonForm.body_draft.slice(end);
        const snippet = `\n:::${kind} Optional title\n${middle || 'Write your callout content here. **Markdown works.**'}\n:::\n`;
        const next = before + snippet + after;
        setLessonForm((c) => ({ ...c, body_draft: next }));
        setTimeout(() => {
            ta.focus();
            ta.setSelectionRange(before.length + snippet.length, before.length + snippet.length);
        }, 0);
    }

    return (
        <div className="space-y-5">
            <PageHeader title="Lesson Editor" subtitle="Author rich lesson content with callouts. Switch between Edit and Preview to see the reader's view." actions={<AdminNav />} />
            <section className="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
                <aside className="space-y-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4">
                        <label className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Module</label>
                        <select value={module?.id || ''} onChange={(e) => setSelectedModuleId(e.target.value)} className="crm-input mt-1 w-full">
                            {modules.map((item) => <option key={item.id} value={item.id}>{item.title}</option>)}
                        </select>
                        <div className="mt-4 space-y-1">
                            {lessons.map((lesson) => {
                                const active = Number(selectedLessonId) === Number(lesson.id);
                                return (
                                    <button key={lesson.id} type="button" onClick={() => setSelectedLessonId(lesson.id)} className={`block w-full rounded-lg border px-3 py-2 text-left text-sm transition ${active ? 'border-teal-300 bg-teal-50 font-semibold text-teal-900' : 'border-slate-200 hover:bg-slate-50'}`}>
                                        <span className="block truncate">{lesson.title}</span>
                                        <span className="text-xs text-slate-400">{lesson.status} · {lesson.duration_minutes}m</span>
                                    </button>
                                );
                            })}
                            <button type="button" onClick={() => { setSelectedLessonId(null); setLessonForm(EMPTY_LESSON); }} className="block w-full rounded-lg border border-dashed border-slate-300 px-3 py-2 text-left text-xs text-slate-500 hover:bg-slate-50">
                                + New lesson
                            </button>
                        </div>
                    </div>
                </aside>

                <main className="rounded-2xl border border-slate-200 bg-white p-5">
                    <form onSubmit={(e) => { e.preventDefault(); saveLesson.mutate(); }} className="space-y-3">
                        <div className="grid gap-3 lg:grid-cols-2">
                            <input value={lessonForm.title} onChange={(e) => setLessonForm((c) => ({ ...c, title: e.target.value }))} className="crm-input" placeholder="Lesson title" />
                            <input value={lessonForm.subtitle} onChange={(e) => setLessonForm((c) => ({ ...c, subtitle: e.target.value }))} className="crm-input" placeholder="Optional subtitle" />
                        </div>
                        <input value={lessonForm.playbook_url} onChange={(e) => setLessonForm((c) => ({ ...c, playbook_url: e.target.value }))} className="crm-input w-full" placeholder="Playbook source URL (https://exoticonline.mintlify.app/…)" />

                        <div className="flex flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <span className="px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-slate-500">Insert callout:</span>
                            {CALLOUTS.map((c) => (
                                <button key={c.kind} type="button" onClick={() => insertCallout(c.kind)} className="rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 hover:border-teal-300 hover:bg-teal-50">
                                    {c.label}
                                </button>
                            ))}
                            <div className="ml-auto flex gap-1">
                                <button type="button" onClick={() => setPreview(false)} className={`rounded-md px-2 py-1 text-xs font-semibold ${!preview ? 'bg-slate-950 text-white' : 'bg-white text-slate-700'}`}>Edit</button>
                                <button type="button" onClick={() => setPreview(true)} className={`rounded-md px-2 py-1 text-xs font-semibold ${preview ? 'bg-slate-950 text-white' : 'bg-white text-slate-700'}`}>Preview</button>
                            </div>
                        </div>

                        {preview ? (
                            <div className="min-h-72 rounded-lg border border-slate-200 bg-white px-5 py-4">
                                <LessonBody body={lessonForm.body_draft} />
                            </div>
                        ) : (
                            <textarea
                                ref={bodyRef}
                                value={lessonForm.body_draft || ''}
                                onChange={(e) => setLessonForm((c) => ({ ...c, body_draft: e.target.value }))}
                                className="crm-input min-h-96 w-full font-mono text-sm leading-6"
                                placeholder={"# Section\nMarkdown body…\n\n:::script\nThe script line\n:::\n"}
                            />
                        )}

                        <textarea
                            value={lessonForm.quick_reference}
                            onChange={(e) => setLessonForm((c) => ({ ...c, quick_reference: e.target.value }))}
                            className="crm-input min-h-24 w-full"
                            placeholder="Quick reference panel content (shows in sticky right rail on lesson page)"
                        />

                        <div className="grid gap-3 sm:grid-cols-3">
                            <input type="number" value={lessonForm.duration_minutes} onChange={(e) => setLessonForm((c) => ({ ...c, duration_minutes: Number(e.target.value) }))} className="crm-input" placeholder="Duration min" />
                            <select value={lessonForm.status} onChange={(e) => setLessonForm((c) => ({ ...c, status: e.target.value }))} className="crm-input">
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
                                <input value={embedUrl} onChange={(e) => setEmbedUrl(e.target.value)} className="crm-input min-w-[280px]" placeholder="YouTube, Vimeo, or Loom URL" />
                                <button type="button" onClick={() => { const data = new FormData(); data.append('embed_url', embedUrl); uploadMedia.mutate(data); }} disabled={!embedUrl.trim()} className="crm-btn-secondary px-3 py-2 text-sm disabled:opacity-50">Add embed</button>
                            </div>
                        </div>
                    ) : null}
                </main>
            </section>
        </div>
    );
}
