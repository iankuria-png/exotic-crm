import React from 'react';

export default function Leads() {
    return (
        <div>
            <h2 className="text-2xl font-bold text-gray-900">Leads</h2>
            <p className="mt-1 text-sm text-gray-500">Lead pipeline and conversion tracking</p>

            {/* Lead pipeline placeholder */}
            <div className="mt-6 grid gap-4 sm:grid-cols-5">
                {['New', 'Contacted', 'Qualified', 'Converted', 'Lost'].map((stage) => (
                    <div key={stage} className="rounded-xl bg-white p-4 shadow-sm border border-gray-200">
                        <h3 className="text-sm font-semibold text-gray-700">{stage}</h3>
                        <p className="mt-1 text-2xl font-bold text-gray-900">--</p>
                    </div>
                ))}
            </div>
        </div>
    );
}
