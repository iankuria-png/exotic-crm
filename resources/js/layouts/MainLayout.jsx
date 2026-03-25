import React, { useState } from 'react';
import { Link, Outlet } from 'react-router-dom';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../hooks/useAuth';
import { useHeartbeat } from '../hooks/useHeartbeat';

export default function MainLayout() {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { user } = useAuth();
    const isMarketing = user?.role === 'marketing';

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

                    <p className="hidden text-sm font-medium text-slate-500 lg:block">Sales operations workspace</p>

                    {!isMarketing ? (
                        <div className="hidden items-center gap-2 sm:flex">
                            <Link to="/leads" className="crm-btn-secondary px-3 py-1.5 text-xs">
                                New lead
                            </Link>
                            <Link to="/clients" className="crm-btn-primary px-3 py-1.5 text-xs">
                                New client
                            </Link>
                        </div>
                    ) : null}
                </header>

                {/* Page content */}
                <main className="flex-1 overflow-y-auto p-4 lg:p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
