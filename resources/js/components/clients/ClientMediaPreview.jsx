import React, { useEffect, useRef, useState } from 'react';

// The proxy shares a small PHP worker pool with the rest of the CRM. Keep
// gallery loading bounded, including the actual download, not just a HEAD probe.
const previewQueue = [];
let activePreviews = 0;
const RETRY_DELAYS = [1500, 4000];

function queuePreview(start) {
    const entry = { start, cancelled: false, release: null };
    previewQueue.push(entry);

    function drain() {
        while (activePreviews < 2 && previewQueue.length > 0) {
            const next = previewQueue.shift();
            if (next.cancelled) continue;

            activePreviews += 1;
            let released = false;
            next.release = () => {
                if (released) return;
                released = true;
                activePreviews -= 1;
                drain();
            };
            next.start(next.release);
        }
    }

    drain();
    return () => {
        entry.cancelled = true;
        const index = previewQueue.indexOf(entry);
        if (index !== -1) previewQueue.splice(index, 1);
        entry.release?.();
    };
}

function retryUrl(src, attempt) {
    // Bust only the CRM proxy's browser cache; never change the market URL or
    // its routing parameters. A failed/empty cached response must be retried too.
    if (attempt === 0 || !src.startsWith('/api/crm/image-proxy?')) return src;
    return `${src}&preview_attempt=${Date.now()}-${attempt}`;
}

export default function ClientMediaPreview({ src, kind, label }) {
    const [attempt, setAttempt] = useState(0);
    const [state, setState] = useState(src ? 'loading' : 'unavailable');
    const [assetUrl, setAssetUrl] = useState('');
    const settleRef = useRef(null);
    const assetRef = useRef(null);

    useEffect(() => {
        if (!src) return undefined;

        let cancelled = false;
        let failed = false;
        let deadline;
        let retryTimer;
        setAssetUrl('');
        setState(attempt > 0 ? 'retrying' : 'loading');

        const stopAsset = () => {
            const asset = assetRef.current;
            if (!asset) return;
            asset.removeAttribute('src');
            if (kind === 'video') asset.load();
        };

        const cancelQueue = queuePreview((release) => {
            const settle = (success) => {
                if (cancelled || failed) return;
                clearTimeout(deadline);

                if (success) {
                    release();
                    setState('ready');
                    return;
                }

                failed = true;
                stopAsset();
                release();
                setAssetUrl('');
                if (attempt < RETRY_DELAYS.length) {
                    setState('retrying');
                    retryTimer = setTimeout(() => setAttempt(attempt + 1), RETRY_DELAYS[attempt]);
                } else {
                    setState('unavailable');
                }
            };

            settleRef.current = settle;
            // A stalled image or video must not hold up every subsequent card.
            deadline = setTimeout(() => settle(false), 20000);
            setAssetUrl(retryUrl(src, attempt));
        });

        return () => {
            cancelled = true;
            clearTimeout(deadline);
            clearTimeout(retryTimer);
            settleRef.current = null;
            stopAsset();
            cancelQueue();
        };
    }, [src, kind, attempt]);

    const ready = state === 'ready';
    const unavailable = state === 'unavailable';

    return (
        <div className="relative h-full" aria-busy={!ready && !unavailable}>
            {assetUrl ? (kind === 'video' ? (
                <video
                    key={assetUrl}
                    ref={assetRef}
                    src={assetUrl}
                    aria-label={label}
                    controls
                    playsInline
                    preload="metadata"
                    className={`h-full w-full bg-slate-950 object-contain ${ready ? '' : 'invisible'}`}
                    onLoadedMetadata={() => settleRef.current?.(true)}
                    onError={() => settleRef.current?.(false)}
                />
            ) : (
                <img
                    key={assetUrl}
                    ref={assetRef}
                    src={assetUrl}
                    alt={label}
                    className={`h-full w-full object-cover ${ready ? '' : 'invisible'}`}
                    onLoad={() => settleRef.current?.(true)}
                    onError={() => settleRef.current?.(false)}
                />
            )) : null}
            {!ready ? (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-slate-100 px-4 text-center">
                    {unavailable ? (
                        <>
                            <span role="status" className="text-sm font-medium text-slate-700">Preview unavailable</span>
                            <span className="text-xs text-slate-500">
                                {src ? 'We couldn’t load this preview after retrying. You can try again.' : 'No preview link is available for this file.'}
                            </span>
                            {src ? (
                                <button
                                    type="button"
                                    onClick={() => setAttempt(0)}
                                    aria-label={`Retry preview for ${label}`}
                                    className="mt-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-teal-400 hover:text-teal-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
                                >
                                    Retry preview
                                </button>
                            ) : null}
                        </>
                    ) : (
                        <span role="status" className="text-sm text-slate-500">
                            {state === 'retrying' ? 'Retrying preview…' : 'Loading preview…'}
                        </span>
                    )}
                </div>
            ) : null}
        </div>
    );
}
