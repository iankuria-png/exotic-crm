import React, { useMemo } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * Renders a lesson body authored as markdown with custom callout directives.
 *
 * Callout syntax:
 *   :::script Optional title
 *   content...
 *   :::
 *
 * Supported kinds: script | scenario | objection | tip | warning | checklist | quickref
 *
 * The reader splits the body on `:::kind ... :::` blocks and renders each
 * segment as either a markdown block or a styled callout panel. Heading ids
 * are auto-assigned (h2/h3 → kebab-case) so the TOC can deep-link.
 */

const OWNER_ACCENT = {
    'Customer Service': { bg: 'bg-teal-100', text: 'text-teal-800', ring: 'ring-teal-200' },
    'Head of Markets': { bg: 'bg-indigo-100', text: 'text-indigo-800', ring: 'ring-indigo-200' },
    Finance: { bg: 'bg-emerald-100', text: 'text-emerald-800', ring: 'ring-emerald-200' },
    'R&D / Product': { bg: 'bg-violet-100', text: 'text-violet-800', ring: 'ring-violet-200' },
    'R&D/Product': { bg: 'bg-violet-100', text: 'text-violet-800', ring: 'ring-violet-200' },
    IT: { bg: 'bg-amber-100', text: 'text-amber-800', ring: 'ring-amber-200' },
    Management: { bg: 'bg-rose-100', text: 'text-rose-800', ring: 'ring-rose-200' },
};

function ownerStyle(owner) {
    if (!owner) return { bg: 'bg-slate-100', text: 'text-slate-700', ring: 'ring-slate-200' };
    const exact = OWNER_ACCENT[owner.trim()];
    if (exact) return exact;
    const lower = owner.toLowerCase();
    for (const key of Object.keys(OWNER_ACCENT)) {
        if (lower.includes(key.toLowerCase())) return OWNER_ACCENT[key];
    }
    return { bg: 'bg-slate-100', text: 'text-slate-700', ring: 'ring-slate-200' };
}

const CALLOUT_STYLES = {
    script: {
        label: 'Script',
        ring: 'border-teal-300 bg-teal-50/80',
        chip: 'bg-teal-600 text-white',
        icon: 'M3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM8.25 6h12M8.25 12h12m-12 6h12',
    },
    scenario: {
        label: 'Scenario',
        ring: 'border-violet-300 bg-violet-50/80',
        chip: 'bg-violet-600 text-white',
        icon: 'M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z',
    },
    objection: {
        label: 'Objection',
        ring: 'border-rose-300 bg-rose-50/80',
        chip: 'bg-rose-600 text-white',
        icon: 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z',
    },
    tip: {
        label: 'Tip',
        ring: 'border-emerald-300 bg-emerald-50/80',
        chip: 'bg-emerald-600 text-white',
        icon: 'M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18',
    },
    warning: {
        label: 'Warning',
        ring: 'border-amber-300 bg-amber-50/80',
        chip: 'bg-amber-600 text-white',
        icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
    },
    checklist: {
        label: 'Checklist',
        ring: 'border-sky-300 bg-sky-50/80',
        chip: 'bg-sky-600 text-white',
        icon: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
    },
    quickref: {
        label: 'Quick Reference',
        ring: 'border-slate-300 bg-slate-50',
        chip: 'bg-slate-700 text-white',
        icon: 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm0 0h-1.5m-9 1.5h.008v.008H4.5v-.008Z',
    },
};

function slugify(text) {
    return String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function parseBody(body) {
    if (!body) return [];
    const re = /^:::(\w+)([^\n]*)\n([\s\S]*?)^:::\s*$/gm;
    const segments = [];
    let cursor = 0;
    let match;
    while ((match = re.exec(body)) !== null) {
        if (match.index > cursor) {
            segments.push({ type: 'markdown', content: body.slice(cursor, match.index) });
        }
        segments.push({
            type: 'callout',
            kind: match[1].toLowerCase(),
            title: (match[2] || '').trim(),
            content: match[3].trim(),
        });
        cursor = match.index + match[0].length;
    }
    if (cursor < body.length) {
        segments.push({ type: 'markdown', content: body.slice(cursor) });
    }
    return segments;
}

function MarkdownBlock({ content }) {
    return (
        <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            components={{
                h1: ({ children }) => <h1 id={slugify(children?.toString())} className="mt-8 text-2xl font-bold tracking-tight text-slate-950">{children}</h1>,
                h2: ({ children }) => <h2 id={slugify(children?.toString())} className="mt-10 scroll-mt-24 text-xl font-bold tracking-tight text-slate-950 first:mt-0">{children}</h2>,
                h3: ({ children }) => <h3 id={slugify(children?.toString())} className="mt-6 scroll-mt-24 text-base font-semibold uppercase tracking-[0.12em] text-slate-600">{children}</h3>,
                p: ({ children }) => <p className="my-3 text-[15px] leading-7 text-slate-700">{children}</p>,
                ul: ({ children }) => <ul className="my-3 list-disc space-y-1.5 pl-6 text-[15px] leading-7 text-slate-700 marker:text-teal-600">{children}</ul>,
                ol: ({ children }) => <ol className="my-3 list-decimal space-y-1.5 pl-6 text-[15px] leading-7 text-slate-700 marker:font-semibold marker:text-teal-700">{children}</ol>,
                li: ({ children }) => <li className="pl-1">{children}</li>,
                table: ({ children }) => <div className="my-5 overflow-x-auto rounded-lg border border-slate-200"><table className="w-full text-sm">{children}</table></div>,
                thead: ({ children }) => <thead className="bg-slate-50 text-left font-semibold text-slate-700">{children}</thead>,
                th: ({ children }) => <th className="border-b border-slate-200 px-3 py-2">{children}</th>,
                td: ({ children }) => <td className="border-b border-slate-100 px-3 py-2 align-top">{children}</td>,
                code: ({ inline, children }) => inline
                    ? <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[13px] text-slate-800">{children}</code>
                    : <pre className="my-4 overflow-x-auto rounded-lg bg-slate-950 p-4 text-sm text-slate-100"><code>{children}</code></pre>,
                strong: ({ children }) => <strong className="font-semibold text-slate-950">{children}</strong>,
                em: ({ children }) => <em className="italic text-slate-600">{children}</em>,
                blockquote: ({ children }) => <blockquote className="my-4 border-l-4 border-slate-300 pl-4 italic text-slate-600">{children}</blockquote>,
                a: ({ href, children }) => <a href={href} target="_blank" rel="noopener noreferrer" className="font-medium text-teal-700 underline decoration-teal-300 underline-offset-4 hover:text-teal-800">{children}</a>,
            }}
        >
            {content}
        </ReactMarkdown>
    );
}

function Callout({ kind, title, content }) {
    const style = CALLOUT_STYLES[kind] || CALLOUT_STYLES.tip;
    return (
        <aside className={`my-6 overflow-hidden rounded-xl border-l-4 ${style.ring} ring-1 ring-inset ring-black/[0.04]`}>
            <header className="flex items-center gap-2 px-5 py-2.5">
                <span className={`inline-flex h-7 w-7 items-center justify-center rounded-full ${style.chip}`}>
                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={style.icon} />
                    </svg>
                </span>
                <span className="text-xs font-bold uppercase tracking-[0.16em] text-slate-600">{style.label}</span>
                {title ? <span className="text-sm font-semibold text-slate-700">— {title}</span> : null}
            </header>
            <div className="px-5 pb-4 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0">
                <MarkdownBlock content={content} />
            </div>
        </aside>
    );
}

function EscalationMatrix({ content }) {
    const rows = String(content || '')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line && line.includes('|'))
        .map((line) => {
            const [problem, owner] = line.split('|').map((s) => s.trim());
            return { problem, owner };
        });

    if (!rows.length) return null;

    return (
        <aside className="my-6 overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white shadow-sm ring-1 ring-inset ring-black/[0.03]">
            <header className="flex items-center gap-2 border-b border-slate-200 bg-slate-900 px-5 py-3 text-white">
                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-500 text-white">
                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M3.75 6.75 7.5 3m0 0 3.75 3.75M7.5 3v18m13.5-4.5L17.25 21m0 0L13.5 17.25M17.25 21V3" /></svg>
                </span>
                <span className="text-xs font-bold uppercase tracking-[0.16em] text-slate-300">Escalation matrix</span>
                <span className="ml-auto text-[10px] font-semibold uppercase tracking-wider text-slate-500">Who owns what</span>
            </header>
            <div className="grid divide-y divide-slate-200">
                {rows.map((row, idx) => {
                    const style = ownerStyle(row.owner);
                    return (
                        <div key={idx} className="grid grid-cols-[1fr_auto] items-center gap-4 px-5 py-3 transition hover:bg-slate-50">
                            <p className="text-sm font-semibold text-slate-900">{row.problem}</p>
                            <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider ring-1 ring-inset ${style.bg} ${style.text} ${style.ring}`}>
                                <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                {row.owner}
                            </span>
                        </div>
                    );
                })}
            </div>
        </aside>
    );
}

export default function LessonBody({ body }) {
    const segments = useMemo(() => parseBody(body), [body]);

    if (!body) {
        return <p className="text-sm text-slate-500">No lesson content yet.</p>;
    }

    return (
        <div className="max-w-[70ch]">
            {segments.map((segment, idx) => {
                if (segment.type === 'callout') {
                    if (segment.kind === 'escalation') {
                        return <EscalationMatrix key={idx} content={segment.content} />;
                    }
                    return <Callout key={idx} kind={segment.kind} title={segment.title} content={segment.content} />;
                }
                return <MarkdownBlock key={idx} content={segment.content} />;
            })}
        </div>
    );
}

export function extractHeadings(body) {
    if (!body) return [];
    // Strip callout blocks first so headings inside them don't pollute the TOC
    const stripped = body.replace(/^:::(\w+)[^\n]*\n[\s\S]*?^:::\s*$/gm, '');
    const headings = [];
    const re = /^(#{2,3})\s+(.+?)\s*$/gm;
    let match;
    while ((match = re.exec(stripped)) !== null) {
        const text = match[2].trim();
        headings.push({ level: match[1].length, text, id: slugify(text) });
    }
    return headings;
}
