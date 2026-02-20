import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import MainLayout from './layouts/MainLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Clients from './pages/Clients';
import ClientDetail from './pages/ClientDetail';
import Deals from './pages/Deals';
import Payments from './pages/Payments';
import Leads from './pages/Leads';
import Conversations from './pages/Conversations';
import Renewals from './pages/Renewals';
import Reports from './pages/Reports';
import Settings from './pages/Settings';
import { useAuth } from './hooks/useAuth';

function ProtectedRoute({ children }) {
    const { user, isLoading } = useAuth();

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

    return children;
}

export default function AppRouter() {
    return (
        <Routes>
            <Route path="/login" element={<Login />} />
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
                <Route path="renewals" element={<Renewals />} />
                <Route path="reports" element={<Reports />} />
                <Route path="settings" element={<Settings />} />
            </Route>
        </Routes>
    );
}
