import React from 'react';
import ReactMarkdown from 'react-markdown';

export default function MarkdownRenderer({ children }) {
    return (
        <div className="prose prose-slate max-w-none prose-headings:text-slate-900 prose-p:text-slate-700 prose-li:text-slate-700">
            <ReactMarkdown
                components={{
                    a: ({ ...props }) => <a {...props} className="text-teal-700 underline decoration-teal-200 underline-offset-4" />,
                    img: ({ ...props }) => <img {...props} className="rounded-2xl border border-slate-200 shadow-sm" alt={props.alt || ''} />,
                    video: ({ ...props }) => <video {...props} controls className="w-full rounded-2xl border border-slate-200 shadow-sm" />,
                    code: ({ inline, className, children: codeChildren, ...props }) => inline
                        ? <code {...props} className={`rounded bg-slate-100 px-1.5 py-0.5 text-sm ${className || ''}`}>{codeChildren}</code>
                        : <code {...props} className={className}>{codeChildren}</code>,
                }}
            >
                {children || ''}
            </ReactMarkdown>
        </div>
    );
}
