import React, { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';
import VitalsBoard from './operations/VitalsBoard';
import QueueLanesPanel from './operations/QueueLanesPanel';
import IncidentTimeline from './operations/IncidentTimeline';
import TuningPanel from './operations/TuningPanel';
import LevelSummaryPanel from './operations/LevelSummaryPanel';
import OverrideModal from './operations/OverrideModal';

const LEVEL_NAMES = ['Normal', 'Cautious', 'Limp', 'Critical'];

/**
 * Settings → Operations.
 *
 * A sibling to System Health rather than a replacement: System Health asks
 * whether each dependency is configured and reachable, this asks whether the
 * platform is under pressure right now and what it is doing about it.
 *
 * The vitals endpoint serves the last cached sample and never recomputes, so
 * polling it — including several people opening this page because the site
 * feels slow — costs one cache read.
 */
export default function OperationsWorkspace({ canOverride = false, onOpenMarket }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [fieldError, setFieldError] = useState(null);
    const [summaryHours, setSummaryHours] = useState(24);
    const [overrideOpen, setOverrideOpen] = useState(false);
    const [tuningQuery, setTuningQuery] = useState('');
    const [changedOnly, setChangedOnly] = useState(false);

    const vitalsQuery = useQuery({
        queryKey: ['ops-vitals'],
        queryFn: async () => (await api.get('/crm/settings/system-health/vitals')).data,
        refetchInterval: 30000,
    });

    const incidentsQuery = useQuery({
        queryKey: ['ops-incidents'],
        queryFn: async () => (await api.get('/crm/settings/system-health/incidents')).data,
        refetchInterval: 120000,
    });

    const summaryQuery = useQuery({
        queryKey: ['ops-summary', summaryHours],
        queryFn: async () => (await api.get('/crm/settings/system-health/operations-summary', { params: { hours: summaryHours } })).data,
        refetchInterval: 300000,
    });

    const settingsQuery = useQuery({
        queryKey: ['ops-settings'],
        queryFn: async () => (await api.get('/crm/settings/system-health/operations-settings')).data,
    });

    const saveMutation = useMutation({
        mutationFn: async (updates) => (await api.put('/crm/settings/system-health/operations-settings', { updates })).data,
        onSuccess: (data) => {
            setFieldError(null);
            toast.success(
                data.updated === 0
                    ? 'Nothing changed.'
                    : `${data.updated} setting${data.updated === 1 ? '' : 's'} saved — in force on the next scheduler tick.`
            );
            queryClient.invalidateQueries({ queryKey: ['ops-settings'] });
        },
        onError: (error) => {
            const message = error?.response?.data?.message || 'The change was rejected.';
            setFieldError({ key: error?.response?.data?.key, message });
            toast.error(message);
        },
    });

    const resetMutation = useMutation({
        mutationFn: async (key) => (await api.post('/crm/settings/system-health/operations-settings/reset', { key })).data,
        onSuccess: () => {
            setFieldError(null);
            toast.success('Reset to default.');
            queryClient.invalidateQueries({ queryKey: ['ops-settings'] });
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Reset failed.'),
    });

    const degradationMutation = useMutation({
        mutationFn: async (payload) => {
            if (payload === null) {
                return (await api.delete('/crm/settings/system-health/degradation')).data;
            }
            return (await api.post('/crm/settings/system-health/degradation', payload)).data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['ops-vitals'] });
            queryClient.invalidateQueries({ queryKey: ['ops-incidents'] });
            queryClient.invalidateQueries({ queryKey: ['ops-summary'] });
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'The override could not be applied.'),
    });

    const handleForce = (payload) => {
        degradationMutation.mutate(payload, {
            onSuccess: () => {
                setOverrideOpen(false);
                toast.success(`Held at ${LEVEL_NAMES[payload.level]} — expires in ${payload.expires_in_minutes} minutes.`);
            },
        });
    };

    const handleRelease = () => {
        degradationMutation.mutate(null, {
            onSuccess: () => toast.success('Normal operation resumed — automatic evaluation is back in control.'),
        });
    };

    const groups = settingsQuery.data?.groups || [];

    return (
        <div className="space-y-4">
            <VitalsBoard
                vitals={vitalsQuery.data}
                isLoading={vitalsQuery.isLoading}
                error={vitalsQuery.isError}
                canOverride={canOverride}
                isMutating={degradationMutation.isPending}
                onForce={() => setOverrideOpen(true)}
                onRelease={handleRelease}
                onOpenMarket={onOpenMarket}
            />

            <LevelSummaryPanel
                summary={summaryQuery.data}
                isLoading={summaryQuery.isLoading}
                error={summaryQuery.isError}
                hours={summaryHours}
                onHoursChange={setSummaryHours}
            />

            <QueueLanesPanel lanes={vitalsQuery.data?.lanes} isLoading={vitalsQuery.isLoading} />

            <IncidentTimeline
                incidents={incidentsQuery.data?.incidents}
                isLoading={incidentsQuery.isLoading}
                error={incidentsQuery.isError}
            />

            {settingsQuery.isLoading ? (
                <div className="h-40 animate-pulse rounded-lg border border-slate-200 bg-slate-50" />
            ) : settingsQuery.isError ? (
                <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    The tuning settings could not be loaded.
                </div>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3">
                        <input
                            type="search"
                            value={tuningQuery}
                            onChange={(e) => setTuningQuery(e.target.value)}
                            placeholder="Search settings…"
                            aria-label="Search operations settings"
                            className="crm-input w-64 px-3 py-2 text-sm"
                        />
                        <label className="inline-flex items-center gap-2 text-[13px] text-slate-700">
                            <input
                                type="checkbox"
                                checked={changedOnly}
                                onChange={(e) => setChangedOnly(e.target.checked)}
                                className="h-4 w-4 rounded border-slate-300 text-teal-600"
                            />
                            Only settings changed from default
                        </label>
                        {tuningQuery || changedOnly ? (
                            <button type="button" onClick={() => { setTuningQuery(''); setChangedOnly(false); }} className="text-[12px] font-medium text-teal-700 hover:underline">
                                Clear
                            </button>
                        ) : null}
                    </div>
                    {groups.map((group) => (
                        <TuningPanel
                            key={group.key}
                            group={group}
                            fieldError={fieldError}
                            isSaving={saveMutation.isPending}
                            query={tuningQuery}
                            changedOnly={changedOnly}
                            onSave={(updates) => saveMutation.mutate(updates)}
                            onReset={(key) => resetMutation.mutate(key)}
                        />
                    ))}
                </>
            )}

            <OverrideModal
                open={overrideOpen}
                onClose={() => setOverrideOpen(false)}
                onSubmit={handleForce}
                isSubmitting={degradationMutation.isPending}
                pausedPreview={vitalsQuery.data?.paused_capabilities || []}
            />
        </div>
    );
}
