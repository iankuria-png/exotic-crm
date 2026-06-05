import React from 'react';
import { Routes, Route, Navigate, useLocation } from 'react-router-dom';
import MainLayout from './layouts/MainLayout';
import Login from './pages/Login';
import BriefingShare from './pages/BriefingShare';
import Dashboard from './pages/Dashboard';
import Clients from './pages/Clients';
import ClientDetail from './pages/ClientDetail';
import Deals from './pages/Deals';
import Payments from './pages/Payments';
import Leads from './pages/Leads';
import Conversations from './pages/Conversations';
import Campaigns from './pages/Campaigns';
import PushCampaigns from './pages/PushCampaigns';
import AutoPush from './pages/AutoPush';
import Reports from './pages/Reports';
import Settings from './pages/Settings';
import Setup from './pages/Setup';
import Team from './pages/Team';
import Kyc from './pages/Kyc';
import FieldHome from './pages/Field/FieldHome';
import FieldCommissions from './pages/Field/FieldCommissions';
import AdminCommissions from './pages/AdminCommissions';
import FaqHome from './pages/Faq/FaqHome';
import FaqCategory from './pages/Faq/FaqCategory';
import FaqArticle from './pages/Faq/FaqArticle';
import FeedbackHub from './pages/Faq/FeedbackHub';
import FeedbackNew from './pages/Faq/FeedbackNew';
import FeedbackDetail from './pages/Faq/FeedbackDetail';
import UniversityHome from './pages/University/UniversityHome';
import CourseView from './pages/University/CourseView';
import CertificationLanding from './pages/University/CertificationLanding';
import QuizRunner from './pages/University/QuizRunner';
import AttemptResult from './pages/University/AttemptResult';
import CertificateView from './pages/University/CertificateView';
import UniversityGlossary from './pages/University/Glossary';
import UniversityLeaderboard from './pages/University/Leaderboard';
import CourseEditor from './pages/University/admin/CourseEditor';
import LessonEditor from './pages/University/admin/LessonEditor';
import QuestionBank from './pages/University/admin/QuestionBank';
import UniversityAnalytics from './pages/University/admin/Analytics';
import UniversityManagement from './pages/University/admin/Management';
import { useAuth } from './hooks/useAuth';

function ProtectedRoute({ children }) {
    const { user, isLoading } = useAuth();
    const location = useLocation();

    if (isLoading) {
        return (
            <div className="flex h-screen items-center justify-center">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
            </div>
        );
    }

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (user.role === 'marketing') {
        const path = location.pathname || '/';
        const allowed = path === '/'
            || path.startsWith('/clients')
            || path.startsWith('/kyc')
            || path.startsWith('/push-campaigns')
            || path.startsWith('/auto-push')
            || path.startsWith('/team')
            || path.startsWith('/faq')
            || path.startsWith('/university');

        if (!allowed) {
            return <Navigate to="/push-campaigns" replace />;
        }
    }

    if (
        user.role === 'sales'
        && (
            (location.pathname || '').startsWith('/push-campaigns')
            || (location.pathname || '').startsWith('/settings')
        )
    ) {
        return <Navigate to="/" replace />;
    }

    if (user.role === 'field_sales') {
        const path = location.pathname || '/';
        const allowed = path.startsWith('/field')
            || path.startsWith('/clients')
            || path.startsWith('/deals')
            || path.startsWith('/payments')
            || path.startsWith('/leads')
            || path.startsWith('/conversations')
            || path.startsWith('/campaigns')
            || path.startsWith('/kyc')
            || path.startsWith('/reports')
            || path.startsWith('/faq')
            || path.startsWith('/university');

        if (path === '/') {
            return <Navigate to="/field/home" replace />;
        }

        if (!allowed) {
            return <Navigate to="/field/home" replace />;
        }
    }

    return children;
}

export default function AppRouter() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/setup" element={<Setup />} />
            <Route path="/b/:token" element={<BriefingShare />} />
            <Route path="/university/verify/:code" element={<CertificateView />} />
            <Route
                path="/*"
                element={
                    <ProtectedRoute>
                        <MainLayout />
                    </ProtectedRoute>
                }
            >
                <Route index element={<Dashboard />} />
                <Route path="field/home" element={<FieldHome />} />
                <Route path="field/commissions" element={<FieldCommissions />} />
                <Route path="clients" element={<Clients />} />
                <Route path="clients/:id" element={<ClientDetail />} />
                <Route path="deals" element={<Deals />} />
                <Route path="payments" element={<Payments />} />
                <Route path="leads" element={<Leads />} />
                <Route path="conversations" element={<Conversations />} />
                <Route path="campaigns" element={<Campaigns />} />
                <Route path="kyc" element={<Kyc />} />
                <Route path="push-campaigns" element={<PushCampaigns />} />
                <Route path="auto-push" element={<AutoPush />} />
                <Route path="team" element={<Team />} />
                <Route path="faq" element={<FaqHome />} />
                <Route path="faq/c/:slug" element={<FaqCategory />} />
                <Route path="faq/a/:slug" element={<FaqArticle />} />
                <Route path="faq/feedback" element={<FeedbackHub />} />
                <Route path="faq/feedback/new" element={<FeedbackNew />} />
                <Route path="faq/feedback/:id" element={<FeedbackDetail />} />
                <Route path="university" element={<UniversityHome />} />
                <Route path="university/glossary" element={<UniversityGlossary />} />
                <Route path="university/leaderboard" element={<UniversityLeaderboard />} />
                <Route path="university/courses/:slug" element={<CourseView />} />
                <Route path="university/certifications/:id" element={<CertificationLanding />} />
                <Route path="university/quiz/:attemptId" element={<QuizRunner />} />
                <Route path="university/results/:attemptId" element={<AttemptResult />} />
                <Route path="university/manage" element={<CourseEditor />} />
                <Route path="university/manage/lessons" element={<LessonEditor />} />
                <Route path="university/manage/questions" element={<QuestionBank />} />
                <Route path="university/manage/analytics" element={<UniversityAnalytics />} />
                <Route path="university/manage/dashboard" element={<UniversityManagement />} />
                <Route path="renewals" element={<Navigate to="/campaigns" replace />} />
                <Route path="reports" element={<Reports />} />
                <Route path="admin/commissions" element={<AdminCommissions />} />
                <Route path="settings" element={<Settings />} />
            </Route>
        </Routes>
    );
}
