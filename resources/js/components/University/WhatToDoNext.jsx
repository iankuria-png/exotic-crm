import React, { useMemo } from 'react';
import { Link } from 'react-router-dom';

/**
 * Picks the most relevant "next action" for the learner based on engagement, courses,
 * and today's drill state. Falls back gracefully when nothing's urgent.
 */
export default function WhatToDoNext({ engagement, courses, drill }) {
    const action = useMemo(() => {
        // 1. Expired certificate → recertify
        const expired = (engagement?.certificates || []).find((c) => c.expired && !c.revoked);
        if (expired) {
            return {
                kind: 'recertify',
                title: 'Recertify: ' + (expired.title || 'Sales/CS Certification'),
                time: '35 min',
                why: 'Your certification expired — recertifying keeps your authority on discounts and free trials current.',
                cta: 'Start recertification',
                href: '/university',
                accent: 'rose',
            };
        }

        // 2. Today's drill not done → do the drill (highest engagement leverage)
        if (drill && !drill.completed) {
            return {
                kind: 'drill',
                title: "Today's Daily Drill",
                time: '1 min',
                why: 'One scenario question builds your streak and sharpens decision-making.',
                cta: 'Answer the drill',
                href: '#daily-drill',
                accent: 'amber',
            };
        }

        // 3. In-progress course → continue
        const inProgress = (courses || []).find((c) => Number(c.progress_pct || 0) > 0 && Number(c.progress_pct || 0) < 100);
        if (inProgress) {
            return {
                kind: 'continue',
                title: `Continue: ${inProgress.title}`,
                time: `${Math.max(3, Math.round((inProgress.duration_minutes || 12) / Math.max(1, inProgress.lesson_count || 1)))} min`,
                why: inProgress.summary || 'Pick up where you left off.',
                cta: 'Continue training',
                href: `/university/courses/${inProgress.slug}`,
                accent: 'teal',
            };
        }

        // 4. Has lessons but no cert yet → take cert
        if ((engagement?.stats?.lessons_completed || 0) >= 5 && (engagement?.stats?.active_certificates || 0) === 0) {
            return {
                kind: 'certify',
                title: 'Take the Core Sales/CS Certification',
                time: '35 min',
                why: "You've completed enough lessons — lock in the certificate while everything is fresh.",
                cta: 'Start certification',
                href: '/university',
                accent: 'emerald',
            };
        }

        // 5. Untouched course → start the most strategic one (Escalation Tree first)
        const candidates = (courses || []).filter((c) => Number(c.progress_pct || 0) === 0);
        const escalation = candidates.find((c) => c.slug === 'escalation-tree');
        const next = escalation || candidates[0];
        if (next) {
            return {
                kind: 'start',
                title: `Start: ${next.title}`,
                time: `${next.duration_minutes || 12} min`,
                why: next.summary || 'A great place to begin.',
                cta: 'Begin course',
                href: `/university/courses/${next.slug}`,
                accent: escalation ? 'rose' : 'indigo',
            };
        }

        return {
            kind: 'browse',
            title: 'Browse the learning catalog',
            time: '5 min',
            why: 'See what new courses are available.',
            cta: 'Open catalog',
            href: '/university',
            accent: 'indigo',
        };
    }, [engagement, courses, drill]);

    const ACCENT = {
        teal:    { ring: 'border-teal-300',    chip: 'bg-teal-600',    text: 'text-teal-700' },
        amber:   { ring: 'border-amber-300',   chip: 'bg-amber-600',   text: 'text-amber-700' },
        emerald: { ring: 'border-emerald-300', chip: 'bg-emerald-600', text: 'text-emerald-700' },
        rose:    { ring: 'border-rose-300',    chip: 'bg-rose-600',    text: 'text-rose-700' },
        indigo:  { ring: 'border-indigo-300',  chip: 'bg-indigo-600',  text: 'text-indigo-700' },
    }[action.accent] || { ring: 'border-teal-300', chip: 'bg-teal-600', text: 'text-teal-700' };

    return (
        <section className={`relative overflow-hidden rounded-2xl border-2 ${ACCENT.ring} bg-white p-5 shadow-sm`}>
            <div className="flex items-start gap-4">
                <span className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${ACCENT.chip} text-white shadow-md`}>
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3.75 13.5 16.5 5.25 12 13.5h7.5L7.5 21.75 12 13.5H3.75Z" /></svg>
                </span>
                <div className="min-w-0 flex-1">
                    <p className={`text-[11px] font-bold uppercase tracking-[0.18em] ${ACCENT.text}`}>Today's next action</p>
                    <h3 className="mt-0.5 text-xl font-bold leading-snug text-slate-950">{action.title}</h3>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-900 px-2 py-0.5 font-bold uppercase tracking-wider text-white">
                            <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            {action.time}
                        </span>
                        <span className="text-slate-500">·</span>
                        <span className="text-slate-600">{action.why}</span>
                    </div>
                </div>
                {action.href.startsWith('#') ? (
                    <a href={action.href} className={`inline-flex shrink-0 items-center gap-1.5 rounded-xl ${ACCENT.chip} px-4 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110`}>
                        {action.cta}
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </a>
                ) : (
                    <Link to={action.href} className={`inline-flex shrink-0 items-center gap-1.5 rounded-xl ${ACCENT.chip} px-4 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110`}>
                        {action.cta}
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                    </Link>
                )}
            </div>
        </section>
    );
}
