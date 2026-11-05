import React, { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useAuth } from '../../hooks/useAuth';
import api from '../../services/api';

export default function TrialAuthorizationControl({ marketId, value = '', onChange, disabled = false }) {
    const { user } = useAuth();
    useEffect(() => { onChange?.(''); }, [marketId]);
    const policy = useQuery({ queryKey: ['trial-policy', user?.id, marketId], queryFn: () => api.get(`/crm/markets/${marketId}/trial-approval-policy`).then(r => r.data), enabled: Boolean(marketId), staleTime: 15000 });
    if (policy.isError) return <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="alert">Trial policy could not be loaded. The server will require current authorization before activation.</div>;
    const admin = user?.role === 'admin';
    const allowPin = !admin && policy.data?.approval_mode === 'admin_or_pin';
    return <div className="rounded-lg border border-teal-200 bg-teal-50/60 p-4 text-sm text-slate-700">
        <p className="font-semibold text-teal-900">{policy.isLoading ? 'Checking market trial policy…' : admin ? 'Admin approval' : allowPin ? 'Admin or market PIN approval' : 'Admin approval required'}</p>
        <p className="mt-1 text-xs leading-5 text-slate-600">{admin ? 'Your approval is recorded before activation. A PIN is not required.' : allowPin ? 'Leave the PIN blank to request admin approval, or enter it to authorize this trial now.' : 'Submit the trial details for review. Service activates only after an admin approves.'}</p>
        {allowPin && <label className="mt-3 block"><span className="text-xs font-semibold">Free-trial PIN (optional)</span><input aria-label="Free-trial PIN (optional)" type="password" inputMode="numeric" autoComplete="off" value={value} onChange={e => onChange?.(e.target.value.replace(/\D/g, '').slice(0, 6))} maxLength={6} disabled={disabled} className="crm-input mt-1" /></label>}
    </div>;
}
