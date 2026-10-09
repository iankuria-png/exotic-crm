import React, { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { apiError, Drawer, InertCode, Status } from './shared';

export default function CampaignContainment({ targets, onClose }) {
    const [result, setResult] = useState(null);
    const [confirmation, setConfirmation] = useState('');
    const [privileged, setPrivileged] = useState({});
    const [error, setError] = useState(null);
    const requestKey = useRef(crypto.randomUUID());
    const preview = useMutation({ mutationFn: () => dbObservatory.campaignPreview({ targets, request_key: requestKey.current }), onSuccess: (data) => { setResult(data); setError(null); }, onError: (e) => setError(apiError(e)) });
    const progress = useQuery({ queryKey: ['dbo', 'campaign', result?.campaign.id], queryFn: () => dbObservatory.campaign(result.campaign.id), enabled: Boolean(result?.campaign.approved_at), refetchInterval: 3000 });
    const displayed = progress.data || result;
    const confirm = useMutation({ mutationFn: () => dbObservatory.confirmCampaign(result.campaign.id, { confirmation, preview_digest: result.campaign.preview_digest, privilege_confirmations: privileged }), onSuccess: (data) => { setResult(data); setError(null); }, onError: (e) => setError(apiError(e)) });
    const cancel = useMutation({ mutationFn: () => dbObservatory.cancelCampaign(result.campaign.id), onSuccess: setResult, onError: (e) => setError(apiError(e)) });
    const valid = result && confirmation === result.confirmation && result.operations.every((op) => !op.preview.privilege_confirmation || privileged[op.id] === op.preview.privilege_confirmation);
    return <Drawer open onClose={onClose} title="Campaign containment" subtitle={`${targets.length} selected finding(s). Every market must produce a valid preview before the whole campaign can be approved.`}>
        <div className="space-y-4">
            <p className="rounded bg-amber-50 p-3 text-xs leading-5 text-amber-900">Actions run independently, one market at a time. A failure leaves successful markets in place. Restore can re-enable access and requires its own confirmation.</p>
            {!result ? <button type="button" className="crm-btn-primary" disabled={preview.isPending} onClick={() => preview.mutate()}>{preview.isPending ? 'Preparing all market previews…' : 'Preview all selected markets'}</button> : <>
                <p className="text-sm font-semibold">{displayed.operations.length} market(s) · {Object.entries(displayed.counts).map(([state, count]) => `${count} ${state.replaceAll('_', ' ')}`).join(' · ')}</p>
                {displayed.operations.map((op) => <details key={op.id} open className="rounded-lg border border-slate-200 p-3"><summary className="cursor-pointer text-sm font-semibold">{op.preview.confirmation} <Status value={op.status} /></summary><div className="mt-2 space-y-2"><InertCode>{(op.result?.lines || op.preview.lines).join('\n')}</InertCode>{!result.campaign.approved_at && op.preview.privilege_confirmation ? <label className="block text-xs">Type {op.preview.privilege_confirmation}<input className="crm-input mt-1 w-full" autoComplete="off" value={privileged[op.id] || ''} onChange={(e) => setPrivileged({ ...privileged, [op.id]: e.target.value })} /></label> : null}</div></details>)}
                {!result.campaign.approved_at && result.campaign.status === 'preview' ? <div className="space-y-2"><label className="block text-xs font-semibold">Type {result.confirmation}<input className="crm-input mt-1 w-full" autoComplete="off" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} /></label><button type="button" className="crm-btn-primary" disabled={!valid || confirm.isPending} onClick={() => confirm.mutate()}>{confirm.isPending ? 'Submitting…' : 'Confirm entire campaign'}</button></div> : null}
                <button type="button" className="crm-btn-secondary" disabled={cancel.isPending} onClick={() => cancel.mutate()}>Cancel remaining campaign actions</button>
                {result.campaign.approved_at ? <p aria-live="polite" className="text-xs text-slate-500">Per-market results are retained in Containment history, including Restore for verified changes.</p> : null}
            </>}
            {error ? <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">{error} No reduced set has been approved. Refresh the findings and create a new campaign preview.</p> : null}
        </div>
    </Drawer>;
}
