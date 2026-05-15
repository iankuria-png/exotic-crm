import api from './api';

const universityApi = {
    listCourses: (params = {}) => api.get('/crm/university/courses', { params }).then((response) => response.data),
    getCourse: (slug) => api.get(`/crm/university/courses/${slug}`).then((response) => response.data),
    createCourse: (payload) => api.post('/crm/university/courses', payload).then((response) => response.data),
    updateCourse: (id, payload) => api.patch(`/crm/university/courses/${id}`, payload).then((response) => response.data),
    createModule: (courseId, payload) => api.post(`/crm/university/courses/${courseId}/modules`, payload).then((response) => response.data),
    createLesson: (moduleId, payload) => api.post(`/crm/university/modules/${moduleId}/lessons`, payload).then((response) => response.data),
    updateLesson: (lessonId, payload) => api.patch(`/crm/university/lessons/${lessonId}`, payload).then((response) => response.data),
    uploadLessonMedia: (lessonId, formData) => api.post(`/crm/university/lessons/${lessonId}/media`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    }).then((response) => response.data),
    markProgress: (lessonId, payload) => api.post(`/crm/university/lessons/${lessonId}/progress`, payload).then((response) => response.data),
    getDailyQuote: () => api.get('/crm/university/daily-quote').then((response) => response.data),
    refreshDailyQuote: (payload = {}) => api.post('/crm/university/daily-quote/refresh', payload).then((response) => response.data),
    submitNextDayQuote: (payload) => api.post('/crm/university/daily-quote/next-day', payload).then((response) => response.data),

    listCertifications: () => api.get('/crm/university/certifications').then((response) => response.data),
    getCertification: (id) => api.get(`/crm/university/certifications/${id}`).then((response) => response.data),
    startAttempt: (certificationId) => api.post(`/crm/university/certifications/${certificationId}/attempts`).then((response) => response.data),
    submitAttempt: (attemptId, answers) => api.post(`/crm/university/attempts/${attemptId}/submit`, { answers }).then((response) => response.data),
    getAttemptResult: (attemptId) => api.get(`/crm/university/attempts/${attemptId}/result`).then((response) => response.data),
    verifyCertificate: (code) => api.get(`/crm/university/certificates/${code}/verify`).then((response) => response.data),

    createCertification: (payload) => api.post('/crm/university/certifications', payload).then((response) => response.data),
    updateCertification: (id, payload) => api.patch(`/crm/university/certifications/${id}/settings`, payload).then((response) => response.data),
    listQuestions: (certificationId) => api.get(`/crm/university/certifications/${certificationId}/questions`).then((response) => response.data),
    createQuestion: (certificationId, payload) => api.post(`/crm/university/certifications/${certificationId}/questions`, payload).then((response) => response.data),

    teamAnalytics: () => api.get('/crm/university/analytics/team').then((response) => response.data),
    certificationAnalytics: (certificationId) => api.get(`/crm/university/analytics/certifications/${certificationId}`).then((response) => response.data),
    expiringCertificates: () => api.get('/crm/university/analytics/expiring').then((response) => response.data),
    liveAttempts: () => api.get('/crm/university/analytics/live-attempts').then((response) => response.data),

    // Phase 2
    listGlossary: () => api.get('/crm/university/glossary').then((r) => r.data),
    getGlossaryTerm: (slug) => api.get(`/crm/university/glossary/${slug}`).then((r) => r.data),
    todayDrill: () => api.get('/crm/university/daily-drill').then((r) => r.data),
    answerDrill: (drillId, selectedIndex) => api.post(`/crm/university/daily-drill/${drillId}/answer`, { selected_index: selectedIndex }).then((r) => r.data),
    engagementMe: () => api.get('/crm/university/engagement/me').then((r) => r.data),
    leaderboard: () => api.get('/crm/university/leaderboard').then((r) => r.data),
    submitLessonFeedback: (lessonId, payload) => api.post(`/crm/university/lessons/${lessonId}/feedback`, payload).then((r) => r.data),
};

export default universityApi;
