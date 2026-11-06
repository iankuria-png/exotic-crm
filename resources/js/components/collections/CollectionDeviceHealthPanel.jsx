import React from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuth } from '../../hooks/useAuth';

const titles = {
    ready: 'Receiving normally', no_recent_contact: 'No recent check-in', awaiting_check_in: 'Waiting for the first check-in',
    capture_unverified: 'Capture needs verification', app_stopped: 'Receiving app needs to be started',
    sms_permission_needed: 'SMS permission needs attention', filter_review: 'Payment filter needs verification',
    sim_review: 'Receiving SIM needs verification', format_review: 'Payment message format needs review',
    revoked: 'Phone connection has been revoked', account_review: 'Receiving account needs review', not_enrolled: 'Phone setup is incomplete',
};
const when = value => value ? new Date(value).toLocaleString() : 'No signed contact yet';

export default function CollectionDeviceHealthPanel({ platformId }) {
    const { user } = useAuth();
    const query = useQuery({ queryKey: ['collection-device-health', user?.id, platformId || null],
        queryFn: async () => (await api.get('/crm/collections/device-health', { params: { platform_id: platformId || undefined } })).data,
        refetchInterval: () => document.visibilityState === 'visible' ? 10000 : false, refetchOnWindowFocus: true });
    return <section aria-label="Receiving phone health" className="mb-5 border-b border-slate-200 pb-5">
        <div className="flex items-center justify-between gap-3"><h3 className="text-sm font-semibold text-slate-900">Receiving phone</h3>
            <button type="button" onClick={() => query.refetch()} disabled={query.isFetching} className="rounded px-2 py-2 text-sm font-medium text-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-50">{query.isFetching ? 'Checking…' : 'Check again'}</button></div>
        {query.isError && <p role="alert" className="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">CRM could not refresh phone status. The last loaded result may be out of date. Check your connection and try again.</p>}
        {query.isPending ? <p role="status" className="text-sm text-slate-500">Checking the office phone…</p> : query.data?.devices?.length === 0 ? <p className="text-sm text-slate-500">No receiving phone is available in your market scope. Ask an administrator to complete setup.</p> : query.data?.devices?.map((device, index) => <div key={device.id || `unenrolled-${index}`} className={`mb-3 rounded-lg border p-4 ${device.capture_ready ? 'border-teal-200 bg-teal-50' : 'border-amber-200 bg-amber-50'}`}>
            <div className="flex flex-wrap items-start justify-between gap-2"><p className="text-sm font-semibold text-slate-900">{device.label}</p><p className={`text-sm font-medium ${device.capture_ready ? 'text-teal-800' : 'text-amber-900'}`}>{titles[device.state] || 'Phone needs attention'}</p></div>
            <p className="mt-1 text-xs text-slate-600">Last contact: {when(device.last_contact_at)}</p>
            {device.capture_ready ? <p className="mt-2 text-sm text-teal-900">CRM received a fresh signed check-in from the payment capture app. Individual payments still follow their matching and review checks.</p> : <>
                <p className="mt-3 text-sm text-amber-950">New payment SMS may be delayed. A missed check-in can mean the phone is off, offline or the receiving app has stopped.</p>
                <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm text-amber-950"><li>Charge and unlock the office phone.</li><li>Connect it to working Wi-Fi or mobile data.</li><li>Open the receiving app and start it. Allow its SMS permission and background operation.</li><li>Keep this view open until CRM confirms a fresh check-in. If it stays here, ask an administrator to check the SIM, payment filter and account setup.</li></ol>
                {['revoked', 'account_review', 'sim_review', 'filter_review', 'format_review', 'not_enrolled'].includes(device.state) && <p className="mt-3 text-sm font-medium text-amber-950">An administrator must also resolve the setup or review issue. Restarting the phone alone will not clear it.</p>}
            </>}
        </div>)}
        {query.data?.checked_at && <p className="text-xs text-slate-500">Checked {when(query.data.checked_at)} · Updates automatically while this page is open</p>}
    </section>;
}
