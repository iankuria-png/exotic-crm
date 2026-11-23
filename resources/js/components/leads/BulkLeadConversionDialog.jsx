import React, { useEffect, useRef, useState } from 'react';
import api from '../../services/api';
import './bulk-lead-conversion.css';

const label = (row) => {
    if (row.result?.status === 'created') return 'Client created';
    if (row.result?.status === 'already_converted') return 'Already converted';
    if (row.result?.status === 'failed') return 'Could not finish';
    if (row.result?.status === 'review_required') return 'Review again';
    if (row.result?.status === 'recovery_required') return 'Review individually';
    if (row.result?.status === 'running') return 'Creating client…';
    if (row.action === 'create') return 'Ready to create';
    if (row.action === 'existing_match') return 'Existing match';
    if (row.action === 'already_converted') return 'Already converted';
    return row.action === 'skipped' ? 'Skipped' : 'Needs attention';
};
const completed = (row) => ['created', 'already_converted'].includes(row.result?.status);

export default function BulkLeadConversionDialog({ leads, returnFocus, onClose, onComplete }) {
    const dialog = useRef(null);
    const runRef = useRef(false);
    const stopRef = useRef(false);
    const [rows, setRows] = useState([]);
    const [selected, setSelected] = useState(new Set());
    const [phase, setPhase] = useState('loading');
    const [error, setError] = useState('');
    const [filter, setFilter] = useState('all');
    const [reason, setReason] = useState('');
    const [stopRequested, setStopRequested] = useState(false);
    const [progress, setProgress] = useState({ done: 0, total: 0, name: '' });
    const [expires, setExpires] = useState(null);

    useEffect(() => {
        const previous = returnFocus || document.activeElement;
        const node = dialog.current;
        node?.showModal();
        const oldOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = oldOverflow;
            if (node?.open) node.close();
            requestAnimationFrame(() => {
                if (previous?.isConnected && !previous.disabled) previous.focus();
                else {
                    const heading = document.querySelector('main h1, h1');
                    heading?.setAttribute('tabindex', '-1');
                    heading?.focus();
                }
            });
        };
    }, []);

    const preview = async (signal) => {
        setPhase('loading');
        setError('');
        setFilter('all');
        if (leads.length > 100) {
            setError('Select up to 100 leads per batch. Reduce your selection and try again.');
            setPhase('error');
            return;
        }
        try {
            const { data } = await api.post('/crm/leads/bulk-convert/preview', { lead_ids: leads.map((lead) => lead.id) }, { signal });
            setRows(data.rows);
            setSelected(new Set(data.rows.filter((row) => row.action === 'create').map((row) => row.id)));
            setExpires(data.expires_at);
            setPhase('review');
        } catch (failure) {
            if (signal?.aborted) return;
            setError(failure?.response?.data?.message || 'We couldn’t check this selection. Try the review again.');
            setPhase('error');
        }
    };

    useEffect(() => {
        const controller = new AbortController();
        void preview(controller.signal);
        return () => controller.abort();
    }, []);

    useEffect(() => {
        if (phase !== 'running') return undefined;
        const preventLeave = (event) => { event.preventDefault(); event.returnValue = ''; };
        window.addEventListener('beforeunload', preventLeave);
        return () => window.removeEventListener('beforeunload', preventLeave);
    }, [phase]);

    const updateResult = (id, result) => setRows((current) => current.map((row) => row.id === id ? { ...row, result } : row));
    const run = async (queue) => {
        if (runRef.current || !queue.length) return;
        runRef.current = true;
        stopRef.current = false;
        setStopRequested(false);
        setError('');
        setFilter('all');
        setPhase('running');
        setProgress({ done: 0, total: queue.length, name: '' });
        let processed = 0;
        try {
            for (const row of queue) {
                if (stopRef.current) break;
                setProgress({ done: processed, total: queue.length, name: row.name });
                updateResult(row.id, { status: 'running' });
                try {
                    const { data } = await api.post(`/crm/leads/${row.id}/bulk-convert`, { review_token: row.review_token, reason: reason.trim() || null });
                    updateResult(row.id, data);
                } catch (failure) {
                    const status = failure?.response?.status;
                    const retryable = !status || status >= 500 || failure?.response?.data?.status === 'busy';
                    updateResult(row.id, {
                        status: retryable ? 'failed' : failure?.response?.data?.status === 'recovery_required' ? 'recovery_required' : 'review_required',
                        message: failure?.response?.data?.message || 'Connection lost. Retry to check whether this client was created.',
                    });
                    if (!status || [401, 403, 419, 429].includes(status)) {
                        stopRef.current = true;
                        setError('The batch stopped. Check your connection or access, then retry the remaining leads.');
                    }
                }
                processed += 1;
                setProgress({ done: processed, total: queue.length, name: '' });
            }
        } finally {
            runRef.current = false;
            setPhase('results');
            onComplete();
        }
    };

    const ready = rows.filter((row) => row.action === 'create');
    const matches = rows.filter((row) => row.action === 'existing_match');
    const attention = rows.filter((row) => !['create', 'existing_match'].includes(row.action));
    const queue = ready.filter((row) => selected.has(row.id) && !completed(row) && !row.result);
    const retry = ready.filter((row) => selected.has(row.id) && row.result?.status === 'failed');
    const success = rows.filter(completed);
    const needsReview = rows.filter((row) => ['review_required', 'recovery_required'].includes(row.result?.status));
    const visible = rows.filter((row) => filter === 'all' || (filter === 'ready' ? row.action === 'create' : row.action !== 'create'));
    const running = phase === 'running';
    const toggle = (id) => setSelected((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next; });
    const close = () => { if (!runRef.current) onClose(); };
    const trapFocus = (event) => {
        if (event.key !== 'Tab') return;
        const controls = Array.from(dialog.current.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),a[href]')).filter((node) => node.getClientRects().length);
        const first = controls[0], last = controls.at(-1);
        if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
            event.preventDefault();
            (event.shiftKey ? last : first)?.focus();
        }
    };
    const title = phase === 'results' ? (success.length ? `${success.length} ${success.length === 1 ? 'client' : 'clients'} ready for follow-up` : 'Your conversion results')
        : running ? 'Creating your clients' : 'Convert leads to clients';

    return (
        <dialog ref={dialog} className="lead-conversion-dialog" aria-labelledby="bulk-conversion-title" aria-describedby="bulk-conversion-description"
            onKeyDown={trapFocus} onCancel={(event) => { event.preventDefault(); close(); }}>
            <div className="lead-conversion-panel">
                <header className="lead-conversion-header">
                    <div>
                        <p className="lead-conversion-eyebrow">Leads <span aria-hidden="true">/</span> Bulk conversion</p>
                        <h2 id="bulk-conversion-title">{title}</h2>
                        <p id="bulk-conversion-description">{running ? 'Keep this window open. Each lead is saved as it finishes.'
                            : phase === 'results' ? 'Every outcome is listed below. Completed conversions are saved.'
                                : `${leads.length} selected · Review once, create clients together.`}</p>
                    </div>
                    <button type="button" className="lead-conversion-close" aria-label="Close bulk conversion" onClick={close} disabled={running}>×</button>
                </header>

                <div className="lead-conversion-body" aria-busy={phase === 'loading'}>
                    {phase === 'loading' ? (
                        <div className="lead-conversion-loading" role="status"><span className="lead-conversion-spinner" aria-hidden="true" /><h3>Checking your selection</h3><p>Looking for existing clients and missing details.</p></div>
                    ) : phase === 'error' ? (
                        <div className="lead-conversion-loading"><h3>We couldn’t prepare this batch</h3><p role="alert">{error}</p><button type="button" className="crm-btn-secondary" onClick={() => preview()}>Try review again</button></div>
                    ) : (
                        <>
                            <div className="lead-conversion-summary" aria-label="Selection summary">
                                <div><strong>{phase === 'results' ? success.length : ready.length}</strong><span>{phase === 'results' ? 'Completed' : 'Ready to create'}</span></div>
                                <div><strong>{matches.length}</strong><span>Existing matches</span></div>
                                <div><strong>{phase === 'results' ? retry.length + needsReview.length : attention.length}</strong><span>{phase === 'results' ? 'Need another look' : 'Skipped / attention'}</span></div>
                            </div>
                            {running ? (
                                <section className="lead-conversion-progress" aria-label="Conversion progress">
                                    <div><strong>{stopRequested ? 'Stopping after the current lead…' : progress.name ? `Creating ${progress.name}` : 'Preparing next lead…'}</strong><span>{progress.done} / {progress.total}</span></div>
                                    <progress max={Math.max(1, progress.total)} value={progress.done} aria-label="Leads processed" />
                                    <p role="status" aria-live="polite">{progress.done} of {progress.total} processed. {stopRequested ? 'Completed clients will stay saved.' : 'You can stop after the current lead.'}</p>
                                </section>
                            ) : (
                                <section className="lead-conversion-policy">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>
                                    <div><strong>Private profiles. Ready for your next step.</strong><p>New clients keep each lead’s market and owner. Existing matches stay unchanged. Conversion does not activate a plan or send a message.</p></div>
                                </section>
                            )}
                            {error ? <p className="lead-conversion-error" role="alert">{error}</p> : null}
                            {phase === 'review' && !ready.length ? <p className="lead-conversion-notice">No new clients can be created from this selection. Review the reasons below; use Reconcile selected for existing client matches.</p> : null}
                            <div className="lead-conversion-list-toolbar">
                                <div className="lead-conversion-tabs" role="group" aria-label="Filter conversion review">
                                    {[['all', `All ${rows.length}`], ['ready', `Ready ${ready.length}`], ['attention', `Review ${rows.length - ready.length}`]].map(([key, text]) => <button type="button" key={key} aria-pressed={filter === key} onClick={() => setFilter(key)}>{text}</button>)}
                                </div>
                                {phase === 'review' && ready.length ? <button type="button" className="lead-conversion-text-button" onClick={() => setSelected(selected.size ? new Set() : new Set(ready.map((row) => row.id)))}>{selected.size ? 'Deselect ready' : 'Select ready'}</button> : null}
                            </div>
                            <ul className="lead-conversion-list" aria-label="Lead conversion outcomes">
                                {visible.map((row) => (
                                    <li key={row.id} className={`lead-conversion-row ${row.result?.status === 'running' ? 'is-running' : ''}`}>
                                        <div className="lead-conversion-identity">
                                            {phase === 'review' && row.action === 'create' ? <input id={`bulk-create-lead-${row.id}`} type="checkbox" checked={selected.has(row.id)} onChange={() => toggle(row.id)} aria-label={`Create client for ${row.name}`} /> : <span className={`lead-conversion-mark ${completed(row) ? 'is-complete' : ''}`} aria-hidden="true">{completed(row) ? '✓' : row.action === 'create' ? '·' : '—'}</span>}
                                            <label htmlFor={phase === 'review' && row.action === 'create' ? `bulk-create-lead-${row.id}` : undefined}><strong>{row.name}</strong><p>{row.market} <span aria-hidden="true">·</span> {row.contact}</p><p className="lead-conversion-owner">{row.owner}</p></label>
                                        </div>
                                        <div className="lead-conversion-outcome"><span className={`lead-conversion-status ${completed(row) ? 'is-success' : ['failed', 'review_required', 'recovery_required'].includes(row.result?.status) ? 'is-warning' : ''}`}>{label(row)}</span>
                                            {(row.result?.client_id || row.client_id) && !running ? <a href={`/clients/${row.result?.client_id || row.client_id}`} target="_blank" rel="noreferrer">View client <span className="sr-only">for {row.name} (opens in a new tab)</span><span aria-hidden="true">↗</span></a> : null}
                                            {row.result?.message || row.action !== 'create' ? <p>{row.result?.message || row.message}</p> : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                            {!visible.length ? <p className="lead-conversion-notice">No leads in this view.</p> : null}
                            {phase === 'review' ? <label className="lead-conversion-note">Batch note <span>(optional)</span><input className="crm-input" maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Add context for the activity history" /></label> : null}
                        </>
                    )}
                </div>

                <footer className="lead-conversion-footer">
                    <p>{running ? 'Changes are saved one lead at a time.' : phase === 'review' ? `${selected.size} ${selected.size === 1 ? 'client' : 'clients'} will be created · Review valid for 30 minutes` : phase === 'results' ? `${success.length} completed · ${queue.length} not started` : 'Your leads stay unchanged until you confirm.'}</p>
                    <div>
                        {running ? <button type="button" className="crm-btn-secondary" disabled={stopRequested} onClick={() => { stopRef.current = true; setStopRequested(true); }}>Stop after current</button> : <button type="button" className="crm-btn-secondary" onClick={close}>{phase === 'results' ? 'Done' : 'Cancel'}</button>}
                        {phase === 'review' ? <button type="button" className="crm-btn-primary" disabled={!selected.size} onClick={() => run(ready.filter((row) => selected.has(row.id)))}>Create {selected.size} {selected.size === 1 ? 'client' : 'clients'} <span aria-hidden="true">→</span></button> : null}
                        {phase === 'results' && (queue.length || retry.length) ? <button type="button" className="crm-btn-primary" onClick={() => run([...retry, ...queue])}>{retry.length ? 'Retry / continue' : `Continue ${queue.length} remaining`}</button> : null}
                        {phase === 'results' && (needsReview.length || (expires && Date.parse(expires) <= Date.now())) ? <button type="button" className="crm-btn-secondary" onClick={() => preview()}>Review selection again</button> : null}
                    </div>
                </footer>
            </div>
        </dialog>
    );
}
