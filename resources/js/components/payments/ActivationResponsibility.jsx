import React from 'react';

function timestamp(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Not recorded';
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        timeZoneName: 'short',
    }).format(date);
}

function label(value) {
    return value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()).replace(/\bCrm\b/g, 'CRM') : 'Not recorded';
}

export default function ActivationResponsibility({ data }) {
    const state = data?.state || 'not_recorded';
    const staff = state === 'staff';
    const automatic = state === 'automatic';
    const actorName = staff ? (data.actor?.name || 'Former CRM user')
        : automatic ? 'Automatic activation'
            : state === 'not_activated' ? 'No activation recorded' : 'Activator not recorded';
    const confirmation = data?.payment_confirmation;

    return (
        <section aria-label="Activation responsibility" className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-sm font-semibold text-slate-900">Activation responsibility</h4>
                <span className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold ${staff ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-200 bg-slate-50 text-slate-600'}`}>
                    {staff ? 'CRM user' : automatic ? 'System' : 'Not recorded'}
                </span>
            </div>
            <p className="mt-3 break-words text-base font-semibold text-slate-900">{actorName}</p>
            {staff && data.actor?.role ? <p className="mt-0.5 text-xs text-slate-500">{label(data.actor.role)} · CRM user #{data.actor.id}</p> : null}
            {!staff && !automatic ? (
                <p className="mt-1 text-xs leading-5 text-slate-500">
                    {state === 'not_activated' ? 'This payment has no linked subscription activation evidence yet.' : 'Historical evidence does not identify who activated this subscription.'}
                </p>
            ) : null}
            <dl className="mt-4 grid gap-x-6 gap-y-3 text-xs sm:grid-cols-2">
                <div><dt className="text-slate-500">Activated at</dt><dd className="mt-1 font-medium text-slate-800">{data?.activated_at ? <time dateTime={data.activated_at}>{timestamp(data.activated_at)}</time> : 'Not recorded'}</dd></div>
                <div><dt className="text-slate-500">Payment method</dt><dd className="mt-1 font-medium text-slate-800">{label(data?.method)}</dd></div>
                <div><dt className="text-slate-500">Activation source</dt><dd className="mt-1 break-words font-medium text-slate-800">{label(data?.source)}</dd></div>
                <div><dt className="text-slate-500">Evidence</dt><dd className="mt-1 font-medium text-slate-800">{data?.evidence || 'Not recorded'}</dd></div>
            </dl>
            {data?.reason ? <p className="mt-3 break-words rounded-lg bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600"><span className="font-semibold text-slate-800">Reason:</span> {data.reason}</p> : null}
            {data?.recorded_at ? <p className="mt-3 text-[11px] text-slate-500">Recorded {timestamp(data.recorded_at)}</p> : null}
            {confirmation ? (
                <div className="mt-4 border-t border-slate-100 pt-3 text-xs leading-5 text-slate-600">
                    <p><span className="font-semibold text-slate-800">Payment confirmed by:</span> {confirmation.actor?.name || 'Former CRM user'}</p>
                    <p>{timestamp(confirmation.confirmed_at)}</p>
                    <p className="mt-1 text-slate-500">Payment confirmation is recorded separately from subscription activation.</p>
                </div>
            ) : null}
        </section>
    );
}
