import React, { useState } from 'react';
import { Fact, HealthChip, TechChip, groupTech } from './scraperUi';

const VERDICT_COPY = {
    ready: 'Ready to scrape',
    needs_config: 'Works, but needs a look',
    blocked: 'Blocked',
    error: 'Could not check',
};

const SEVERITY = {
    blocking: { label: 'Blocks scraping', tone: 'border-rose-200 bg-rose-50/60', badge: 'bg-rose-100 text-rose-800' },
    warning: { label: 'Needs attention', tone: 'border-amber-200 bg-amber-50/60', badge: 'bg-amber-100 text-amber-800' },
    info: { label: 'Note', tone: 'border-slate-200 bg-slate-50', badge: 'bg-slate-100 text-slate-700' },
};

/**
 * "At a glance" view of a probe: verdict, technology, blockers with overrides,
 * robots/sitemap/template/pagination facts and the first extracted rows.
 */
export default function ProbeSummary({ profile, onOverride, overrideBusy = null, canManage = true, compact = false, onPickLocation = null, defaultLocations = [] }) {
    const [showAllTech, setShowAllTech] = useState(false);
    if (!profile) return null;

    const counts = profile.counts || {};
    const groups = groupTech(profile.tech);
    const visibleGroups = showAllTech ? groups : groups.filter((g) => !['plugin', 'analytics'].includes(g.category));
    const hiddenCount = groups.filter((g) => ['plugin', 'analytics'].includes(g.category)).reduce((n, g) => n + g.items.length, 0);
    const blockers = profile.blockers || [];

    return (
        <div className="space-y-4">
            {/* Verdict */}
            <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <HealthChip status={profile.verdict} size="lg" />
                        <span className="text-base font-semibold text-slate-900">{VERDICT_COPY[profile.verdict] || 'Checked'}</span>
                    </div>
                    <p className="mt-1.5 text-sm text-slate-600">{profile.headline || '—'}</p>
                </div>
                <dl className="grid shrink-0 grid-cols-3 gap-4 text-center sm:text-right">
                    <Stat label="Profiles" value={counts.rows ?? 0} />
                    <Stat label="With phone" value={counts.phones ?? 0} tone={counts.rows > 0 && counts.phones === 0 ? 'text-amber-700' : undefined} />
                    <Stat label="Pages" value={profile.pagination?.estimated_pages || (profile.pagination?.next_url ? '2+' : (counts.rows ? 1 : '—'))} />
                </dl>
            </div>

            {/* Blockers */}
            {blockers.length > 0 ? (
                <section aria-label="Blockers" className="space-y-2">
                    {blockers.map((b) => {
                        const sev = SEVERITY[b.severity] || SEVERITY.info;
                        return (
                            <div key={b.key} className={`rounded-lg border p-3 ${sev.tone}`}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide ${sev.badge}`}>{sev.label}</span>
                                    <span className="text-sm font-semibold text-slate-900">{b.title}</span>
                                </div>
                                <p className="mt-1 text-sm text-slate-700">{b.detail}</p>
                                {(b.override_options || []).length > 0 && onOverride ? (
                                    <div className="mt-2.5 flex flex-wrap gap-2">
                                        {b.override_options.map((o, i) => {
                                            const needsManage = o.kind !== 'capture' && !canManage;
                                            const disabled = !o.available || needsManage || overrideBusy === o.key;
                                            return (
                                                <button
                                                    key={o.key}
                                                    type="button"
                                                    disabled={disabled}
                                                    onClick={() => onOverride(o, b)}
                                                    title={!o.available ? 'Not available yet' : (needsManage ? 'Only admins can change site settings' : o.detail)}
                                                    className={`${i === 0 ? 'crm-btn-primary' : 'crm-btn-secondary'} px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50`}
                                                >
                                                    {overrideBusy === o.key ? 'Applying…' : o.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </section>
            ) : (
                <p className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50/60 px-3 py-2 text-sm text-emerald-800">
                    <span aria-hidden="true">✓</span> No blockers: the CRM can read this page directly.
                </p>
            )}

            {/* Technology + facts */}
            <div className={`grid gap-4 ${compact ? '' : 'lg:grid-cols-5'}`}>
                <section className={`rounded-lg border border-slate-200 p-3 ${compact ? '' : 'lg:col-span-3'}`}>
                    <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Technology</h4>
                    {groups.length === 0 ? (
                        <p className="mt-2 text-sm text-slate-500">Nothing recognisable (the page may not have loaded).</p>
                    ) : (
                        <div className="mt-2 space-y-2">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {visibleGroups.flatMap((g) => g.items).map((t) => <TechChip key={t.key} tech={t} />)}
                            </div>
                            {hiddenCount > 0 ? (
                                <button type="button" className="text-xs font-semibold text-teal-700 hover:underline" onClick={() => setShowAllTech((v) => !v)}>
                                    {showAllTech ? 'Hide plugins & analytics' : `Show ${hiddenCount} plugins & analytics`}
                                </button>
                            ) : null}
                        </div>
                    )}
                </section>
                <dl className={`grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-3 ${compact ? '' : 'lg:col-span-2'}`}>
                    <Fact label="Template">
                        {profile.template ? `${profile.template.label}${profile.template.confidence ? ` · ${profile.template.confidence}%` : ''}` : (profile.detected_rules ? 'Auto-detected layout' : 'None')}
                    </Fact>
                    <Fact label="robots.txt">
                        <span className={profile.robots?.allowed === false ? 'font-semibold text-rose-700' : ''}>
                            {profile.robots ? (profile.robots.allowed ? (profile.robots.policy === 'robots_matched' ? 'Allows' : 'No rules') : 'Disallows') : '—'}
                        </span>
                        {profile.robots?.crawl_delay ? ` · delay ${profile.robots.crawl_delay}s` : ''}
                    </Fact>
                    <Fact label="Sitemap">{profile.sitemap?.found ? 'Found' : ((profile.sitemap?.declared || []).length ? 'Declared' : 'Not checked')}</Fact>
                    <Fact label="Response">
                        {profile.http ? `HTTP ${profile.http.status || '—'} · ${Math.round((profile.http.elapsed_ms || 0) / 100) / 10}s` : '—'}
                    </Fact>
                    <Fact label="Next page" mono>{profile.pagination?.next_url ? new URL(profile.pagination.next_url).pathname + (new URL(profile.pagination.next_url).search || '') : '—'}</Fact>
                    <Fact label="Fetched with">{profile.driver === 'capture' ? 'Browser capture' : profile.driver === 'render_worker' ? 'Render worker' : 'Direct'}</Fact>
                </dl>
            </div>

            {/* Where the profiles are */}
            {(profile.locations || []).length > 0 ? (
                <section className="rounded-lg border border-slate-200 p-3" aria-label="Where the profiles are">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Where the profiles are</h4>
                        <span className="text-xs text-slate-500">
                            {profile.with_location ?? 0} of {counts.rows} list a location{onPickLocation ? ' · pick a place to collect only there' : ''}
                        </span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {profile.locations.slice(0, 16).map((l) => {
                            const isDefault = (defaultLocations || []).some((d) => d.toLowerCase() === l.label.toLowerCase());
                            const chip = (
                                <>
                                    <span aria-hidden="true">📍</span>{l.label}
                                    <span className={`tabular-nums ${isDefault ? 'text-teal-100' : 'text-slate-400'}`}>{l.count}</span>
                                </>
                            );
                            const tone = isDefault ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 bg-white text-slate-700';
                            return onPickLocation ? (
                                <button key={l.label} type="button" onClick={() => onPickLocation(l.label)} title={`Collect only profiles in ${l.label}`}
                                    className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition hover:border-teal-600 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 ${tone}`}>
                                    {chip}
                                </button>
                            ) : (
                                <span key={l.label} className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium ${tone}`}>{chip}</span>
                            );
                        })}
                    </div>
                </section>
            ) : null}

            {/* Sample rows */}
            {(profile.sample || []).length > 0 ? (
                <section className="overflow-hidden rounded-lg border border-slate-200">
                    <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-2">
                        <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">What the scraper reads</h4>
                        <span className="text-xs text-slate-500">First {profile.sample.length} of {counts.rows}</span>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="text-left text-xs text-slate-500">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Name</th>
                                    <th className="px-3 py-2 font-medium">Phone</th>
                                    <th className="px-3 py-2 font-medium">City</th>
                                    <th className="px-3 py-2 font-medium">Profile</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {profile.sample.map((row, i) => (
                                    <tr key={`${row.profile_url || row.phone || i}-${i}`}>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                {row.image_url ? <img src={row.image_url} alt="" loading="lazy" referrerPolicy="no-referrer" className="h-8 w-8 shrink-0 rounded object-cover" /> : <span className="h-8 w-8 shrink-0 rounded bg-slate-100" />}
                                                <span className="truncate font-medium text-slate-900">{row.name || <em className="text-slate-400">No name</em>}</span>
                                            </div>
                                        </td>
                                        <td className="crm-mono whitespace-nowrap px-3 py-2 text-[13px]">
                                            {row.phone ? `+${row.phone}` : <span className="font-sans text-xs text-amber-700">Not on listing</span>}
                                            {row.whatsapp ? <span className="ml-1.5 rounded bg-emerald-50 px-1 text-[10px] font-semibold text-emerald-700">WA</span> : null}
                                        </td>
                                        <td className="max-w-[12rem] truncate px-3 py-2 text-slate-600">{row.city || '—'}</td>
                                        <td className="px-3 py-2">
                                            {row.profile_url ? <a className="text-teal-700 hover:underline" href={row.profile_url} target="_blank" rel="noreferrer noopener">Open ↗</a> : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}
        </div>
    );
}

function Stat({ label, value, tone }) {
    return (
        <div>
            <dt className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</dt>
            <dd className={`crm-mono mt-0.5 text-xl font-semibold tabular-nums ${tone || 'text-slate-900'}`}>{value}</dd>
        </div>
    );
}
