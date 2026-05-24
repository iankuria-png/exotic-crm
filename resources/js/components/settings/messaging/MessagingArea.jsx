import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../services/api';
import TestSendModal from './TestSendModal';
import WhatsAppProfileForm from './WhatsAppProfileForm';
import WhatsAppProfilesTable from './WhatsAppProfilesTable';
import WhatsAppRoutingPanel from './WhatsAppRoutingPanel';

export default function MessagingArea({ platformRows, statusChip, toast }) {
    const queryClient = useQueryClient();
    const platforms = useMemo(() => platformRows.map((platform) => ({
        id: platform.platform_id,
        name: platform.platform_name,
        country: platform.country,
    })), [platformRows]);
    const [profileFormOpen, setProfileFormOpen] = useState(false);
    const [editingProfile, setEditingProfile] = useState(null);
    const [testingProfile, setTestingProfile] = useState(null);
    const [selectedMarketId, setSelectedMarketId] = useState(platforms[0]?.id || null);
    const [selectedMessageType, setSelectedMessageType] = useState('transactional');

    const profilesQuery = useQuery({
        queryKey: ['messaging-profiles'],
        queryFn: () => api.get('/crm/messaging/whatsapp/profiles').then((response) => response.data),
    });

    const profiles = profilesQuery.data?.profiles || [];
    const messageTypes = profilesQuery.data?.message_types || ['transactional', 'conversation', 'renewal', 'payment_link', 'credential'];
    const enabledProfiles = profiles.filter((profile) => profile.active && !profile.kill_switch_enabled).length;

    useEffect(() => {
        if (!selectedMarketId && platforms[0]?.id) {
            setSelectedMarketId(platforms[0].id);
        }
    }, [platforms, selectedMarketId]);

    const invalidateMessaging = () => {
        queryClient.invalidateQueries({ queryKey: ['messaging-profiles'] });
        queryClient.invalidateQueries({ queryKey: ['messaging-routing'] });
    };

    const saveProfileMutation = useMutation({
        mutationFn: ({ profile, form }) => {
            const payload = Object.fromEntries(Object.entries({
                ...form,
                market_id: Number(form.market_id),
                meta_access_token: form.meta_access_token || undefined,
                meta_webhook_verify_token: form.meta_webhook_verify_token || undefined,
                meta_app_secret: form.meta_app_secret || undefined,
            }).filter(([, value]) => value !== undefined));

            if (profile) {
                return api.put(`/crm/messaging/whatsapp/profiles/${profile.id}`, payload).then((response) => response.data);
            }

            return api.post('/crm/messaging/whatsapp/profiles', payload).then((response) => response.data);
        },
        onSuccess: () => {
            toast?.success?.('WhatsApp profile saved.');
            setProfileFormOpen(false);
            setEditingProfile(null);
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not save WhatsApp profile.'),
    });

    const killSwitchMutation = useMutation({
        mutationFn: ({ profile, enabled }) => api.post(`/crm/messaging/whatsapp/profiles/${profile.id}/kill-switch`, { enabled }).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('Kill switch updated.');
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not update kill switch.'),
    });

    const testMutation = useMutation({
        mutationFn: ({ profile, form }) => api.post(`/crm/messaging/whatsapp/profiles/${profile.id}/test`, {
            ...form,
            template_name: form.template_name || null,
        }).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('WhatsApp test sent.');
            setTestingProfile(null);
            invalidateMessaging();
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'WhatsApp test failed.'),
    });

    const saveRoutingMutation = useMutation({
        mutationFn: (payload) => api.put(`/crm/messaging/whatsapp/routing/${selectedMarketId}/${selectedMessageType}`, payload).then((response) => response.data),
        onSuccess: () => {
            toast?.success?.('WhatsApp routing updated.');
            queryClient.invalidateQueries({ queryKey: ['messaging-routing', selectedMarketId, selectedMessageType] });
        },
        onError: (error) => toast?.error?.(error.response?.data?.message || 'Could not update WhatsApp routing.'),
    });

    return (
        <section className="crm-surface overflow-hidden">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Messaging Gateway</h3>
                    <p className="crm-panel-subtitle">Configure Meta WhatsApp Cloud API profiles and market routing. Production producers remain SMS-only until their rollout phase.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <button type="button" onClick={() => profilesQuery.refetch()} className="crm-btn-secondary px-3 py-2">Refresh</button>
                    <button
                        type="button"
                        onClick={() => {
                            setEditingProfile(null);
                            setProfileFormOpen(true);
                        }}
                        className="crm-btn-primary"
                    >
                        New profile
                    </button>
                </div>
            </header>

            <div className="space-y-4 p-4">
                <div className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Profiles</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{profiles.length}</p>
                    </div>
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Ready</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{enabledProfiles}</p>
                    </div>
                    <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Default API</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{profilesQuery.data?.meta_default_api_version || 'v25.0'}</p>
                    </div>
                </div>

                <WhatsAppProfilesTable
                    isLoading={profilesQuery.isLoading}
                    onEdit={(profile) => {
                        setEditingProfile(profile);
                        setProfileFormOpen(true);
                    }}
                    onTest={setTestingProfile}
                    onToggleKillSwitch={(profile, enabled) => killSwitchMutation.mutate({ profile, enabled })}
                    profiles={profiles}
                    statusChip={statusChip}
                    toggling={killSwitchMutation.isPending}
                />

                <WhatsAppRoutingPanel
                    messageTypes={messageTypes}
                    onMessageTypeChange={setSelectedMessageType}
                    onMarketChange={setSelectedMarketId}
                    onSave={(payload) => saveRoutingMutation.mutate(payload)}
                    platforms={platforms}
                    saving={saveRoutingMutation.isPending}
                    selectedMarketId={selectedMarketId}
                    selectedMessageType={selectedMessageType}
                    statusChip={statusChip}
                />
            </div>

            {profileFormOpen ? (
                <WhatsAppProfileForm
                    isSaving={saveProfileMutation.isPending}
                    onClose={() => {
                        setProfileFormOpen(false);
                        setEditingProfile(null);
                    }}
                    onSave={(profile, form) => saveProfileMutation.mutate({ profile, form })}
                    platforms={platforms}
                    profile={editingProfile}
                />
            ) : null}

            <TestSendModal
                isSending={testMutation.isPending}
                onClose={() => setTestingProfile(null)}
                onSend={(profile, form) => testMutation.mutate({ profile, form })}
                profile={testingProfile}
            />
        </section>
    );
}
