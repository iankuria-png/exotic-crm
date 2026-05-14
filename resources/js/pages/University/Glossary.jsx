import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import universityApi from '../../services/universityApi';

export default function Glossary() {
    const [search, setSearch] = useState('');
    const [topic, setTopic] = useState('all');

    const termsQuery = useQuery({
        queryKey: ['university-glossary'],
        queryFn: () => universityApi.listGlossary(),
    });

    const topics = useMemo(() => {
        const set = new Set(['all']);
        (termsQuery.data?.terms || []).forEach((t) => t.topic_tag && set.add(t.topic_tag));
        return Array.from(set);
    }, [termsQuery.data]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const all = termsQuery.data?.terms || [];
        return all.filter((t) => {
            const matchesQ = !q || t.term.toLowerCase().includes(q) || (t.definition || '').toLowerCase().includes(q) || (t.aliases || []).some((a) => a.toLowerCase().includes(q));
            const matchesTopic = topic === 'all' || t.topic_tag === topic;
            return matchesQ && matchesTopic;
        });
    }, [termsQuery.data, search, topic]);

    const grouped = useMemo(() => {
        const groups = {};
        for (const t of filtered) {
            const letter = (t.term[0] || '#').toUpperCase();
            (groups[letter] ||= []).push(t);
        }
        return Object.entries(groups).sort(([a], [b]) => a.localeCompare(b));
    }, [filtered]);

    return (
        <div className="space-y-6">
            <PageHeader
                title="University Glossary"
                subtitle="Every term, alias, and definition the team needs. Sourced from the Exotic Online playbook and the failure-mode taxonomy."
                actions={<Link to="/university" className="crm-btn-secondary px-3 py-2 text-sm">← Back to University</Link>}
            />

            <section className="rounded-2xl border border-slate-200 bg-white p-5">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search a term, definition, or alias" className="crm-input w-full lg:max-w-md" />
                    <div className="flex flex-wrap gap-1.5">
                        {topics.map((t) => (
                            <button key={t} type="button" onClick={() => setTopic(t)} className={`rounded-full px-3 py-1 text-xs font-semibold transition ${topic === t ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`}>
                                {t === 'all' ? 'All topics' : t}
                            </button>
                        ))}
                    </div>
                </div>
                <p className="mt-3 text-xs text-slate-500">{filtered.length} terms</p>
            </section>

            {termsQuery.isLoading ? <p className="text-sm text-slate-500">Loading glossary…</p> : null}

            <section className="space-y-6">
                {grouped.map(([letter, items]) => (
                    <div key={letter}>
                        <h2 id={`letter-${letter}`} className="mb-3 flex items-center gap-2 text-lg font-bold tracking-tight text-slate-950">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-teal-600 to-cyan-600 text-sm font-bold text-white">{letter}</span>
                        </h2>
                        <dl className="grid gap-3 md:grid-cols-2">
                            {items.map((t) => (
                                <div key={t.id} className="rounded-xl border border-slate-200 bg-white p-4 transition hover:border-teal-300">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <dt className="text-base font-bold text-slate-950">{t.term}</dt>
                                        {t.topic_tag ? <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600">{t.topic_tag}</span> : null}
                                    </div>
                                    {t.aliases?.length ? <p className="mt-0.5 text-xs italic text-slate-500">a.k.a. {t.aliases.join(', ')}</p> : null}
                                    <dd className="mt-2 text-sm leading-6 text-slate-700">{t.definition}</dd>
                                    {t.playbook_url ? (
                                        <a href={t.playbook_url} target="_blank" rel="noopener noreferrer" className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800">
                                            Playbook source
                                            <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.5 6h2.25a.75.75 0 0 1 .75.75v2.25M21 3l-9 9m6.75 4.5v3a.75.75 0 0 1-.75.75H4.5a.75.75 0 0 1-.75-.75V6.75A.75.75 0 0 1 4.5 6h3" /></svg>
                                        </a>
                                    ) : null}
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
                {!termsQuery.isLoading && !filtered.length ? (
                    <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">No terms match your search.</div>
                ) : null}
            </section>
        </div>
    );
}
