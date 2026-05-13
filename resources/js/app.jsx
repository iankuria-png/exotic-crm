import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import AppRouter from './router';
import { ToastProvider } from './components/ToastProvider';
import { MediaUploadProvider } from './components/MediaUploadProvider';
import '../css/app.css';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: 1,
        },
    },
});

function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <ToastProvider>
                <MediaUploadProvider>
                    <BrowserRouter>
                        <AppRouter />
                    </BrowserRouter>
                </MediaUploadProvider>
            </ToastProvider>
        </QueryClientProvider>
    );
}

createRoot(document.getElementById('app')).render(<App />);
