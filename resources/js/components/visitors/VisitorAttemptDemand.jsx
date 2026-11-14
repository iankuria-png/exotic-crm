import React from 'react';
import MetricCard from '../MetricCard';
import SectionFrame from '../SectionFrame';
import { InsightEmptyState } from '../shared/InsightStates';
import { compactNumber, copyText } from './visitorFormat';

function observedValue(row, key) {
    return row?.coverage === 'unavailable' ? 'Unavailable' : compactNumber(row?.[key]);
}

export default function VisitorAttemptDemand({ demand, isLoading, toast }) {
    const totals = demand?.totals || {};
    const countries = demand?.countries || [];
    const locations = demand?.profile_locations || [];
    const download = () => {
        const rows = [
            ['Country', 'Website', 'Checkout setting', 'Recorded attempts', 'Site sessions', 'Coverage', 'Attempt definition', 'Session definition', 'Coverage note'],
            ...countries.flatMap((country) => (country.sites || []).map((site) => [
                country.country, site.platform_name, site.checkout_configured ? 'Configured' : 'Disabled',
                site.coverage === 'unavailable' ? 'Unavailable' : site.recorded_attempts,
                site.coverage === 'unavailable' ? 'Unavailable' : site.site_sessions, site.coverage,
                demand?.definition, demand?.session_definition, demand?.coverage_note,
            ])),
        ];
        const csv = rows.map((row) => row.map((value) => `"${String(value ?? '').replace(/^[=+\-@\t\r]/, (prefix) => `'${prefix}`).replaceAll('"', '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'contact-unlock-demand.csv';
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <MetricCard label="Recorded unlock attempts" value={demand ? observedValue(totals, 'recorded_attempts') : '—'} hint="Clicks to open contact unlock, including repeat clicks" isLoading={isLoading} />
                <MetricCard label="Browser sessions" value={demand ? observedValue(totals, 'site_sessions') : '—'} hint="Distinct per website; not unique people across sites" isLoading={isLoading} />
                <MetricCard label="Sites with event history" value={demand ? `${totals.sites_with_event_history || 0} / ${totals.sites || 0}` : '—'} hint="History observed; continuous coverage is unverified" isLoading={isLoading} />
            </div>

            <SectionFrame title="Demand by country and website" subtitle="Interest in the feature is counted even when payments are disabled." action={countries.length ? <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={download}>Export CSV</button> : null}>
                {isLoading ? <p className="text-sm text-slate-500" role="status">Loading recorded demand…</p> : countries.length ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 text-xs text-slate-500"><tr><th scope="col" className="py-2 pr-4">Country / website</th><th scope="col" className="py-2 pr-4">Attempts</th><th scope="col" className="py-2 pr-4">Site sessions</th><th scope="col" className="py-2">Coverage / checkout</th></tr></thead>
                            <tbody>
                                {countries.map((country) => (
                                    <React.Fragment key={country.country}>
                                        <tr className="border-b border-slate-100 bg-slate-50 font-semibold text-slate-800"><th scope="row" className="py-2 pr-4">{country.country}</th><td className="py-2 pr-4">{observedValue(country, 'recorded_attempts')}</td><td className="py-2 pr-4">{observedValue(country, 'site_sessions')}</td><td className="py-2 text-xs font-normal text-slate-500">{country.sites_with_event_history} of {(country.sites || []).length} sites observed</td></tr>
                                        {(country.sites || []).map((site) => (
                                            <tr key={site.platform_id} className="border-b border-slate-100 text-slate-600"><th scope="row" className="py-2 pl-3 pr-4 font-normal">{site.platform_name}{site.site_active ? '' : ' (inactive site)'}</th><td className="py-2 pr-4">{observedValue(site, 'recorded_attempts')}</td><td className="py-2 pr-4">{observedValue(site, 'site_sessions')}</td><td className="py-2 text-xs">{site.coverage === 'unavailable' ? 'No event history' : 'Recorded events only'} · Checkout setting {site.checkout_configured ? 'on' : 'off'}</td></tr>
                                        ))}
                                    </React.Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : <InsightEmptyState title="No sites in this selection" message="Choose another country or website within your assigned markets." />}
                <p className="mt-3 text-xs leading-5 text-slate-500">{demand?.coverage_note || 'Tracking coverage is not available yet.'} Site session counts are added across websites and cannot identify unique people across the Tanzania sites. Checkout settings do not establish provider readiness.</p>
            </SectionFrame>

            <SectionFrame title="Profile cities requested" subtitle="Cities of the profiles people tried to contact, using each profile’s current cached city." action={locations.length ? <button type="button" className="crm-btn-secondary px-3 py-1.5 text-xs" onClick={() => copyText(locations.map((row) => `${row.country} / ${row.platform_name} / ${row.city}: ${row.recorded_attempts}`).join('\n'), toast)}>Copy</button> : null}>
                {locations.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-200 text-xs text-slate-500"><tr><th scope="col" className="py-2 pr-4">Profile city</th><th scope="col" className="py-2 pr-4">Website / country</th><th scope="col" className="py-2">Attempts</th></tr></thead><tbody>{locations.map((row, index) => <tr key={`${row.platform_id}-${index}`} className="border-b border-slate-100 text-slate-600"><th scope="row" className="py-2 pr-4 font-normal">{row.city}</th><td className="py-2 pr-4">{row.platform_name} / {row.country}</td><td className="py-2">{compactNumber(row.recorded_attempts)}</td></tr>)}</tbody></table></div> : <InsightEmptyState title="No requested locations recorded" message="No unlock-attempt events are recorded for this selection. Collection may be missing; this does not establish zero interest." />}
                <p className="mt-3 text-xs leading-5 text-slate-500">Showing up to 100 requested profile locations. Cities reflect the current profile cache and may have changed since the attempt.</p>
            </SectionFrame>
        </div>
    );
}
