import React, { useEffect, useMemo, useRef, useState } from 'react';
import kyc from '../../services/kyc';

function isPdf(document) {
    return String(document?.mime || '').includes('pdf');
}

function ToolbarButton({ label, onClick, disabled = false, children }) {
    return (
        <button
            type="button"
            disabled={disabled}
            onClick={onClick}
            className="inline-flex items-center gap-1 rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {children}
            <span>{label}</span>
        </button>
    );
}

export default function KycDocumentViewer({ open, documents = [], initialIndex = 0, onClose }) {
    const [activeIndex, setActiveIndex] = useState(initialIndex);
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const [compareMode, setCompareMode] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [blobUrls, setBlobUrls] = useState({});
    const blobUrlsRef = useRef({});

    const activeDocument = documents[activeIndex] || null;
    const compareDocument = compareMode && documents.length > 1 ? documents[(activeIndex + 1) % documents.length] : null;

    useEffect(() => {
        if (!open) return undefined;

        setActiveIndex(initialIndex);
        setZoom(1);
        setRotation(0);
        setCompareMode(false);

        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose?.();
            if (event.key === 'ArrowRight' && documents.length > 1) setActiveIndex((current) => (current + 1) % documents.length);
            if (event.key === 'ArrowLeft' && documents.length > 1) setActiveIndex((current) => (current - 1 + documents.length) % documents.length);
            if (event.key === '+' || event.key === '=') setZoom((current) => Math.min(3, current + 0.15));
            if (event.key === '-') setZoom((current) => Math.max(0.5, current - 0.15));
            if (event.key.toLowerCase() === 'r') setRotation((current) => (current + 90) % 360);
            if (event.key.toLowerCase() === 'c' && documents.length > 1) setCompareMode((current) => !current);
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, onClose, documents.length, initialIndex]);

    useEffect(() => {
        blobUrlsRef.current = blobUrls;
    }, [blobUrls]);

    useEffect(() => {
        if (!open || !activeDocument) return undefined;

        let cancelled = false;
        const urlToFetch = [activeDocument, compareDocument].filter(Boolean);

        const fetchDocuments = async () => {
            setLoading(true);
            setError('');

            try {
                const nextEntries = {};
                await Promise.all(urlToFetch.map(async (document) => {
                    if (blobUrls[document.id]) {
                        nextEntries[document.id] = blobUrls[document.id];
                        return;
                    }

                    const absoluteUrl = document.view_url?.startsWith('http')
                        ? document.view_url
                        : new URL(document.view_url, window.location.origin).toString();
                    const blob = await kyc.fetchDocumentBlob(absoluteUrl);
                    nextEntries[document.id] = window.URL.createObjectURL(blob);
                }));

                if (!cancelled) {
                    setBlobUrls((current) => ({ ...current, ...nextEntries }));
                }
            } catch (fetchError) {
                if (!cancelled) {
                    setError(fetchError?.response?.data?.message || fetchError?.message || 'Unable to load this document.');
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        };

        fetchDocuments();

        return () => {
            cancelled = true;
        };
    }, [open, activeDocument, compareDocument, blobUrls]);

    useEffect(() => () => {
        Object.values(blobUrlsRef.current).forEach((url) => window.URL.revokeObjectURL(url));
    }, []);

    const panels = useMemo(() => [activeDocument, compareDocument].filter(Boolean), [activeDocument, compareDocument]);

    if (!open || !activeDocument) return null;

    return (
        <div className="fixed inset-0 z-50 bg-slate-950 text-white">
            <div className="flex h-full flex-col">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                    <div>
                        <p className="text-sm font-semibold text-white">Document viewer</p>
                        <p className="text-xs text-slate-400">
                            {activeDocument.kind.replaceAll('_', ' ')} • {activeIndex + 1} of {documents.length}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ToolbarButton label="Compare" onClick={() => setCompareMode((current) => !current)} disabled={documents.length < 2}>C</ToolbarButton>
                        <ToolbarButton label="Rotate" onClick={() => setRotation((current) => (current + 90) % 360)}>R</ToolbarButton>
                        <ToolbarButton label="Zoom out" onClick={() => setZoom((current) => Math.max(0.5, current - 0.15))}>−</ToolbarButton>
                        <ToolbarButton label="Zoom in" onClick={() => setZoom((current) => Math.min(3, current + 0.15))}>+</ToolbarButton>
                        <button
                            type="button"
                            onClick={onClose}
                            className="inline-flex items-center rounded-lg border border-white/15 bg-white/10 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/15"
                        >
                            Close
                        </button>
                    </div>
                </div>

                <div className="flex flex-1 flex-col overflow-hidden lg:flex-row">
                    <aside className="w-full overflow-x-auto border-b border-white/10 px-4 py-3 lg:w-72 lg:flex-none lg:border-b-0 lg:border-r">
                        <div className="flex gap-2 lg:flex-col">
                            {documents.map((document, index) => {
                                const active = index === activeIndex;
                                return (
                                    <button
                                        key={document.id}
                                        type="button"
                                        onClick={() => setActiveIndex(index)}
                                        className={`min-w-[180px] rounded-xl border px-3 py-3 text-left transition lg:min-w-0 ${
                                            active
                                                ? 'border-teal-300/70 bg-teal-500/20 text-white'
                                                : 'border-white/10 bg-white/5 text-slate-300 hover:bg-white/10'
                                        }`}
                                    >
                                        <p className="text-sm font-semibold">{document.kind.replaceAll('_', ' ')}</p>
                                        <p className="mt-1 text-xs text-slate-400">{document.mime || 'Unknown type'}</p>
                                    </button>
                                );
                            })}
                        </div>
                    </aside>

                    <div className="flex-1 overflow-auto p-4">
                        {loading ? <p className="text-sm text-slate-400">Loading document…</p> : null}
                        {error ? <p className="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">{error}</p> : null}
                        <div className={`grid h-full gap-4 ${compareMode && panels.length > 1 ? 'lg:grid-cols-2' : ''}`}>
                            {panels.map((document) => {
                                const src = blobUrls[document.id];
                                return (
                                    <div key={document.id} className="flex min-h-[60vh] items-center justify-center overflow-auto rounded-2xl border border-white/10 bg-black/40 p-4">
                                        {!src ? (
                                            <div className="text-sm text-slate-500">Preparing preview…</div>
                                        ) : isPdf(document) ? (
                                            <iframe
                                                src={src}
                                                title={`KYC document ${document.id}`}
                                                className="h-[78vh] w-full rounded-lg bg-white"
                                            />
                                        ) : (
                                            <img
                                                src={src}
                                                alt={document.kind}
                                                className="max-h-[78vh] w-auto max-w-full rounded-lg object-contain transition"
                                                style={{ transform: `scale(${zoom}) rotate(${rotation}deg)` }}
                                            />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
