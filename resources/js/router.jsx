import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import MainLayout from './layouts/MainLayout';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import Clients from './pages/Clients';
import Payments from './pages/Payments';
import Leads from './pages/Leads';
import Settings from './pages/Settings';
import { useAuth } from './hooks/useAuth';

function ProtectedRoute({ children }) {
    const { user, isLoading } = useAuth();

    if (isLoading) {
        return (
            <div className="flex h-screen items-center justify-center">
                <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
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
                <Route path="payments" element={<Payments />} />
                <Route path="leads" element={<Leads />} />
                <Route path="settings" element={<Settings />} />
            </Route>
        </Routes>
    );
}
