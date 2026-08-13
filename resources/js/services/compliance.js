import api from './api';

const compliance = {
    getClientCompliance(clientId) {
        return api.get(`/crm/clients/${clientId}/compliance`).then((response) => response.data);
    },

    exportEvidencePack(clientId, payload) {
        return api.post(`/crm/clients/${clientId}/compliance/evidence-pack`, payload).then((response) => response.data);
    },
};

export default compliance;
