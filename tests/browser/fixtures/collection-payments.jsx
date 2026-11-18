import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../../resources/js/components/ToastProvider';
import { MediaUploadProvider } from '../../../resources/js/components/MediaUploadProvider';
import MainLayout from '../../../resources/js/layouts/MainLayout';
import ClientDetail from '../../../resources/js/pages/ClientDetail';
import CollectionWorkspace from '../../../resources/js/components/collections/CollectionWorkspace';
import '../../../resources/css/app.css';
function MarketWorkspace() { const [market, setMarket] = useState(''); return <div className="p-4"><CollectionWorkspace platformId={market} onPlatformChange={setMarket} platformOptions={[{platform_id:3,name:'Ghana'}, {platform_id:6,name:'TZ'}]} /></div>; }
createRoot(document.getElementById('fixture')).render(
<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
<ToastProvider><MediaUploadProvider><BrowserRouter><Routes><Route element={<MainLayout />}>
<Route path="/tests/browser/fixtures/collection-payments.html" element={<div className="p-4"><CollectionWorkspace /></div>} />
<Route path="/tests/browser/fixtures/collection-markets.html" element={<MarketWorkspace />} />
<Route path="/payments" element={<div className="p-4"><CollectionWorkspace /></div>} />
<Route path="/clients/:id" element={<ClientDetail />} />
</Route></Routes></BrowserRouter></MediaUploadProvider></ToastProvider></QueryClientProvider>);
