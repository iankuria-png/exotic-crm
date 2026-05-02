import React from 'react';
import ReactMarkdown from 'react-markdown';

export default function MarkdownRenderer({ children, onMediaOpen }) {
    return (
        <div className="max-w-none text-[15px] leading-7 text-slate-700">
            <ReactMarkdown
                components={{
                    h1: ({ ...props }) => <h1 {...props} className="mb-5 text-3xl font-semibold tracking-tight text-slate-950" />,
                    h2: ({ ...props }) => <h2 {...props} className="mb-3 mt-8 text-xl font-semibold text-slate-900" />,
                    h3: ({ ...props }) => <h3 {...props} className="mb-2 mt-6 text-lg font-semibold text-slate-900" />,
                    h4: ({ ...props }) => <h4 {...props} className="mb-2 mt-5 text-base font-semibold text-slate-900" />,
                    p: ({ ...props }) => <p {...props} className="mb-4 text-[15px] leading-7 text-slate-700" />,
                    ul: ({ ...props }) => <ul {...props} className="mb-5 ml-5 list-disc space-y-2 marker:text-slate-400" />,
                    ol: ({ ...props }) => <ol {...props} className="mb-5 ml-5 list-decimal space-y-2 marker:font-semibold marker:text-slate-500" />,
                    li: ({ ...props }) => <li {...props} className="pl-1 text-[15px] leading-7 text-slate-700" />,
                    hr: ({ ...props }) => <hr {...props} className="my-8 border-slate-200" />,
                    blockquote: ({ ...props }) => <blockquote {...props} className="mb-5 rounded-r-2xl border-l-4 border-amber-300 bg-amber-50/70 px-4 py-3 text-slate-700" />,
                    strong: ({ ...props }) => <strong {...props} className="font-semibold text-slate-900" />,
                    a: ({ ...props }) => <a {...props} className="font-medium text-teal-700 underline decoration-teal-200 underline-offset-4" />,
                    img: ({ ...props }) => (
                        <button
                            type="button"
                            onClick={() => onMediaOpen?.([{ kind: 'image', url: props.src, caption: props.alt || '' }], 0)}
                            className="block w-full cursor-zoom-in text-left"
                        >
                            <img {...props} className="my-6 rounded-2xl border border-slate-200 shadow-sm transition hover:shadow-md" alt={props.alt || ''} />
                        </button>
                    ),
                    video: ({ ...props }) => (
                        <div className="my-6">
                            <video {...props} controls className="w-full rounded-2xl border border-slate-200 shadow-sm" />
                            <button
                                type="button"
                                onClick={() => onMediaOpen?.([{ kind: 'video', url: props.src, caption: props.title || props['aria-label'] || 'FAQ video' }], 0)}
                                className="mt-2 inline-flex items-center gap-2 text-sm font-medium text-teal-700 transition hover:text-teal-800"
                            >
                                Open larger preview
                            </button>
                        </div>
                    ),
                    code: ({ inline, className, children: codeChildren, ...props }) => inline
                        ? <code {...props} className={`rounded bg-slate-100 px-1.5 py-0.5 text-sm text-slate-900 ${className || ''}`}>{codeChildren}</code>
                        : <code {...props} className={`block overflow-x-auto rounded-2xl bg-slate-950 px-4 py-4 text-sm text-slate-100 ${className || ''}`}>{codeChildren}</code>,
                }}
            >
                {children || ''}
            </ReactMarkdown>
        </div>
    );
}
