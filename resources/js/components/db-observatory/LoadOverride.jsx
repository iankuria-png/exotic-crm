import React, { useEffect, useState } from 'react';
import { fmtDateTime, humanize, TERMINAL_PASS } from './shared';

export function LoadOverridePrompt({ load, market, preflight = false, pending, onRun }) {
    const [reason, setReason] = useState('');
    return (
        <section className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" aria-label="Load protection">
            <div role="status">
                <p className="font-semibold">{load?.override_allowed ? 'Load protection is holding this request' : 'Load protection cannot be overridden right now'}</p>
                <p className="mt-1 text-xs">
                    Level {load?.level ?? 'unknown'}{load?.label ? ` · ${load.label}` : ''}
                    {load?.signal ? ` · ${humanize(load.signal)}: ${load.value ?? '—'} (threshold ${load.threshold ?? '—'})` : ''}
                    {load?.sampled_at ? ` · sampled ${fmtDateTime(load.sampled_at)}` : ''}
                </p>
            </div>
            {load?.override_allowed ? (
                <>
                    <p>{preflight
                        ? `Check saved credentials for ${market} once under elevated load. This reads metadata only; it does not start a scan.`
                        : `Allow this scan of ${market} under elevated load for up to 15 minutes, using one reader and row traversal batches of at most 250 rows.`}</p>
                    <p className="text-xs">Critical load and emergency stop still block the reader. All credential and health checks remain active. {preflight ? 'This permission ends after this attempt.' : 'The scan pauses if the override expires. Scheduled scans do not receive this permission.'}</p>
                    <label className="block text-xs font-semibold">
                        Reason for override (required)
                        <textarea className="crm-input mt-1 min-h-[4.5rem] text-sm" value={reason} onChange={(e) => setReason(e.target.value)} minLength={10} maxLength={500} disabled={pending} placeholder="For example: first Kenya canary scan during setup" />
                    </label>
                    <p className="text-xs">Use 10–500 characters. Your account, reason and expiry are recorded in Logs & audit.</p>
                    <button type="button" className="crm-btn-secondary !border-amber-500 !bg-white !text-amber-950" disabled={pending || reason.trim().length < 10} onClick={() => onRun(reason.trim())}>
                        {pending ? (preflight ? 'Checking credentials…' : 'Queuing scan…') : (preflight ? 'Run credential check once' : 'Run this scan under load')}
                    </button>
                </>
            ) : <p>Wait for a fresh load reading below Critical (level 3), then retry.</p>}
        </section>
    );
}

export function LoadOverrideBanner({ pass }) {
    const [now, setNow] = useState(Date.now());
    const override = pass?.load_override;
    const terminal = !pass || TERMINAL_PASS.includes(pass.status);
    useEffect(() => {
        if (!override || terminal) return undefined;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [override?.expires_at, terminal]);
    if (!override) return null;
    const remaining = Math.max(0, Math.ceil((Date.parse(override.expires_at) - now) / 1000));
    const state = override.revoked_at ? 'Revoked' : terminal ? 'Finished' : remaining === 0 ? 'Expired' : `${Math.floor(remaining / 60)}m ${remaining % 60}s remaining`;
    return (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-950">
            <p className="font-semibold">Load override · <span className="tabular-nums">{state}</span></p>
            <p className="mt-1 break-words">{override.reason}</p>
            <p className="mt-1">Admin #{override.actor_id} · Expires {fmtDateTime(override.expires_at)} · This scan only</p>
            {!terminal && !override.revoked_at && remaining === 0 ? <p className="mt-1 font-medium">The reader pauses at its next checkpoint. Stop this scan before requesting another override.</p> : null}
        </div>
    );
}
