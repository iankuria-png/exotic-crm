import React, { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../../hooks/useAuth';
import api from '../../services/api';

// Paired-market promotions use the same PIN, policy endpoint and approval accounting with neutral wording.
const copy = {
    trial: { admin: 'Your admin approval will be recorded with the trial.', adminTitle: 'Admin approval', pinTitle: 'Admin or market PIN approval', requiredTitle: 'Admin approval required', pinLabel: 'Free-trial PIN (optional)',
        adminBody: 'Your approval is recorded before activation. A PIN is not required.', pinBody: 'Leave the PIN blank to request admin approval, or enter it to authorize this trial now.', requiredBody: 'Submit the trial details for review. Service activates only after an admin approves.', error: 'Trial policy could not be loaded. The server will require current authorization before activation.', loading: 'Checking market trial policy…' },
    promotion: { admin: 'Your admin approval will be recorded.', adminTitle: 'Admin approval', pinTitle: 'Approval', requiredTitle: 'Admin approval required', pinLabel: 'Approval PIN (optional)',
        adminBody: 'Your approval is recorded before activation. A PIN is not required.', pinBody: 'Leave the PIN blank to request admin approval, or enter it to approve now.', requiredBody: 'An admin approves this before the paired market activates.', error: 'Approval settings could not be loaded. The server will require current authorization before activation.', loading: 'Checking approval settings…' },
};

export default function TrialAuthorizationControl({ marketId, value = '', onChange, disabled = false, compact = false, context = 'trial' }) {
    const text = copy[context] || copy.trial;
    const { user } = useAuth();
    useEffect(() => { onChange?.(''); }, [marketId]);
    const policy = useQuery({ queryKey: ['trial-policy', user?.id, marketId], queryFn: () => api.get(`/crm/markets/${marketId}/trial-approval-policy`).then(r => r.data), enabled: Boolean(marketId), staleTime: 15000 });
    if (policy.isError) return <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">{text.error}</div>;
    const admin = user?.role === 'admin';
    const allowPin = !admin && policy.data?.approval_mode === 'admin_or_pin';
    if (compact && admin) return <p className="text-xs text-teal-800" role="status">{text.admin}</p>;
    return <div className="rounded-lg border border-teal-200 bg-teal-50/60 p-4 text-sm text-slate-700">
        <p className="font-semibold text-teal-900">{policy.isLoading ? text.loading : admin ? text.adminTitle : allowPin ? text.pinTitle : text.requiredTitle}</p>
        <p className="mt-1 text-xs leading-5 text-slate-600">{admin ? text.adminBody : allowPin ? text.pinBody : text.requiredBody}</p>
        {allowPin && <label className="mt-3 block"><span className="text-xs font-semibold">{text.pinLabel}</span><input aria-label={text.pinLabel} type="password" inputMode="numeric" autoComplete="off" value={value} onChange={e => onChange?.(e.target.value.replace(/\D/g, '').slice(0, 6))} maxLength={6} disabled={disabled} className="crm-input mt-1" /></label>}
    </div>;
}
