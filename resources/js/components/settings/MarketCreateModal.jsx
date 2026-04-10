import React from 'react';

export default function MarketCreateModal({
    createForm,
    createPlatformMutation,
    onClose,
    onUpdateForm,
}) {
    if (!createForm) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Add Market Integration</h3>
                        <p className="crm-panel-subtitle">Create a new market profile with WordPress sync API and provisioning database credentials.</p>
                    </div>
                </header>
                <div className="grid gap-3 p-4 md:grid-cols-2">
                    <input value={createForm.name} onChange={(event) => onUpdateForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="Market name" />
                    <input value={createForm.domain} onChange={(event) => onUpdateForm((current) => ({ ...current, domain: event.target.value }))} className="crm-input" placeholder="Domain" />
                    <input value={createForm.country} onChange={(event) => onUpdateForm((current) => ({ ...current, country: event.target.value }))} className="crm-input" placeholder="Country" />
                    <input value={createForm.phone_prefix} onChange={(event) => onUpdateForm((current) => ({ ...current, phone_prefix: event.target.value }))} className="crm-input" placeholder="Phone prefix" />
                    <input value={createForm.currency_code} onChange={(event) => onUpdateForm((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))} className="crm-input" placeholder="Currency code" />
                    <input value={createForm.timezone} onChange={(event) => onUpdateForm((current) => ({ ...current, timezone: event.target.value }))} className="crm-input" placeholder="PHP/IANA timezone, e.g. Africa/Nairobi" />
                    <input value={createForm.wp_api_url} onChange={(event) => onUpdateForm((current) => ({ ...current, wp_api_url: event.target.value }))} className="crm-input md:col-span-2" placeholder="WordPress Sync API URL" />
                    <input value={createForm.support_chat_url} onChange={(event) => onUpdateForm((current) => ({ ...current, support_chat_url: event.target.value }))} className="crm-input md:col-span-2" placeholder="Support board URL" />
                    <input value={createForm.wp_api_user} onChange={(event) => onUpdateForm((current) => ({ ...current, wp_api_user: event.target.value }))} className="crm-input" placeholder="WordPress API user" />
                    <input value={createForm.wp_api_password} onChange={(event) => onUpdateForm((current) => ({ ...current, wp_api_password: event.target.value }))} className="crm-input" type="password" placeholder="WordPress API password" />
                    <input value={createForm.db_host} onChange={(event) => onUpdateForm((current) => ({ ...current, db_host: event.target.value }))} className="crm-input" placeholder="WordPress DB host" />
                    <input value={createForm.db_name} onChange={(event) => onUpdateForm((current) => ({ ...current, db_name: event.target.value }))} className="crm-input" placeholder="WordPress DB name" />
                    <input value={createForm.db_user} onChange={(event) => onUpdateForm((current) => ({ ...current, db_user: event.target.value }))} className="crm-input" placeholder="WordPress DB user" />
                    <input value={createForm.db_pass} onChange={(event) => onUpdateForm((current) => ({ ...current, db_pass: event.target.value }))} className="crm-input" type="password" placeholder="WordPress DB password" />
                    <input value={createForm.db_prefix} onChange={(event) => onUpdateForm((current) => ({ ...current, db_prefix: event.target.value }))} className="crm-input md:col-span-2" placeholder="WordPress table prefix (for example wp_)" />
                    <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={createForm.is_active} onChange={(event) => onUpdateForm((current) => ({ ...current, is_active: event.target.checked }))} className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200" />
                        Market is active
                    </label>
                    <p className="md:col-span-2 rounded-md border border-teal-200 bg-teal-50/70 px-3 py-2 text-xs text-teal-700">
                        Onboarding flow: create market, configure package pricing, activate market, then run initial full sync.
                    </p>
                    <p className="md:col-span-2 rounded-md border border-sky-200 bg-sky-50/80 px-3 py-2 text-xs text-sky-800">
                        For CRM provisioning, copy the DB values from the market site’s `wp-config.php`: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `$table_prefix`.
                    </p>
                </div>
                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                    <button
                        type="button"
                        onClick={() => createPlatformMutation.mutate({
                            ...createForm,
                            reason: 'Created from integrations workspace',
                        })}
                        disabled={createPlatformMutation.isPending || !createForm.name.trim() || !createForm.domain.trim() || !createForm.country.trim() || !createForm.timezone.trim()}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {createPlatformMutation.isPending ? 'Creating...' : 'Create market'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
