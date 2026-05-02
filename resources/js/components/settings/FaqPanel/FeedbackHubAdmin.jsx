import React from 'react';
import { useNavigate } from 'react-router-dom';
import FeedbackKanban from '../../faq/FeedbackKanban';

export default function FeedbackHubAdmin({ items = [] }) {
    const navigate = useNavigate();

    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div>
                <p className="text-sm font-semibold text-slate-900">Feedback hub admin</p>
                <p className="text-sm text-slate-500">Triage items by status without leaving Settings.</p>
            </div>
            <FeedbackKanban items={items} onOpen={(item) => navigate(`/faq/feedback/${item.id}`)} />
        </section>
    );
}
