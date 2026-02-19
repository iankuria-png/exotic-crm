import React from 'react';

export default function Dashboard() {
    return (
        <div>
            <h2 className="text-2xl font-bold text-gray-900">Dashboard</h2>
            <p className="mt-1 text-sm text-gray-500">Sales overview and work queue</p>

            {/* KPI cards placeholder */}
            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {['Active Clients', 'Pending Leads', 'Revenue (MTD)', 'Expiring Soon'].map((label) => (
                    <div key={label} className="rounded-xl bg-white p-5 shadow-sm border border-gray-200">
                        <p className="text-sm font-medium text-gray-500">{label}</p>
                        <p className="mt-2 text-3xl font-bold text-gray-900">--</p>
                    </div>
                ))}
            </div>

            {/* Work queue placeholder */}
            <div className="mt-8 rounded-xl bg-white p-6 shadow-sm border border-gray-200">
                <h3 className="text-lg font-semibold text-gray-900">Work Queue</h3>
                <p className="mt-2 text-sm text-gray-500">Upcoming tasks and follow-ups will appear here.</p>
            </div>
        </div>
    );
}
