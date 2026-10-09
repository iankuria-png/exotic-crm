import React, { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { apiError, CopyId, ErrorState, fmtDateTime, humanize, InertCode, Loading, Panel, Status } from './shared';

const LABELS = {
    lock_account: ['Lock account', 'Replace the password, clear its reset key and end sessions. Password reset remains possible.'],
    revoke_app_password: ['Revoke selected key', 'Remove every observed key in this exact name group. Other keys remain active.'],
    end_sessions: ['End sessions', 'Sign this account out. Staff logout requires a second confirmation.'],
    clear_user_level: ['Clear stale user level', 'Set this site’s legacy level to zero without changing roles.'],
    remove_hidden_link: ['Remove hidden widget link', 'Remove the supported off-screen block and preserve surrounding widget content.'],
    deactivate_dangling_plugin_entry: ['Deactivate absent plugin entry', 'Remove the database entry after an independent recent disk check. No file deletion.'],
};
const ACTIVE = ['approved', 'executing', 'waiting_for_scan', 'waiting_for_paused_scan', 'commit_intent', 'committed', 'outcome_unknown', 'recovery_pending', 'cache_pending'];
const key = () => crypto.randomUUID();

export function OperationResult({ initial, onChanged }) {
    const [operation, setOperation] = useState(initial);
    const [confirmation, setConfirmation] = useState('');
    const [privilege, setPrivilege] = useState('');
    const [error, setError] = useState(null);
    const [now, setNow] = useState(Date.now());
    const queryClient = useQueryClient();
    const mutationKey = useRef(key());
    useEffect(() => { setOperation(initial); setConfirmation(''); setPrivilege(''); setError(null); mutationKey.current = key(); }, [initial.id]);
    useEffect(() => { const timer = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(timer); }, []);
    const query = useQuery({ queryKey: ['dbo', 'operation', operation.id], queryFn: () => dbObservatory.operation(operation.id), refetchInterval: ACTIVE.includes(operation.status) ? 3000 : false });
    useEffect(() => { if (query.data?.operation) { setOperation(query.data.operation); queryClient.invalidateQueries({ queryKey: ['dbo', 'finding'] }); } }, [query.data]);
    const action = useMutation({
        mutationFn: (kind) => {
            if (kind === 'restore') return dbObservatory.restorePreview(operation.id, { request_key: mutationKey.current });
            if (kind === 'cancel') return dbObservatory.cancelOperation(operation.id);
            if (kind === 'verify') return dbObservatory.verifyOperation(operation.id);
            return dbObservatory.confirmOperation(operation.id, { confirmation, privilege_confirmation: privilege || null, preview_digest: operation.preview_digest });
        },
        onSuccess: (data, kind) => { setError(null); setOperation(data.operation); if (kind === 'restore') { setConfirmation(''); setPrivilege(''); } mutationKey.current = key(); queryClient.invalidateQueries({ queryKey: ['dbo'] }); onChanged?.(); },
        onError: (e) => setError(apiError(e)),
    });
    const preview = operation.preview;
    const expired = operation.status === 'preview' && now >= new Date(operation.expires_at).getTime();
    const canConfirm = !expired && confirmation === preview.confirmation && (!preview.privilege_confirmation || privilege === preview.privilege_confirmation);
    return <div className="space-y-3 rounded-lg border border-slate-200 bg-white p-3">
        <div className="flex flex-wrap items-center justify-between gap-2"><Status value={expired ? 'expired' : operation.status} /><CopyId value={operation.id} label="operation" /></div>
        <div aria-live="polite" className="text-xs text-slate-600">
            {operation.status === 'preview' ? `Preview expires ${fmtDateTime(operation.expires_at)}. Nothing has changed.` : humanize(operation.result_code || operation.status)}
            {operation.status === 'waiting_for_paused_scan' ? <p className="mt-1 text-amber-800">A paused scan owns this market. Resume or stop it in Runs; this action will expire if it cannot start in time.</p> : null}
            {operation.status === 'cache_pending' ? <p className="mt-1 text-amber-800">Database verified. WordPress/cache verification is pending; this finding is not Contained.</p> : null}
            {operation.status === 'outcome_unknown' ? <p className="mt-1 text-amber-800">The remote commit outcome is unknown. Recovery compares the backup with current rows before any further action.</p> : null}
        </div>
        <InertCode>{(operation.result?.lines || preview.lines).join('\n')}</InertCode>
        {preview.diff?.length ? <details><summary className="cursor-pointer text-xs font-semibold">Widget before / after and placement</summary>{preview.diff.map((item, i) => <div key={i} className="mt-2"><p className="text-xs">{item.widget} · {item.sidebars.join(', ') || 'Unplaced widget'}</p><InertCode>{`BEFORE\n${item.before}\nAFTER\n${item.after}`}</InertCode></div>)}</details> : null}
        {operation.status === 'preview' ? <div className="space-y-3">
            <p className="text-xs leading-5 text-slate-600">{preview.consequences || 'Only the rows or file listed in this preview will change. Restore requires another preview and confirmation.'}</p>
            {operation.parent_id ? <p className="rounded bg-amber-50 p-2 text-xs text-amber-900">Restore can re-enable old passwords, sessions, keys or executable code.</p> : null}
            <label className="block text-xs font-semibold text-slate-700">Type <span className="crm-mono">{preview.confirmation}</span><input autoComplete="off" spellCheck={false} className="crm-input mt-1 w-full" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} /></label>
            {preview.privilege_confirmation ? <label className="block text-xs font-semibold text-amber-900">Privileged target: type <span className="crm-mono">{preview.privilege_confirmation}</span><input autoComplete="off" spellCheck={false} className="crm-input mt-1 w-full" value={privilege} onChange={(e) => setPrivilege(e.target.value)} /></label> : null}
            <button type="button" className="crm-btn-primary w-full sm:w-auto" disabled={!canConfirm || action.isPending} onClick={() => action.mutate('confirm')}>{action.isPending ? 'Submitting…' : operation.parent_id ? 'Confirm Restore' : 'Confirm containment'}</button>
            {expired ? <p className="text-xs text-amber-800">This preview expired. Return to the action choices and request a fresh preview.</p> : null}
        </div> : null}
        <div className="flex flex-wrap gap-2">
            {['verified', 'cache_pending'].includes(operation.status) ? <button type="button" className="crm-btn-secondary" disabled={action.isPending} onClick={() => action.mutate('restore')}>Preview Restore</button> : null}
            {['cache_pending', 'committed', 'outcome_unknown', 'recovery_pending'].includes(operation.status) ? <button type="button" className="crm-btn-secondary" disabled={action.isPending} onClick={() => action.mutate('verify')}>Retry verification / recovery</button> : null}
            {['preview', ...ACTIVE].includes(operation.status) ? <button type="button" className="crm-btn-secondary" disabled={action.isPending} onClick={() => action.mutate('cancel')}>Cancel{operation.status === 'executing' ? ' after current transaction' : ''}</button> : null}
        </div>
        {error ? <p role="alert" className="rounded bg-rose-50 p-2 text-xs text-rose-800">{error}</p> : null}
        {query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : null}
    </div>;
}

export default function ContainmentPanel({ findingId }) {
    const query = useQuery({ queryKey: ['dbo', 'containment', findingId], queryFn: () => dbObservatory.actionAvailability(findingId) });
    const [selected, setSelected] = useState([]);
    const [operation, setOperation] = useState(null);
    const [error, setError] = useState(null);
    const requestKey = useRef(key());
    const preview = useMutation({ mutationFn: () => dbObservatory.previewActions(findingId, { actions: selected, request_key: requestKey.current }), onSuccess: (data) => { setOperation(data.operation); setError(null); }, onError: (e) => setError(apiError(e)) });
    if (query.isLoading) return <Loading label="Loading containment availability…" rows={1} />;
    if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />;
    const data = query.data;
    return <section className="space-y-3 border-t border-slate-200 pt-4">
        <div><h4 className="font-semibold text-slate-900">Containment</h4><p className="mt-1 text-xs text-slate-500">Preview exact targets, then confirm. Encrypted backups and verification are required.</p></div>
        {!data.enabled ? <p className="rounded bg-slate-100 p-3 text-xs text-slate-600">{data.reason}</p> : null}
        {!data.actions.length ? <p className="text-xs text-slate-500">No supported automated action for this finding. Follow its manual remediation guidance.</p> : <fieldset className="space-y-2"><legend className="sr-only">Choose containment actions</legend>{data.actions.map((name) => <label key={name} className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" disabled={!data.enabled || preview.isPending} checked={selected.includes(name)} onChange={(e) => { setSelected(e.target.checked ? [...selected, name] : selected.filter((item) => item !== name)); requestKey.current = key(); setOperation(null); }} /><span><span className="font-medium">{LABELS[name][0]}</span><span className="block text-xs text-slate-500">{LABELS[name][1]}</span></span></label>)}</fieldset>}
        {data.actions.length ? <button type="button" className="crm-btn-primary" disabled={!data.enabled || !selected.length || preview.isPending} onClick={() => { if (operation) requestKey.current = key(); preview.mutate(); }}>{preview.isPending ? 'Checking exact targets…' : 'Preview selected actions'}</button> : null}
        {error ? <p role="alert" className="rounded bg-rose-50 p-2 text-xs text-rose-800">{error}</p> : null}
        {operation ? <OperationResult key={operation.id} initial={operation} onChanged={query.refetch} /> : null}
        {data.operations.length ? <details><summary className="cursor-pointer text-xs font-semibold">Operation history · {data.operations.length}</summary><div className="mt-2 space-y-2">{data.operations.filter((op) => op.id !== operation?.id).map((op) => <OperationResult key={op.id} initial={op} onChanged={query.refetch} />)}</div></details> : null}
    </section>;
}

export function ContainmentHistory({ markets }) {
    const [staffOperation, setStaffOperation] = useState(null);
    const requestKey = useRef(key());
    const previewStaff = useMutation({ mutationFn: () => dbObservatory.staffSessionsPreview(market, { request_key: requestKey.current }), onSuccess: (data) => { setStaffOperation(data.operation); requestKey.current = key(); } });
    const [market, setMarket] = useState('');
    const query = useQuery({ queryKey: ['dbo', 'operations', market], queryFn: () => dbObservatory.operations({ platform_id: market || undefined }), refetchInterval: 5000 });
    return <Panel title="Containment history" subtitle="Per-market results and guarded Restore. Database and filesystem verification remain separate." action={<select aria-label="Containment market" className="crm-select" value={market} onChange={(e) => setMarket(e.target.value)}><option value="">All markets</option>{markets.map((m) => <option key={m.platform_id} value={m.platform_id}>{m.market}</option>)}</select>} bodyClass="space-y-3 p-4">
        <div className="space-y-2"><button type="button" className="crm-btn-secondary" disabled={!market || previewStaff.isPending} onClick={() => previewStaff.mutate()}>{previewStaff.isPending ? 'Checking staff accounts…' : 'Preview staff logout for selected market'}</button><p className="text-xs text-slate-500">Resolves current privileged accounts, including protected staff. The exact IDs and session counts require two typed confirmations.</p>{previewStaff.isError ? <p role="alert" className="text-xs text-rose-800">{apiError(previewStaff.error)}</p> : null}{staffOperation ? <OperationResult key={staffOperation.id} initial={staffOperation} /> : null}</div>
        {query.isLoading ? <Loading /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : query.data.operations.length ? query.data.operations.map((op) => <details key={op.id} className="rounded-lg border border-slate-200 p-3"><summary className="cursor-pointer text-sm"><Status value={op.status} /> <span className="ml-2">{op.preview.confirmation} · {op.selection.map(humanize).join(', ')}</span> <span className="text-xs text-slate-500">{fmtDateTime(op.created_at)}</span></summary><div className="mt-3"><OperationResult initial={op} /></div></details>) : <p className="text-sm text-slate-500">No containment operations yet. Open a supported finding to preview its action.</p>}
    </Panel>;
}
