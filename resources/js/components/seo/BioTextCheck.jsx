import React, { useMemo } from 'react';
import {
    FORMAT_HTML,
    FORMAT_TEXT,
    SAFE_FIXES,
    fixBioText,
    inspectBioText,
} from '../../utils/bioTextIntegrity';

// Warns about bio text that visitors and Google would see as broken, shows
// each problem as a proofreader's mark (garbled word struck through, the
// repair beside it) and offers the fix. Shared by the SEO review modal, the
// profile editor and Clients → Bio text.

export const ISSUE_COPY = {
    broken_accents: {
        label: 'Garbled accents',
        short: 'Accents',
        detail: 'Accented letters were saved in the wrong encoding, so visitors and Google read “dÃ©tour” instead of “détour”.',
        fix: 'Fix accents',
    },
    lost_characters: {
        label: 'Missing letters',
        short: 'Missing letters',
        detail: 'Some letters were replaced by “�” and cannot be recovered. Retype these words.',
        manual: 'Retype these words',
    },
    escaped_html: {
        label: 'Code showing as text',
        short: 'Code as text',
        detail: 'Formatting code such as “&eacute;” or “<p>” would show on the profile as text.',
        fix: 'Convert code',
    },
    markdown: {
        label: 'Markdown symbols',
        short: 'Markdown',
        detail: 'AI formatting like **bold** or ## headings would appear on the profile as stray symbols.',
        fix: 'Remove symbols',
    },
    ai_text: {
        label: 'AI leftovers',
        short: 'AI leftovers',
        detail: 'Text meant for you, not for visitors: an AI preamble, a refusal or a placeholder like [Name].',
        manual: 'Delete it or generate again',
    },
    invisible_characters: {
        label: 'Invisible characters',
        short: 'Invisible',
        detail: 'Hidden characters from copy and paste. They can split words in search results.',
        fix: 'Remove them',
    },
    emoji: {
        label: 'Emoji and symbols',
        short: 'Emoji',
        detail: 'Plain-text bios look the same on every phone. Emoji can show as empty boxes and read as spam.',
        fix: 'Remove emoji',
    },
};

const INVISIBLE_DISPLAY = new RegExp('[\\u00AD\\u200B-\\u200F\\u2060-\\u2064\\uFEFF\\u0080-\\u009F]', 'g');
const LOST = new RegExp('\\uFFFD');
const PLAIN_WORD = /^[A-Za-z0-9.,;:!?'"()%/-]*$/;

/** Memoised report for a bio. */
export function useBioTextReport(value, format = FORMAT_HTML) {
    return useMemo(() => inspectBioText(value || '', format), [value, format]);
}

/** Readable text of a bio's HTML, one paragraph per line. */
export function bioHtmlToText(html = '') {
    if (!html) return '';
    if (typeof window === 'undefined') return String(html).replace(/<[^>]*>/g, ' ');
    const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    doc.querySelectorAll('br').forEach((node) => node.replaceWith('\n'));
    doc.querySelectorAll('p, div, li, h1, h2, h3, h4').forEach((node) => node.append('\n\n'));
    return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
}

function showHidden(value) {
    return String(value).replace(INVISIBLE_DISPLAY, '·');
}

/** A proofreader's mark: the broken text struck through, the repair beside it. */
export function ProofMark({ found, fixed }) {
    return (
        <span className="whitespace-pre-wrap">
            <del className="rounded-sm bg-rose-50 px-0.5 text-rose-700 decoration-rose-400 decoration-[1.5px]">{showHidden(found)}</del>
            {fixed !== null && fixed !== undefined ? (
                <>
                    <span aria-hidden="true" className="px-0.5 text-slate-300">›</span>
                    <ins className="rounded-sm bg-teal-50 px-0.5 font-medium text-teal-800 no-underline">
                        {fixed === '' ? <span className="italic text-teal-600/80">removed</span> : fixed}
                    </ins>
                </>
            ) : null}
        </span>
    );
}

/**
 * Plain text with every repairable word marked and text a person must
 * rewrite highlighted. `window` limits it to the stretch around the first
 * problem, for list rows.
 */
export function ProofText({ text, window: windowWords = 0, className = '' }) {
    const tokens = useMemo(() => proofTokens(text || ''), [text]);

    let visible = tokens;
    let clippedStart = false;
    let clippedEnd = false;
    if (windowWords > 0) {
        const first = Math.max(0, tokens.findIndex((token) => token.kind !== 'plain'));
        const start = Math.max(0, first - Math.floor(windowWords / 4) * 2);
        const end = Math.min(tokens.length, start + windowWords * 2);
        visible = tokens.slice(start, end);
        clippedStart = start > 0;
        clippedEnd = end < tokens.length;
    }

    return (
        <p className={`whitespace-pre-wrap break-words ${className}`}>
            {clippedStart ? <span className="text-slate-400">… </span> : null}
            {visible.map((token, index) => {
                if (token.kind === 'fix') return <ProofMark key={index} found={token.text} fixed={token.fixed} />;
                if (token.kind === 'manual') {
                    return (
                        <mark key={index} className="rounded-sm bg-amber-100 px-0.5 text-amber-900 ring-1 ring-inset ring-amber-200">
                            {token.text}
                        </mark>
                    );
                }
                return <React.Fragment key={index}>{token.text}</React.Fragment>;
            })}
            {clippedEnd ? <span className="text-slate-400"> …</span> : null}
        </p>
    );
}

function proofTokens(text) {
    const phrases = inspectBioText(text, FORMAT_TEXT, ['ai_text']).issues
        .flatMap((issue) => issue.samples.map((sample) => sample.found.replace(/…$/, '')))
        .filter((phrase) => phrase.length > 1);

    const tokens = [];
    for (const part of splitByPhrases(text, phrases)) {
        if (part.phrase) {
            tokens.push({ kind: 'manual', text: part.text });
            continue;
        }
        // Words and the whitespace between them; each word is checked alone.
        for (const piece of part.text.split(/([ \t\n\r]+)/)) {
            if (piece === '') continue;
            if (/^[ \t\n\r]+$/.test(piece) || PLAIN_WORD.test(piece)) {
                tokens.push({ kind: 'plain', text: piece });
            } else if (LOST.test(piece)) {
                tokens.push({ kind: 'manual', text: piece });
            } else {
                const fixed = fixBioText(`${piece} `, FORMAT_TEXT, SAFE_FIXES).replace(/ +$/, '');
                tokens.push(fixed === piece ? { kind: 'plain', text: piece } : { kind: 'fix', text: piece, fixed });
            }
        }
    }

    return tokens;
}

function splitByPhrases(text, phrases) {
    if (!phrases.length) return [{ text, phrase: false }];
    const escaped = phrases
        .sort((a, b) => b.length - a.length)
        .map((phrase) => phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    return text
        .split(new RegExp(`(${escaped.join('|')})`, 'gi'))
        .filter((part) => part !== '')
        .map((part) => ({ text: part, phrase: phrases.some((phrase) => phrase.toLowerCase() === part.toLowerCase()) }));
}

function Samples({ issue }) {
    return (
        <ul className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-[13px] leading-6">
            {issue.samples.map((sample) => (
                <li key={sample.found} className="min-w-0 max-w-full truncate">
                    {issue.fixable
                        ? <ProofMark found={sample.found} fixed={sample.fixed ?? ''} />
                        : issue.kind === 'invisible_characters'
                            ? <span className="text-slate-600">{sample.found}</span>
                            : <mark className="rounded-sm bg-amber-100 px-1 text-amber-900 ring-1 ring-inset ring-amber-200">{sample.found}</mark>}
                </li>
            ))}
        </ul>
    );
}

/**
 * Inline check under a bio editor. Renders nothing when the text is clean
 * (use <BioTextBadge> for a quiet all-clear) and a list of problems with
 * one-click fixes otherwise.
 */
export function BioTextCheck({ value, format = FORMAT_HTML, onChange, className = '' }) {
    const report = useBioTextReport(value, format);
    if (!value || report.clean) return null;

    const fixableKinds = report.issues.filter((issue) => issue.fixable).map((issue) => issue.kind);
    const hasErrors = report.errors > 0;
    const problemCount = report.issues.reduce((total, issue) => total + issue.count, 0);

    return (
        <section
            className={`overflow-hidden rounded-xl border ${hasErrors ? 'border-rose-200 bg-rose-50/40' : 'border-amber-200 bg-amber-50/50'} ${className}`}
            aria-live="polite"
            data-testid="bio-text-check"
        >
            <header className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                <div className="flex min-w-0 items-center gap-2">
                    <span className={`inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white ${hasErrors ? 'bg-rose-600' : 'bg-amber-500'}`} aria-hidden="true">!</span>
                    <p className="text-sm font-semibold text-slate-900">
                        {hasErrors ? 'This bio would look broken on the profile' : 'Worth tidying before you save'}
                        <span className="ml-1.5 font-normal text-slate-500">· {problemCount} {problemCount === 1 ? 'spot' : 'spots'}</span>
                    </p>
                </div>
                {fixableKinds.length > 1 && onChange ? (
                    <button
                        type="button"
                        onClick={() => onChange(fixBioText(value, format, fixableKinds))}
                        className="rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-1"
                    >
                        Fix all
                    </button>
                ) : null}
            </header>
            <ul className="divide-y divide-white/80 border-t border-white/80">
                {report.issues.map((issue) => {
                    const copy = ISSUE_COPY[issue.kind];
                    return (
                        <li key={issue.kind} className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 bg-white/60 px-4 py-2.5">
                            <div className="min-w-0 flex-1">
                                <p className="text-[13px] font-semibold text-slate-800">
                                    {copy.label}
                                    <span className={`ml-2 rounded-full px-1.5 py-px text-[10px] font-bold uppercase tracking-wide ${issue.severity === 'error' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800'}`}>
                                        {issue.count}
                                    </span>
                                </p>
                                <p className="mt-0.5 text-xs leading-relaxed text-slate-600">{copy.detail}</p>
                                <Samples issue={issue} />
                            </div>
                            {issue.fixable && onChange ? (
                                <button
                                    type="button"
                                    onClick={() => onChange(fixBioText(value, format, [issue.kind]))}
                                    className="shrink-0 rounded-lg border border-teal-200 bg-white px-2.5 py-1 text-xs font-semibold text-teal-800 transition hover:border-teal-300 hover:bg-teal-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                >
                                    {copy.fix}
                                </button>
                            ) : !issue.fixable ? (
                                <span className="shrink-0 pt-0.5 text-[11px] font-medium text-amber-800">{copy.manual}</span>
                            ) : null}
                        </li>
                    );
                })}
            </ul>
            {report.issues.some((issue) => issue.kind === 'broken_accents') ? (
                <p className="border-t border-white/80 px-4 py-2 text-[11px] text-slate-500">
                    Garbled accents are also repaired automatically when the bio is saved to the profile.
                </p>
            ) : null}
        </section>
    );
}

/** A quiet status chip for editor headers: clean, or how many problems. */
export function BioTextBadge({ value, format = FORMAT_HTML }) {
    const report = useBioTextReport(value, format);
    if (!value) return null;
    if (report.clean) {
        return (
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100">
                ✓ Clean text
            </span>
        );
    }
    const tone = report.errors > 0 ? 'bg-rose-50 text-rose-700 ring-rose-200' : 'bg-amber-50 text-amber-700 ring-amber-200';
    return (
        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${tone}`}>
            {report.issues.length} text {report.issues.length === 1 ? 'problem' : 'problems'}
        </span>
    );
}

/**
 * Save-time warning. Shown when a bio with problems is about to be used or
 * saved: fix and continue (the default), continue as it is, or go back.
 */
export function BioTextSaveWarning({ open, value, format = FORMAT_HTML, continueLabel = 'save', onFixAndContinue, onContinueAnyway, onCancel, isPending = false }) {
    const report = useBioTextReport(open ? value : '', format);

    React.useEffect(() => {
        if (!open) return undefined;
        const onKey = (event) => {
            if (event.key === 'Escape' && !isPending) onCancel?.();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, isPending, onCancel]);

    if (!open || report.clean) return null;

    const fixable = report.issues.filter((issue) => issue.fixable);
    const manual = report.issues.filter((issue) => !issue.fixable);
    const fixableKinds = fixable.map((issue) => issue.kind);

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/45 p-4" onClick={isPending ? undefined : onCancel}>
            <div
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="bio-text-warning-title"
                className="w-full max-w-lg overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="border-b border-slate-100 px-5 py-4">
                    <p className={`text-[11px] font-semibold uppercase tracking-[0.14em] ${report.errors > 0 ? 'text-rose-600' : 'text-amber-600'}`}>Before you {continueLabel}</p>
                    <h3 id="bio-text-warning-title" className="mt-1 text-base font-semibold text-slate-900">
                        {report.errors > 0 ? 'Visitors and Google would see broken text in this bio' : 'This bio has text worth tidying'}
                    </h3>
                </header>
                <div className="max-h-[50vh] space-y-3 overflow-y-auto px-5 py-4">
                    {fixable.length ? (
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.1em] text-teal-700">Can be fixed for you</p>
                            <ul className="mt-1.5 space-y-2">
                                {fixable.map((issue) => (
                                    <li key={issue.kind} className="text-sm text-slate-700">
                                        <span className="font-semibold">{ISSUE_COPY[issue.kind].label}</span>
                                        <span className="text-slate-400"> · {issue.count}</span>
                                        <Samples issue={issue} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {manual.length ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5">
                            <p className="text-xs font-semibold uppercase tracking-[0.1em] text-amber-800">Needs you</p>
                            <ul className="mt-1 space-y-2">
                                {manual.map((issue) => (
                                    <li key={issue.kind} className="text-sm text-amber-950">
                                        <span className="font-semibold">{ISSUE_COPY[issue.kind].label}</span>. {ISSUE_COPY[issue.kind].detail}
                                        <Samples issue={issue} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                </div>
                <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 bg-slate-50 px-5 py-3.5">
                    <button type="button" onClick={onCancel} disabled={isPending} className="crm-btn-secondary disabled:opacity-50">
                        Keep editing
                    </button>
                    <button
                        type="button"
                        onClick={onContinueAnyway}
                        disabled={isPending}
                        className="rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                    >
                        {capitalise(continueLabel)} as it is
                    </button>
                    {fixable.length ? (
                        <button
                            type="button"
                            autoFocus
                            onClick={() => onFixAndContinue(fixBioText(value, format, fixableKinds))}
                            disabled={isPending}
                            className="crm-btn-primary disabled:opacity-50"
                        >
                            Fix and {continueLabel}
                        </button>
                    ) : null}
                </footer>
            </div>
        </div>
    );
}

function capitalise(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}
