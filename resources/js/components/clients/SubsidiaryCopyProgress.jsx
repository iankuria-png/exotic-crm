import React, { useState } from 'react';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

const held = {
    approval_expired: 'The approval expired while the profile was being set up. Approve again to continue with the same details.',
    authorization_changed: 'The approval or market settings changed. Review the request before continuing.',
    primary_inactive: 'The primary subscription is no longer active, so setup is paused.',
    source_removed: 'The original profile was removed, so setup cannot continue.',
    target_changed: 'The new profile was edited during setup. Review it before continuing.',
    target_not_owned: 'The new profile could not be confirmed as the one created for this sale.',
    item_needs_review: 'One item needs a check on the website before setup can continue.',
    item_unconfirmed: 'We could not confirm the last upload.',
    verification_failed: 'The final check found a difference. Check the saved setup to continue.',
    engine_unavailable: 'The website could not be reached. Check the saved setup to continue.',
    cancelled: 'Setup was cancelled. The new profile stays private.',
};
const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** Persisted copy progress plus the named resume/reauthorize actions. Never invents a percentage. */
export default function SubsidiaryCopyProgress({ row, onChanged }) {
    const { user } = useAuth();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [pin, setPin] = useState('');
    const copy = row?.copy;
    if (!copy) return null;
    const p = copy.progress || {};
    const fieldsDone = p.fields === 'completed';
    const paused = copy.state === 'needs_review';
    const steps = [
        [fieldsDone, fieldsDone ? 'Details, bio and contacts copied' : 'Details, bio and contacts · Waiting'],
        ...(p.location ? [[fieldsDone, fieldsDone ? 'Location applied' : 'Location · Waiting']] : []),
        ...(p.photos_total ? [[p.photos_done === p.photos_total, `Photos · ${p.photos_done} of ${p.photos_total} copied`]] : []),
        ...(p.videos_total ? [[p.videos_done === p.videos_total, p.videos_total === 1 ? `Video · ${p.videos_done ? 'Copied' : 'Waiting'}` : `Videos · ${p.videos_done} of ${p.videos_total} copied`]] : []),
    ];
    const act = async (fn) => {
        setBusy(true); setError('');
        try { await fn(); setPin(''); await onChanged?.(); }
        catch (e) { setError(e?.response?.data?.message || 'Setup could not continue. Try again.'); }
        finally { setBusy(false); }
    };
    const resume = () => act(() => api.post(`/crm/subsidiary-profile-copies/${copy.run_id}/resume`, { version: copy.run_version, reason: 'Checked and resumed paired-market profile setup from the sales handoff.' }));
    const reauthorize = () => act(() => api.post(`/crm/free-trials/requests/${row.request_id}/reauthorize-copy`, { request_version: copy.request_version, run_version: copy.run_version, reason: 'Approved continuing the same paired-market profile setup.', ...(pin ? { free_trial_pin: pin } : {}) }));
    if (copy.state === 'ready' && row.status === 'active') return <p className="mt-2 text-xs text-slate-600">Profile copied from {row.copied_from || 'the primary market'}</p>;
    return <div className="mt-3 space-y-1.5 text-sm" aria-live="polite">
        {steps.map(([done, label]) => <p key={label} className={done ? 'text-teal-800' : 'text-slate-700'}><span aria-hidden="true">{done ? '✓ ' : '• '}</span>{label}</p>)}
        {copy.duration_days && <p className="text-xs text-slate-500">{plural(copy.duration_days, 'day')} start when the profile is ready.</p>}
        {!paused && copy.state !== 'cancelled' && copy.state !== 'ready' && <p className="text-xs text-slate-500">You can close this panel. Setup will continue.</p>}
        {(paused || copy.state === 'cancelled') && <p className="text-xs text-amber-900">{held[copy.held_reason] || 'Setup is paused. Check the saved setup to continue.'}</p>}
        {copy.can_resume && <button type="button" className="rec-button rec-button-primary mt-2" disabled={busy} onClick={resume}>{busy ? 'Checking saved setup…' : 'Check and resume setup'}</button>}
        {copy.needs_reauthorization && <div className="mt-2 space-y-2">
            {user?.role !== 'admin' && <label className="block text-xs font-semibold">Approval PIN<input aria-label="Approval PIN" type="password" inputMode="numeric" autoComplete="off" className="crm-input mt-1 w-32" value={pin} onChange={e => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))} /></label>}
            <button type="button" className="rec-button rec-button-primary" disabled={busy || (user?.role !== 'admin' && pin.length < 4)} onClick={reauthorize}>{busy ? 'Approving…' : 'Approve and continue setup'}</button>
        </div>}
        {error && <p role="alert" className="text-xs text-rose-800">{error}</p>}
    </div>;
}
