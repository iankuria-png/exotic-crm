import React, { useEffect, useMemo, useState } from 'react';
import FaqMediaLightbox from './FaqMediaLightbox';

export default function MediaManagerDialog({ open, article, onClose, onUpload, onDelete }) {
    const [file, setFile] = useState(null);
    const [caption, setCaption] = useState('');
    const [lightboxIndex, setLightboxIndex] = useState(0);
    const [lightboxItems, setLightboxItems] = useState([]);
    const [lightboxOpen, setLightboxOpen] = useState(false);

    const previewUrl = useMemo(() => {
        if (!file) {
            return null;
        }

        return URL.createObjectURL(file);
    }, [file]);

    useEffect(() => {
        if (!previewUrl) {
            return undefined;
        }

        return () => URL.revokeObjectURL(previewUrl);
    }, [previewUrl]);

    if (!open) {
        return null;
    }

    const articleMedia = article?.media || [];
    const fileKind = file?.type?.startsWith('video/') ? 'video' : 'image';

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4" onClick={onClose}>
            <div className="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Manage media</h3>
                </div>
                <div className="space-y-4 px-5 py-5">
                    <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto]">
                        <input accept="image/*,video/*" type="file" onChange={(event) => setFile(event.target.files?.[0] || null)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        <input value={caption} onChange={(event) => setCaption(event.target.value)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Caption" />
                        <button
                            type="button"
                            onClick={() => {
                                if (!file) {
                                    return;
                                }

                                onUpload?.(file, caption);
                                setFile(null);
                                setCaption('');
                            }}
                            className="crm-btn-primary px-3 py-2 text-sm"
                        >
                            Upload
                        </button>
                    </div>
                    {file && previewUrl ? (
                        <div className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">Upload preview</p>
                                    <p className="text-sm text-slate-500">{file.name}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setLightboxItems([{ kind: fileKind, url: previewUrl, caption: caption || file.name, mime: file.type }]);
                                        setLightboxIndex(0);
                                        setLightboxOpen(true);
                                    }}
                                    className="crm-btn-secondary px-3 py-2 text-sm"
                                >
                                    Open large preview
                                </button>
                            </div>
                            <div className="mt-4">
                                {fileKind === 'video' ? (
                                    <video src={previewUrl} controls className="max-h-72 w-full rounded-2xl border border-slate-200 bg-black object-contain" />
                                ) : (
                                    <img src={previewUrl} alt={caption || file.name} className="max-h-72 w-full rounded-2xl border border-slate-200 object-contain bg-white" />
                                )}
                            </div>
                        </div>
                    ) : null}
                    <div className="grid gap-3 md:grid-cols-2">
                        {articleMedia.map((media, mediaIndex) => (
                            <div key={media.id} className="rounded-2xl border border-slate-200 p-3">
                                {media.kind === 'video' ? (
                                    <div>
                                        <video src={media.url} controls className="w-full rounded-xl border border-slate-200 bg-black" />
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setLightboxItems(articleMedia);
                                                setLightboxIndex(mediaIndex);
                                                setLightboxOpen(true);
                                            }}
                                            className="mt-2 inline-flex items-center gap-2 text-sm font-medium text-teal-700 transition hover:text-teal-800"
                                        >
                                            Open large preview
                                        </button>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setLightboxItems(articleMedia);
                                            setLightboxIndex(mediaIndex);
                                            setLightboxOpen(true);
                                        }}
                                        className="block w-full cursor-zoom-in text-left"
                                    >
                                        <img src={media.url} alt={media.caption || ''} className="w-full rounded-xl border border-slate-200 object-cover transition hover:shadow-md" />
                                    </button>
                                )}
                                <div className="mt-3 flex items-center justify-between gap-3">
                                    <p className="text-sm text-slate-600">{media.caption || media.mime}</p>
                                    <button type="button" onClick={() => onDelete?.(media.id)} className="crm-btn-danger px-3 py-2 text-sm">Delete</button>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="flex justify-end border-t border-slate-100 px-5 py-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-sm">Close</button>
                </div>
            </div>
            <FaqMediaLightbox
                open={lightboxOpen}
                items={lightboxItems}
                index={lightboxIndex}
                onChangeIndex={setLightboxIndex}
                onClose={() => setLightboxOpen(false)}
            />
        </div>
    );
}
