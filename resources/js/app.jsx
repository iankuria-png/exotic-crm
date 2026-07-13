import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, QueryCache, MutationCache } from '@tanstack/react-query';
import { BrowserRouter } from 'react-router-dom';
import AppRouter from './router';
import { ToastProvider } from './components/ToastProvider';
import { MediaUploadProvider } from './components/MediaUploadProvider';
import ErrorBoundary from './components/ErrorBoundary';
import { bridgeToast } from './services/toastBridge';
import { classifyError, friendlyMessage, errorTitle, getRequestId, INFRA_CATEGORIES } from './services/errors';
import { reportClientError } from './services/clientErrorReporter';
import '../css/app.css';

// Global safety net for query/mutation failures. We deliberately only surface
// INFRA categories (network/timeout/server) — the classes components almost
// never handle locally — so this never double-toasts the many hand-wired
// local onError/toast.error handlers. Domain + validation errors stay local.
const infra = new Set(INFRA_CATEGORIES);
const lastToastByCategory = new Map();

function handleGlobalQueryError(error) {
    const category = classifyError(error);
    if (!infra.has(category)) {
        return;
    }

    // Collapse a burst (e.g. several background pollers failing at once) into a
    // single toast per category within a short window.
    const now = Date.now();
    const last = lastToastByCategory.get(category) || 0;
    if (now - last < 5_000) {
        return;
    }
    lastToastByCategory.set(category, now);

    const toast = bridgeToast();
    if (toast) {
        toast.error(friendlyMessage(category, error), { title: errorTitle(category) });
    }

    // Server errors are unexpected — mirror them into Error Logs. Network/timeout
    // are connectivity, not app faults, so we don't report those.
    if (category === 'server_error') {
        reportClientError({
            message: `API server_error: ${error?.config?.method || 'get'} ${error?.config?.url || 'unknown'}`,
            category: 'server_error',
            requestId: getRequestId(error),
        });
    }
}

const queryClient = new QueryClient({
    queryCache: new QueryCache({ onError: handleGlobalQueryError }),
    mutationCache: new MutationCache({ onError: handleGlobalQueryError }),
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: 1,
        },
    },
});

function App() {
    return (
        <ErrorBoundary label="app">
            <QueryClientProvider client={queryClient}>
                <ToastProvider>
                    <MediaUploadProvider>
                        <BrowserRouter>
                            <AppRouter />
                        </BrowserRouter>
                    </MediaUploadProvider>
                </ToastProvider>
            </QueryClientProvider>
        </ErrorBoundary>
    );
}

createRoot(document.getElementById('app')).render(<App />);
