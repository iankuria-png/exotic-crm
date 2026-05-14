import React from 'react';

export default function CertificatePreview({ certificate }) {
    if (!certificate) return null;

    return (
        <div className="rounded-lg border border-amber-300 bg-white p-5 shadow-sm">
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Certificate</p>
            <p className="mt-2 text-lg font-semibold text-slate-950">{certificate.certificate_code}</p>
            <p className="mt-1 text-sm text-slate-500">Valid until {certificate.expires_at ? new Date(certificate.expires_at).toLocaleDateString() : 'unavailable'}</p>
            <div className="mt-4 flex flex-wrap gap-2">
                {certificate.pdf_url ? <a href={certificate.pdf_url} className="crm-btn-primary px-3 py-2 text-sm">Download PDF</a> : null}
                <a href={certificate.verify_url} className="crm-btn-secondary px-3 py-2 text-sm">Verify</a>
            </div>
        </div>
    );
}
