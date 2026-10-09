import api from './api';

const base = '/crm/db-observatory';
const data = (promise) => promise.then((response) => response.data);

const dbObservatory = {
    actionAvailability: (id) => data(api.get(`${base}/findings/${id}/actions`)),
    previewActions: (id, payload) => data(api.post(`${base}/findings/${id}/actions/preview`, payload, { timeout: 90_000 })),
    operation: (id) => data(api.get(`${base}/actions/${id}`)),
    staffSessionsPreview: (id, payload) => data(api.post(`${base}/markets/${id}/staff-sessions/preview`, payload, { timeout: 90_000 })),
    operations: (params) => data(api.get(`${base}/actions`, { params })),
    confirmOperation: (id, payload) => data(api.post(`${base}/actions/${id}/confirm`, payload)),
    restorePreview: (id, payload) => data(api.post(`${base}/actions/${id}/restore/preview`, payload, { timeout: 90_000 })),
    cancelOperation: (id) => data(api.post(`${base}/actions/${id}/cancel`)),
    verifyOperation: (id) => data(api.post(`${base}/actions/${id}/verify`)),
    campaignPreview: (payload) => data(api.post(`${base}/actions/campaign/preview`, payload, { timeout: 120_000 })),
    campaign: (id) => data(api.get(`${base}/actions/campaign/${id}`)),
    confirmCampaign: (id, payload) => data(api.post(`${base}/actions/campaign/${id}/confirm`, payload)),
    cancelCampaign: (id) => data(api.post(`${base}/actions/campaign/${id}/cancel`)),
    filesystem: (id) => data(api.get(`${base}/markets/${id}/filesystem`)),
    diagnoseFilesystem: (id) => data(api.post(`${base}/markets/${id}/filesystem/diagnose`, {}, { timeout: 90_000 })),
    quarantinePreview: (id, payload) => data(api.post(`${base}/filesystem/${id}/quarantine/preview`, payload)),
    overview: () => data(api.get(`${base}/overview`)),
    markets: () => data(api.get(`${base}/markets`)),
    inventory: (platformId) => data(api.get(`${base}/markets/${platformId}/inventory`)),

    passes: (params) => data(api.get(`${base}/passes`, { params })),
    pass: (id) => data(api.get(`${base}/passes/${id}`)),
    startPass: (payload) => data(api.post(`${base}/passes`, payload)),
    pausePass: (id) => data(api.post(`${base}/passes/${id}/pause`)),
    resumePass: (id) => data(api.post(`${base}/passes/${id}/resume`)),
    stopPass: (id) => data(api.post(`${base}/passes/${id}/stop`)),

    run: (id) => data(api.get(`${base}/market-runs/${id}`)),
    runEvents: (id, params) => data(api.get(`${base}/market-runs/${id}/events`, { params })),
    runCoverage: (id) => data(api.get(`${base}/market-runs/${id}/coverage`)),

    findings: (params) => data(api.get(`${base}/findings`, { params })),
    finding: (id) => data(api.get(`${base}/findings/${id}`)),
    updateFinding: (id, payload) => data(api.patch(`${base}/findings/${id}`, payload)),
    suppress: (id, payload) => data(api.post(`${base}/findings/${id}/suppressions`, payload)),
    revokeSuppression: (id) => data(api.delete(`${base}/suppressions/${id}`)),
    exportFindings: (params) => api.get(`${base}/findings/export`, { params, responseType: 'blob', timeout: 120_000 }),

    rules: () => data(api.get(`${base}/rules`)),
    updateRule: (key, payload) => data(api.put(`${base}/rules/${encodeURIComponent(key)}`, payload)),
    testRule: (key, platformId) => data(api.post(`${base}/rules/${encodeURIComponent(key)}/test`, { platform_id: platformId })),
    lists: () => data(api.get(`${base}/lists`)),
    updateList: (key, payload) => data(api.put(`${base}/lists/${encodeURIComponent(key)}`, payload)),

    schedules: () => data(api.get(`${base}/schedules`)),
    createSchedule: (payload) => data(api.post(`${base}/schedules`, payload)),
    updateSchedule: (id, payload) => data(api.patch(`${base}/schedules/${id}`, payload)),
    disableSchedule: (id) => data(api.delete(`${base}/schedules/${id}`)),

    settings: () => data(api.get(`${base}/settings`)),
    updateSettings: (payload) => data(api.put(`${base}/settings`, payload)),
    connections: () => data(api.get(`${base}/connections`)),
    updateConnection: (platformId, payload) => data(api.put(`${base}/connections/${platformId}`, payload)),
    preflight: (platformId, payload = {}) => data(api.post(`${base}/connections/${platformId}/preflight`, payload, { timeout: 90_000 })),

    audit: (params) => data(api.get(`${base}/audit`, { params })),
};

export default dbObservatory;
