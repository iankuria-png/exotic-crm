import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { Spinner, errorMessage, timeAgo } from './scraperUi';

/**
 * Human-in-the-loop override: the person opens the site in their own browser, passes
 * any check themselves, and the bookmarklet sends the rendered page to the CRM.
 * Upload/paste is the fallback for browsers that block bookmarklets.
 */
export default function CapturePanel({ site, open, onClose, onReceived, toast }) {
    const [tab, setTab] = useState('bookmarklet');
    const [pasteHtml, setPasteHtml] = useState('');
    const [pasteUrl, setPasteUrl] = useState('');
    const [captureId, setCaptureId] = useState(null);
    const bookmarkRef = useRef(null);

    const create = useMutation({
        mutationFn: () => api.post(`/crm/scraper/sites/${site.id}/captures`).then((r) => r.data),
        onSuccess: (data) => setCaptureId(data.capture.id),
        onError: (e) => toast.error(errorMessage(e, 'Could not start a capture.')),
    });

    useEffect(() => {
        if (open && site) {
            setCaptureId(null);
            setPasteHtml('');
            setPasteUrl(site.source_url);
            create.mutate();
        }
    }, [open, site?.id]); // eslint-disable-line react-hooks/exhaustive-deps

    // React strips javascript: hrefs, so set the bookmarklet link imperatively.
    useEffect(() => {
        if (bookmarkRef.current && create.data?.bookmarklet) {
            bookmarkRef.current.setAttribute('href', create.data.bookmarklet);
        }
    }, [create.data, tab]);

    const status = useQuery({
        queryKey: ['scraper-capture', captureId],
        enabled: open && !!captureId,
        queryFn: () => api.get(`/crm/scraper/captures/${captureId}`).then((r) => r.data.capture),
        refetchInterval: (q) => (q.state.data?.status === 'received' ? false : 2500),
    });

    const received = status.data?.status === 'received';
    useEffect(() => {
        if (received) {
            toast.success('Page received from your browser.');
            onReceived?.(status.data);
        }
    }, [received]); // eslint-disable-line react-hooks/exhaustive-deps

    const upload = useMutation({
        mutationFn: (payload) => {
            if (payload instanceof FormData) {
                return api.post(`/crm/scraper/sites/${site.id}/captures/upload`, payload, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data.capture);
            }
            return api.post(`/crm/scraper/sites/${site.id}/captures/upload`, payload).then((r) => r.data.capture);
        },
        onSuccess: (capture) => { toast.success('Page saved.'); onReceived?.(capture); },
        onError: (e) => toast.error(errorMessage(e, 'Could not use that page.')),
    });

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape' && open) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose]);

    if (!open || !site) return null;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(create.data?.bookmarklet || '');
            toast.success('Bookmarklet copied. Paste it as the address of a new bookmark.');
        } catch {
            toast.error('Copy failed: drag the button to your bookmarks bar instead.');
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onMouseDown={onClose}>
            <div role="dialog" aria-modal="true" aria-labelledby="capture-title" className="w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-2xl" onMouseDown={(e) => e.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 id="capture-title" className="crm-panel-title">Capture from your browser</h3>
                        <p className="crm-panel-subtitle">For pages that block automated visits. You open the page as yourself; the CRM only reads what you send.</p>
                    </div>
                    <button type="button" className="crm-btn-secondary px-3 py-1.5" onClick={onClose}>Close</button>
                </header>

                <div className="flex gap-1 border-b border-slate-100 px-4 pt-2" role="tablist">
                    {[['bookmarklet', 'One-click capture'], ['upload', 'Upload or paste']].map(([key, label]) => (
                        <button key={key} role="tab" aria-selected={tab === key} type="button" onClick={() => setTab(key)}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm font-semibold ${tab === key ? 'border-teal-700 text-teal-800' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'bookmarklet' ? (
                    <ol className="space-y-4 px-5 py-4 text-sm text-slate-700">
                        <li className="flex gap-3">
                            <Step n={1} />
                            <div className="min-w-0 flex-1">
                                <p className="font-medium text-slate-900">Add the capture button to your bookmarks bar</p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {create.isPending ? <span className="flex items-center gap-2 text-slate-500"><Spinner />Preparing…</span> : (
                                        <>
                                            <a ref={bookmarkRef} href="#capture" onClick={(e) => e.preventDefault()} draggable
                                                className="inline-flex cursor-grab items-center gap-1.5 rounded-lg border-2 border-dashed border-teal-600 bg-teal-50 px-3 py-1.5 text-sm font-semibold text-teal-800">
                                                ⤓ Send to CRM · {site.host}
                                            </a>
                                            <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={copy}>Copy instead</button>
                                        </>
                                    )}
                                </div>
                                <p className="mt-1 text-xs text-slate-500">Drag it to the bookmarks bar. It works once and expires in 30 minutes.</p>
                            </div>
                        </li>
                        <li className="flex gap-3">
                            <Step n={2} />
                            <div>
                                <p className="font-medium text-slate-900">Open the listing page and pass any check</p>
                                <a className="mt-1 inline-block text-teal-700 hover:underline" href={site.source_url} target="_blank" rel="noreferrer noopener">Open {site.host} ↗</a>
                                <p className="mt-1 text-xs text-slate-500">Scroll until the profiles you want are visible.</p>
                            </div>
                        </li>
                        <li className="flex gap-3">
                            <Step n={3} />
                            <div>
                                <p className="font-medium text-slate-900">Click “Send to CRM” on that page</p>
                                <p className="mt-1 flex items-center gap-2 text-xs" aria-live="polite">
                                    {received ? (
                                        <span className="font-semibold text-emerald-700">✓ Received {timeAgo(status.data.received_at)} · {(status.data.bytes / 1024).toFixed(0)} KB</span>
                                    ) : captureId ? (
                                        <span className="flex items-center gap-2 text-slate-500"><Spinner className="h-3 w-3" />Waiting for your page…</span>
                                    ) : null}
                                </p>
                            </div>
                        </li>
                    </ol>
                ) : (
                    <div className="space-y-3 px-5 py-4">
                        <label className="block">
                            <span className="text-sm font-medium text-slate-700">Saved page (.html)</span>
                            <input type="file" accept=".html,.htm,text/html" className="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold"
                                onChange={(e) => {
                                    const file = e.target.files?.[0];
                                    if (!file) return;
                                    const fd = new FormData();
                                    fd.append('file', file);
                                    if (pasteUrl) fd.append('url', pasteUrl);
                                    upload.mutate(fd);
                                }} />
                        </label>
                        <div className="relative text-center text-xs text-slate-400"><span className="bg-white px-2">or paste the page source</span></div>
                        <label className="block">
                            <span className="text-sm font-medium text-slate-700">Page address</span>
                            <input className="crm-input mt-1" value={pasteUrl} onChange={(e) => setPasteUrl(e.target.value)} />
                        </label>
                        <textarea className="crm-input crm-mono h-32 text-xs" placeholder="<html>…" value={pasteHtml} onChange={(e) => setPasteHtml(e.target.value)} />
                        <div className="flex justify-end">
                            <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!pasteHtml.trim() || upload.isPending} onClick={() => upload.mutate({ html: pasteHtml, url: pasteUrl || undefined })}>
                                {upload.isPending ? 'Saving…' : 'Use this page'}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function Step({ n }) {
    return <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-teal-700 text-xs font-semibold text-white">{n}</span>;
}
