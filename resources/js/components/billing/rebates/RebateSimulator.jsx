import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../../services/api';
import { channelLabels, errorMessage, money } from '../RebatesTab';

export default function RebateSimulator({ marketId, draft, currency, products = [] }) {
    const [scenario, setScenario] = useState('topup');
    const [amount, setAmount] = useState(5000);
    const [activationAmount, setActivationAmount] = useState(6000);
    const [channel, setChannel] = useState('wallet');
    const [first, setFirst] = useState(true);
    const [earned, setEarned] = useState(0);
    const [quote, setQuote] = useState(null);
    const [error, setError] = useState('');
    const [pending, setPending] = useState(false);
    const [projectDraft, setProjectDraft] = useState(draft);
    useEffect(() => { const timer = setTimeout(() => setProjectDraft(draft), 700); return () => clearTimeout(timer); }, [draft]);
    const report = useQuery({ queryKey: ['rebates-projection', marketId, projectDraft], queryFn: ({ signal }) => api.post(`/crm/settings/billing/rebates/${marketId}/projection`, { draft: projectDraft }, { signal }).then(r => ({ projection: r.data })), staleTime: 30_000 });
    useEffect(() => {
        const aborter = new AbortController();
        setPending(true);
        const timer = setTimeout(async () => {
            try {
                const res = await api.post(`/crm/settings/billing/rebates/${marketId}/simulate`, { scenario, amount: Number(amount || 0), activation_amount: Number(activationAmount || 0), channel, first_topup: first, earned_this_month: Number(earned || 0), use: 'draft', draft }, { signal: aborter.signal });
                setQuote(res.data); setError('');
            } catch (e) { if (!aborter.signal.aborted) setError(errorMessage(e)); }
            finally { if (!aborter.signal.aborted) setPending(false); }
        }, 350);
        return () => { clearTimeout(timer); aborter.abort(); };
    }, [marketId, draft, scenario, amount, activationAmount, channel, first, earned]);
    return <aside className="rbt-rail"><section className="rbt-card rbt-simulator"><div className="rbt-eyebrow">Try the draft</div><h5>What would she get?</h5><div className="rbt-segment" aria-label="Simulation scenario">{['topup', 'activation', 'journey'].map(s => <button key={s} aria-pressed={scenario === s} onClick={() => setScenario(s)}>{s === 'topup' ? 'Top-up' : s === 'activation' ? 'Activation' : 'Journey'}</button>)}</div>
        {scenario !== 'activation' && <label className="rbt-field">Top-up amount<span className="rbt-input"><input type="number" min="0" value={amount} onChange={e => setAmount(e.target.value)} /><span>{currency}</span></span></label>}
        {scenario !== 'topup' && <><label className="rbt-field">Plan<select value="" onChange={e => { const price = products.find(p => String(p.id) === e.target.value); if (price) { setActivationAmount(price.price); if (scenario === 'activation') setAmount(price.price); } }}><option value="">Choose a plan or enter its price</option>{products.filter(p => p.currency === currency && p.is_active).map(p => <option key={p.id} value={p.id}>{p.product?.display_name || p.product?.name} · {p.duration_label} · {money(p.price, currency)}</option>)}</select></label><label className="rbt-field">Activation amount<input type="number" min="0" value={scenario === 'activation' ? amount : activationAmount} onChange={e => scenario === 'activation' ? setAmount(e.target.value) : setActivationAmount(e.target.value)} /></label><label className="rbt-field">Channel<select value={channel} onChange={e => setChannel(e.target.value)}>{Object.entries(channelLabels).filter(([k]) => k !== 'topup').map(([k, label]) => <option key={k} value={k}>{label}</option>)}</select></label></>}
        {scenario !== 'activation' && <label className="rbt-check"><input type="checkbox" checked={first} onChange={e => setFirst(e.target.checked)} />First top-up</label>}<label className="rbt-field">Already earned this month<input type="number" min="0" value={earned} onChange={e => setEarned(e.target.value)} /></label>
        <div className="rbt-quote" aria-live="polite" aria-busy={pending}><span>Wallet rebate {pending ? '· updating…' : ''}</span><strong>{error ? '—' : `+${money(quote?.total, currency)}`}</strong>{!error && quote?.lines?.map((line, i) => <div key={i}><span>{line.label}{line.rate != null ? ` · ${line.rate}%` : ''}</span><b>+{money(line.amount, currency)}</b></div>)}{!error && (quote?.capped_by || quote?.reason) && <small>{(quote.capped_by || quote.reason).replaceAll('_', ' ')}</small>}</div>{error && <p className="rbt-error" role="alert">{error}</p>}
        {scenario === 'journey' && quote && !error && <p className="rbt-hint">Effective reward: {Number(amount) > 0 ? (100 * quote.total / Number(amount)).toFixed(1) : 0}% of the top-up.</p>}<p className="rbt-hint">A preview. Settlement checks eligibility, caps and the budget again.</p></section>
        <section className="rbt-projection"><div className="rbt-eyebrow">Draft rules · real volume</div><h5>Projected monthly cost</h5>{report.isLoading ? <p role="status">Calculating…</p> : report.isError ? <p role="alert">{errorMessage(report.error)}</p> : <><strong>{money(report.data?.projection.total, currency)}</strong><p>{report.data?.projection.basis}</p>{report.data?.projection.rows.map(row => <div key={row.channel}><span>{channelLabels[row.channel]}</span><b>{money(row.projected, currency)}</b></div>)}{report.data?.projection.total > draft.guard.budget && <p className="rbt-warning">Projected cost exceeds this draft's budget. The budget would cap later rewards.</p>}{!report.data?.projection.rows.length && <p>No qualifying production payments in the last 30 days. Cost will become measurable as this market collects payments.</p>}</>}</section>
    </aside>;
}
