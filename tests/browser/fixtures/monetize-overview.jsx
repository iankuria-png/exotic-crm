import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Monetization from '../../../resources/js/pages/Monetization';
import '../../../resources/css/app.css';

createRoot(document.getElementById('fixture')).render(<BrowserRouter><QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><Monetization /></QueryClientProvider></BrowserRouter>);
