import React from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import CertificatePreview from '../../components/University/CertificatePreview';
import universityApi from '../../services/universityApi';
import { useToast } from '../../components/ToastProvider';

export default function CertificationLanding() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const certificationQuery = useQuery({
        queryKey: ['university-certification', id],
        queryFn: () => universityApi.getCertification(id),
    });
    const startMutation = useMutation({
        mutationFn: () => universityApi.startAttempt(id),
        onSuccess: (data) => navigate(`/university/quiz/${data.attempt.id}`, { state: data }),
        onError: (error) => toast.warning(error?.response?.data?.message || error?.response?.data?.errors?.attempts?.[0] || 'Attempt could not start.'),
    });
    const certification = certificationQuery.data?.certification;

    return (
        <div className="space-y-5">
            <PageHeader title={certification?.title || 'Certification'} subtitle={certification?.description || 'Certification rules and attempt status.'} />
            <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div className="rounded-lg border border-slate-200 bg-white p-6">
                    <h2 className="text-xl font-semibold text-slate-950">Attempt rules</h2>
                    <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <Rule label="Pass threshold" value={`${certification?.pass_threshold || 80}%`} />
                        <Rule label="Time limit" value={`${certification?.time_limit_minutes || 0} min`} />
                        <Rule label="Questions" value={certification?.question_count || 0} />
                        <Rule label="Attempts left" value={certificationQuery.data?.attempts_remaining ?? 0} />
                    </div>
                    <button type="button" onClick={() => startMutation.mutate()} disabled={startMutation.isPending || Number(certificationQuery.data?.attempts_remaining || 0) <= 0} className="crm-btn-primary mt-6 px-4 py-2 disabled:opacity-50">
                        Start certification
                    </button>
                </div>
                <CertificatePreview certificate={certification?.certificate} />
            </section>
        </div>
    );
}

function Rule({ label, value }) {
    return (
        <div className="rounded-lg bg-slate-50 px-4 py-3">
            <p className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <p className="mt-1 text-lg font-semibold text-slate-950">{value}</p>
        </div>
    );
}
