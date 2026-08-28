import api from './api';

function cleanParams(params = {}) {
    return Object.entries(params).reduce((acc, [key, value]) => {
        if (value === '' || value === 'all' || value === null || value === undefined) {
            return acc;
        }
        acc[key] = value;
        return acc;
    }, {});
}

const contactUnlocks = {
    getOverview(params = {}) {
        return api.get('/crm/settings/billing/contact-unlock', {
            params: cleanParams(params),
        }).then((response) => response.data);
    },

    getPulse(params = {}) {
        return api.get('/crm/settings/billing/contact-unlock/pulse', {
            params: cleanParams(params),
        }).then((response) => response.data);
    },

    updateSettings(payload) {
        return api.put('/crm/settings/billing/contact-unlock', payload).then((response) => response.data);
    },

    deleteRule(ruleId) {
        return api.delete(`/crm/settings/billing/contact-unlock/rules/${ruleId}`).then((response) => response.data);
    },

    runReadiness(params = {}) {
        return api.post('/crm/settings/billing/contact-unlock/readiness', cleanParams(params)).then((response) => response.data);
    },

    async exportUnlocks(params = {}) {
        const response = await api.post('/crm/settings/billing/contact-unlock/export', cleanParams(params), {
            responseType: 'blob',
        });
        const disposition = response.headers?.['content-disposition'] || '';
        const match = disposition.match(/filename="?([^"]+)"?/i);
        const filename = match?.[1] || `crm-contact-unlocks-${new Date().toISOString().slice(0, 10)}.xlsx`;
        const blob = new Blob([response.data], {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        return {
            truncated: response.headers?.['x-export-truncated'] === 'true',
            rowLimit: Number(response.headers?.['x-export-row-limit'] || 5000),
            rowTotal: Number(response.headers?.['x-export-row-total'] || 0),
        };
    },
};

export default contactUnlocks;
