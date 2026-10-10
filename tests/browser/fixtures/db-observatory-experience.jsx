import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../../resources/js/components/ToastProvider';
import DbObservatory from '../../../resources/js/pages/DbObservatory';
import '../../../resources/css/app.css';
createRoot(document.getElementById('fixture')).render(<BrowserRouter><QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ToastProvider><DbObservatory /></ToastProvider></QueryClientProvider></BrowserRouter>);
