import React, { useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import ErrorBoundary from '../components/ErrorBoundary';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../hooks/useAuth';
import { useHeartbeat } from '../hooks/useHeartbeat';
import HelpButton from '../components/faq/HelpButton';
import FeedbackButton from '../components/faq/FeedbackButton';
import Walkthrough from '../components/faq/Walkthrough';
import { useMediaUploads } from '../components/MediaUploadProvider';

function WorkspaceActionIcon({ children, className = '' }) {
    return (
        <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md ${className}`} aria-hidden="true">
            {children}
        </span>
    );
}

export default function MainLayout() {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [uploadMenuOpen, setUploadMenuOpen] = useState(false);
    const { user, impersonation, logout } = useAuth();
    const location = useLocation();
    const { activeCount, failedCount, uploads, retryUpload, dismissUpload } = useMediaUploads();
    const isMarketing = user?.role === 'marketing';
    const showUploadIndicator = activeCount > 0 || failedCount > 0;
    const uploadIndicatorLabel = activeCount > 0
        ? `${activeCount} upload${activeCount === 1 ? '' : 's'} active`
        : `${failedCount} upload${failedCount === 1 ? '' : 's'} failed`;

    useHeartbeat(user);

    return (
        <div className="flex h-screen min-h-svh overflow-hidden">
            {/* Mobile overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 z-30 bg-black/50 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar */}
            <div className={`
                fixed inset-y-0 left-0 z-40 w-64 transform transition-transform duration-200 lg:static lg:translate-x-0
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}
            `}>
                <Sidebar onClose={() => setSidebarOpen(false)} />
            </div>

            {/* Main content */}
            <div className="flex flex-1 flex-col overflow-hidden">
                {/* Top bar */}
                <header className="flex h-14 items-center justify-between border-b border-slate-200 bg-white/95 px-4 backdrop-blur lg:px-6">
                    <button
                        onClick={() => setSidebarOpen(true)}
                        className="rounded-md p-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 lg:hidden"
                        aria-label="Open navigation"
                    >
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                        </svg>
                    </button>

                    <p className="hidden text-sm font-medium text-slate-500 lg:block">Customer service workspace</p>

                    <div className="flex items-center gap-2">
                        <div className="hidden items-center gap-2 sm:flex">
                            {showUploadIndicator ? (
                                <div className="relative">
                                    <button
                                        type="button"
                                        onClick={() => setUploadMenuOpen((open) => !open)}
                                        className={`rounded-md border px-3 py-1.5 text-xs font-semibold transition ${
                                            failedCount > 0 && activeCount === 0
                                                ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'
                                                : 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100'
                                        }`}
                                        aria-expanded={uploadMenuOpen}
                                    >
                                        {uploadIndicatorLabel}
                                    </button>
                                    {uploadMenuOpen ? (
                                        <div className="absolute right-0 z-50 mt-2 w-80 rounded-lg border border-slate-200 bg-white p-3 shadow-lg">
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Media uploads</p>
                                                <button
                                                    type="button"
                                                    onClick={() => setUploadMenuOpen(false)}
                                                    className="text-xs font-semibold text-slate-500 hover:text-slate-700"
                                                >
                                                    Close
                                                </button>
                                            </div>
                                            <div className="mt-2 max-h-72 space-y-2 overflow-y-auto">
                                                {uploads.filter((upload) => ['uploading', 'failed'].includes(upload.status)).map((upload) => (
                                                    <div key={upload.id} className="rounded-md bg-slate-50 px-3 py-2 text-xs">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <div className="min-w-0">
                                                                <p className="truncate font-semibold text-slate-800">{upload.label}</p>
                                                                <p className="truncate text-slate-500">{upload.clientName || `Client #${upload.clientId}`}</p>
                                                            </div>
                                                            <span className={upload.status === 'failed' ? 'text-rose-700' : 'text-amber-700'}>
                                                                {upload.status === 'failed' ? 'Failed' : 'Active'}
                                                            </span>
                                                        </div>
                                                        <p className="mt-1 text-slate-500">{upload.message}</p>
                                                        {upload.status === 'failed' ? (
                                                            <div className="mt-2 flex justify-end gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => retryUpload(upload.id)}
                                                                    className="rounded-md border border-slate-200 bg-white px-2 py-1 font-semibold text-slate-700 hover:bg-slate-100"
                                                                >
                                                                    Retry
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => dismissUpload(upload.id)}
                                                                    className="rounded-md px-2 py-1 font-semibold text-slate-500 hover:bg-slate-100"
                                                                >
                                                                    Dismiss
                                                                </button>
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}
                            <HelpButton />
                            <FeedbackButton />
                        </div>
                        {!isMarketing ? (
                            <div className="hidden items-center gap-2 sm:flex">
                                <Link
                                    to="/network-check"
                                    title="Run a browser and CRM connectivity check"
                                    className="group inline-flex h-10 min-w-[8.75rem] items-center gap-2 rounded-lg border border-sky-200 bg-sky-50/80 px-2.5 text-left text-sky-800 shadow-sm transition hover:border-sky-300 hover:bg-sky-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300"
                                >
                                    <WorkspaceActionIcon className="bg-white text-sky-700 ring-1 ring-sky-200 transition group-hover:text-sky-800">
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3.75 19.5h16.5M6.75 15.75l2.5-2.5 2.25 2.25 5.75-6.25M16.5 9.25h-4.25M16.5 9.25v4.25" />
                                        </svg>
                                    </WorkspaceActionIcon>
                                    <span className="min-w-0">
                                        <span className="block text-xs font-semibold leading-4">Network Check</span>
                                        <span className="hidden truncate text-[10px] font-medium leading-3 text-sky-600 xl:block">Run diagnostics</span>
                                    </span>
                                </Link>
                                <Link
                                    to="/clients?create=1"
                                    data-tour="nav-new-client"
                                    title="Open the client creation form"
                                    className="group inline-flex h-10 min-w-[8.25rem] items-center gap-2 rounded-lg bg-teal-700 px-2.5 text-left text-white shadow-sm shadow-teal-900/10 transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                >
                                    <WorkspaceActionIcon className="bg-white/15 text-white ring-1 ring-white/25 transition group-hover:bg-white/20">
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0M19.5 8.25v4.5M21.75 10.5h-4.5" />
                                        </svg>
                                    </WorkspaceActionIcon>
                                    <span className="min-w-0">
                                        <span className="block text-xs font-semibold leading-4">New client</span>
                                        <span className="hidden truncate text-[10px] font-medium leading-3 text-teal-100 xl:block">Create profile</span>
                                    </span>
                                </Link>
                            </div>
                        ) : null}
                    </div>
                </header>

                {impersonation ? (
                    <div data-tour="dashboard-impersonation-banner" className="border-b border-amber-200 bg-amber-50 px-4 py-2.5 lg:px-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-amber-900">
                                Viewing CRM as <span className="font-semibold">{user?.name}</span> ({String(user?.role || '').replace('_', ' ')}) from {impersonation.impersonator?.name}.
                            </p>
                            <button
                                type="button"
                                onClick={logout}
                                className="rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 transition hover:bg-amber-100"
                            >
                                Return to admin
                            </button>
                        </div>
                    </div>
                ) : null}

                {/* Page content */}
                <main className="flex-1 overflow-y-auto p-4 lg:p-6">
                    {/* Keyed by path so a page crash resets when the user navigates. */}
                    <ErrorBoundary variant="inline" label="page" key={location.pathname}>
                        <Outlet />
                    </ErrorBoundary>
                </main>
                <Walkthrough />
            </div>
        </div>
    );
}
