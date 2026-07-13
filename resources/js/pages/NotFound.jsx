import React from 'react';
import { Link } from 'react-router-dom';

export default function NotFound() {
    return (
        <div className="flex min-h-[70vh] items-center justify-center p-6">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                <p className="text-5xl font-bold tracking-tight text-teal-600">404</p>
                <h1 className="mt-3 text-lg font-semibold text-slate-900">Page not found</h1>
                <p className="mt-2 text-sm text-slate-600">
                    The page you’re looking for doesn’t exist or may have moved.
                </p>
                <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-center">
                    <Link
                        to="/"
                        className="inline-flex items-center justify-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700"
                    >
                        Back to dashboard
                    </Link>
                    <Link
                        to="/network-check"
                        className="inline-flex items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Run a network check
                    </Link>
                </div>
            </div>
        </div>
    );
}
