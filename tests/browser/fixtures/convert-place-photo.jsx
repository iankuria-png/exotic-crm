import React from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import api from '../../../resources/js/services/api';
import ConvertPlaceDialog from '../../../resources/js/components/scraper/ConvertPlaceDialog';
import '../../../resources/css/app.css';

// Two Cameroon leads: one listing had a photo, one did not.
const leads = [
    { lead_id: 1, name: 'Lesly', city: 'Akwa', phone: '237680348597' },
    { lead_id: 2, name: 'Nadia', city: 'Akwa', phone: '237680348598' },
];
const option = { region_id: 4, city_id: 41, region: 'Douala', city: 'Akwa', our_available: 0 };
const ok = (config, data) => Promise.resolve({ data, status: 200, statusText: 'OK', headers: {}, config });
window.__requests = [];
api.defaults.adapter = (config) => {
    const body = config.data ? JSON.parse(config.data) : {};
    window.__requests.push({ url: config.url, body });
    if (config.url === '/crm/scraper/placements') {
        return ok(config, { matches: [{ place: 'Akwa', clean: 'Akwa', match: option, confidence: 'exact', alternatives: [] }], options: [option] });
    }
    if (config.url === '/crm/leads/bulk-convert/preview') {
        const rows = body.lead_ids.map((id) => ({ id, name: leads.find((l) => l.lead_id === id).name, contact: leads.find((l) => l.lead_id === id).phone, place: 'Akwa',
            action: 'create', message: 'Create private profile', review_token: `t${id}`, has_photo: id === 1, profile_defaults: null }));
        return ok(config, { rows, expires_at: new Date(Date.now() + 1800e3).toISOString(), limit: 100, summary: { create: rows.length }, ai_bio: false, photo: !!body.photo });
    }
    const id = Number(config.url.match(/leads\/(\d+)\//)?.[1]);
    return ok(config, { status: 'created', client_id: 4881030 + id, city: 'Akwa', message: 'Client created in Akwa. Profile is private.', ...(id === 1 ? { photo: 'queued' } : { photo: 'none' }) });
};

createRoot(document.getElementById('fixture')).render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>
            <ConvertPlaceDialog platformId={21} platformName="Cameroon" leads={leads} onClose={() => {}} onFinished={() => {}} toast={{ success() {}, error() {}, info() {} }} />
        </MemoryRouter>
    </QueryClientProvider>,
);
