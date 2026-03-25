import React from 'react';
import { Routes, Route, Navigate, useLocation } from 'react-router-dom';
import MainLayout from './layouts/MainLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Clients from './pages/Clients';
import ClientDetail from './pages/ClientDetail';
import Deals from './pages/Deals';
import Payments from './pages/Payments';
import Leads from './pages/Leads';
import Conversations from './pages/Conversations';
import Campaigns from './pages/Campaigns';
import PushCampaigns from './pages/PushCampaigns';
import Reports from './pages/Reports';
import Settings from './pages/Settings';
import Setup from './pages/Setup';
import Team from './pages/Team';
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
        const allowed = path === '/' || path.startsWith('/clients') || path.startsWith('/push-campaigns') || path.startsWith('/team');

        if (!allowed) {
            return <Navigate to="/push-campaigns" replace />;
        }
    }

    if (user.role === 'sales' && (location.pathname || '').startsWith('/push-campaigns')) {
        return <Navigate to="/" replace />;
    }

    return children;
}

export default function AppRouter() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
            <Route path="/setup" element={<Setup />} />
            <Route
                path="/*"
                element={
                    <ProtectedRoute>
                        <MainLayout />
                    </ProtectedRoute>
                }
            >
                <Route index element={<Dashboard />} />
                <Route path="clients" element={<Clients />} />
                <Route path="clients/:id" element={<ClientDetail />} />
                <Route path="deals" element={<Deals />} />
                <Route path="payments" element={<Payments />} />
                <Route path="leads" element={<Leads />} />
                <Route path="conversations" element={<Conversations />} />
                <Route path="campaigns" element={<Campaigns />} />
                <Route path="push-campaigns" element={<PushCampaigns />} />
                <Route path="team" element={<Team />} />
                <Route path="renewals" element={<Navigate to="/campaigns" replace />} />
                <Route path="reports" element={<Reports />} />
                <Route path="settings" element={<Settings />} />
            </Route>
        </Routes>
    );
}
