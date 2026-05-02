import React, { useEffect, useMemo, useState } from 'react';

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

export default function FaqMediaLightbox({ open, items = [], index = 0, onClose, onChangeIndex }) {
    const safeItems = useMemo(() => items.filter((item) => item?.url), [items]);
    const currentIndex = clamp(index, 0, Math.max(safeItems.length - 1, 0));
    const currentItem = safeItems[currentIndex] || null;
    const [zoom, setZoom] = useState(1);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        setZoom(1);

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }

            if (event.key === 'ArrowRight' && safeItems.length > 1) {
                onChangeIndex?.((currentIndex + 1) % safeItems.length);
            }

            if (event.key === 'ArrowLeft' && safeItems.length > 1) {
                onChangeIndex?.((currentIndex - 1 + safeItems.length) % safeItems.length);
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [currentIndex, onChangeIndex, onClose, open, safeItems.length]);

    useEffect(() => {
        setZoom(1);
    }, [currentIndex]);

    if (!open || !currentItem) {
        return null;
    }

    const isVideo = currentItem.kind === 'video';

    return (
        <div className="fixed inset-0 z-[140] bg-slate-950/88" onClick={onClose}>
            <div className="flex h-full flex-col" onClick={(event) => event.stopPropagation()}>
                <div className="flex items-center justify-between gap-4 border-b border-white/10 px-5 py-4 text-white">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-300">
                            {safeItems.length > 1 ? `Media ${currentIndex + 1} of ${safeItems.length}` : 'Media preview'}
                        </p>
                        <p className="mt-1 text-sm text-slate-200">{currentItem.caption || currentItem.mime || 'FAQ media'}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {!isVideo ? (
                            <>
                                <button type="button" onClick={() => setZoom((current) => clamp(current - 0.2, 0.6, 3))} className="rounded-xl border border-white/15 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                                    Zoom out
                                </button>
                                <button type="button" onClick={() => setZoom(1)} className="rounded-xl border border-white/15 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                                    Reset
                                </button>
                                <button type="button" onClick={() => setZoom((current) => clamp(current + 0.2, 0.6, 3))} className="rounded-xl border border-white/15 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                                    Zoom in
                                </button>
                            </>
                        ) : null}
                        <button type="button" onClick={onClose} className="rounded-xl border border-white/15 px-3 py-2 text-sm font-medium text-white transition hover:bg-white/10">
                            Close
                        </button>
                    </div>
                </div>

                <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden px-5 py-6">
                    {safeItems.length > 1 ? (
                        <>
                            <button
                                type="button"
                                onClick={() => onChangeIndex?.((currentIndex - 1 + safeItems.length) % safeItems.length)}
                                className="absolute left-5 top-1/2 z-10 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-slate-950/70 text-white transition hover:bg-slate-900"
                                aria-label="Previous media"
                            >
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m15 19-7-7 7-7" />
                                </svg>
                            </button>
                            <button
                                type="button"
                                onClick={() => onChangeIndex?.((currentIndex + 1) % safeItems.length)}
                                className="absolute right-5 top-1/2 z-10 inline-flex h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-slate-950/70 text-white transition hover:bg-slate-900"
                                aria-label="Next media"
                            >
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="m9 5 7 7-7 7" />
                                </svg>
                            </button>
                        </>
                    ) : null}

                    {isVideo ? (
                        <video src={currentItem.url} controls autoPlay className="max-h-full max-w-[min(1100px,100%)] rounded-2xl border border-white/10 bg-black shadow-2xl" />
                    ) : (
                        <div className="overflow-auto">
                            <img
                                src={currentItem.url}
                                alt={currentItem.caption || ''}
                                className="max-h-[calc(100vh-12rem)] max-w-[min(1100px,100%)] rounded-2xl border border-white/10 shadow-2xl transition-transform duration-150"
                                style={{ transform: `scale(${zoom})`, transformOrigin: 'center center' }}
                            />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
