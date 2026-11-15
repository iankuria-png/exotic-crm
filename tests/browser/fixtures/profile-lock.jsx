import React from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../../resources/js/components/ToastProvider';
import { MediaUploadProvider } from '../../../resources/js/components/MediaUploadProvider';
import MainLayout from '../../../resources/js/layouts/MainLayout';
import Clients from '../../../resources/js/pages/Clients';
import ClientDetail from '../../../resources/js/pages/ClientDetail';
import '../../../resources/css/app.css';

// Isolated fixture: the spec answers every /api request; no Laravel or database.
const entry = new URLSearchParams(window.location.search).get('entry') || '/clients';

createRoot(document.getElementById('fixture')).render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider><MediaUploadProvider><MemoryRouter initialEntries={[entry]}>
            <Routes>
                <Route element={<MainLayout />}>
                    <Route path="clients" element={<Clients />} />
                    <Route path="clients/:id" element={<ClientDetail />} />
                </Route>
            </Routes>
        </MemoryRouter></MediaUploadProvider></ToastProvider>
    </QueryClientProvider>,
);
