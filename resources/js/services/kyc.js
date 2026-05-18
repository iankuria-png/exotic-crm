import api from './api';

const kyc = {
    getQueue(params = {}) {
        return api.get('/crm/kyc/queue', { params }).then((response) => response.data);
    },

    getQueueCount() {
        return api.get('/crm/kyc/queue-count').then((response) => response.data);
    },

    getSubject(subjectId) {
        return api.get(`/crm/kyc/subjects/${subjectId}`).then((response) => response.data);
    },

    approveSubject(subjectId, payload = {}) {
        return api.post(`/crm/kyc/subjects/${subjectId}/approve`, payload).then((response) => response.data);
    },

    rejectSubject(subjectId, payload) {
        return api.post(`/crm/kyc/subjects/${subjectId}/reject`, payload).then((response) => response.data);
    },

    requestInfo(subjectId, payload) {
        return api.post(`/crm/kyc/subjects/${subjectId}/request-info`, payload).then((response) => response.data);
    },

    reRequest(subjectId, payload = {}) {
        return api.post(`/crm/kyc/subjects/${subjectId}/re-request`, payload).then((response) => response.data);
    },

    bulkReRequest(subjectIds, reason = '') {
        return api.post('/crm/kyc/subjects/bulk-re-request', {
            subject_ids: subjectIds,
            reason,
        }).then((response) => response.data);
    },

    uploadDocument(subjectId, payload) {
        const formData = new FormData();
        formData.append('kind', payload.kind);
        formData.append('upload_source_channel', payload.upload_source_channel);
        formData.append('upload_note', payload.upload_note);
        formData.append('file', payload.file);

        return api.post(`/crm/kyc/subjects/${subjectId}/documents`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        }).then((response) => response.data);
    },

    deleteDocument(subjectId, documentId) {
        return api.delete(`/crm/kyc/subjects/${subjectId}/documents/${documentId}`).then((response) => response.data);
    },

    getSettings() {
        return api.get('/crm/kyc/settings').then((response) => response.data);
    },

    updateSettings(payload) {
        return api.put('/crm/kyc/settings', payload).then((response) => response.data);
    },

    testS3Connectivity(payload) {
        return api.post('/crm/kyc/settings/test-s3', payload).then((response) => response.data);
    },

    setClientVerified(clientId, payload) {
        return api.post(`/crm/clients/${clientId}/verified-status`, payload).then((response) => response.data);
    },

    async fetchDocumentBlob(url) {
        const response = await api.get(url, { responseType: 'blob' });
        return response.data;
    },

    exportQueueCsv(rows, filename = 'kyc-queue-export.csv') {
        const headers = [
            'subject_id',
            'client_id',
            'client_name',
            'platform',
            'status',
            'verified',
            'verified_source',
            'required',
            'expires_at',
            'updated_at',
        ];
        const escapeCell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
        const csv = [
            headers.join(','),
            ...rows.map((row) => [
                row.id,
                row.client?.id,
                row.client?.name,
                row.client?.platform?.name || row.client?.platform?.platform_name,
                row.status,
                row.client?.verified ? 'yes' : 'no',
                row.client?.verified_source || '',
                row.client?.kyc_required ? 'yes' : 'no',
                row.expires_at || '',
                row.updated_at || '',
            ].map(escapeCell).join(',')),
        ].join('\n');

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const objectUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(objectUrl);
    },
};

export default kyc;
