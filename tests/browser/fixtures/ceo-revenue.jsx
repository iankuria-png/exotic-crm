import React from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import MainLayout from '../../../resources/js/layouts/MainLayout';
import Dashboard from '../../../resources/js/pages/Dashboard';
import { ToastProvider } from '../../../resources/js/components/ToastProvider';
import { MediaUploadProvider } from '../../../resources/js/components/MediaUploadProvider';

createRoot(document.getElementById('fixture')).render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider><MediaUploadProvider><MemoryRouter initialEntries={['/dashboard']}><Routes><Route element={<MainLayout />}>
            <Route path="dashboard" element={<Dashboard />} /><Route path="payments" element={<div>Payment ledger fixture</div>} />
        </Route></Routes></MemoryRouter></MediaUploadProvider></ToastProvider>
    </QueryClientProvider>
);
