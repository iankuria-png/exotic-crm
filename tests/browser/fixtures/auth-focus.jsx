import React from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../../resources/js/components/ToastProvider';
import { MediaUploadProvider } from '../../../resources/js/components/MediaUploadProvider';
import AppRouter from '../../../resources/js/router';
import '../../../resources/css/app.css';

// The real ProtectedRoute + MainLayout + ClientDetail, so a full-tree remount is observable.
createRoot(document.getElementById('fixture')).render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ToastProvider><MediaUploadProvider><MemoryRouter initialEntries={['/clients/912345']}>
            <AppRouter />
        </MemoryRouter></MediaUploadProvider></ToastProvider>
    </QueryClientProvider>,
);
