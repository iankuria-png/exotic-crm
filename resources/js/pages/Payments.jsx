import React from 'react';

export default function Payments() {
    return (
        <div>
            <h2 className="text-2xl font-bold text-gray-900">Payments</h2>
            <p className="mt-1 text-sm text-gray-500">Payment history and matching queue</p>

            {/* Payments table placeholder */}
            <div className="mt-6 rounded-xl bg-white shadow-sm border border-gray-200 overflow-hidden">
                <div className="p-6 text-center text-sm text-gray-500">
                    Payment records from the Ads API will be displayed here.
                </div>
            </div>
        </div>
    );
}
