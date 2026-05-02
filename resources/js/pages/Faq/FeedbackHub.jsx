import React, { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import PageHeader from '../../components/PageHeader';
import faqApi from '../../services/faqApi';
import KindChip from '../../components/faq/KindChip';
import StatusChip from '../../components/faq/StatusChip';
import SeverityChip from '../../components/faq/SeverityChip';
import FeedbackKanban from '../../components/faq/FeedbackKanban';
import useFaqAdmin from '../../hooks/useFaqAdmin';

const tabs = [
    { id: 'bugs', label: 'Bugs', kind: 'bug' },
    { id: 'features', label: 'Feature requests', kind: 'feature_request' },
    { id: 'suggestions', label: 'Suggestions', kind: 'general' },
    { id: 'mine', label: 'My submissions', mine: true },
];

export default function FeedbackHub() {
    const admin = useFaqAdmin();
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const [view, setView] = useState('table');
    const activeTab = searchParams.get('tab') || 'bugs';
    const activeTabDef = tabs.find((tab) => tab.id === activeTab) || tabs[0];

    const query = useQuery({
        queryKey: ['faq-feedback', activeTab, searchParams.get('status') || '', searchParams.get('severity') || ''],
        queryFn: () => faqApi.listFeedback({
            kind: activeTabDef.kind,
            tab: activeTabDef.mine ? 'mine' : undefined,
            status: searchParams.get('status') || undefined,
            severity: searchParams.get('severity') || undefined,
            per_page: 50,
        }),
    });

    const items = query.data?.feedback || [];
    const meta = query.data?.meta || {};
    const list = useMemo(() => items, [items]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Feedback Hub"
                subtitle="Track bugs, feature requests, and product suggestions across the CRM."
                actions={(
                    <>
                        {admin.isAdmin ? (
                            <button type="button" onClick={() => setView((current) => current === 'table' ? 'kanban' : 'table')} className="crm-btn-secondary px-3 py-2 text-sm">
                                {view === 'table' ? 'Kanban view' : 'Table view'}
                            </button>
                        ) : null}
                        <Link to={`/faq/feedback/new?kind=${encodeURIComponent(activeTabDef.kind || 'general')}`} className="crm-btn-primary px-3 py-2 text-sm">
                            Submit feedback
                        </Link>
                    </>
                )}
            />

            <section className="crm-surface space-y-4 px-5 py-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        {tabs.map((tab) => (
                            <button
                                key={tab.id}
                                type="button"
                                onClick={() => {
                                    const next = new URLSearchParams(searchParams);
                                    next.set('tab', tab.id);
                                    setSearchParams(next);
                                }}
                                className={`rounded-xl px-3 py-2 text-sm font-medium transition ${activeTab === tab.id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                    <div className="text-sm text-slate-500">
                        {admin.isAdmin ? `${meta.admin_new_count || 0} new for triage` : `${meta.submitter_update_count || 0} updates waiting`}
                    </div>
                </div>

                {view === 'kanban' && admin.isAdmin ? (
                    <FeedbackKanban items={list} onOpen={(item) => navigate(`/faq/feedback/${item.id}`)} />
                ) : (
                    <div className="space-y-3">
                        {list.map((item) => (
                            <Link key={item.id} to={`/faq/feedback/${item.id}`} className="block rounded-2xl border border-slate-200 px-4 py-4 transition hover:border-teal-200 hover:bg-teal-50/50">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <KindChip kind={item.kind} />
                                            <StatusChip status={item.status} />
                                            <SeverityChip severity={item.severity} />
                                        </div>
                                        <p className="text-sm font-semibold text-slate-900">{item.title}</p>
                                        <p className="text-sm text-slate-500">{item.comment}</p>
                                    </div>
                                    <div className="text-right text-xs text-slate-500">
                                        <p>{item.votes_count} votes</p>
                                        <p>{item.comments_count} comments</p>
                                    </div>
                                </div>
                            </Link>
                        ))}
                        {!query.isLoading && !list.length ? <p className="text-sm text-slate-500">No feedback matches the current tab or filters.</p> : null}
                    </div>
                )}
            </section>
        </div>
    );
}
