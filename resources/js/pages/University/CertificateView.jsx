import React from 'react';
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import universityApi from '../../services/universityApi';

export default function CertificateView() {
    const { code } = useParams();
    const certificateQuery = useQuery({
        queryKey: ['university-certificate-verify', code],
        queryFn: () => universityApi.verifyCertificate(code),
        retry: false,
    });
    const certificate = certificateQuery.data?.certificate;

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-950 px-4 py-10">
            <section className="w-full max-w-2xl rounded-lg border border-amber-300 bg-white p-8 text-center shadow-2xl">
                <p className="text-xs font-bold uppercase tracking-[0.18em] text-teal-700">Exotic Online University</p>
                <h1 className="mt-3 text-3xl font-semibold text-slate-950">Certificate Verification</h1>
                {certificateQuery.isLoading ? <p className="mt-6 text-sm text-slate-500">Checking certificate...</p> : null}
                {certificate ? (
                    <div className="mt-8 space-y-4">
                        <p className="text-2xl font-semibold text-slate-950">{certificate.holder_name}</p>
                        <p className="text-slate-600">{certificate.certification_title}</p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Meta label="Status" value={certificate.status} />
                            <Meta label="Score" value={`${Math.round(Number(certificate.score_pct || 0))}%`} />
                            <Meta label="Code" value={certificate.certificate_code} />
                        </div>
                    </div>
                ) : null}
                {!certificateQuery.isLoading && !certificate ? <p className="mt-6 text-sm text-rose-600">Certificate not found.</p> : null}
            </section>
        </div>
    );
}

function Meta({ label, value }) {
    return (
        <div className="rounded-lg bg-slate-50 px-3 py-3">
            <p className="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <p className="mt-1 font-semibold text-slate-900">{value}</p>
        </div>
    );
}
