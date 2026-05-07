import api from './api';

function withParams(path, params = {}) {
    const search = new URLSearchParams();

    Object.entries(params || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '') {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => search.append(`${key}[]`, item));
            return;
        }

        search.set(key, value);
    });

    const query = search.toString();
    return query ? `${path}?${query}` : path;
}

export const faqApi = {
    listCategories(params) {
        return api.get(withParams('/crm/faq/categories', params)).then((response) => response.data);
    },
    createCategory(payload) {
        return api.post('/crm/faq/categories', payload).then((response) => response.data);
    },
    updateCategory(slug, payload) {
        return api.patch(`/crm/faq/categories/${slug}`, payload).then((response) => response.data);
    },
    reorderCategories(ids) {
        return api.post('/crm/faq/categories/reorder', { ids }).then((response) => response.data);
    },
    deleteCategory(slug) {
        return api.delete(`/crm/faq/categories/${slug}`).then((response) => response.data);
    },
    listArticles(params) {
        return api.get(withParams('/crm/faq/articles', params)).then((response) => response.data);
    },
    getContext(params) {
        return api.get(withParams('/crm/faq/context', params)).then((response) => response.data);
    },
    getArticle(slug, params) {
        return api.get(withParams(`/crm/faq/articles/${slug}`, params)).then((response) => response.data);
    },
    createArticle(payload) {
        return api.post('/crm/faq/articles', payload).then((response) => response.data);
    },
    updateArticle(slug, payload) {
        return api.patch(`/crm/faq/articles/${slug}`, payload).then((response) => response.data);
    },
    saveDraft(slug, payload) {
        return api.patch(`/crm/faq/articles/${slug}/draft`, payload).then((response) => response.data);
    },
    publishArticle(slug) {
        return api.post(`/crm/faq/articles/${slug}/publish`).then((response) => response.data);
    },
    duplicateArticle(slug) {
        return api.post(`/crm/faq/articles/${slug}/duplicate`).then((response) => response.data);
    },
    reorderArticles(ids) {
        return api.post('/crm/faq/articles/reorder', { ids }).then((response) => response.data);
    },
    deleteArticle(slug) {
        return api.delete(`/crm/faq/articles/${slug}`).then((response) => response.data);
    },
    uploadMedia(slug, formData) {
        return api.post(`/crm/faq/articles/${slug}/media`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        }).then((response) => response.data);
    },
    deleteMedia(slug, mediaId) {
        return api.delete(`/crm/faq/articles/${slug}/media/${mediaId}`).then((response) => response.data);
    },
    listWalkthroughs() {
        return api.get('/crm/faq/walkthroughs').then((response) => response.data);
    },
    getWalkthrough(slug) {
        return api.get(`/crm/faq/walkthroughs/${slug}`).then((response) => response.data);
    },
    createWalkthrough(payload) {
        return api.post('/crm/faq/walkthroughs', payload).then((response) => response.data);
    },
    updateWalkthrough(slug, payload) {
        return api.patch(`/crm/faq/walkthroughs/${slug}`, payload).then((response) => response.data);
    },
    deleteWalkthrough(slug) {
        return api.delete(`/crm/faq/walkthroughs/${slug}`).then((response) => response.data);
    },
    listFeedback(params) {
        return api.get(withParams('/crm/faq/feedback', params)).then((response) => response.data);
    },
    getFeedback(id) {
        return api.get(`/crm/faq/feedback/${id}`).then((response) => response.data);
    },
    createFeedback(payload) {
        const isMultipart = payload instanceof FormData;
        return api.post('/crm/faq/feedback', payload, isMultipart ? {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        } : undefined).then((response) => response.data);
    },
    updateFeedback(id, payload) {
        return api.patch(`/crm/faq/feedback/${id}`, payload).then((response) => response.data);
    },
    deleteFeedback(id) {
        return api.delete(`/crm/faq/feedback/${id}`).then((response) => response.data);
    },
    toggleFeedbackVote(id) {
        return api.post(`/crm/faq/feedback/${id}/votes/toggle`).then((response) => response.data);
    },
    listFeedbackComments(id) {
        return api.get(`/crm/faq/feedback/${id}/comments`).then((response) => response.data);
    },
    addFeedbackComment(id, payload) {
        return api.post(`/crm/faq/feedback/${id}/comments`, payload).then((response) => response.data);
    },
    deleteFeedbackComment(feedbackId, commentId) {
        return api.delete(`/crm/faq/feedback/${feedbackId}/comments/${commentId}`).then((response) => response.data);
    },
};

export default faqApi;
