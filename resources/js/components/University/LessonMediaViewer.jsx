import React from 'react';

function embedUrl(url) {
    const value = String(url || '');
    if (value.includes('youtube.com/watch')) {
        return value.replace('/watch?v=', '/embed/');
    }
    if (value.includes('youtu.be/')) {
        return value.replace('youtu.be/', 'www.youtube.com/embed/');
    }
    if (value.includes('vimeo.com/')) {
        return value.replace('vimeo.com/', 'player.vimeo.com/video/');
    }

    return value;
}

export default function LessonMediaViewer({ media = [] }) {
    if (!media.length) return null;

    return (
        <div className="mt-8 space-y-5">
            {media.map((item) => (
                <figure key={item.id} className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                    {item.kind === 'image' ? (
                        <img src={item.url} alt={item.caption || 'Lesson media'} className="max-h-[520px] w-full object-contain bg-slate-50" />
                    ) : null}
                    {item.kind === 'video' ? (
                        <video src={item.url} controls className="aspect-video w-full bg-black" />
                    ) : null}
                    {item.kind === 'embed' ? (
                        <iframe title={item.caption || 'Embedded lesson media'} src={embedUrl(item.embed_url || item.url)} className="aspect-video w-full" allowFullScreen />
                    ) : null}
                    {item.kind === 'pdf' ? (
                        <a href={item.url} className="block px-4 py-3 text-sm font-semibold text-teal-700">Open attached PDF</a>
                    ) : null}
                    {item.caption ? <figcaption className="border-t border-slate-100 px-4 py-3 text-sm text-slate-500">{item.caption}</figcaption> : null}
                </figure>
            ))}
        </div>
    );
}
