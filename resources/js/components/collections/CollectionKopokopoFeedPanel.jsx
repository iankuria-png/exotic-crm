import React, { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

const button = 'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60';
const primary = `${button} !border-teal-700 !bg-teal-700 !text-white hover:!bg-teal-800`;
const titles = { ready: 'Connected · Reconciliation current', setup_pending: 'Finish connecting KopoKopo', request_review: 'Connection request needs checking', reconciliation_due: 'Reconciliation needs refreshing', provider_review: 'Receiving evidence needs review', configuration_review: 'Connection settings need review', disabled: 'Receiving connection paused' };
const descriptions = {
    ready: 'Direct till payments arrive here. Review matching and service exceptions below.',
    setup_pending: 'Complete the receiving callbacks, then verify reconciliation before relying on automatic activation.',
    request_review: 'A request has no confirmed result. Check it before sending another request. Captured receipts remain available.',
    reconciliation_due: 'CRM needs a fresh comparison with KopoKopo. New notifications can still arrive while recovery is checked.',
    provider_review: 'Some receiving evidence needs an administrator’s review. Check the affected receipts before activating them.',
    configuration_review: 'An administrator needs to check the KopoKopo account connection.',
    disabled: 'The receiving feed is paused. Previously captured receipts remain available for review.',
};
const requestLabels = { requesting: 'Request sent · Awaiting result', accepted: 'Waiting for KopoKopo', complete: 'Reconciled', needs_review: 'Result needs checking', failed: 'Request failed' };
const subscriptionLabels = { not_registered: 'Not connected', requesting: 'Awaiting result', registered: 'Connected', needs_review: 'Needs provider review', failed: 'Connection rejected' };
function when(value) { return value ? new Intl.DateTimeFormat('en-KE', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Africa/Nairobi' }).format(new Date(value)) + ' EAT' : 'Not yet verified'; }
function explain(error) {
    const code = error?.response?.data?.reason;
    const messages = {
        provider_connection_version_changed: 'Connection settings changed. Refresh the status before trying again.',
        provider_subscription_result_requires_review: 'The previous subscription request is unconfirmed. Ask an administrator to verify its result with KopoKopo before registering again.',
        provider_authorization_failed: 'KopoKopo could not authorize the account connection. Ask an administrator to check the saved integration.',
        provider_poll_status_unavailable: 'KopoKopo could not return this request’s status. Try checking the request again.',
        provider_poll_location_requires_review: 'The request has no confirmed provider location. Ask an administrator to check it with KopoKopo.',
        provider_reconciliation_not_due: 'Reconciliation is already current. New receipts continue to update automatically.',
        provider_setup_unavailable: 'Receiving setup is not available yet. Ask an administrator to check the release configuration.',
        provider_setup_required: 'Prepare the receiving till before connecting KopoKopo.',
    };
    return messages[code] || 'The connection check could not finish. Refresh the status and try again; captured payments remain available.';
}

export default function CollectionKopokopoFeedPanel() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const titleId = useId();
    const detailsId = useId();
    const [expanded, setExpanded] = useState(false);
    const [error, setError] = useState('');
    const [copied, setCopied] = useState('');
    const [recoveryTopic, setRecoveryTopic] = useState('buygoods_transaction_received');
    const [recoveryLocation, setRecoveryLocation] = useState('');
    const queryKey = ['collection-kopokopo-feed', user?.id];
    const query = useQuery({ queryKey, queryFn: async () => (await api.get('/crm/collections/kopokopo')).data,
        refetchInterval: () => document.visibilityState === 'visible' ? 10000 : false, refetchOnWindowFocus: true });
    const mutate = useMutation({ mutationFn: async ({ action, body }) => (await api.post(`/crm/collections/kopokopo/${action}`, body)).data,
        onMutate: () => { setError(''); setCopied(''); },
        onSuccess: data => {
            queryClient.setQueryData(queryKey, data);
            queryClient.invalidateQueries({ queryKey: ['collection-device-health'] });
            queryClient.invalidateQueries({ queryKey: ['collection-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['collection-policies'] });
        }, onError: failure => { setError(explain(failure)); query.refetch(); } });
    const feed = query.data?.feed;
    const state = feed?.health?.state;
    const ready = state === 'ready';
    const manager = query.data?.can_manage;
    const unknownSubscription = feed?.subscriptions?.some(s => ['requesting', 'needs_review'].includes(s.state));
    const unconnected = feed?.subscriptions?.some(s => s.state !== 'registered');
    const busy = mutate.isPending;
    const doAction = (action, body = {}) => mutate.mutate({ action, body });
    async function copyCallback() {
        try { await navigator.clipboard.writeText(feed.callback_url); setCopied('Callback address copied'); }
        catch { setCopied('Select the callback address below to copy it.'); }
    }
    return <section aria-labelledby={titleId} aria-busy={busy || query.isPending} className="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-4 p-4 sm:p-5">
            <div className="min-w-0 flex-1 basis-64">
                <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-slate-500"><span className="uppercase tracking-wider">Receiving connection</span><span aria-hidden="true">·</span><span className="tabular-nums">Till 268491</span></div>
                <h3 id={titleId} className="mt-1.5 text-lg font-semibold tracking-tight text-slate-900">KopoKopo</h3>
                <div className="mt-2 flex items-start gap-2"><span aria-hidden="true" className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${ready ? 'bg-teal-600' : feed ? 'bg-amber-500' : 'bg-slate-400'}`} />
                    <p className={`text-sm font-semibold ${ready ? 'text-teal-900' : 'text-slate-800'}`}>{query.isPending ? 'Checking receiving connection…' : query.isError ? 'Receiving status unavailable' : !feed ? 'Ready to connect direct till payments' : titles[state] || 'Connection needs checking'}</p></div>
                <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-slate-600">{feed ? descriptions[state] : 'Bring payments sent directly to your Kenya till into the CRM, with payer history and a clear review path.'}</p>
            </div>
            <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
                {manager && query.data?.storage_ready && !feed && <button type="button" className={primary} disabled={busy} onClick={() => doAction('setup', { confirmation: 'prepare-till-268491' })}>{busy ? 'Preparing till…' : 'Set up receiving till'}</button>}
                {manager && feed && unconnected && !unknownSubscription && <button type="button" className={primary} disabled={busy || !feed.enabled} onClick={() => doAction('subscribe', { expected_version: feed.version, confirmation: 'connect-till-268491' })}>{busy ? 'Connecting…' : 'Connect KopoKopo'}</button>}
                {manager && feed && !unconnected && (!ready || expanded) && <button type="button" className={ready ? button : primary} disabled={busy || !feed.enabled} onClick={() => doAction('reconcile')}>{busy ? 'Checking…' : 'Check reconciliation'}</button>}
                <button type="button" className={button} disabled={query.isFetching || busy} onClick={() => query.refetch()}>{query.isFetching && !query.isPending ? 'Refreshing…' : 'Refresh status'}</button>
            </div>
        </div>
        {query.isError && <div role="alert" className="mx-4 mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-relaxed text-amber-950 sm:mx-5">Connection status could not refresh. {feed ? 'The last loaded result may be out of date.' : 'Check your connection and refresh the status.'}</div>}
        {error && <div role="alert" className="mx-4 mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-relaxed text-amber-950 sm:mx-5">{error}</div>}
        {feed && (!ready || expanded) && <dl className="grid gap-y-3 border-t border-slate-100 bg-slate-50/60 px-4 py-3 text-xs sm:grid-cols-3 sm:gap-x-5 sm:px-5">
            <div><dt className="text-slate-500">Latest provider contact</dt><dd className="mt-1 font-medium tabular-nums text-slate-800">{when(feed.health?.last_contact_at)}</dd></div>
            <div><dt className="text-slate-500">Reconciled through</dt><dd className="mt-1 font-medium tabular-nums text-slate-800">{when(feed.health?.reconciled_until)}</dd></div>
            <div><dt className="text-slate-500">Automatic activation</dt><dd className="mt-1 font-medium text-slate-800">{feed.automatic_enabled ? 'On · Approved purchases only' : 'Off · Staff review available'}</dd></div>
        </dl>}
        {feed && ready && !expanded && <p className="border-t border-slate-100 bg-slate-50/60 px-4 py-3 text-xs tabular-nums text-slate-600 sm:px-5">Reconciled through {when(feed.health?.reconciled_until)}</p>}
        {!manager && !query.isPending && !feed && <p className="border-t border-slate-100 px-4 py-3 text-sm text-slate-600 sm:px-5">An administrator can complete the receiving connection. Existing payment records are still available.</p>}
        {manager && feed && <>
            <button type="button" aria-expanded={expanded} aria-controls={detailsId} onClick={() => setExpanded(!expanded)} className="flex min-h-11 w-full items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-left text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600 sm:px-5"><span>Connection details</span><span aria-hidden="true" className="text-base">{expanded ? '−' : '+'}</span></button>
            {expanded && <div id={detailsId} className="space-y-4 border-t border-slate-100 p-4 sm:p-5">
                <ol aria-label="Connection setup progress" className="grid gap-3 sm:grid-cols-3">{[['Receiving account', true], ['Receiving callbacks', !unconnected], ['Reconciliation', ready]].map(([label, done], index) => <li key={label} className="flex items-center gap-2 text-sm"><span aria-hidden="true" className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${done ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-200' : 'bg-slate-100 text-slate-500'}`}>{done ? '✓' : index + 1}</span><span className={done ? 'font-medium text-slate-800' : 'text-slate-500'}>{label}<span className="sr-only">{done ? ', complete' : ', pending'}</span></span></li>)}</ol>
                <div className="divide-y divide-slate-100 rounded-lg border border-slate-200">{feed.subscriptions.map(s => <div key={s.event} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"><span className="text-slate-700">{s.event.endsWith('_reversed') ? 'Payment reversals' : 'Payment received'}</span><span className={`font-medium ${s.state === 'registered' ? 'text-teal-800' : 'text-amber-900'}`}>{subscriptionLabels[s.state] || 'Needs review'}</span></div>)}</div>
                {unknownSubscription && <p className="rounded-lg bg-amber-50 p-3 text-sm leading-relaxed text-amber-950">A callback request is unconfirmed. Ask an administrator to verify its registration with KopoKopo before creating another subscription.</p>}
                {feed.polls?.map(p => <div key={p.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2.5 text-sm"><div><p className="font-medium text-slate-800">{requestLabels[p.state] || 'Reconciliation needs checking'}</p><p className="mt-1 text-xs tabular-nums text-slate-500">Through {when(p.until_at)}</p></div>{p.state !== 'complete' && p.can_check && <button type="button" className={button} disabled={busy} onClick={() => doAction(`polls/${p.id}/recover`)}>Check request</button>}</div>)}
                <details className="text-sm"><summary className="min-h-10 cursor-pointer py-2 font-medium text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600">Technical connection information</summary>
                    <p className="mt-2 text-xs leading-relaxed text-slate-500">This receiving connection is for direct till 268491. Existing STK checkout uses K317087. Repeated delivery is checked against the original receipt before activation.</p>
                    <label className="mt-3 block text-xs font-medium text-slate-600">Receiving callback<input readOnly value={feed.callback_url || ''} className="crm-input mt-1 w-full min-w-0 font-mono text-xs" onFocus={event => event.target.select()} /></label><button type="button" className={`${button} mt-2`} onClick={copyCallback}>Copy callback address</button>
                    {copied && <p role="status" className="mt-2 text-xs text-teal-800">{copied}</p>}
                    <form className="mt-4 space-y-3 border-t border-slate-100 pt-4" onSubmit={event => { event.preventDefault(); doAction('subscriptions/verify', { topic: recoveryTopic, location: recoveryLocation.trim(), expected_version: feed.version }); }}>
                        <h4 className="font-semibold text-slate-800">Verify an existing subscription</h4>
                        <p className="text-xs leading-relaxed text-slate-500">Use a confirmed KopoKopo resource address after an interrupted request. CRM checks its till, event, active status, callback and payload format before accepting it.</p>
                        <label className="block text-xs font-medium text-slate-600">Event<select className="crm-input mt-1 w-full" value={recoveryTopic} onChange={event => setRecoveryTopic(event.target.value)}><option value="buygoods_transaction_received">Payment received</option><option value="buygoods_transaction_reversed">Payment reversals</option></select></label>
                        <label className="block text-xs font-medium text-slate-600">KopoKopo subscription address<input type="url" required maxLength={512} className="crm-input mt-1 w-full" value={recoveryLocation} onChange={event => setRecoveryLocation(event.target.value)} placeholder="https://api.kopokopo.com/api/v2/webhook_subscriptions/…" /></label>
                        <button type="submit" className={button} disabled={busy || !recoveryLocation.trim()}>Verify subscription</button>
                    </form>
                    {feed.polls?.filter(p => p.state !== 'complete' && !p.can_check).map(p => <form key={p.id} className="mt-4 space-y-3 border-t border-slate-100 pt-4" onSubmit={event => { event.preventDefault(); doAction(`polls/${p.id}/recover`, { location: new FormData(event.currentTarget).get('location') }); }}><p className="font-semibold text-slate-800">Recover reconciliation through {when(p.until_at)}</p><label className="block text-xs font-medium text-slate-600">KopoKopo reconciliation address<input name="location" type="url" required maxLength={512} className="crm-input mt-1 w-full" placeholder="https://api.kopokopo.com/api/v2/polling/…" /></label><button type="submit" className={button} disabled={busy}>Verify reconciliation</button></form>)}
                </details>
            </div>}
        </>}
        <span role="status" className="sr-only">{busy ? 'Checking KopoKopo connection. You can continue reviewing captured payments.' : ''}</span>
    </section>;
}
