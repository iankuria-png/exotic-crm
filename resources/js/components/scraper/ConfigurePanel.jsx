import React, { useEffect, useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../../services/api';
import { Spinner, errorMessage } from './scraperUi';

const LIST_FIELDS = [
    ['row_selector', 'Profile card', 'Repeated element that holds one profile'],
    ['name_selector', 'Name', 'Inside the card'],
    ['phone_selector', 'Phone', 'tel: link or text'],
    ['whatsapp_selector', 'WhatsApp', 'wa.me / whatsapp.com link'],
    ['link_selector', 'Profile link', 'Link to the profile page'],
    ['city_selector', 'City / area', 'Inside the card'],
    ['age_selector', 'Age', 'Inside the card'],
    ['image_selector', 'Photo', 'img element'],
    ['email_selector', 'Email', 'mailto: link'],
];

const DEDUPE_LABELS = {
    phone_or_email: 'Phone, email or profile link',
    phone_only: 'Phone only',
    email_only: 'Email only',
    source_url: 'Profile link only',
};

function blankForm(site) {
    const crawl = site.crawl_settings || {};
    return {
        name: site.name || '',
        source_url: site.source_url || '',
        template_key: site.template_key || '',
        fetch_driver: site.fetch_driver || 'http',
        parser_rules: { ...(site.parser_rules || {}) },
        crawl_settings: {
            ...crawl,
            pagination: { ...(crawl.pagination || {}) },
            profile_rules: { ...(crawl.profile_rules || {}) },
            headers: [...(crawl.headers || [])],
            cookies: [...(crawl.cookies || [])],
        },
        dedupe_mode: site.dedupe_mode || 'phone_or_email',
        is_active: !!site.is_active,
        compliance_ack_robots: !!site.compliance_ack_robots,
        compliance_ack_tos: !!site.compliance_ack_tos,
    };
}

export default function ConfigurePanel({ site, meta, canManage, onSaved, focus, toast }) {
    const [form, setForm] = useState(() => blankForm(site));
    const [test, setTest] = useState(null);

    useEffect(() => { setForm(blankForm(site)); setTest(null); }, [site.id, site.last_probed_at]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (focus) document.getElementById(`cfg-${focus}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, [focus]);

    const template = useMemo(() => (meta.templates || []).find((t) => t.key === form.template_key) || null, [meta.templates, form.template_key]);
    const detected = site.site_profile?.detected_rules?.rules || {};
    const placeholderFor = (key) => (template?.rules?.[key] || (!form.template_key ? detected[key] : '') || '');
    const dirty = JSON.stringify(form) !== JSON.stringify(blankForm(site));

    const set = (patch) => setForm((f) => ({ ...f, ...patch }));
    const setRule = (key, value) => setForm((f) => ({ ...f, parser_rules: { ...f.parser_rules, [key]: value } }));
    const setCrawl = (patch) => setForm((f) => ({ ...f, crawl_settings: { ...f.crawl_settings, ...patch } }));

    const payload = () => ({ ...form, template_key: form.template_key || null });

    const save = useMutation({
        mutationFn: () => api.patch(`/crm/scraper/sites/${site.id}`, payload()).then((r) => r.data.site),
        onSuccess: (s) => { toast.success('Settings saved.'); onSaved(s); },
        onError: (e) => toast.error(errorMessage(e, 'Could not save settings.')),
    });

    const runTest = useMutation({
        mutationFn: () => api.post(`/crm/scraper/sites/${site.id}/probe`, {
            test_only: true,
            template_key: form.template_key || null,
            driver: form.fetch_driver,
            parser_rules: form.parser_rules,
            crawl_settings: form.crawl_settings,
        }).then((r) => r.data.profile),
        onSuccess: setTest,
        onError: (e) => toast.error(errorMessage(e, 'Test failed.')),
    });

    const disabled = !canManage;

    return (
        <div className="space-y-5">
            {!canManage ? (
                <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">You can test settings here; only admins and sub-admins can save them.</p>
            ) : null}

            <Section id="cfg-fetch" title="How pages are fetched">
                <div className="grid gap-2 sm:grid-cols-3">
                    {(meta.drivers || []).map((d) => (
                        <label key={d.key} className={`flex cursor-pointer flex-col rounded-lg border p-3 text-sm ${form.fetch_driver === d.key ? 'border-teal-600 bg-teal-50/60 ring-1 ring-teal-600' : 'border-slate-200 hover:bg-slate-50'} ${!d.available ? 'cursor-not-allowed opacity-60' : ''}`}>
                            <span className="flex items-center gap-2 font-semibold text-slate-900">
                                <input type="radio" name="driver" value={d.key} checked={form.fetch_driver === d.key} disabled={!d.available || disabled} onChange={() => set({ fetch_driver: d.key })} />
                                {d.key === 'http' ? 'Direct' : d.key === 'capture' ? 'Browser capture' : 'Render worker'}
                            </span>
                            <span className="mt-1 text-xs text-slate-500">{d.available ? d.label : 'Not configured yet'}</span>
                        </label>
                    ))}
                </div>
            </Section>

            <Section id="cfg-address" title="Site">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Name"><input className="crm-input" value={form.name} disabled={disabled} onChange={(e) => set({ name: e.target.value })} /></Field>
                    <Field label="Listing page address"><input className="crm-input crm-mono text-[13px]" value={form.source_url} disabled={disabled} onChange={(e) => set({ source_url: e.target.value })} /></Field>
                </div>
            </Section>

            <Section id="cfg-selectors" title="What to read" hint="Leave a field empty to use the template. CSS selectors, e.g. .card h3">
                <Field label="Template">
                    <select className="crm-select-enhanced sm:w-96" value={form.template_key} disabled={disabled} onChange={(e) => set({ template_key: e.target.value })}>
                        <option value="">Auto-detect from the page</option>
                        {(meta.templates || []).map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                    </select>
                </Field>
                <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {LIST_FIELDS.map(([key, label, hint]) => (
                        <Field key={key} label={label} hint={hint}>
                            <input className="crm-input crm-mono text-[12.5px]" value={form.parser_rules[key] || ''} placeholder={placeholderFor(key)} disabled={disabled} onChange={(e) => setRule(key, e.target.value)} />
                        </Field>
                    ))}
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <button type="button" className="crm-btn-secondary disabled:opacity-60" onClick={() => runTest.mutate()} disabled={runTest.isPending}>
                        {runTest.isPending ? <><Spinner className="mr-2 h-4 w-4" />Testing…</> : 'Test on the live page'}
                    </button>
                    {test ? (
                        <span className="text-sm text-slate-700" aria-live="polite">
                            <strong className="tabular-nums">{test.counts.rows}</strong> cards · <strong className="tabular-nums">{test.counts.phones}</strong> phones · <strong className="tabular-nums">{test.counts.links}</strong> links
                            {(test.extraction_errors || []).length ? <span className="ml-2 text-rose-700">{test.extraction_errors[0]}</span> : null}
                            {test.blockers?.[0] ? <span className="ml-2 text-amber-700">· {test.blockers[0].title}</span> : null}
                        </span>
                    ) : null}
                </div>
                {test?.sample?.length ? (
                    <ul className="mt-2 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">
                        {test.sample.slice(0, 6).map((r, i) => (
                            <li key={i} className="truncate rounded border border-slate-100 bg-slate-50 px-2 py-1"><span className="font-medium text-slate-800">{r.name || '—'}</span> · <span className="crm-mono">{r.phone ? `+${r.phone}` : 'no phone'}</span>{r.city ? ` · ${r.city}` : ''}</li>
                        ))}
                    </ul>
                ) : null}
            </Section>

            <Section id="cfg-pages" title="Pages and profiles">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Route">
                        <select className="crm-select-enhanced" value={form.crawl_settings.route || 'listing'} disabled={disabled} onChange={(e) => setCrawl({ route: e.target.value })}>
                            <option value="listing">Listing pages</option>
                            <option value="sitemap">Sitemap profile pages</option>
                        </select>
                    </Field>
                    <Field label="Max listing pages"><NumberInput value={form.crawl_settings.max_pages} min={1} max={meta.limits?.max_pages || 25} disabled={disabled} onChange={(v) => setCrawl({ max_pages: v })} /></Field>
                    <Field label="Max profile pages"><NumberInput value={form.crawl_settings.max_profiles} min={0} max={meta.limits?.max_profiles || 150} disabled={disabled} onChange={(v) => setCrawl({ max_profiles: v })} /></Field>
                    <Field label="Delay between pages (ms)"><NumberInput value={form.crawl_settings.delay_ms} min={meta.limits?.min_delay_ms || 500} max={meta.limits?.max_delay_ms || 15000} step={250} disabled={disabled} onChange={(v) => setCrawl({ delay_ms: v })} /></Field>
                </div>
                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <Field label="Next page link" hint={`Template: ${template?.pagination?.next_selector || 'rel=next'}`}>
                        <input className="crm-input crm-mono text-[12.5px]" value={form.crawl_settings.pagination.next_selector || ''} disabled={disabled} placeholder={template?.pagination?.next_selector || 'a[rel="next"]'} onChange={(e) => setCrawl({ pagination: { ...form.crawl_settings.pagination, next_selector: e.target.value } })} />
                    </Field>
                    <Field label="Page address pattern" hint="{base}page/{n}/ or {url}?page={n}">
                        <input className="crm-input crm-mono text-[12.5px]" value={form.crawl_settings.pagination.pattern || ''} disabled={disabled} placeholder={template?.pagination?.pattern || ''} onChange={(e) => setCrawl({ pagination: { ...form.crawl_settings.pagination, pattern: e.target.value } })} />
                    </Field>
                </div>
                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
                    <Toggle checked={!!form.crawl_settings.follow_profiles} disabled={disabled} onChange={(v) => setCrawl({ follow_profiles: v, max_profiles: v && !form.crawl_settings.max_profiles ? 40 : form.crawl_settings.max_profiles })} label="Open profile pages for phone numbers" />
                    <Toggle checked={form.crawl_settings.follow_only_missing_phone !== false} disabled={disabled || !form.crawl_settings.follow_profiles} onChange={(v) => setCrawl({ follow_only_missing_phone: v })} label="Only when the card has no phone" />
                    <Toggle checked={form.crawl_settings.stop_when_no_new !== false} disabled={disabled} onChange={(v) => setCrawl({ stop_when_no_new: v })} label="Stop when a page has nothing new" />
                </div>
            </Section>

            <Section id="cfg-headers" title="Cookies and headers" hint="E.g. the cookie a site sets after its age notice. Sent with every request.">
                <PairList label="Cookie" rows={form.crawl_settings.cookies} disabled={disabled} onChange={(rows) => setCrawl({ cookies: rows })} />
                <div className="mt-3"><PairList label="Header" rows={form.crawl_settings.headers} disabled={disabled} onChange={(rows) => setCrawl({ headers: rows })} /></div>
            </Section>

            <Section id="cfg-import" title="Importing">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Treat as the same lead when it matches">
                        <select className="crm-select-enhanced" value={form.dedupe_mode} disabled={disabled} onChange={(e) => set({ dedupe_mode: e.target.value })}>
                            {(meta.dedupe_modes || []).map((m) => <option key={m} value={m}>{DEDUPE_LABELS[m] || m}</option>)}
                        </select>
                    </Field>
                </div>
                <div className="mt-3 flex flex-col gap-2 text-sm text-slate-700">
                    <Toggle checked={form.is_active} disabled={disabled} onChange={(v) => set({ is_active: v })} label="Site is active" />
                    <Toggle checked={form.compliance_ack_robots} disabled={disabled} onChange={(v) => set({ compliance_ack_robots: v })} label="robots.txt checked" />
                    <Toggle checked={form.compliance_ack_tos} disabled={disabled} onChange={(v) => set({ compliance_ack_tos: v })} label="Terms checked" />
                </div>
            </Section>

            {canManage ? (
                <div className="sticky bottom-0 -mx-4 flex items-center justify-end gap-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
                    {dirty ? <span className="mr-auto text-xs text-amber-700">Unsaved changes</span> : null}
                    <button type="button" className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50" disabled={!dirty} onClick={() => { setForm(blankForm(site)); setTest(null); }}>Discard</button>
                    <button type="button" className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50" disabled={!dirty || save.isPending} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save settings'}</button>
                </div>
            ) : null}
        </div>
    );
}

function Section({ id, title, hint, children }) {
    return (
        <section id={id} className="scroll-mt-24 rounded-lg border border-slate-200 p-4">
            <h4 className="text-sm font-semibold text-slate-900">{title}</h4>
            {hint ? <p className="mt-0.5 text-xs text-slate-500">{hint}</p> : null}
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Field({ label, hint, children }) {
    return (
        <label className="block min-w-0">
            <span className="text-xs font-semibold text-slate-600">{label}</span>
            <div className="mt-1">{children}</div>
            {hint ? <span className="mt-0.5 block truncate text-[11px] text-slate-400">{hint}</span> : null}
        </label>
    );
}

function NumberInput({ value, onChange, min, max, step = 1, disabled }) {
    return <input type="number" className="crm-input tabular-nums" value={value ?? ''} min={min} max={max} step={step} disabled={disabled} onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))} />;
}

function Toggle({ checked, onChange, label, disabled }) {
    return (
        <label className={`inline-flex items-center gap-2 ${disabled ? 'opacity-60' : 'cursor-pointer'}`}>
            <input type="checkbox" className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-600" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            {label}
        </label>
    );
}

function PairList({ label, rows, onChange, disabled }) {
    const list = rows || [];
    return (
        <div className="space-y-2">
            {list.map((row, i) => (
                <div key={i} className="flex gap-2">
                    <input className="crm-input crm-mono text-[12.5px]" placeholder={`${label} name`} value={row.name} disabled={disabled} onChange={(e) => onChange(list.map((r, j) => (j === i ? { ...r, name: e.target.value } : r)))} />
                    <input className="crm-input crm-mono text-[12.5px]" placeholder="Value" value={row.value} disabled={disabled} onChange={(e) => onChange(list.map((r, j) => (j === i ? { ...r, value: e.target.value } : r)))} />
                    <button type="button" className="crm-btn-secondary px-3" disabled={disabled} aria-label={`Remove ${label.toLowerCase()}`} onClick={() => onChange(list.filter((_, j) => j !== i))}>×</button>
                </div>
            ))}
            <button type="button" className="text-sm font-semibold text-teal-700 hover:underline disabled:opacity-50" disabled={disabled} onClick={() => onChange([...list, { name: '', value: '' }])}>+ Add {label.toLowerCase()}</button>
        </div>
    );
}
