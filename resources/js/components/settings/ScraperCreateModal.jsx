import React from 'react';

export default function ScraperCreateModal({
    createScraperSourceMutation,
    dedupeModeLabel,
    onClose,
    onUpdateForm,
    platformRows,
    scraperCreateForm,
    scraperDedupeModes,
    scraperProfileLabel,
    scraperProfiles,
    scraperScheduleLabel,
    scraperSchedules,
}) {
    if (!scraperCreateForm) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={onClose}>
            <div className="w-full max-w-2xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Add Scraper Source</h3>
                        <p className="crm-panel-subtitle">Create a source profile, confirm compliance, then validate with dry-run.</p>
                    </div>
                </header>
                <div className="grid gap-3 p-4 md:grid-cols-2">
                    <select
                        value={scraperCreateForm.platform_id}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, platform_id: event.target.value }))}
                        className="crm-select"
                    >
                        <option value="">Select market</option>
                        {platformRows.map((platform) => (
                            <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                        ))}
                    </select>
                    <input
                        value={scraperCreateForm.name}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, name: event.target.value }))}
                        className="crm-input"
                        placeholder="Source name"
                    />
                    <input
                        value={scraperCreateForm.source_url}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, source_url: event.target.value }))}
                        className="crm-input md:col-span-2"
                        placeholder="https://example.com/listings"
                    />
                    <select
                        value={scraperCreateForm.parser_profile}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, parser_profile: event.target.value }))}
                        className="crm-select"
                    >
                        {scraperProfiles.map((profile) => (
                            <option key={profile} value={profile}>{scraperProfileLabel(profile)}</option>
                        ))}
                    </select>
                    <select
                        value={scraperCreateForm.fetch_schedule}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, fetch_schedule: event.target.value }))}
                        className="crm-select"
                    >
                        {scraperSchedules.map((schedule) => (
                            <option key={schedule} value={schedule}>{scraperScheduleLabel(schedule)}</option>
                        ))}
                    </select>
                    <select
                        value={scraperCreateForm.dedupe_mode}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, dedupe_mode: event.target.value }))}
                        className="crm-select md:col-span-2"
                    >
                        {scraperDedupeModes.map((mode) => (
                            <option key={mode} value={mode}>{dedupeModeLabel(mode)}</option>
                        ))}
                    </select>
                    <input
                        value={scraperCreateForm.parser_rules.row_selector}
                        onChange={(event) => onUpdateForm((current) => ({
                            ...current,
                            parser_rules: { ...current.parser_rules, row_selector: event.target.value },
                        }))}
                        className="crm-input"
                        placeholder="Row selector (optional)"
                    />
                    <input
                        value={scraperCreateForm.parser_rules.link_selector}
                        onChange={(event) => onUpdateForm((current) => ({
                            ...current,
                            parser_rules: { ...current.parser_rules, link_selector: event.target.value },
                        }))}
                        className="crm-input"
                        placeholder="Link selector (optional)"
                    />
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={Boolean(scraperCreateForm.is_active)}
                            onChange={(event) => onUpdateForm((current) => ({ ...current, is_active: event.target.checked }))}
                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                        />
                        Source is active
                    </label>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={Boolean(scraperCreateForm.compliance_ack_robots)}
                            onChange={(event) => onUpdateForm((current) => ({ ...current, compliance_ack_robots: event.target.checked }))}
                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                        />
                        Robots policy reviewed
                    </label>
                    <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            checked={Boolean(scraperCreateForm.compliance_ack_tos)}
                            onChange={(event) => onUpdateForm((current) => ({ ...current, compliance_ack_tos: event.target.checked }))}
                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                        />
                        Terms and source usage reviewed
                    </label>
                    <textarea
                        rows={2}
                        value={scraperCreateForm.compliance_notes}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, compliance_notes: event.target.value }))}
                        className="crm-input md:col-span-2"
                        placeholder="Compliance notes (optional)"
                    />
                    <textarea
                        rows={2}
                        value={scraperCreateForm.reason}
                        onChange={(event) => onUpdateForm((current) => ({ ...current, reason: event.target.value }))}
                        className="crm-input md:col-span-2"
                        placeholder="Reason"
                    />
                </div>
                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary">Cancel</button>
                    <button
                        type="button"
                        onClick={() => createScraperSourceMutation.mutate({
                            platform_id: Number(scraperCreateForm.platform_id),
                            name: scraperCreateForm.name,
                            source_url: scraperCreateForm.source_url,
                            parser_profile: scraperCreateForm.parser_profile,
                            fetch_schedule: scraperCreateForm.fetch_schedule,
                            dedupe_mode: scraperCreateForm.dedupe_mode,
                            parser_rules: scraperCreateForm.parser_rules,
                            is_active: scraperCreateForm.is_active,
                            compliance_ack_robots: scraperCreateForm.compliance_ack_robots,
                            compliance_ack_tos: scraperCreateForm.compliance_ack_tos,
                            compliance_notes: scraperCreateForm.compliance_notes,
                            reason: scraperCreateForm.reason,
                        })}
                        disabled={
                            createScraperSourceMutation.isPending
                            || !scraperCreateForm.platform_id
                            || !scraperCreateForm.name.trim()
                            || !scraperCreateForm.source_url.trim()
                            || !scraperCreateForm.reason.trim()
                        }
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {createScraperSourceMutation.isPending ? 'Creating...' : 'Create source'}
                    </button>
                </footer>
            </div>
        </div>
    );
}
