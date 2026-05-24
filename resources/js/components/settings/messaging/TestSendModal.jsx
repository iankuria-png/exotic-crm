import React, { useState } from 'react';

export default function TestSendModal({ isSending, onClose, onSend, profile }) {
    const [form, setForm] = useState({
        phone: '',
        body: 'Exotic CRM WhatsApp test message.',
        template_name: '',
        template_language: 'en_US',
    });

    if (!profile) {
        return null;
    }

    const update = (field, value) => setForm((current) => ({ ...current, [field]: value }));

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4 py-6">
            <div className="w-full max-w-lg rounded-lg bg-white shadow-xl ring-1 ring-slate-200">
                <div className="border-b border-slate-200 px-4 py-3">
                    <h3 className="text-sm font-semibold text-slate-900">Test {profile.profile_name}</h3>
                    <p className="mt-1 text-xs text-slate-500">Sends through the selected Meta profile and records a WhatsApp message row.</p>
                </div>
                <div className="space-y-3 px-4 py-4">
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Recipient phone</span>
                        <input
                            value={form.phone}
                            onChange={(event) => update('phone', event.target.value)}
                            className="crm-input"
                            placeholder="254748612016"
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-medium text-slate-600">Text body</span>
                        <textarea
                            rows={3}
                            value={form.body}
                            onChange={(event) => update('body', event.target.value)}
                            className="crm-input"
                        />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Template name</span>
                            <input
                                value={form.template_name}
                                onChange={(event) => update('template_name', event.target.value)}
                                className="crm-input"
                                placeholder="Optional"
                            />
                        </label>
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">Language</span>
                            <input
                                value={form.template_language}
                                onChange={(event) => update('template_language', event.target.value)}
                                className="crm-input"
                            />
                        </label>
                    </div>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2">Cancel</button>
                    <button
                        type="button"
                        disabled={isSending || !form.phone.trim() || (!form.body.trim() && !form.template_name.trim())}
                        onClick={() => onSend(profile, form)}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {isSending ? 'Sending...' : 'Send test'}
                    </button>
                </div>
            </div>
        </div>
    );
}
