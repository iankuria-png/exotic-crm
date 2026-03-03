import React, { useEffect, useMemo, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '../../services/api';
import { useToast } from '../ToastProvider';

function selectorFields() {
    return {
        name_selector: '',
        age_selector: '',
        city_selector: '',
        phone_selector: '',
        image_selector: '',
        name_regex: '',
        age_regex: '',
    };
}

export default function PresetWizard({ domain, onSaved, onCancel }) {
    const toast = useToast();
    const [sampleUrl, setSampleUrl] = useState(`https://${domain}/`);
    const [detected, setDetected] = useState(null);
    const [selectors, setSelectors] = useState(selectorFields());

    useEffect(() => {
        setSampleUrl(`https://${domain}/`);
        setDetected(null);
        setSelectors(selectorFields());
    }, [domain]);

    const detectMutation = useMutation({
        mutationFn: (url) => api.post('/crm/push-campaigns/presets/detect', { url }).then((response) => response.data),
        onSuccess: (response) => {
            const payload = response?.detected || {};
            setDetected(payload);
            setSelectors((current) => ({
                ...current,
                name_selector: payload?.selectors?.name || current.name_selector,
                age_selector: payload?.selectors?.age || current.age_selector,
                city_selector: payload?.selectors?.city || current.city_selector,
                phone_selector: payload?.selectors?.phone || current.phone_selector,
                image_selector: payload?.selectors?.image || current.image_selector,
            }));
            toast.success('Selector detection complete. Review values before saving.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Selector detection failed.');
        },
    });

    const saveMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/push-campaigns/presets', payload).then((response) => response.data),
        onSuccess: () => {
            toast.success('Preset saved. You can continue campaign extraction.');
            onSaved?.();
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save preset.');
        },
    });

    const hasDetectedData = useMemo(() => Boolean(detected?.selectors), [detected]);

    return (
        <section className="rounded-lg border border-amber-300 bg-amber-50 p-3">
            <header className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h4 className="text-sm font-semibold text-amber-900">Preset Setup Required</h4>
                    <p className="text-xs text-amber-800">No extraction preset exists for <span className="font-semibold">{domain}</span>.</p>
                </div>
                {onCancel ? (
                    <button type="button" onClick={onCancel} className="text-xs font-semibold text-amber-700 hover:text-amber-900">
                        Close
                    </button>
                ) : null}
            </header>

            <div className="mt-3 space-y-3">
                <div>
                    <label className="mb-1 block text-xs font-medium text-amber-900">Sample profile URL</label>
                    <div className="flex gap-2">
                        <input
                            value={sampleUrl}
                            onChange={(event) => setSampleUrl(event.target.value)}
                            className="crm-input"
                            placeholder={`https://${domain}/escort/example-profile`}
                        />
                        <button
                            type="button"
                            onClick={() => detectMutation.mutate(sampleUrl.trim())}
                            disabled={detectMutation.isPending || !sampleUrl.trim()}
                            className="crm-btn-secondary whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {detectMutation.isPending ? 'Detecting...' : 'Auto-detect'}
                        </button>
                    </div>
                </div>

                {hasDetectedData ? (
                    <div className="rounded-md border border-amber-200 bg-white p-2 text-xs text-slate-700">
                        <p className="font-semibold text-slate-900">Detected values</p>
                        <div className="mt-1 grid gap-1 md:grid-cols-2">
                            <p><span className="font-medium">Name:</span> {detected?.extracted?.name || 'n/a'}</p>
                            <p><span className="font-medium">Age:</span> {detected?.extracted?.age || 'n/a'}</p>
                            <p><span className="font-medium">City:</span> {detected?.extracted?.city || 'n/a'}</p>
                            <p><span className="font-medium">Phone:</span> {detected?.extracted?.phone || 'n/a'}</p>
                        </div>
                    </div>
                ) : null}

                <div className="grid gap-2 md:grid-cols-2">
                    <input
                        value={selectors.name_selector}
                        onChange={(event) => setSelectors((current) => ({ ...current, name_selector: event.target.value }))}
                        className="crm-input"
                        placeholder="Name selector"
                    />
                    <input
                        value={selectors.age_selector}
                        onChange={(event) => setSelectors((current) => ({ ...current, age_selector: event.target.value }))}
                        className="crm-input"
                        placeholder="Age selector"
                    />
                    <input
                        value={selectors.city_selector}
                        onChange={(event) => setSelectors((current) => ({ ...current, city_selector: event.target.value }))}
                        className="crm-input"
                        placeholder="City selector"
                    />
                    <input
                        value={selectors.phone_selector}
                        onChange={(event) => setSelectors((current) => ({ ...current, phone_selector: event.target.value }))}
                        className="crm-input"
                        placeholder="Phone selector"
                    />
                    <input
                        value={selectors.image_selector}
                        onChange={(event) => setSelectors((current) => ({ ...current, image_selector: event.target.value }))}
                        className="crm-input md:col-span-2"
                        placeholder="Image selector"
                    />
                    <input
                        value={selectors.name_regex}
                        onChange={(event) => setSelectors((current) => ({ ...current, name_regex: event.target.value }))}
                        className="crm-input"
                        placeholder="Name regex (optional)"
                    />
                    <input
                        value={selectors.age_regex}
                        onChange={(event) => setSelectors((current) => ({ ...current, age_regex: event.target.value }))}
                        className="crm-input"
                        placeholder="Age regex (optional)"
                    />
                </div>

                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => saveMutation.mutate({
                            domain,
                            test_url: sampleUrl.trim() || undefined,
                            test_result: detected || undefined,
                            ...selectors,
                            is_active: true,
                        })}
                        disabled={saveMutation.isPending || !selectors.name_selector.trim()}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {saveMutation.isPending ? 'Saving...' : 'Save preset'}
                    </button>
                </div>
            </div>
        </section>
    );
}
