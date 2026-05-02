import React, { useState } from 'react';

export default function MediaManagerDialog({ open, article, onClose, onUpload, onDelete }) {
    const [file, setFile] = useState(null);
    const [caption, setCaption] = useState('');

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4" onClick={onClose}>
            <div className="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Manage media</h3>
                </div>
                <div className="space-y-4 px-5 py-5">
                    <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)_auto]">
                        <input type="file" onChange={(event) => setFile(event.target.files?.[0] || null)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                        <input value={caption} onChange={(event) => setCaption(event.target.value)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Caption" />
                        <button type="button" onClick={() => file && onUpload?.(file, caption)} className="crm-btn-primary px-3 py-2 text-sm">Upload</button>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2">
                        {(article?.media || []).map((media) => (
                            <div key={media.id} className="rounded-2xl border border-slate-200 p-3">
                                {media.kind === 'video' ? (
                                    <video src={media.url} controls className="w-full rounded-xl border border-slate-200" />
                                ) : (
                                    <img src={media.url} alt={media.caption || ''} className="w-full rounded-xl border border-slate-200 object-cover" />
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
        </div>
    );
}
