import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import { useToast } from '../../ToastProvider';

export default function McpKnowledgePanel({ canManage }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [stageError, setStageError] = useState(null);
    const [selectedVersionId, setSelectedVersionId] = useState(null);
    const [selectedDocumentId, setSelectedDocumentId] = useState(null);
    const [reviewConfirmed, setReviewConfirmed] = useState(false);
    const query = useQuery({ queryKey: ['mcp-knowledge'], queryFn: () => api.get('/crm/settings/mcp/knowledge').then((r) => r.data) });
    const review = useQuery({
        queryKey: ['mcp-knowledge-review', selectedVersionId],
        queryFn: () => api.get(`/crm/settings/mcp/knowledge/versions/${selectedVersionId}/review`).then((r) => r.data),
        enabled: Boolean(selectedVersionId),
    });
    const refresh = () => queryClient.invalidateQueries({ queryKey: ['mcp-knowledge'] });
    const closeReview = () => {
        setSelectedVersionId(null);
        setSelectedDocumentId(null);
        setReviewConfirmed(false);
    };
    const openReview = (versionId) => {
        setSelectedVersionId(versionId);
        setSelectedDocumentId(null);
        setReviewConfirmed(false);
    };
    const bootstrap = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/knowledge/bootstrap'),
        onSuccess: () => { toast.success('Ontology v1 is active. You can now stage approved documentation.'); refresh(); },
    });
    const stage = useMutation({
        mutationFn: () => api.post('/crm/settings/mcp/knowledge/stage', { idempotency_key: crypto.randomUUID() }),
        onSuccess: () => { setStageError(null); toast.success('Knowledge snapshot staged. Review it before publishing.'); refresh(); },
        onError: (error) => {
            const response = error.response?.data || {};
            setStageError({
                code: response.code || 'sync_failed',
                message: response.message || 'Knowledge staging could not complete. Check the latest run and try again.',
            });
            refresh();
        },
    });
    const promote = useMutation({
        mutationFn: (id) => api.post(`/crm/settings/mcp/knowledge/versions/${id}/promote`),
        onSuccess: () => {
            toast.success('Knowledge snapshot published to MCP.');
            closeReview();
            refresh();
        },
    });

    if (query.isLoading) return <div className="crm-surface p-6 text-sm text-slate-500">Loading knowledge provenance…</div>;

    const data = query.data || {};
    const active = data.active_release;
    const latestRun = data.runs?.[0];
    const snapshots = data.versions || [];
    const failure = stageError || (latestRun?.status === 'failed'
        ? { code: latestRun.error_code || 'sync_failed', message: 'The previous staging run did not complete.' }
        : null);

    return <section className="crm-surface overflow-hidden" data-testid="mcp-knowledge-panel">
        <div className="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Approved knowledge</p>
                <h3 className="mt-1 text-lg font-semibold text-slate-950">Versioned reference context</h3>
                <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Remote documentation is staged first. Only an immutable snapshot you have reviewed reaches MCP.</p>
            </div>
            {canManage && !active ? <button className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" disabled={bootstrap.isPending} onClick={() => bootstrap.mutate()}>{bootstrap.isPending ? 'Activating…' : 'Activate ontology v1'}</button> : null}
            {canManage && active ? <button className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50" disabled={stage.isPending} onClick={() => stage.mutate()}>{stage.isPending ? 'Staging…' : 'Stage approved docs'}</button> : null}
        </div>
        <div className="grid gap-px bg-slate-200 sm:grid-cols-3">
            <Fact label="Active release" value={active?.ontology_version || 'Not activated'} />
            <Fact label="Approved snapshots" value={String(snapshots.filter((version) => version.status === 'ready').length)} />
            <Fact label="Latest run" value={latestRun?.status || 'None'} />
        </div>
        {failure ? <FailureNotice error={failure} canRetry={canManage && Boolean(active)} isRetrying={stage.isPending} onRetry={() => stage.mutate()} /> : null}
        <div className="divide-y divide-slate-100">
            {snapshots.map((version) => <div className="flex flex-wrap items-center justify-between gap-3 p-4" key={version.id}>
                <div>
                    <p className="font-medium text-slate-800">{version.version}</p>
                    <p className="mt-1 text-xs text-slate-500">{version.status} · {shortHash(version.content_sha256)}</p>
                </div>
                {canManage && version.status === 'ready' ? <button type="button" className="text-sm font-semibold text-teal-700 hover:text-teal-900" aria-expanded={selectedVersionId === version.id} onClick={() => openReview(version.id)}>Review snapshot</button> : null}
            </div>)}
            {!snapshots.length ? <div className="p-6 text-sm text-slate-500">{active ? 'No staged reference snapshot. Stage the approved documentation to create a reviewable baseline.' : 'Activate ontology v1 to establish the immutable contract before staging documentation.'}</div> : null}
        </div>
        {selectedVersionId ? <SnapshotReview
            review={review}
            selectedDocumentId={selectedDocumentId}
            onSelectDocument={(documentId) => { setSelectedDocumentId(documentId); setReviewConfirmed(false); }}
            reviewConfirmed={reviewConfirmed}
            onReviewConfirmed={setReviewConfirmed}
            onClose={closeReview}
            onPromote={() => promote.mutate(selectedVersionId)}
            isPublishing={promote.isPending}
        /> : null}
    </section>;
}

function SnapshotReview({ review, selectedDocumentId, onSelectDocument, reviewConfirmed, onReviewConfirmed, onClose, onPromote, isPublishing }) {
    if (review.isLoading) return <div className="border-t border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Loading the staged documents for review…</div>;

    if (review.isError) return <div className="border-t border-slate-200 bg-rose-50 p-5" role="alert">
        <p className="font-semibold text-rose-950">The staged snapshot could not be opened</p>
        <p className="mt-1 text-sm leading-6 text-rose-800">Nothing has been published. Try loading the snapshot again before you publish it.</p>
        <div className="mt-3 flex gap-3"><button type="button" className="rounded-md border border-rose-200 bg-white px-3 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100" onClick={() => review.refetch()}>Try again</button><button type="button" className="text-sm font-semibold text-slate-600 hover:text-slate-950" onClick={onClose}>Close review</button></div>
    </div>;

    const snapshot = review.data?.version;
    const documents = review.data?.documents || [];
    const selectedDocument = documents.find((document) => document.id === selectedDocumentId) || documents[0];

    return <div className="border-t border-slate-200 bg-slate-50">
        <div className="flex flex-col gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Snapshot review</p>
                <h4 className="mt-1 text-base font-semibold text-slate-950">Review staged documents before publishing</h4>
                <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Not live yet. Inspect the approved documents below, then publish this exact immutable snapshot to MCP.</p>
            </div>
            <button type="button" className="self-start text-sm font-semibold text-slate-600 hover:text-slate-950" onClick={onClose}>Close review</button>
        </div>
        <div className="grid gap-px bg-slate-200 sm:grid-cols-3">
            <Fact label="Documents" value={String(documents.length)} />
            <Fact label="Ontology" value={snapshot?.ontology_version || '—'} />
            <Fact label="Snapshot hash" value={shortHash(snapshot?.content_sha256)} />
        </div>
        {!documents.length ? <div className="p-5 text-sm text-slate-600">This snapshot has no reviewable documents and cannot be published.</div> : <div className="grid lg:grid-cols-[17rem_minmax(0,1fr)]">
            <nav className="border-b border-slate-200 bg-white p-3 lg:border-b-0 lg:border-r" aria-label="Staged documents">
                <p className="px-2 pb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Approved documents</p>
                <div className="space-y-1">
                    {documents.map((document) => <button type="button" key={document.id} className={`w-full rounded-md px-3 py-2 text-left transition ${selectedDocument?.id === document.id ? 'bg-teal-50 text-teal-950 ring-1 ring-inset ring-teal-200' : 'text-slate-700 hover:bg-slate-100'}`} onClick={() => onSelectDocument(document.id)} aria-current={selectedDocument?.id === document.id ? 'page' : undefined}>
                        <span className="block text-sm font-semibold">{document.title}</span>
                        <span className="mt-0.5 block truncate text-xs text-slate-500">{document.canonical_uri}</span>
                    </button>)}
                </div>
            </nav>
            {selectedDocument ? <article className="min-w-0 p-5">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h5 className="text-base font-semibold text-slate-950">{selectedDocument.title}</h5>
                        <p className="mt-1 text-sm text-slate-500">{selectedDocument.canonical_uri} · {selectedDocument.chunk_count} {selectedDocument.chunk_count === 1 ? 'section' : 'sections'}</p>
                    </div>
                    <a className="text-sm font-semibold text-teal-700 hover:text-teal-900" href={selectedDocument.source_url} target="_blank" rel="noreferrer">Open source ↗</a>
                </div>
                {selectedDocument.summary ? <p className="mt-4 max-w-3xl text-sm leading-6 text-slate-600">{selectedDocument.summary}</p> : null}
                <div className="mt-4 flex flex-wrap gap-2"><TagGroup label="Audience" values={selectedDocument.audiences} /><TagGroup label="Lifecycle" values={selectedDocument.lifecycle_stages} /><TagGroup label="Department" values={selectedDocument.departments} /></div>
                <div className="mt-5 overflow-hidden rounded-md border border-slate-800 bg-slate-950">
                    <p className="border-b border-slate-800 px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Staged content</p>
                    <pre className="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words p-4 text-xs leading-6 text-slate-100">{selectedDocument.body}</pre>
                </div>
                <p className="mt-3 text-xs text-slate-500">Document hash: {selectedDocument.content_sha256}</p>
            </article> : null}
        </div>}
        {documents.length ? <div className="flex flex-col gap-4 border-t border-slate-200 bg-white p-5 sm:flex-row sm:items-center sm:justify-between">
            <label className="flex max-w-2xl items-start gap-3 text-sm leading-6 text-slate-700"><input type="checkbox" className="mt-1 h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-600" checked={reviewConfirmed} onChange={(event) => onReviewConfirmed(event.target.checked)} /><span>I reviewed these documents and want to publish this snapshot to MCP.</span></label>
            <button type="button" className="rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={!reviewConfirmed || isPublishing} onClick={onPromote}>{isPublishing ? 'Publishing…' : 'Publish snapshot'}</button>
        </div> : null}
    </div>;
}

function TagGroup({ label, values }) {
    if (!values?.length) return null;

    return <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-500">{label}</span>{values.join(', ')}</span>;
}

function FailureNotice({ error, canRetry, isRetrying, onRetry }) {
    return <div className="border-b border-rose-100 bg-rose-50 px-5 py-4 text-sm text-rose-950" role="alert">
        <p className="font-semibold">Knowledge staging did not finish</p>
        <p className="mt-1 leading-6">{error.message}</p>
        <div className="mt-3 flex flex-wrap items-center gap-3">
            {canRetry ? <button type="button" className="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-60" disabled={isRetrying} onClick={onRetry}>{isRetrying ? 'Retrying staging…' : 'Retry staging'}</button> : null}
            <p className="text-xs leading-5 text-rose-800">{canRetry ? 'This starts a fresh staging run. The failed run remains in the audit trail.' : 'Activate the ontology before trying again.'}</p>
        </div>
        <p className="mt-3 text-xs font-medium uppercase tracking-[0.1em] text-rose-700">Run code: {error.code}</p>
    </div>;
}

function Fact({ label, value }) {
    return <div className="bg-white p-4"><p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p><p className="mt-1 text-sm font-semibold text-slate-800">{value}</p></div>;
}

function shortHash(hash) {
    return hash ? `${hash.slice(0, 12)}…` : '—';
}
