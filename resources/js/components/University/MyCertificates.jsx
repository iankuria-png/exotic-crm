import React from 'react';
import { Link } from 'react-router-dom';

export default function MyCertificates({ certificates = [], nextCourse = null }) {
    if (!certificates.length) {
        return (
            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3">
                        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                            <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 9.5 7.5 4 8.5l4.5 4-1 6L12 15.5 16.5 18.5l-1-6L20 8.5l-5.5-1L12 2Z" /></svg>
                        </span>
                        <div>
                            <p className="text-sm font-bold text-slate-950">No certificates earned yet</p>
                            <p className="mt-1 max-w-xl text-sm text-slate-600">Finish the core lessons, then pass the certification to unlock your first credential and appear in the team ranking.</p>
                        </div>
                    </div>
                    {nextCourse ? (
                        <Link to={`/university/courses/${nextCourse.slug}`} className="crm-btn-secondary shrink-0 px-3 py-2 text-sm">
                            Start {nextCourse.title}
                        </Link>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {certificates.map((c) => {
                const expired = c.expired;
                const revoked = c.revoked;
                const status = revoked ? 'Revoked' : expired ? 'Expired' : 'Active';
                const statusStyle = revoked
                    ? 'bg-rose-100 text-rose-800 ring-rose-200'
                    : expired
                        ? 'bg-amber-100 text-amber-800 ring-amber-200'
                        : 'bg-emerald-100 text-emerald-800 ring-emerald-200';

                return (
                    <div key={c.code} className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-5 shadow-sm transition hover:shadow-md">
                        {/* Foil-style decorative band */}
                        <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-amber-400 via-amber-500 to-yellow-400" />
                        <div className="flex items-start justify-between gap-2">
                            <span className="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-amber-400 to-yellow-500 text-white shadow">
                                <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2 9.5 7.5 4 8.5l4.5 4-1 6L12 15.5 16.5 18.5l-1-6L20 8.5l-5.5-1L12 2Z" /></svg>
                            </span>
                            <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider ring-1 ring-inset ${statusStyle}`}>{status}</span>
                        </div>
                        <h3 className="mt-3 text-sm font-bold leading-tight text-slate-950">{c.title || 'Certification'}</h3>
                        {c.course ? <p className="mt-0.5 text-xs text-slate-500">{c.course}</p> : null}
                        <dl className="mt-3 grid grid-cols-2 gap-1 text-[11px]">
                            <dt className="font-semibold uppercase tracking-wider text-slate-400">Issued</dt>
                            <dt className="text-right text-slate-600">{formatDate(c.issued_at)}</dt>
                            <dd className="font-semibold uppercase tracking-wider text-slate-400">Expires</dd>
                            <dd className="text-right text-slate-600">{formatDate(c.expires_at)}</dd>
                        </dl>
                        <div className="mt-3 flex items-center justify-between gap-2">
                            <span className="font-mono text-[10px] text-slate-400">{c.code}</span>
                            {c.pdf_url ? (
                                <a href={c.pdf_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                    Download PDF
                                    <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                </a>
                            ) : null}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function formatDate(iso) {
    if (!iso) return '—';
    try { return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); }
    catch { return iso; }
}
