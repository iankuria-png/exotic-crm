import React from 'react';

export default function Clients() {
    return (
        <div>
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-2xl font-bold text-gray-900">Clients</h2>
                    <p className="mt-1 text-sm text-gray-500">Manage escort and agency profiles</p>
                </div>
                <button className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Sync from WordPress
                </button>
            </div>

            {/* Client table placeholder */}
            <div className="mt-6 rounded-xl bg-white shadow-sm border border-gray-200 overflow-hidden">
                <div className="p-6 text-center text-sm text-gray-500">
                    Client data will be populated after WordPress sync is configured.
                </div>
            </div>
        </div>
    );
}
