import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import SubsidiaryTrialSection from '../clients/SubsidiaryTrialSection';
import SubsidiaryCopyProgress from '../clients/SubsidiaryCopyProgress';
import { primaryButton, secondaryButton, StatusPill } from './collectionUi';

const completedStates = ['succeeded', 'already_active'];
const transientReasons = ['subsidiary_profile_copy_unavailable', 'subsidiary_profile_resolution_pending', 'subsidiary_confirmation_pending'];
const explanations = {
    subsidiary_multiple_profiles_require_selection: 'More than one Tanzania profile uses this phone. Choose the profile that should receive this subscription.',
    subsidiary_profile_unavailable: 'The matching Tanzania profile is unavailable. Review its status before continuing.',
    subsidiary_location_or_currency_required: 'The new Tanzania profile needs a location or rate currency. Choose the missing details to continue.',
    subsidiary_profile_copy_blocked: 'A photo, video or bio could not be copied. Review the profile details to continue.',
    subsidiary_market_configuration_required: 'Tanzania’s subscription settings need attention before activation can continue.',
    subsidiary_period_requires_review: 'The purchased period could not be applied to Tanzania. Review the subscription details.',
    subsidiary_policy_grant_required: 'Automatic Tanzania activation needs an administrator’s approval.',
    subsidiary_approval_required: 'The saved Tanzania setup needs approval before it can continue.',
    subsidiary_profile_setup_required: 'The saved Tanzania step needs checking. Review the profile details if the next automatic check cannot complete it.',
    subsidiary_interval_elapsed: 'The purchased period has ended. Review the original subscription before continuing.',
    subsidiary_profile_binding_changed: 'The saved Tanzania profile changed during activation. Check the intended profile before continuing.',
};

export function subsidiaryProgressLabel(state, reason) {
    if (state === 'succeeded') return 'Tanzania active';
    if (state === 'already_active') return 'Tanzania already active';
    if (state === 'canceled') return 'Tanzania cancelled';
    if (state === 'copying') return 'Copying profile';
    if (state === 'prepared') return 'Activating Tanzania';
    if (state === 'uncertain') return 'Confirming Tanzania';
    if (transientReasons.includes(reason)) return 'Retrying automatically';
    if (state === 'needs_approval') return 'Approval needed';
    if (state === 'needs_profile') return 'Details need attention';
    return 'Setting up Tanzania';
}

export default function CollectionSubsidiaryProgress({ row, onChanged }) {
    const client = useQueryClient();
    const [editing, setEditing] = useState(false);
    const [ready, setReady] = useState(false);
    const [value, setValue] = useState({ enabled: true, platform_id: '2', duration_mode: 'match_primary', preview_token: '' });
    const [error, setError] = useState('');
    const base = '/crm/collections/receipts/' + row.id + '/subsidiary';
    const result = useQuery({ queryKey: ['collection-subsidiary', row.id], queryFn: () => api.get(base).then(r => r.data),
        refetchInterval: query => [...completedStates, 'canceled'].includes(query.state.data?.state) ? false : 5000 });
    const data = result.data;
    const profile = data?.summary?.subsidiary;
    const state = data?.state || row.subsidiary_obligation.state;
    const reason = data ? data.last_error_code : row.subsidiary_obligation.last_error_code;
    const complete = completedStates.includes(state);
    const canceled = state === 'canceled';
    const needsHelp = ['needs_approval', 'needs_profile'].includes(state) && !transientReasons.includes(reason);
    const automatic = !complete && !canceled && !needsHelp;
    const days = data?.duration_days ?? row.purchase?.duration_days;
    const profileReady = complete || data?.profile_ready || ['copying', 'prepared'].includes(state);
    const detailsReady = complete || state === 'prepared' || profile?.copy?.state === 'verified';
    const steps = [
        { label: profileReady ? 'Profile found' : data?.profile_action === 'creating' ? 'Creating profile' : 'Find or create profile', done: !!profileReady },
        { label: state === 'copying' ? 'Copying photos & bio' : data?.profile_action === 'existing' ? 'Existing details kept' : detailsReady ? 'Profile details ready' : 'Prepare profile details', done: !!detailsReady || (profileReady && data?.profile_action === 'existing') },
        { label: complete ? 'Subscription active' : state === 'uncertain' ? 'Confirm subscription' : 'Activate subscription', done: complete },
    ];
    const firstOpen = steps.findIndex(step => !step.done);
    const finish = useMutation({ mutationFn: () => api.post(base + '/finish', { expected_version: data.version, manual_client_id: value.manual_client_id || null,
        preview_token: value.preview_token, create_confirmed: Boolean(value.create_confirmed), ...(value.pin ? { pin: value.pin } : {}),
        copy_region_id: value.copy_region_id || null, copy_city_id: value.copy_city_id || null, copy_currency_id: value.copy_currency_id || null }),
        onSuccess: () => { setEditing(false); setError(''); setValue(v => ({ ...v, pin: '' })); result.refetch(); client.invalidateQueries({ queryKey: ['collection-receipts'] }); onChanged?.(); },
        onError: e => { setError(e.response?.status === 409 ? 'The saved setup changed. Your choices are kept. Refresh its status and preview before retrying.' : e.response?.data?.message || 'Tanzania could not be confirmed. The paid TZ subscription stays active.'); result.refetch(); } });

    return <section aria-label="Paired Tanzania activation" className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div><h3 className="text-sm font-semibold text-slate-900">Tanzania subscription</h3>
                <p className="mt-1 text-xs text-slate-600">Included with TZ{days ? ' · ' + days + (days === 1 ? ' day' : ' days') : ''}</p></div>
            <span aria-live="polite"><StatusPill tone={complete ? 'live' : needsHelp ? 'action' : canceled ? 'neutral' : 'busy'}>{subsidiaryProgressLabel(state, reason)}</StatusPill></span>
        </div>
        <ol aria-label="Tanzania activation progress" className="grid grid-cols-3 gap-3">{steps.map((step, index) => <li key={index} aria-current={!complete && !canceled && index === firstOpen ? 'step' : undefined} className="min-w-0">
            <div aria-hidden="true" className={'mb-2 h-1 rounded-full ' + (step.done ? 'bg-teal-600' : index === firstOpen && needsHelp ? 'bg-amber-400' : index === firstOpen && !canceled ? 'bg-sky-500' : 'bg-slate-200')} />
            <p className="break-words text-xs font-medium leading-5 text-slate-700">{step.done && <span aria-hidden="true" className="mr-1 text-teal-700">✓</span>}{step.label}</p>
        </li>)}</ol>
        {result.isPending && <p role="status" className="text-sm text-slate-600">Checking Tanzania progress…</p>}
        {result.isError && <p role="alert" className="text-sm text-amber-900">Tanzania status could not be loaded. <button type="button" className="min-h-11 underline" onClick={() => result.refetch()}>Retry status</button></p>}
        {automatic && <div className="rounded-lg bg-sky-50 p-3 text-sm leading-6 text-sky-950">
            <p>{state === 'uncertain' ? 'Checking the result with Tanzania before trying again.' : state === 'copying' ? 'Copying photos, bio and public profile details before activation.' : transientReasons.includes(reason) ? 'Tanzania setup has not completed yet. The saved step will retry automatically.' : 'Tanzania is being set up automatically. An existing profile is reused, or a new profile is created when needed.'}</p>
            <p className="mt-1 text-xs">Your paid TZ subscription is active. This view updates automatically.</p>
        </div>}
        {needsHelp && <div role="status" className="rounded-lg bg-amber-50 p-3 text-sm leading-6 text-amber-950">
            <p>{explanations[reason] || 'The Tanzania step needs a check before it can continue. Review the saved details below.'}</p>
            <p className="mt-1 text-xs">Your paid TZ subscription stays active.</p>
        </div>}
        {complete && <p className="text-sm text-teal-900">{state === 'already_active' ? 'Tanzania already covers the purchased period.' : 'Both subscriptions are confirmed active.'}</p>}
        {canceled && <p className="text-sm text-slate-600">The Tanzania step was cancelled.</p>}
        {complete && profile?.public_url && <a className="inline-flex min-h-11 items-center text-sm font-medium text-teal-800 underline" href={profile.public_url} target="_blank" rel="noopener noreferrer">Open Tanzania profile</a>}
        {profile?.copy && <SubsidiaryCopyProgress row={profile} onChanged={() => result.refetch()} />}
        {!complete && !canceled && data && <div className="flex flex-wrap items-center gap-3">
            {needsHelp && !editing && <button type="button" className={primaryButton} onClick={() => setEditing(true)}>Review Tanzania details</button>}
            <button type="button" className={secondaryButton} disabled={result.isFetching} onClick={() => result.refetch()}>{result.isFetching ? 'Checking…' : 'Refresh status'}</button>
        </div>}
        {editing && data && <div className="space-y-3 border-t border-slate-200 pt-4"><SubsidiaryTrialSection endpointBase={base} requestContext={{ source_client_id: row.purchase?.client_id, product_price_id: row.purchase?.product_price_id, receipt_version: data.version }}
            value={value} onChange={patch => setValue(v => ({ ...v, ...patch }))} onReadyChange={setReady} disabled={finish.isPending} />
            {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
            <div className="flex flex-wrap gap-3"><button type="button" disabled={!ready || !value.enabled || finish.isPending} className={primaryButton} onClick={() => finish.mutate()}>{finish.isPending ? 'Saving Tanzania details…' : 'Continue Tanzania activation'}</button><button type="button" className={secondaryButton} disabled={finish.isPending} onClick={() => setEditing(false)}>Cancel</button></div>
        </div>}
    </section>;
}
