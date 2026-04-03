import React from 'react';

export default function ScraperConfigPanel({
    activeScraperSources,
    dedupeModeLabel,
    latestScraperRunResult,
    onOpenCreateModal,
    onOpenRunConfirm,
    onSelectSource,
    onUpdateScraperEditor,
    onUpdateScraperRunForm,
    platformRows,
    runScraperSourceMutation,
    scraperBlockedOrFailed,
    scraperDedupeModes,
    scraperEditor,
    scraperProfileLabel,
    scraperProfiles,
    scraperRunForm,
    scraperRuns,
    scraperScheduleLabel,
    scraperSchedules,
    scraperSources,
    scraperStatusLabel,
    selectedScraperCompliant,
    selectedScraperRules,
    selectedScraperSource,
    selectedScraperSourceId,
    statusChip,
    updateScraperSourceMutation,
}) {
    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Scraper Configuration</h3>
                    <p className="crm-panel-subtitle">Configure compliant scrape sources, preview with dry-run, then import leads into the pipeline.</p>
                </div>
                <button
                    type="button"
                    onClick={onOpenCreateModal}
                    className="crm-btn-primary px-3 py-2"
                >
                    Add scraper source
                </button>
            </header>

            <div className="grid gap-4 p-4 xl:grid-cols-12">
                <div className="space-y-3 xl:col-span-4">
                    <div className="grid gap-2 sm:grid-cols-3 xl:grid-cols-1">
                        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Configured</p>
                            <p className="mt-1 text-lg font-semibold text-slate-900">{scraperSources.length}</p>
                        </div>
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-emerald-700">Active</p>
                            <p className="mt-1 text-lg font-semibold text-emerald-800">{activeScraperSources}</p>
                        </div>
                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-amber-700">Needs review</p>
                            <p className="mt-1 text-lg font-semibold text-amber-800">{scraperBlockedOrFailed}</p>
                        </div>
                    </div>

                    <section className="rounded-lg border border-slate-200 bg-white">
                        <div className="border-b border-slate-100 px-3 py-2">
                            <p className="text-sm font-semibold text-slate-900">Sources</p>
                            <p className="text-xs text-slate-500">Select a source to edit parser and compliance settings.</p>
                        </div>
                        <div className="max-h-72 space-y-2 overflow-auto p-2">
                            {scraperSources.length === 0 ? (
                                <p className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-xs text-slate-500">
                                    No scraper sources yet. Add a source to start dry-run validation.
                                </p>
                            ) : scraperSources.map((source) => (
                                <button
                                    key={source.id}
                                    type="button"
                                    onClick={() => onSelectSource(source.id)}
                                    className={`w-full rounded-md border px-3 py-2 text-left transition ${
                                        selectedScraperSourceId === source.id
                                            ? 'border-teal-300 bg-teal-50'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">{source.name}</p>
                                            <p className="text-[11px] text-slate-500">{source.platform_name}</p>
                                        </div>
                                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${statusChip(source.last_run_status || 'unknown')}`}>
                                            {scraperStatusLabel(source.last_run_status)}
                                        </span>
                                    </div>
                                    <p className="mt-1 truncate text-[11px] text-slate-500">{source.source_url}</p>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white">
                        <div className="border-b border-slate-100 px-3 py-2">
                            <p className="text-sm font-semibold text-slate-900">Recent runs</p>
                        </div>
                        <div className="max-h-48 space-y-2 overflow-auto p-2">
                            {scraperRuns.length === 0 ? (
                                <p className="text-xs text-slate-500">No scraper runs yet.</p>
                            ) : scraperRuns.map((run) => (
                                <div key={run.id} className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5">
                                    <p className="text-xs font-semibold text-slate-800">{run.source_name || `Source #${run.scraper_source_id}`}</p>
                                    <p className="text-[11px] text-slate-500">
                                        {run.mode.replace('_', ' ')} • {run.status} • {run.discovered_count} discovered • {run.created_count} created
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                </div>

                <div className="space-y-3 xl:col-span-8">
                    {!selectedScraperSource || !scraperEditor ? (
                        <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            Select a scraper source to manage parser, compliance, and run controls.
                        </div>
                    ) : (
                        <>
                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Source Profile</h4>
                                <p className="mt-1 text-xs text-slate-500">Define extraction profile, schedule, dedupe strategy, and compliance guardrails.</p>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <input
                                        value={scraperEditor.name}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, name: event.target.value }))}
                                        className="crm-input"
                                        placeholder="Source name"
                                    />
                                    <select
                                        value={scraperEditor.platform_id}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, platform_id: event.target.value }))}
                                        className="crm-select"
                                        disabled
                                    >
                                        {platformRows.map((platform) => (
                                            <option key={platform.platform_id} value={platform.platform_id}>{platform.platform_name}</option>
                                        ))}
                                    </select>
                                    <input
                                        value={scraperEditor.source_url}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, source_url: event.target.value }))}
                                        className="crm-input md:col-span-2"
                                        placeholder="https://example.com/listings"
                                    />
                                    <select
                                        value={scraperEditor.parser_profile}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, parser_profile: event.target.value }))}
                                        className="crm-select"
                                    >
                                        {scraperProfiles.map((profile) => (
                                            <option key={profile} value={profile}>{scraperProfileLabel(profile)}</option>
                                        ))}
                                    </select>
                                    <select
                                        value={scraperEditor.fetch_schedule}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, fetch_schedule: event.target.value }))}
                                        className="crm-select"
                                    >
                                        {scraperSchedules.map((schedule) => (
                                            <option key={schedule} value={schedule}>{scraperScheduleLabel(schedule)}</option>
                                        ))}
                                    </select>
                                    <select
                                        value={scraperEditor.dedupe_mode}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, dedupe_mode: event.target.value }))}
                                        className="crm-select md:col-span-2"
                                    >
                                        {scraperDedupeModes.map((mode) => (
                                            <option key={mode} value={mode}>{dedupeModeLabel(mode)}</option>
                                        ))}
                                    </select>
                                    <input
                                        value={selectedScraperRules.row_selector}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({
                                            ...current,
                                            parser_rules: { ...current.parser_rules, row_selector: event.target.value },
                                        }))}
                                        className="crm-input"
                                        placeholder="Row selector (optional)"
                                    />
                                    <input
                                        value={selectedScraperRules.link_selector}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({
                                            ...current,
                                            parser_rules: { ...current.parser_rules, link_selector: event.target.value },
                                        }))}
                                        className="crm-input"
                                        placeholder="Link selector (optional)"
                                    />
                                    <input
                                        value={selectedScraperRules.name_selector}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({
                                            ...current,
                                            parser_rules: { ...current.parser_rules, name_selector: event.target.value },
                                        }))}
                                        className="crm-input"
                                        placeholder="Name selector (optional)"
                                    />
                                    <input
                                        value={selectedScraperRules.phone_selector}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({
                                            ...current,
                                            parser_rules: { ...current.parser_rules, phone_selector: event.target.value },
                                        }))}
                                        className="crm-input"
                                        placeholder="Phone selector (optional)"
                                    />
                                    <input
                                        value={selectedScraperRules.email_selector}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({
                                            ...current,
                                            parser_rules: { ...current.parser_rules, email_selector: event.target.value },
                                        }))}
                                        className="crm-input md:col-span-2"
                                        placeholder="Email selector (optional)"
                                    />

                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(scraperEditor.is_active)}
                                            onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, is_active: event.target.checked }))}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Source is active
                                    </label>
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(scraperEditor.compliance_ack_robots)}
                                            onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, compliance_ack_robots: event.target.checked }))}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Robots policy reviewed
                                    </label>
                                    <label className="md:col-span-2 flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(scraperEditor.compliance_ack_tos)}
                                            onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, compliance_ack_tos: event.target.checked }))}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Terms and source usage reviewed
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={scraperEditor.compliance_notes}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, compliance_notes: event.target.value }))}
                                        className="crm-input md:col-span-2"
                                        placeholder="Compliance notes (optional)"
                                    />
                                    <textarea
                                        rows={2}
                                        value={scraperEditor.reason}
                                        onChange={(event) => onUpdateScraperEditor((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input md:col-span-2"
                                        placeholder="Reason for profile update"
                                    />
                                </div>
                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={() => updateScraperSourceMutation.mutate({
                                            sourceId: selectedScraperSource.id,
                                            payload: {
                                                name: scraperEditor.name,
                                                source_url: scraperEditor.source_url,
                                                parser_profile: scraperEditor.parser_profile,
                                                fetch_schedule: scraperEditor.fetch_schedule,
                                                dedupe_mode: scraperEditor.dedupe_mode,
                                                parser_rules: scraperEditor.parser_rules,
                                                is_active: scraperEditor.is_active,
                                                compliance_ack_robots: scraperEditor.compliance_ack_robots,
                                                compliance_ack_tos: scraperEditor.compliance_ack_tos,
                                                compliance_notes: scraperEditor.compliance_notes,
                                                reason: scraperEditor.reason,
                                            },
                                        })}
                                        disabled={
                                            updateScraperSourceMutation.isPending
                                            || !scraperEditor.name.trim()
                                            || !scraperEditor.source_url.trim()
                                            || !scraperEditor.reason.trim()
                                        }
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {updateScraperSourceMutation.isPending ? 'Saving...' : 'Save scraper source'}
                                    </button>
                                </div>
                            </section>

                            <section className="rounded-lg border border-slate-200 bg-white p-3">
                                <h4 className="text-sm font-semibold text-slate-900">Manual Run</h4>
                                <p className="mt-1 text-xs text-slate-500">Run a controlled scrape now. Dry-run previews candidates without creating leads.</p>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(scraperRunForm.dry_run)}
                                            onChange={(event) => onUpdateScraperRunForm((current) => ({
                                                ...current,
                                                dry_run: event.target.checked,
                                                reason: event.target.checked
                                                    ? 'Dry-run scraper execution from settings'
                                                    : 'Scraper import run from settings',
                                            }))}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                        />
                                        Dry run preview
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="250"
                                        value={scraperRunForm.max_candidates}
                                        onChange={(event) => onUpdateScraperRunForm((current) => ({ ...current, max_candidates: Number(event.target.value || 50) }))}
                                        className="crm-input"
                                        placeholder="Max candidates"
                                    />
                                    <textarea
                                        rows={2}
                                        value={scraperRunForm.reason}
                                        onChange={(event) => onUpdateScraperRunForm((current) => ({ ...current, reason: event.target.value }))}
                                        className="crm-input md:col-span-2"
                                        placeholder="Reason for scraper run"
                                    />
                                </div>

                                {!selectedScraperCompliant ? (
                                    <p className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-700">
                                        Robots and terms acknowledgements are required before runs can proceed.
                                    </p>
                                ) : null}

                                <div className="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        onClick={onOpenRunConfirm}
                                        disabled={runScraperSourceMutation.isPending || !scraperRunForm.reason.trim() || !selectedScraperSource}
                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {runScraperSourceMutation.isPending ? 'Running...' : 'Run scraper now'}
                                    </button>
                                </div>

                                {latestScraperRunResult ? (
                                    <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs text-slate-700">
                                        <p className="font-semibold text-slate-800">Latest run summary</p>
                                        <p className="mt-1">
                                            Status: <span className="font-semibold">{scraperStatusLabel(latestScraperRunResult.status)}</span> •
                                            Discovered: <span className="font-semibold">{latestScraperRunResult.discovered || 0}</span> •
                                            Created: <span className="font-semibold">{latestScraperRunResult.created || 0}</span> •
                                            Duplicates: <span className="font-semibold">{latestScraperRunResult.duplicates || 0}</span>
                                        </p>
                                        {latestScraperRunResult.message ? (
                                            <p className="mt-1 text-slate-600">{latestScraperRunResult.message}</p>
                                        ) : null}
                                        {Array.isArray(latestScraperRunResult.errors) && latestScraperRunResult.errors.length > 0 ? (
                                            <div className="mt-2 rounded-md border border-amber-200 bg-white p-2">
                                                <p className="font-semibold text-amber-800">Errors</p>
                                                <ul className="mt-1 space-y-1">
                                                    {latestScraperRunResult.errors.slice(0, 3).map((error) => (
                                                        <li key={error} className="text-slate-700">{error}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ) : null}
                                        {Array.isArray(latestScraperRunResult.preview) && latestScraperRunResult.preview.length > 0 ? (
                                            <div className="mt-2 rounded-md border border-slate-200 bg-white p-2">
                                                <p className="font-semibold text-slate-800">Candidate preview</p>
                                                <div className="mt-1 space-y-1">
                                                    {latestScraperRunResult.preview.slice(0, 4).map((row, index) => (
                                                        <p key={`${row.source_url || row.name || 'row'}-${index}`} className="text-slate-700">
                                                            <span className="font-semibold text-slate-900">{row.name || 'Unnamed'}</span>
                                                            {row.phone_normalized ? ` • ${row.phone_normalized}` : ''}
                                                            {row.email ? ` • ${row.email}` : ''}
                                                            {row.result ? ` • ${row.result}` : ''}
                                                        </p>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>
                                ) : null}
                            </section>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}
