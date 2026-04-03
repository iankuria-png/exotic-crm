import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';
import { normalizePhone } from '../utils/phone';

const METHOD_OPTIONS = [
    {
        key: 'setup_link',
        label: 'Setup link',
        description: 'Recommended: client sets password using secure reset link.',
    },
    {
        key: 'temporary_password',
        label: 'Temporary password',
        description: 'Set a temporary password and ask client to reset after first login.',
    },
];

const CHANNEL_OPTIONS = [
    { key: 'both', label: 'Email + SMS' },
    { key: 'email', label: 'Email only' },
    { key: 'sms', label: 'SMS only' },
];

const TIMING_OPTIONS = [
    { key: 'send_now', label: 'Send now', hint: 'Deliver immediately and log provider results.' },
    { key: 'manual_send_later', label: 'Manual send later', hint: 'Store in queue and send when ready.' },
];

const statusTone = {
    deferred: 'bg-amber-50 text-amber-700 ring-amber-200',
    sent: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    partial: 'bg-orange-50 text-orange-700 ring-orange-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
};

function shortHash(value) {
    let hash = 0;
    const input = String(value || '');
    for (let i = 0; i < input.length; i += 1) {
        hash = ((hash << 5) - hash) + input.charCodeAt(i);
        hash |= 0;
    }
    return Math.abs(hash).toString(36);
}

function toneClassForFeedback(tone) {
    if (tone === 'success') return 'border-emerald-200 bg-emerald-50 text-emerald-800';
    if (tone === 'warning') return 'border-amber-200 bg-amber-50 text-amber-800';
    if (tone === 'danger') return 'border-rose-200 bg-rose-50 text-rose-800';
    return 'border-slate-200 bg-slate-50 text-slate-700';
}

async function copyTextValue(value) {
    if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return;
    }

    throw new Error('Clipboard access is unavailable.');
}

export default function CredentialDispatchDrawer({
    open,
    onClose,
    client,
    defaultReason = 'Client onboarding credentials dispatch',
    defaultSource = 'crm',
    onSuccess,
}) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const loginWindowRef = useRef(null);
    const [form, setForm] = useState({
        method: 'setup_link',
        channel: 'both',
        timing: 'send_now',
        recipient_email: '',
        recipient_phone: '',
        temporary_password: '',
        reason: defaultReason,
    });
    const [dispatchFeedback, setDispatchFeedback] = useState(null);
    const [credentialReveal, setCredentialReveal] = useState(null);

    useEffect(() => {
        if (!open || !client) {
            return;
        }

        setDispatchFeedback(null);
        setCredentialReveal(null);
        setForm({
            method: 'setup_link',
            channel: 'both',
            timing: 'send_now',
            recipient_email: client.email || '',
            recipient_phone: client.phone_normalized || '',
            temporary_password: '',
            reason: defaultReason,
        });
    }, [open, client, defaultReason]);

    const phonePrefix = client?.platform?.phone_prefix || '254';

    const accessContextQuery = useQuery({
        queryKey: ['client-credential-access-context', client?.id],
        queryFn: () => api.get(`/crm/clients/${client.id}/access-context`).then((response) => response.data),
        enabled: Boolean(open && client?.id),
    });

    const accessContext = accessContextQuery.data || null;
    const accessMessages = accessContext?.messages || {};
    const supportsTemporaryPassword = Boolean(accessContext?.can_reset_password);
    const canGenerateSessionLink = Boolean(accessContext?.can_generate_session_link);
    const profileUrl = accessContext?.profile_url || client?.wp_profile_url || null;
    const loginUrl = accessContext?.login_url || null;
    const setupUrl = accessContext?.setup_url || null;
    const wpUsername = accessContext?.wp_username || null;
    const hasAccessLinks = Boolean(profileUrl || loginUrl || setupUrl);
    const accessContextError = accessContextQuery.error?.response?.data?.message
        || accessContextQuery.error?.message
        || null;

    useEffect(() => {
        if (!supportsTemporaryPassword && form.method === 'temporary_password') {
            setForm((current) => ({ ...current, method: 'setup_link', temporary_password: '' }));
        }
    }, [form.method, supportsTemporaryPassword]);

    const dispatchHistoryQuery = useQuery({
        queryKey: ['client-credential-dispatches', client?.id],
        queryFn: () => api.get(`/crm/clients/${client.id}/credentials/dispatches`, {
            params: { per_page: 10 },
        }).then((response) => response.data),
        enabled: Boolean(open && client?.id),
    });

    const sendMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${client.id}/credentials/dispatch`, payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['client-credential-dispatches', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client', client?.id] });
            setDispatchFeedback(result?.recommendation || null);

            const status = result?.dispatch?.status;
            if (status === 'sent') {
                toast.success('Credentials delivered successfully.');
            } else if (status === 'partial') {
                toast.warning('Credentials partially delivered. Review failed channel and retry.');
            } else if (status === 'deferred') {
                toast.info('Credential dispatch queued for manual send later.');
            } else {
                toast.error('Credential delivery failed. Review provider response and retry.');
            }

            if (typeof onSuccess === 'function') {
                onSuccess(result);
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Credential dispatch failed.');
        },
    });

    const retryMutation = useMutation({
        mutationFn: ({ dispatchId, payload }) => api.post(
            `/crm/clients/${client.id}/credentials/dispatches/${dispatchId}/retry`,
            payload,
        ).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['client-credential-dispatches', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            setDispatchFeedback(result?.recommendation || null);
            toast.success('Credential dispatch retried.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Retry failed.');
        },
    });

    const resetCredentialsMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${client.id}/credentials/reset`, payload).then((response) => response.data),
        onSuccess: (result) => {
            const nextAccessContext = result?.access_context || null;

            queryClient.invalidateQueries({ queryKey: ['client-credential-access-context', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client', client?.id] });

            setForm((current) => ({
                ...current,
                temporary_password: '',
            }));
            setCredentialReveal({
                wp_username: nextAccessContext?.wp_username || null,
                password: result?.revealed?.password || '',
                login_url: nextAccessContext?.login_url || null,
                profile_url: nextAccessContext?.profile_url || null,
            });

            toast.success('Credentials reset. Copy the temporary password now.');

            if (typeof onSuccess === 'function') {
                onSuccess(result);
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Credential reset failed.');
        },
    });

    const loginAsClientMutation = useMutation({
        mutationFn: (payload) => api.post(`/crm/clients/${client.id}/login-as-client`, payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['client-timeline', client?.id] });
            queryClient.invalidateQueries({ queryKey: ['client', client?.id] });

            const popup = loginWindowRef.current;
            if (popup && !popup.closed) {
                try {
                    popup.opener = null;
                } catch {
                    // Ignore cross-window restrictions.
                }
                popup.location.href = result.url;
                popup.focus();
            } else {
                window.open(result.url, '_blank', 'noopener,noreferrer');
            }
            loginWindowRef.current = null;

            toast.success('Client session opened in a new tab.');

            if (typeof onSuccess === 'function') {
                onSuccess(result);
            }
        },
        onError: (error) => {
            const popup = loginWindowRef.current;
            if (popup && !popup.closed) {
                popup.close();
            }
            loginWindowRef.current = null;
            toast.error(error?.response?.data?.message || 'Unable to open client session.');
        },
    });

    const requiresEmailNow = useMemo(
        () => form.timing === 'send_now' && (form.channel === 'email' || form.channel === 'both'),
        [form.channel, form.timing],
    );

    const requiresPhoneNow = useMemo(
        () => form.timing === 'send_now' && (form.channel === 'sms' || form.channel === 'both'),
        [form.channel, form.timing],
    );

    const canSubmit =
        Boolean(client?.id)
        && form.reason.trim().length > 0
        && (!requiresEmailNow || form.recipient_email.trim().length > 0)
        && (!requiresPhoneNow || normalizePhone(form.recipient_phone, phonePrefix).length > 0)
        && (form.method !== 'temporary_password' || supportsTemporaryPassword)
        && (form.method !== 'setup_link' || hasAccessLinks)
        && !sendMutation.isPending;

    if (!open || !client) {
        return null;
    }

    const historyRows = dispatchHistoryQuery.data?.data || [];
    const handleCopy = async (label, value) => {
        if (!value) {
            return;
        }

        try {
            await copyTextValue(value);
            toast.success(`${label} copied.`);
        } catch {
            toast.error(`Unable to copy ${label.toLowerCase()}.`);
        }
    };

    const handleLoginAsClient = () => {
        loginWindowRef.current = window.open('', '_blank');
        if (loginWindowRef.current && !loginWindowRef.current.closed) {
            loginWindowRef.current.document.write('<p style="font-family: sans-serif; padding: 16px;">Opening client session...</p>');
        }

        loginAsClientMutation.mutate({
            target: 'edit_profile',
            reason: form.reason.trim() || defaultReason,
            source: defaultSource,
        });
    };

    return (
        <div className="fixed inset-0 z-[70] bg-slate-900/45" onClick={onClose}>
            <aside
                className="absolute right-0 top-0 h-full w-full max-w-xl overflow-y-auto border-l border-slate-200 bg-white shadow-2xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Client access</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-900">Manage client access</h3>
                            <p className="mt-1 text-xs text-slate-500">
                                {client.name || `Client #${client.id}`} • CRM #{client.id}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                        >
                            Close
                        </button>
                    </div>
                </header>

                <div className="space-y-4 px-5 py-4">
                    <div className="rounded-md border border-teal-200 bg-teal-50/70 px-3 py-2 text-xs text-teal-800">
                        Recommended default: <span className="font-semibold">Setup link + Email/SMS + Send now</span> for secure onboarding.
                    </div>

                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Quick actions</p>
                            {accessContextQuery.isFetching ? (
                                <span className="text-[11px] text-slate-400">Refreshing...</span>
                            ) : null}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {profileUrl ? (
                                <a
                                    href={profileUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100"
                                >
                                    Open profile
                                </a>
                            ) : null}
                            <button
                                type="button"
                                onClick={handleLoginAsClient}
                                disabled={accessContextQuery.isLoading || !canGenerateSessionLink || loginAsClientMutation.isPending}
                                className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {loginAsClientMutation.isPending ? 'Opening client session...' : 'Log in as client'}
                            </button>
                            <button
                                type="button"
                                onClick={() => resetCredentialsMutation.mutate({
                                    temporary_password: form.temporary_password.trim() || null,
                                    reason: form.reason.trim() || defaultReason,
                                    source: defaultSource,
                                })}
                                disabled={accessContextQuery.isLoading || !supportsTemporaryPassword || resetCredentialsMutation.isPending}
                                className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {resetCredentialsMutation.isPending ? 'Resetting credentials...' : 'Reset & copy credentials'}
                            </button>
                        </div>
                        {!profileUrl && accessMessages.access_links ? (
                            <p className="mt-2 text-[11px] text-amber-700">{accessMessages.access_links}</p>
                        ) : null}
                        {accessMessages.login_as_client ? (
                            <p className="mt-1 text-[11px] text-amber-700">{accessMessages.login_as_client}</p>
                        ) : null}
                        {accessMessages.reset_password ? (
                            <p className="mt-1 text-[11px] text-amber-700">{accessMessages.reset_password}</p>
                        ) : null}
                        {accessContextError ? (
                            <p className="mt-2 text-[11px] text-rose-700">{accessContextError}</p>
                        ) : null}
                    </div>

                    <section className="rounded-md border border-slate-200 bg-white px-3 py-3">
                        <p className="text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Access details</p>
                        <dl className="mt-3 space-y-2 text-sm">
                            <div className="flex items-start justify-between gap-3">
                                <dt className="text-slate-500">WordPress username</dt>
                                <dd className="text-right font-medium text-slate-900">{wpUsername || 'Unavailable'}</dd>
                            </div>
                            <div className="flex items-start justify-between gap-3">
                                <dt className="text-slate-500">Login URL</dt>
                                <dd className="text-right">
                                    {loginUrl ? (
                                        <a href={loginUrl} target="_blank" rel="noreferrer" className="font-medium text-teal-700 underline decoration-teal-200 underline-offset-2">
                                            Open login page
                                        </a>
                                    ) : (
                                        <span className="font-medium text-slate-400">Unavailable</span>
                                    )}
                                </dd>
                            </div>
                            <div className="flex items-start justify-between gap-3">
                                <dt className="text-slate-500">Setup link</dt>
                                <dd className="text-right">
                                    {setupUrl ? (
                                        <a href={setupUrl} target="_blank" rel="noreferrer" className="font-medium text-teal-700 underline decoration-teal-200 underline-offset-2">
                                            Open setup page
                                        </a>
                                    ) : (
                                        <span className="font-medium text-slate-400">Unavailable</span>
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    {supportsTemporaryPassword ? (
                        <section>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Temporary password (optional)</label>
                            <input
                                type="text"
                                value={form.temporary_password}
                                onChange={(event) => setForm((current) => ({ ...current, temporary_password: event.target.value }))}
                                className="crm-input"
                                placeholder="Auto-generated if blank"
                            />
                            <p className="mt-1 text-[11px] text-slate-500">
                                Used for reset-and-copy and temporary-password dispatch. Plaintext is never stored after the immediate response.
                            </p>
                        </section>
                    ) : null}

                    {credentialReveal ? (
                        <section className="rounded-md border border-emerald-200 bg-emerald-50/70 px-3 py-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-xs font-semibold uppercase tracking-[0.09em] text-emerald-800">Fresh credentials</p>
                                <span className="text-[11px] text-emerald-700">Copy now</span>
                            </div>
                            <p className="mt-2 text-[11px] text-emerald-800">
                                The temporary password is only revealed in this success state. Close and reopen the drawer to clear it.
                            </p>
                            <div className="mt-3 space-y-2">
                                {credentialReveal.wp_username ? (
                                    <div className="flex items-center justify-between gap-3 rounded-md border border-emerald-200 bg-white/70 px-3 py-2">
                                        <div className="min-w-0">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Username</p>
                                            <p className="truncate text-sm font-medium text-slate-900">{credentialReveal.wp_username}</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleCopy('Username', credentialReveal.wp_username)}
                                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            Copy
                                        </button>
                                    </div>
                                ) : null}
                                <div className="flex items-center justify-between gap-3 rounded-md border border-emerald-200 bg-white/70 px-3 py-2">
                                    <div className="min-w-0">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Temporary password</p>
                                        <p className="truncate text-sm font-medium text-slate-900">{credentialReveal.password}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleCopy('Temporary password', credentialReveal.password)}
                                        className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
                                    >
                                        Copy
                                    </button>
                                </div>
                                {credentialReveal.login_url ? (
                                    <div className="flex items-center justify-between gap-3 rounded-md border border-emerald-200 bg-white/70 px-3 py-2">
                                        <div className="min-w-0">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">Login URL</p>
                                            <p className="truncate text-sm font-medium text-slate-900">{credentialReveal.login_url}</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleCopy('Login URL', credentialReveal.login_url)}
                                            className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
                                        >
                                            Copy
                                        </button>
                                    </div>
                                ) : null}
                            </div>
                        </section>
                    ) : null}

                    <section>
                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Method</p>
                        <div className="space-y-2">
                            {METHOD_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => setForm((current) => ({ ...current, method: option.key }))}
                                    disabled={option.key === 'temporary_password' && !supportsTemporaryPassword}
                                    className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                        form.method === option.key
                                            ? 'border-teal-300 bg-teal-50'
                                            : 'border-slate-200 bg-white hover:bg-slate-50'
                                    } ${(option.key === 'temporary_password' && !supportsTemporaryPassword) ? 'cursor-not-allowed opacity-60' : ''}`}
                                >
                                    <p className="text-sm font-semibold text-slate-900">{option.label}</p>
                                    <p className="mt-0.5 text-xs text-slate-600">{option.description}</p>
                                </button>
                            ))}
                        </div>
                        {!supportsTemporaryPassword ? (
                            <p className="mt-1 text-[11px] text-amber-700">
                                Temporary password requires a linked WordPress user ID and market database credentials.
                            </p>
                        ) : null}
                        {form.method === 'setup_link' && !hasAccessLinks ? (
                            <p className="mt-1 text-[11px] text-amber-700">
                                Setup-link dispatch needs at least one WordPress login, setup, or profile link for this market.
                            </p>
                        ) : null}
                    </section>

                    <section className="grid gap-3 md:grid-cols-2">
                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Channel</p>
                            <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1">
                                {CHANNEL_OPTIONS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        onClick={() => setForm((current) => ({ ...current, channel: option.key }))}
                                        className={`rounded-md px-2.5 py-1.5 text-xs font-semibold transition ${
                                            form.channel === option.key
                                                ? 'bg-white text-slate-900 shadow-sm'
                                                : 'text-slate-600 hover:text-slate-900'
                                        }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div>
                            <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Timing</p>
                            <div className="space-y-1.5">
                                {TIMING_OPTIONS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        onClick={() => setForm((current) => ({ ...current, timing: option.key }))}
                                        className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                            form.timing === option.key
                                                ? 'border-teal-300 bg-teal-50'
                                                : 'border-slate-200 hover:bg-slate-50'
                                        }`}
                                    >
                                        <p className="text-xs font-semibold text-slate-900">{option.label}</p>
                                        <p className="text-[11px] text-slate-500">{option.hint}</p>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>

                    <section className="grid gap-3 md:grid-cols-2">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Recipient email</label>
                            <input
                                type="email"
                                value={form.recipient_email}
                                onChange={(event) => setForm((current) => ({ ...current, recipient_email: event.target.value }))}
                                className="crm-input"
                                placeholder="client@example.com"
                            />
                            {requiresEmailNow && !form.recipient_email.trim() ? (
                                <p className="mt-1 text-[11px] text-rose-600">Required for selected channel when sending now.</p>
                            ) : null}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Recipient phone</label>
                            <input
                                type="text"
                                value={form.recipient_phone}
                                onChange={(event) => setForm((current) => ({ ...current, recipient_phone: event.target.value }))}
                                className="crm-input"
                                placeholder={`e.g. ${phonePrefix}712345678`}
                            />
                            {requiresPhoneNow && !normalizePhone(form.recipient_phone, phonePrefix) ? (
                                <p className="mt-1 text-[11px] text-rose-600">Required for selected channel when sending now.</p>
                            ) : null}
                        </div>
                    </section>

                    <section>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            rows={3}
                            value={form.reason}
                            onChange={(event) => setForm((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                        />
                    </section>

                    <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 pt-3">
                        <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                        <button
                            type="button"
                            disabled={!canSubmit}
                            onClick={() => {
                                const normalizedEmail = form.recipient_email.trim() || null;
                                const normalizedPhone = normalizePhone(form.recipient_phone.trim(), phonePrefix) || null;
                                const keySeed = `${client.id}|${form.method}|${form.channel}|${form.timing}|${normalizedEmail || ''}|${normalizedPhone || ''}|${form.reason.trim()}`;
                                sendMutation.mutate({
                                    method: form.method,
                                    channel: form.channel,
                                    timing: form.timing,
                                    recipient_email: normalizedEmail,
                                    recipient_phone: normalizedPhone,
                                    temporary_password: form.method === 'temporary_password'
                                        ? (form.temporary_password.trim() || null)
                                        : null,
                                    reason: form.reason.trim(),
                                    source: defaultSource,
                                    idempotency_key: `cred-${client.id}-${shortHash(keySeed)}`,
                                });
                            }}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {sendMutation.isPending
                                ? 'Processing...'
                                : form.timing === 'manual_send_later'
                                    ? 'Queue for manual send'
                                    : 'Send credentials'}
                        </button>
                    </footer>

                    <p className="text-[11px] text-slate-500">
                        Duplicate submits with the same payload are deduplicated for 45 seconds to prevent accidental double-send.
                    </p>

                    {dispatchFeedback ? (
                        <section className={`rounded-md border px-3 py-2 text-xs ${toneClassForFeedback(dispatchFeedback.tone)}`}>
                            <p className="font-semibold">{dispatchFeedback.label}</p>
                            <p className="mt-0.5">{dispatchFeedback.cta}</p>
                        </section>
                    ) : null}

                    <section className="border-t border-slate-200 pt-4">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Recent dispatches</p>
                            {dispatchHistoryQuery.isFetching ? (
                                <span className="text-[11px] text-slate-400">Refreshing...</span>
                            ) : null}
                        </div>

                        <div className="mt-2 space-y-2">
                            {historyRows.length > 0 ? historyRows.map((row) => (
                                <article key={row.id} className="rounded-md border border-slate-200 bg-white p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="text-xs font-semibold text-slate-900">
                                                {row.method === 'setup_link' ? 'Setup link' : 'Temporary password'} • {row.channel}
                                            </p>
                                            <p className="mt-0.5 text-[11px] text-slate-500">
                                                {row.timing === 'manual_send_later' ? 'Queued' : 'Immediate'} • {new Date(row.created_at).toLocaleString()}
                                            </p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${statusTone[row.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}>
                                            {row.status}
                                        </span>
                                    </div>

                                    <p className="mt-1 text-[11px] text-slate-600">
                                        {row.recipient_email ? `Email: ${row.recipient_email}` : 'No email'} • {row.recipient_phone ? `Phone: ${row.recipient_phone}` : 'No phone'}
                                    </p>

                                    {row.error_message ? (
                                        <p className="mt-1 text-[11px] text-rose-700">{row.error_message}</p>
                                    ) : null}

                                    {['deferred', 'failed', 'partial'].includes(row.status) ? (
                                        <div className="mt-2 flex justify-end">
                                            <button
                                                type="button"
                                                disabled={retryMutation.isPending}
                                                onClick={() => {
                                                    const retryEmail = form.recipient_email.trim() || row.recipient_email || null;
                                                    const retryPhone = normalizePhone(form.recipient_phone.trim(), phonePrefix) || row.recipient_phone || null;
                                                    const retryMethod = row.method || 'setup_link';
                                                    const keySeed = `${client.id}|retry|${row.id}|${retryMethod}|${row.channel}|${retryEmail || ''}|${retryPhone || ''}`;
                                                    retryMutation.mutate({
                                                        dispatchId: row.id,
                                                        payload: {
                                                            recipient_email: retryEmail,
                                                            recipient_phone: retryPhone,
                                                            temporary_password: retryMethod === 'temporary_password'
                                                                ? (form.temporary_password.trim() || null)
                                                                : null,
                                                            reason: `Retry credential dispatch #${row.id} from CRM drawer`,
                                                            idempotency_key: `cred-${client.id}-${shortHash(keySeed)}`,
                                                        },
                                                    });
                                                }}
                                                className="rounded-md border border-slate-300 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {retryMutation.isPending ? 'Retrying...' : 'Retry send'}
                                            </button>
                                        </div>
                                    ) : null}
                                </article>
                            )) : (
                                <p className="rounded-md border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-xs text-slate-500">
                                    No credential dispatch records yet.
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </aside>
        </div>
    );
}
