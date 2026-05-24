import React, { useEffect, useState } from 'react';

const emptyForm = {
    market_id: '',
    profile_name: '',
    environment: 'sandbox',
    active: true,
    kill_switch_enabled: false,
    meta_phone_number_id: '',
    meta_business_account_id: '',
    meta_api_version: '',
    meta_access_token: '',
    meta_webhook_verify_token: '',
    meta_app_secret: '',
};

export default function WhatsAppProfileForm({ isSaving, onClose, onSave, platforms, profile }) {
    const [form, setForm] = useState(emptyForm);

    useEffect(() => {
        if (!profile) {
            setForm({
                ...emptyForm,
                market_id: platforms[0]?.id ? String(platforms[0].id) : '',
            });
            return;
        }

        setForm({
            market_id: String(profile.market_id || ''),
            profile_name: profile.profile_name || '',
            environment: profile.environment || 'sandbox',
            active: Boolean(profile.active),
            kill_switch_enabled: Boolean(profile.kill_switch_enabled),
            meta_phone_number_id: profile.meta_phone_number_id || '',
            meta_business_account_id: profile.meta_business_account_id || '',
            meta_api_version: profile.meta_api_version || '',
            meta_access_token: '',
            meta_webhook_verify_token: '',
            meta_app_secret: '',
        });
    }, [profile, platforms]);

    const update = (field, value) => setForm((current) => ({ ...current, [field]: value }));
    const secretHint = (configured) => configured ? 'Stored. Enter a value only to rotate.' : 'Not stored yet.';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4 py-6">
            <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-xl ring-1 ring-slate-200">
                <div className="border-b border-slate-200 px-4 py-3">
                    <h3 className="text-sm font-semibold text-slate-900">{profile ? 'Edit Meta WhatsApp Profile' : 'New Meta WhatsApp Profile'}</h3>
                    <p className="mt-1 text-xs text-slate-500">Credentials are encrypted at rest and masked after saving.</p>
                </div>
                <div className="grid gap-3 px-4 py-4 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Market</span>
                        <select
                            value={form.market_id}
                            onChange={(event) => update('market_id', event.target.value)}
                            className="crm-select"
                        >
                            {platforms.map((platform) => (
                                <option key={platform.id} value={platform.id}>{platform.name}</option>
                            ))}
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Profile name</span>
                        <input value={form.profile_name} onChange={(event) => update('profile_name', event.target.value)} className="crm-input" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Environment</span>
                        <select value={form.environment} onChange={(event) => update('environment', event.target.value)} className="crm-select">
                            <option value="sandbox">Sandbox</option>
                            <option value="production">Production</option>
                        </select>
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Graph API version</span>
                        <input value={form.meta_api_version} onChange={(event) => update('meta_api_version', event.target.value)} className="crm-input" placeholder="v25.0" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Phone number ID</span>
                        <input value={form.meta_phone_number_id} onChange={(event) => update('meta_phone_number_id', event.target.value)} className="crm-input" />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Business account ID</span>
                        <input value={form.meta_business_account_id} onChange={(event) => update('meta_business_account_id', event.target.value)} className="crm-input" />
                    </label>
                    {[
                        ['meta_access_token', 'Access token', profile?.meta_access_token_configured],
                        ['meta_webhook_verify_token', 'Webhook verify token', profile?.meta_webhook_verify_token_configured],
                        ['meta_app_secret', 'App secret', profile?.meta_app_secret_configured],
                    ].map(([field, label, configured]) => (
                        <label key={field} className="block sm:col-span-2">
                            <span className="mb-1 block text-xs font-medium text-slate-600">{label}</span>
                            <input
                                type="password"
                                value={form[field]}
                                onChange={(event) => update(field, event.target.value)}
                                className="crm-input"
                                placeholder={secretHint(configured)}
                            />
                            <span className={`mt-1 block text-xs ${configured ? 'text-emerald-700' : 'text-slate-400'}`}>{secretHint(configured)}</span>
                        </label>
                    ))}
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={form.active} onChange={(event) => update('active', event.target.checked)} className="rounded border-slate-300" />
                        Active
                    </label>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={form.kill_switch_enabled} onChange={(event) => update('kill_switch_enabled', event.target.checked)} className="rounded border-slate-300" />
                        Kill switch enabled
                    </label>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2">Cancel</button>
                    <button
                        type="button"
                        disabled={isSaving || !form.market_id || !form.profile_name.trim()}
                        onClick={() => onSave(profile, form)}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isSaving ? 'Saving...' : 'Save profile'}
                    </button>
                </div>
            </div>
        </div>
    );
}
