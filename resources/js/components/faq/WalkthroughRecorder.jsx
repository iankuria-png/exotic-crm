import React, { useEffect, useState } from 'react';

function emptyStep(position = 1) {
    return {
        element_selector: '',
        title: '',
        body: '',
        position,
        side: 'bottom',
        align: 'start',
    };
}

export default function WalkthroughRecorder({ open, articleTitle, onClose, onSave }) {
    const [slug, setSlug] = useState('');
    const [name, setName] = useState('');
    const [steps, setSteps] = useState([emptyStep(1)]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const generated = String(articleTitle || 'walkthrough').toLowerCase().replace(/[^a-z0-9]+/g, '-');
        setSlug(generated);
        setName(articleTitle ? `${articleTitle} walkthrough` : 'New walkthrough');
        setSteps([emptyStep(1)]);
    }, [articleTitle, open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/45 p-4" onClick={onClose}>
            <div className="w-full max-w-4xl rounded-2xl border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <div className="border-b border-slate-100 px-5 py-4">
                    <h3 className="text-lg font-semibold text-slate-900">Walkthrough recorder</h3>
                    <p className="text-sm text-slate-500">Use `data-tour` selectors from the CRM UI to build a guided flow.</p>
                </div>
                <div className="space-y-4 px-5 py-5">
                    <div className="grid gap-3 md:grid-cols-2">
                        <input value={name} onChange={(event) => setName(event.target.value)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Walkthrough name" />
                        <input value={slug} onChange={(event) => setSlug(event.target.value)} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Slug" />
                    </div>
                    {steps.map((step, index) => (
                        <div key={index} className="grid gap-3 rounded-2xl border border-slate-200 px-4 py-4 lg:grid-cols-2">
                            <input value={step.element_selector} onChange={(event) => setSteps((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, element_selector: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder='[data-tour="payments-auto-match-queue"]' />
                            <input value={step.title} onChange={(event) => setSteps((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, title: event.target.value } : item))} className="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Step title" />
                            <textarea value={step.body} onChange={(event) => setSteps((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, body: event.target.value } : item))} rows={4} className="rounded-xl border border-slate-200 px-3 py-2 text-sm lg:col-span-2" placeholder="Explain what the agent should do here." />
                        </div>
                    ))}
                    <button type="button" onClick={() => setSteps((current) => [...current, emptyStep(current.length + 1)])} className="crm-btn-secondary px-3 py-2 text-sm">Add step</button>
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                    <button type="button" onClick={() => onSave?.({ slug, name, steps: steps.map((step, index) => ({ ...step, position: index + 1 })) })} className="crm-btn-primary px-3 py-2 text-sm">Save walkthrough</button>
                </div>
            </div>
        </div>
    );
}
