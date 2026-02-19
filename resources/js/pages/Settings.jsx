import React from 'react';

export default function Settings() {
    return (
        <div>
            <h2 className="text-2xl font-bold text-gray-900">Settings</h2>
            <p className="mt-1 text-sm text-gray-500">Market configuration and user management</p>

            <div className="mt-6 space-y-6">
                <div className="rounded-xl bg-white p-6 shadow-sm border border-gray-200">
                    <h3 className="text-lg font-semibold text-gray-900">Markets</h3>
                    <p className="mt-1 text-sm text-gray-500">Configure platform connections and sync settings.</p>
                </div>

                <div className="rounded-xl bg-white p-6 shadow-sm border border-gray-200">
                    <h3 className="text-lg font-semibold text-gray-900">Products</h3>
                    <p className="mt-1 text-sm text-gray-500">Manage subscription tiers and pricing.</p>
                </div>

                <div className="rounded-xl bg-white p-6 shadow-sm border border-gray-200">
                    <h3 className="text-lg font-semibold text-gray-900">Users</h3>
                    <p className="mt-1 text-sm text-gray-500">Manage sales agents and their market assignments.</p>
                </div>
            </div>
        </div>
    );
}
