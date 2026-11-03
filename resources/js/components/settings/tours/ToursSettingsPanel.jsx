import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import './tours-settings.css';

const digits = currency => new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits;
const priceText = pass => (pass.amount_minor / (10 ** digits(pass.currency))).toFixed(digits(pass.currency));
const minor = (value, currency) => {
    const places = digits(currency);
    const match = String(value).trim().match(places ? new RegExp(`^(\\d+)(?:\\.(\\d{0,${places}}))?$`) : /^(\d+)$/);
    if (!match) return null;
    const amount = Number(match[1]) * (10 ** places) + Number((match[2] || '').padEnd(places, '0'));
    return Number.isSafeInteger(amount) && amount > 0 ? amount : null;
};
const dateText = value => value ? new Date(value).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Not published yet';
const regionNames = { gulf: 'Dubai & Gulf', europe: 'Europe', africa: 'African countries', hub: 'Regional hub' };

function Switch({ label, note, checked, onChange, disabled }) {
    return <label className="tour-settings-switch"><span><strong>{label}</strong>{note && <small>{note}</small>}</span><input type="checkbox" role="switch" checked={checked} onChange={e => onChange(e.target.checked)} disabled={disabled} /></label>;
}

export default function ToursSettingsPanel({ canWrite = false }) {
    const client = useQueryClient();
    const [market, setMarket] = useState('');
    const [draft, setDraft] = useState(null);
    const [revision, setRevision] = useState(0);
    const [dirty, setDirty] = useState(false);
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');
    const [fields, setFields] = useState({});
    const [priceInputs, setPriceInputs] = useState({});
    const [priceErrors, setPriceErrors] = useState({});
    const [openPass, setOpenPass] = useState(null);
    const [region, setRegion] = useState('all');
    const heading = useRef(null);
    const markets = useQuery({ queryKey: ['tour-settings-markets'], queryFn: () => api.get('/crm/tours/markets').then(r => r.data.data) });
    const configuration = useQuery({ queryKey: ['tour-settings', market], enabled: !!market, queryFn: () => api.get(`/crm/tours/${market}/settings`).then(r => r.data) });
    const selectedMarket = (markets.data || []).find(item => String(item.id) === market);
    const allDestinations = (markets.data || []).filter(item => String(item.id) !== market);
    const current = configuration.data;
    useEffect(() => {
        if (!market && markets.data?.length) {
            setMarket(String((markets.data.find(item => ['kenya', 'ke', 'ken'].includes(String(item.country).toLowerCase())) || markets.data[0]).id));
        }
    }, [market, markets.data]);
    useEffect(() => {
        if (current && !dirty) { setDraft(structuredClone(current.settings)); setRevision(current.revision); }
    }, [current, dirty]);
    useEffect(() => {
        if (!dirty) return;
        const leave = event => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', leave);
        return () => window.removeEventListener('beforeunload', leave);
    }, [dirty]);
    const apply = data => {
        setDraft(structuredClone(data.settings)); setRevision(data.revision); setDirty(false); setFields({}); setPriceInputs({}); setPriceErrors({}); setError('');
        client.setQueryData(['tour-settings', market], data);
    };
    const onError = err => {
        setNotice(''); setError(err.response?.data?.message || 'Could not complete this action. Please try again.');
        setFields(err.response?.data?.errors || {});
        if (err.response?.data?.configuration) client.setQueryData(['tour-settings', market], err.response.data.configuration);
    };
    const save = useMutation({ mutationFn: () => api.put(`/crm/tours/${market}/settings`, { expected_revision: revision, settings: draft }).then(r => r.data), onSuccess: data => { apply(data); setNotice('Saved in CRM. Publish when you are ready to apply these settings to the website.'); }, onError });
    const publish = useMutation({ mutationFn: () => api.post(`/crm/tours/${market}/publish`, { expected_revision: revision }).then(r => r.data), onSuccess: data => { apply(data); setNotice('Published and independently verified on the website.'); }, onError });
    const busy = save.isPending || publish.isPending;
    const change = patch => { setDraft(value => ({ ...value, ...patch })); setDirty(true); setNotice(''); setError(''); setFields({}); };
    const passChange = (index, patch) => change({ passes: draft.passes.map((pass, i) => i === index ? { ...pass, ...patch } : pass) });
    const fieldError = key => fields[`settings.${key}`]?.[0];
    const catalogue = useMemo(() => (draft?.passes || []).map((pass, index) => ({ pass, index })).filter(({ pass }) => region === 'all' || pass.region === region), [draft, region]);
    const format = pass => new Intl.NumberFormat(undefined, { style: 'currency', currency: pass.currency, maximumFractionDigits: digits(pass.currency) }).format(pass.amount_minor / (10 ** digits(pass.currency)));
    const addPass = () => {
        let n = draft.passes.length + 1;
        while (draft.passes.some(pass => pass.code === `pass_${n}`)) n++;
        change({ passes: [...draft.passes, { code: `pass_${n}`, name: '', region: region === 'all' ? 'africa' : region, currency: current.currencies[0] || 'KES', amount_minor: 100, days: 7, market_limit: null, destination_platform_ids: [], enabled: false }] });
        setOpenPass(draft.passes.length); setRegion('all');
    };
    const exportCatalogue = () => {
        const cell = value => {
            let text = String(value ?? '');
            if (/^[=+@-]/.test(text)) text = "'" + text;
            return '"' + text.replaceAll('"', '""') + '"';
        };
        const rows = [['Reference', 'Pass', 'Group', 'Currency', 'Price', 'Days', 'Markets covered', 'Destination sites', 'Available'], ...draft.passes.map(pass => [pass.code, pass.name, regionNames[pass.region], pass.currency, priceText(pass), pass.days, pass.market_limit ?? 'No limit', pass.destination_platform_ids.map(id => allDestinations.find(item => item.id === id)?.domain || id).join('; '), pass.enabled ? 'Yes' : 'No'])];
        const url = URL.createObjectURL(new Blob(['\uFEFF' + rows.map(row => row.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = `tour-prices-${market}.csv`; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    const changeMarket = value => {
        if (dirty && !window.confirm('Discard your unsaved Tours changes and change market?')) return;
        setMarket(value); setDraft(null); setDirty(false); setNotice(''); setError(''); setFields({}); setPriceInputs({}); setPriceErrors({}); setOpenPass(null); setRegion('all');
    };
    if (markets.isLoading) return <div className="tour-settings-state" role="status">Loading tour markets…</div>;
    if (markets.isError) return <div className="tour-settings-state" role="alert">We could not load your markets. <button onClick={() => markets.refetch()}>Try again</button></div>;
    return <section className="tour-settings" aria-labelledby="tour-settings-title">
        <header className="tour-settings-head">
            <div><span className="tour-settings-eyebrow">DESTINATIONS & PRICING</span><h2 id="tour-settings-title" ref={heading}>A little further.</h2><p>Decide where profiles can travel, and what each pass costs.</p></div>
            <label className="tour-settings-market">Home market<select value={market} onChange={e => changeMarket(e.target.value)} disabled={busy}>{(markets.data || []).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
        </header>
        {!markets.data?.length ? <div className="tour-settings-state">No markets are available to your account.</div> : configuration.isError ? <div className="tour-settings-state" role="alert">Could not load settings. <button onClick={() => configuration.refetch()}>Try again</button></div> : configuration.isLoading || !draft ? <div className="tour-settings-state" role="status">Loading this market’s Tours settings…</div> : <>
            <div className="tour-settings-status"><span className={`tour-settings-status-mark ${current.publication_status === 'published' ? 'is-published' : ''}`}></span><div><strong>{dirty ? 'Unsaved changes' : current.publication_status === 'published' ? 'Website settings verified' : current.publication_status === 'failed' ? 'Website publication needs attention' : 'Saved settings are not published'}</strong><span>{current.last_synced_at ? `Last verified ${dateText(current.last_synced_at)}` : 'Saving keeps a draft in CRM. Publishing applies it to this market’s website.'}</span></div><span className="tour-settings-revision">{selectedMarket?.domain || selectedMarket?.name}</span></div>
            {notice && <div className="tour-settings-notice" role="status">{notice}</div>}
            {(error || current.last_sync_error) && <div className="tour-settings-error" role="alert">{error || current.last_sync_error}{Object.keys(fields).length > 0 && <ul>{Object.entries(fields).map(([key, messages]) => <li key={key}>{messages[0]}</li>)}</ul>}</div>}
            <div className="tour-settings-preferences">
                <section className="tour-settings-availability"><h3>Availability</h3><label className="tour-settings-mode">Payment mode<select value={draft.environment} disabled={!canWrite || busy} onChange={e => change({ environment: e.target.value })}><option value="sandbox">Test mode — no live destination access</option><option value="production">Live payments</option></select><small>Use test mode for release checks. Live mode still requires working checkout and destination support.</small></label><Switch label="Tours" note="Turn tour booking and public tour visibility on or off for this market." checked={draft.enabled} onChange={enabled => change({ enabled })} disabled={!canWrite || busy} /><Switch label="Free local tours" note="Accept new trips within the home market. Saved trips keep their dates while Tours is on." checked={draft.local_enabled} onChange={local_enabled => change({ local_enabled })} disabled={!canWrite || busy} /><Switch label="A number for each stop" note="Let travellers choose a different contact number for a destination." checked={draft.allow_custom_contact} onChange={allow_custom_contact => change({ allow_custom_contact })} disabled={!canWrite || busy} /></section>
                <section className="tour-settings-route"><h3>A manageable route</h3><p>Keep itineraries focused. Local tours are always free.</p><label className="tour-settings-mode">Local destination level<select value={draft.local_destination_depth} disabled={!canWrite || busy} onChange={e => change({ local_destination_depth: Number(e.target.value) })}><option value={0}>Top-level locations</option><option value={1}>Second-level locations</option><option value={2}>Third-level locations</option></select><small>Match the city or region level on this market’s website. Kenya uses top-level cities.</small></label><div className="tour-settings-limits"><label>Stops per trip<input type="number" min="1" max="10" value={draft.max_stops} disabled={!canWrite || busy} onChange={e => change({ max_stops: e.target.value === '' ? '' : Number(e.target.value) })} />{fieldError('max_stops') && <small role="alert">{fieldError('max_stops')}</small>}</label><label>Longest trip<span className="tour-settings-suffix"><input type="number" min="1" max="90" value={draft.max_span_days} disabled={!canWrite || busy} onChange={e => change({ max_span_days: e.target.value === '' ? '' : Number(e.target.value) })} /><span>days</span></span>{fieldError('max_span_days') && <small role="alert">{fieldError('max_span_days')}</small>}</label></div><p className="tour-settings-policy">Paid passes keep their own end date. A home subscription ending does not end a paid destination pass.</p></section>
            </div>
            <section className="tour-settings-receiver"><div><span className="tour-settings-eyebrow">WELCOME VISITING PROFILES</span><h3>Who can travel here?</h3><p>Accept paid visiting profiles only from the home markets you trust.</p></div><Switch label="Accept visiting profiles" note="Destination access still follows the paid pass, its dates and account safety checks." checked={draft.receiver_enabled} onChange={receiver_enabled => change({ receiver_enabled })} disabled={!canWrite || busy} />
                {(draft.receiver_sources || []).map((source, index) => <div className="tour-settings-source" key={source.platform_id}><header><div><strong>{allDestinations.find(item => item.id === source.platform_id)?.name || 'Home market'}</strong><small>{source.origin}</small></div>{canWrite && <button className="tour-settings-remove" disabled={busy} onClick={() => change({ receiver_sources: draft.receiver_sources.filter((_, i) => i !== index) })}>Remove home market</button>}</header><label>Approved media websites<textarea value={source.media_origins.join('\n')} disabled={!canWrite || busy} rows={2} onChange={e => change({ receiver_sources: draft.receiver_sources.map((item, i) => i === index ? { ...item, media_origins: e.target.value.split('\n') } : item) })} /></label><small>One HTTPS website per line, including any approved media CDN. Use the website origin only, with no path.</small></div>)}
                {canWrite && <label className="tour-settings-add-source">Add a trusted home market<select value="" disabled={busy} onChange={e => { const item = allDestinations.find(item => item.id === Number(e.target.value)); if (item?.origin) change({ receiver_sources: [...draft.receiver_sources, { platform_id: item.id, origin: item.origin, media_origins: [item.origin] }] }); }}><option value="">Choose a market…</option>{allDestinations.filter(item => item.origin && !(draft.receiver_sources || []).some(source => source.platform_id === item.id)).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
            </section>
            <section className="tour-settings-catalogue" aria-labelledby="tour-pass-title"><header><div><span className="tour-settings-eyebrow">THE PASS COLLECTION</span><h3 id="tour-pass-title">Their next destination. Your terms.</h3><p>Every price, duration and destination can be changed here.</p></div><div className="tour-settings-catalogue-actions">{draft.passes.length > 0 && <button className="tour-settings-button" onClick={exportCatalogue}>Export prices</button>}{canWrite && <button className="tour-settings-button" onClick={addPass} disabled={busy || draft.passes.length >= 32}>+ Add a pass</button>}</div></header>
                <p className="tour-settings-readiness">International checkout and profile distribution will open after the destination sites pass their release checks. You can prepare the catalogue now.</p>
                {draft.passes.length > 0 && <div className="tour-settings-filters" aria-label="Filter passes">{['all', ...Object.keys(regionNames)].map(key => <button key={key} aria-pressed={region === key} onClick={() => setRegion(key)}>{key === 'all' ? 'All passes' : regionNames[key]}<span>{draft.passes.filter(pass => key === 'all' || pass.region === key).length}</span></button>)}</div>}
                {!draft.passes.length ? <div className="tour-settings-empty"><svg viewBox="0 0 80 50" aria-hidden="true"><path d="M4 40C22 40 17 7 42 9S53 38 76 13"/><circle cx="4" cy="40" r="3"/><circle cx="76" cy="13" r="3"/></svg><h4>Choose where the story goes next.</h4><p>Add a pass with its price, length and destination sites. It stays unavailable until you choose otherwise.</p>{canWrite && current.starter_passes?.length > 0 && <button className="tour-settings-button" disabled={busy} onClick={() => { change({ passes: structuredClone(current.starter_passes) }); setOpenPass(0); }}>Use the approved Kenya prices</button>}<small>Dubai and Europe: 1 week, 2 weeks, or 1 month (30 days). All fields remain editable.</small></div> : !catalogue.length ? <p className="tour-settings-state">No passes in this group.</p> : <div className="tour-settings-pass-list">{catalogue.map(({ pass, index }) => <article key={index} className={`tour-settings-pass ${openPass === index ? 'is-open' : ''}`}>
                    <button className="tour-settings-pass-summary" aria-expanded={openPass === index} aria-controls={`tour-pass-${index}`} onClick={() => setOpenPass(openPass === index ? null : index)}><span className="tour-settings-pass-number">{String(index + 1).padStart(2, '0')}</span><span className="tour-settings-pass-title"><strong>{pass.name || 'Name your pass'}</strong><small>{regionNames[pass.region]} · {pass.days} days · {pass.destination_platform_ids.length} destination {pass.destination_platform_ids.length === 1 ? 'site' : 'sites'}</small></span><span className="tour-settings-pass-price">{format(pass)}<small>{pass.enabled ? 'Available when ready' : 'Unavailable'}</small></span><span className="tour-settings-expand" aria-hidden="true">{openPass === index ? '−' : '+'}</span></button>
                    {openPass === index && <div id={`tour-pass-${index}`} className="tour-settings-pass-editor"><div className="tour-settings-pass-fields"><label>Pass name<input value={pass.name} maxLength="80" disabled={!canWrite || busy} onChange={e => passChange(index, { name: e.target.value })} /></label><label>Destination group<select value={pass.region} disabled={!canWrite || busy} onChange={e => passChange(index, { region: e.target.value })}>{Object.entries(regionNames).map(([key, name]) => <option key={key} value={key}>{name}</option>)}</select></label><label>Price<input type="text" inputMode="decimal" aria-label="Price" value={priceInputs[index] ?? priceText(pass)} aria-invalid={!!priceErrors[index]} aria-describedby={priceErrors[index] ? `tour-price-error-${index}` : undefined} disabled={!canWrite || busy} onChange={e => { const value = e.target.value; const amount = minor(value, pass.currency); setPriceInputs(old => ({ ...old, [index]: value })); setPriceErrors(old => { const next = { ...old }; if (amount === null) next[index] = `Enter a positive price with at most ${digits(pass.currency)} decimal places.`; else delete next[index]; return next; }); if (amount !== null) passChange(index, { amount_minor: amount }); else { setDirty(true); setNotice(''); } }} />{priceErrors[index] && <small id={`tour-price-error-${index}`} role="alert">{priceErrors[index]}</small>}</label><label>Currency<select value={pass.currency} disabled={!canWrite || busy} onChange={e => passChange(index, { currency: e.target.value })}>{current.currencies.map(code => <option key={code}>{code}</option>)}</select></label><label>Length in days<input type="number" min="1" max="90" value={pass.days} disabled={!canWrite || busy} onChange={e => passChange(index, { days: e.target.value === '' ? '' : Number(e.target.value) })} /></label><label>Markets covered<input type="number" min="1" max="100" placeholder="No limit" value={pass.market_limit ?? ''} disabled={!canWrite || busy} onChange={e => passChange(index, { market_limit: e.target.value === '' ? null : Number(e.target.value) })} /></label></div><fieldset className="tour-settings-destinations"><legend>Destination websites</legend><p>Choose the actual sites included in this pass. Each site must support visiting profiles before sales can open.</p><div>{allDestinations.filter(item => item.is_active || pass.destination_platform_ids.includes(item.id)).map(item => <label key={item.id}><input type="checkbox" checked={pass.destination_platform_ids.includes(item.id)} disabled={!canWrite || busy} onChange={e => passChange(index, { destination_platform_ids: e.target.checked ? [...pass.destination_platform_ids, item.id] : pass.destination_platform_ids.filter(id => id !== item.id) })} /><span><strong>{item.name}</strong><small>{item.domain}{!item.is_active ? ' · inactive' : ''}</small></span></label>)}</div></fieldset><div className="tour-settings-pass-bottom"><Switch label="Make this pass available" note="Sales also require working checkout and destination support." checked={pass.enabled} onChange={enabled => passChange(index, { enabled })} disabled={!canWrite || busy} />{canWrite && <button className="tour-settings-remove" disabled={busy} onClick={() => { change({ passes: draft.passes.filter((_, i) => i !== index) }); setPriceInputs({}); setPriceErrors({}); setOpenPass(null); }}>Remove pass</button>}</div><details className="tour-settings-reference"><summary>Pass reference</summary><label>Unique reference<input value={pass.code} maxLength="40" disabled={!canWrite || busy} onChange={e => passChange(index, { code: e.target.value })} /></label><small>Use lowercase letters, numbers and underscores. Keep this reference stable once a pass has been sold.</small></details></div>}
                </article>)}</div>}
            </section>
            <footer className="tour-settings-actions"><div><strong>{dirty ? 'You have unsaved changes' : current.publication_status === 'published' ? 'Your saved settings match the website' : 'Ready when you are'}</strong><span>{canWrite ? 'Save your draft, then publish it to the selected market.' : 'You can view this catalogue. An administrator can update it.'}</span></div>{canWrite && <div><button className="tour-settings-button" disabled={busy || !dirty || Object.keys(priceErrors).length > 0} onClick={() => save.mutate()}>{save.isPending ? 'Saving…' : 'Save in CRM'}</button><button className="tour-settings-button is-primary" disabled={busy || dirty || !revision} onClick={() => publish.mutate()}>{publish.isPending ? 'Publishing & checking…' : 'Publish to website'}</button></div>}</footer>
        </>}
    </section>;
}
