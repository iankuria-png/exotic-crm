import React, { useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';
import RebateProgramEditor from './rebates/RebateProgramEditor';
import RebateSimulator from './rebates/RebateSimulator';
import RebatePerformance from './rebates/RebatePerformance';
import RebateLedger from './rebates/RebateLedger';
import './rebates/rebates.css';

export const channelLabels = { topup: 'Self-paid top-up', wallet: 'Wallet balance', self_checkout: 'Dashboard mobile money', manual_submission: 'Payment-proof upload', staff_link: 'Staff payment link', sales_assisted: 'Sales-assisted', auto_renew: 'Automatic wallet renewal' };
export const money = (n, currency = 'KES') => `${currency} ${Number(n || 0).toLocaleString('en', { maximumFractionDigits: 2 })}`;
export const errorMessage = (e) => Object.values(e?.response?.data?.errors || {}).flat()[0] || e?.response?.data?.message || 'Could not reach CRM. Try again.';

function flatten(o, prefix = '') {
    return Object.entries(o || {}).flatMap(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        return value && typeof value === 'object' && !Array.isArray(value) ? flatten(value, path) : [[path, value]];
    });
}

export default function RebatesTab({ platforms = [] }) {
    const [marketId, setMarketId] = useState('');
    const [tab, setTab] = useState('program');
    const [draft, setDraft] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState('');
    const dialogRef = useRef(null);
    const reasonRef = useRef(null);
    const toast = useToast();
    const cache = useQueryClient();
    const key = ['rebates-program', marketId];
    const url = `/crm/settings/billing/rebates/${marketId}`;
    const query = useQuery({ queryKey: key, queryFn: () => api.get(url).then(r => r.data), enabled: !!marketId, staleTime: 60_000, refetchOnWindowFocus: false });
    useEffect(() => { if (query.data) setDraft(previous => previous ?? structuredClone(query.data.program.draft_json)); }, [query.data]);
    useEffect(() => {
        if (dialog) { dialogRef.current?.showModal(); reasonRef.current?.focus(); }
        else if (dialogRef.current?.open) dialogRef.current.close();
    }, [dialog]);
    const data = query.data;
    const dirty = !!draft && JSON.stringify(draft) !== JSON.stringify(data?.program.draft_json);
    const published = data?.published;
    const changes = draft ? flatten(draft).filter(([key, value]) => JSON.stringify(value) !== JSON.stringify(Object.fromEntries(flatten(published))[key])) : [];
    const openDialog = (action) => { setFailure(''); setReason(''); setDialog(action); };
    const save = async () => {
        const res = await api.put(url, { draft, draft_revision: data.program.draft_revision });
        cache.setQueryData(key, res.data);
        setDraft(structuredClone(res.data.program.draft_json));
        return res.data;
    };
    const submit = async () => {
        setBusy(true); setFailure('');
        try {
            let current = data;
            if (dialog === 'publish') {
                if (dirty) current = await save();
                const res = await api.post(`${url}/publish`, { draft_revision: current.program.draft_revision, reason });
                cache.setQueryData(key, res.data);
                if (res.data.sync?.status === 'failed') toast.error('Published in CRM. WordPress sync failed; retry from Wallet Rules.');
                else toast.success('Rebate revision published.');
            } else {
                const res = await api.post(`${url}/pause`, { paused: dialog === 'pause', reason });
                cache.setQueryData(key, res.data);
                if (res.data.sync?.status === 'failed') toast.error('Program updated in CRM. WordPress sync failed.');
                else toast.success(dialog === 'pause' ? 'Rebates paused. Payments continue normally.' : 'Rebates resumed.');
            }
            cache.invalidateQueries({ queryKey: ['rebates-performance', marketId] });
            setDialog(null);
        } catch (e) { setFailure(errorMessage(e)); }
        finally { setBusy(false); }
    };
    const saveDraft = async () => {
        setBusy(true); setFailure('');
        try { await save(); toast.success('Draft saved. Published rules stay in effect.'); }
        catch (e) { setFailure(errorMessage(e)); }
        finally { setBusy(false); }
    };
    return <div className="rbt" data-rebates-workspace>
        <div className="rbt-market"><label htmlFor="rebate-market">Market</label><select id="rebate-market" value={marketId} onChange={e => {
            if (dirty && !window.confirm('Leave this market and discard unsaved changes?')) return;
            setDraft(null); setFailure(''); setMarketId(e.target.value);
        }}><option value="">Choose a market</option>{platforms.map(p => <option value={p.id} key={p.id}>{p.name}</option>)}</select></div>
        {!marketId ? <div className="rbt-empty"><span className="rbt-empty-symbol" aria-hidden="true">↗</span><h4>Make paying herself the best deal.</h4><p>Choose a market to set its wallet rebates, preview the cost, and review every reward.</p></div> : query.isLoading ? <p role="status" className="rbt-empty">Loading rebate program…</p> : query.isError ? <div className="rbt-error" role="alert">{errorMessage(query.error)} <button onClick={() => query.refetch()}>Retry</button></div> : draft && <>
            <header className="rbt-header"><div><div className="rbt-eyebrow">{data.market.name} <span className={`rbt-state ${data.program.kill_switch ? 'is-paused' : ''}`}>{data.program.kill_switch ? 'Paused' : data.program.rollout_mode}</span> <span>Revision {data.program.published_revision || '—'}</span></div><h4>{data.market.name} rebate program</h4><p>Reward the channels that work for this market. Every credit has a payment and a reason.</p></div><div className="rbt-actions">{data.editable && <><button disabled={busy || !published} onClick={() => openDialog(data.program.kill_switch ? 'resume' : 'pause')}>{data.program.kill_switch ? 'Resume program' : 'Pause program'}</button><button className="rbt-primary" disabled={busy || (!changes.length && published)} onClick={() => openDialog('publish')}>Publish changes</button></>}</div></header>
            <nav className="rbt-tabs" aria-label="Rebate views">{['program', 'performance', 'ledger'].map(t => <button key={t} aria-current={tab === t ? 'page' : undefined} onClick={() => setTab(t)}>{t[0].toUpperCase() + t.slice(1)}</button>)}</nav>
            {failure && !dialog && <div className="rbt-error" role="alert">{failure}</div>}
            {tab === 'program' && <div className="rbt-layout"><RebateProgramEditor draft={draft} setDraft={setDraft} editable={data.editable && !busy} currency={data.program.currency} budget={data.budget} conflict={data.conflict} /><RebateSimulator marketId={marketId} draft={draft} currency={data.program.currency} products={data.products} /></div>}
            {tab === 'performance' && <RebatePerformance marketId={marketId} currency={data.program.currency} />}
            {tab === 'ledger' && <RebateLedger marketId={marketId} currency={data.program.currency} editable={data.editable} />}
            {dirty && data.editable && <div className="rbt-dirty" role="status"><div><strong>Unpublished changes</strong><span>Payments still use revision {data.program.published_revision || '—'}.</span></div><div className="rbt-actions"><button disabled={busy} onClick={() => setDraft(structuredClone(data.program.draft_json))}>Discard</button><button disabled={busy} onClick={saveDraft}>{busy ? 'Saving…' : 'Save draft'}</button><button className="rbt-primary" disabled={busy} onClick={() => openDialog('publish')}>Review & publish</button></div></div>}
            <details className="rbt-history"><summary>Revision history <span>{data.revisions.length}</span></summary>{data.revisions.length ? data.revisions.map(r => <div key={r.id}><b>Revision {r.revision} · {r.action}</b><time>{new Date(r.created_at).toLocaleString()}</time><p>{r.reason}</p><details><summary>Field changes</summary><pre>{JSON.stringify(r.diff_json, null, 2)}</pre></details></div>) : <p>Publish your first revision to start the audit trail.</p>}</details>
        </>}
        <dialog className="rbt-dialog" ref={dialogRef} onCancel={e => { if (busy) e.preventDefault(); else setDialog(null); }} onClose={() => { if (!busy) setDialog(null); }}>
            <form onSubmit={e => { e.preventDefault(); submit(); }}><div className="rbt-dialog-head"><h4>{dialog === 'publish' ? 'Review this revision' : dialog === 'pause' ? 'Pause rebates' : 'Resume rebates'}</h4><button type="button" aria-label="Close" disabled={busy} onClick={() => setDialog(null)}>×</button></div>
                {dialog === 'publish' ? <><p>These rules take effect after publishing. Drafts never affect payments.</p><dl className="rbt-diff">{changes.map(([key, value]) => <div key={key}><dt>{key.replaceAll('_', ' ').replaceAll('.', ' › ')}</dt><dd><del>{JSON.stringify(Object.fromEntries(flatten(published))[key]) ?? '—'}</del><b>{JSON.stringify(value)}</b></dd></div>)}</dl></> : <p>{dialog === 'pause' ? 'Stop new rebates immediately. The existing self-service discount is restored. Wallet payments continue normally.' : 'Resume the last published rules. This leaves your saved draft unchanged.'}</p>}
                <label className="rbt-field">Reason<textarea ref={reasonRef} value={reason} onChange={e => setReason(e.target.value)} minLength={5} maxLength={1000} required placeholder="What changed, and why?" /></label>{failure && <p role="alert" className="rbt-error">{failure}</p>}<div className="rbt-actions"><button type="button" disabled={busy} onClick={() => setDialog(null)}>Cancel</button><button className="rbt-primary" disabled={busy || reason.trim().length < 5}>{busy ? 'Applying…' : dialog === 'publish' ? 'Publish revision' : dialog === 'pause' ? 'Pause now' : 'Resume now'}</button></div>
            </form>
        </dialog>
    </div>;
}
