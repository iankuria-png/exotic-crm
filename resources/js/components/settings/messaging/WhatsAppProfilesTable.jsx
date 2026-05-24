import React from 'react';
import KillSwitchToggle from './KillSwitchToggle';

export default function WhatsAppProfilesTable({
    isLoading,
    onEdit,
    onTest,
    onToggleKillSwitch,
    profiles,
    statusChip,
    toggling,
}) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                <div>
                    <h4 className="text-sm font-semibold text-slate-900">Meta Profiles</h4>
                    <p className="mt-1 text-xs text-slate-500">Cloud API profiles by market. Secrets stay masked after save.</p>
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-slate-200 text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2">Profile</th>
                            <th className="px-4 py-2">Market</th>
                            <th className="px-4 py-2">Status</th>
                            <th className="px-4 py-2">Credentials</th>
                            <th className="px-4 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {profiles.map((profile) => (
                            <tr key={profile.id}>
                                <td className="px-4 py-3">
                                    <div className="font-medium text-slate-900">{profile.profile_name}</div>
                                    <div className="text-xs text-slate-500">{profile.environment} • {profile.meta_api_version}</div>
                                </td>
                                <td className="px-4 py-3 text-slate-700">{profile.market?.name || 'Unknown'}</td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap gap-1.5">
                                        <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(profile.active ? 'connected' : 'configured_disabled')}`}>
                                            {profile.active ? 'active' : 'disabled'}
                                        </span>
                                        <span className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(profile.tested_at ? 'success' : 'pending')}`}>
                                            {profile.tested_at ? 'tested' : 'untested'}
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 text-xs text-slate-600">
                                    Token {profile.meta_access_token_configured ? 'stored' : 'missing'} · App secret {profile.meta_app_secret_configured ? 'stored' : 'missing'}
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-wrap justify-end gap-2">
                                        <KillSwitchToggle disabled={toggling} onToggle={onToggleKillSwitch} profile={profile} />
                                        <button type="button" onClick={() => onTest(profile)} className="crm-btn-secondary px-2.5 py-1.5 text-xs">Test</button>
                                        <button type="button" onClick={() => onEdit(profile)} className="crm-btn-secondary px-2.5 py-1.5 text-xs">Edit</button>
                                    </div>
                                </td>
                            </tr>
                        ))}
                        {!profiles.length ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-6 text-center text-sm text-slate-500">
                                    {isLoading ? 'Loading profiles...' : 'No Meta WhatsApp profiles configured yet.'}
                                </td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
