import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import api from '../services/api';
import { storeAuthSnapshot } from '../utils/authStorage';

const brandLogo = '/Exotic%20Online%20Adv%20Logo-01-ChOpI09X.png';

const highlights = [
    'Track leads, payments, and subscriptions from one workspace.',
    'Stay aligned with market teams through shared operational context.',
    'Keep sensitive client workflows protected with controlled access.',
];

export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [googleLoading, setGoogleLoading] = useState(false);
    const [checkingSetup, setCheckingSetup] = useState(true);
    const [authConfig, setAuthConfig] = useState({
        password: { enabled: true, policy: 'enabled' },
        google: { enabled: false, primary: false, configured: false },
    });
    const { login } = useAuth();
    const navigate = useNavigate();

    useEffect(() => {
        let cancelled = false;

        const checkSetupStatus = async () => {
            try {
                const params = new URLSearchParams(window.location.search);
                const errorMessage = params.get('error');
                if (errorMessage && !cancelled) {
                    setError(errorMessage);
                }

                const { data } = await api.get('/crm/setup/status');
                if (!cancelled && data?.is_first_run) {
                    navigate('/setup', { replace: true });
                    return;
                }

                const configResponse = await api.get('/crm/auth/config');
                if (!cancelled) {
                    setAuthConfig(configResponse.data);
                }

                if (params.get('google') === 'success') {
                    const meResponse = await api.get('/crm/me');
                    if (!cancelled) {
                        storeAuthSnapshot('', meResponse.data.user);
                        navigate('/', { replace: true });
                    }
                }
            } catch {
                // Fall through to the login form if setup status cannot be determined.
            } finally {
                if (!cancelled) {
                    setCheckingSetup(false);
                }
            }
        };

        checkSetupStatus();

        return () => {
            cancelled = true;
        };
    }, [navigate]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            await login(email, password);
            navigate('/', { replace: true });
        } catch (err) {
            setError(err.response?.data?.message || 'Invalid credentials');
        } finally {
            setLoading(false);
        }
    };

    const handleGoogleLogin = () => {
        setError('');
        setGoogleLoading(true);
        window.location.href = '/auth/google/redirect';
    };

    const passwordEnabled = authConfig.password?.enabled !== false;
    const googleEnabled = Boolean(authConfig.google?.enabled);
    const googlePrimary = googleEnabled && Boolean(authConfig.google?.primary);
    const passwordFallbackRequested = new URLSearchParams(window.location.search).get('password') === '1';
    const showPasswordForm = passwordEnabled && (!googleEnabled || passwordFallbackRequested);
    const singleSsoMode = googleEnabled && !showPasswordForm;

    return (
        <div className="relative min-h-screen overflow-hidden bg-slate-950">
            {checkingSetup ? (
                <div className="absolute inset-0 z-20 flex items-center justify-center bg-slate-950/90">
                    <div className="rounded-2xl border border-white/10 bg-slate-900/85 px-5 py-4 text-center shadow-xl backdrop-blur">
                        <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-teal-400 border-t-transparent" />
                        <p className="mt-3 text-sm text-slate-200">Checking installation status...</p>
                    </div>
                </div>
            ) : null}
            <div className="absolute inset-0 bg-gradient-to-br from-slate-950 via-slate-900 to-teal-950" aria-hidden="true" />
            <div className="absolute -left-12 top-10 h-56 w-56 rounded-full bg-teal-300/20 blur-3xl" aria-hidden="true" />
            <div className="absolute -bottom-20 right-0 h-72 w-72 rounded-full bg-cyan-400/20 blur-3xl" aria-hidden="true" />

            <div className="relative mx-auto flex min-h-screen w-full max-w-6xl items-center px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid w-full overflow-hidden rounded-[30px] border border-white/10 bg-white/95 shadow-[0_32px_80px_rgba(2,6,23,0.55)] backdrop-blur lg:min-h-[560px] lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
                    <section className={`px-6 py-8 sm:px-10 lg:px-12 ${singleSsoMode ? 'flex items-center' : ''}`}>
                        <div className={`w-full ${singleSsoMode ? 'mx-auto max-w-md' : ''}`}>
                        <div className="flex items-center gap-3">
                            <div className="rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-slate-200">
                                <img src={brandLogo} alt="Exotic Online Advertising logo" className="h-10 w-auto object-contain" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold tracking-tight text-slate-900">ExoticCRM</p>
                                <p className="text-xs font-medium uppercase tracking-[0.16em] text-slate-500">Sales Operations Workspace</p>
                            </div>
                        </div>

                        <h1 className={`${singleSsoMode ? 'mt-12' : 'mt-8'} text-[2rem] leading-tight font-semibold tracking-tight text-slate-900 sm:text-[2.25rem]`}>Welcome back</h1>
                        <p className="mt-4 max-w-md text-sm leading-6 text-slate-600">
                            Sign in to manage leads, subscriptions, payments, and campaign performance in one secure environment.
                        </p>

                        <div className={`${singleSsoMode ? 'mt-10' : 'mt-8'} space-y-5`}>
                            {error && (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700">
                                    {error}
                                </div>
                            )}

                            {googleEnabled ? (
                                <button
                                    type="button"
                                    onClick={handleGoogleLogin}
                                    disabled={googleLoading}
                                    className={`inline-flex h-12 w-full items-center justify-center gap-3 rounded-xl border px-4 text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${
                                        googlePrimary
                                            ? 'border-teal-700 bg-teal-700 text-white hover:bg-teal-800'
                                            : 'border-slate-300 bg-white text-slate-800 hover:bg-slate-50'
                                    }`}
                                >
                                    <span className="flex h-5 w-5 items-center justify-center rounded-full bg-white text-[13px] font-bold text-blue-600">G</span>
                                    {googleLoading ? 'Opening Google...' : 'Continue with Google'}
                                </button>
                            ) : null}

                            {googleEnabled && showPasswordForm ? (
                                <div className="flex items-center gap-3">
                                    <div className="h-px flex-1 bg-slate-200" />
                                    <span className="text-xs font-medium uppercase tracking-[0.14em] text-slate-400">or</span>
                                    <div className="h-px flex-1 bg-slate-200" />
                                </div>
                            ) : null}

                            {showPasswordForm ? (
                                <form onSubmit={handleSubmit} className="space-y-5">
                            <div>
                                <label htmlFor="email" className="block text-sm font-medium text-slate-700">
                                    Email
                                </label>
                                <div className="relative mt-2">
                                    <svg className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M3.75 6.75h16.5v10.5H3.75V6.75Zm0 0L12 13.125 20.25 6.75" />
                                    </svg>
                                    <input
                                        id="email"
                                        type="email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        className="h-12 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm text-slate-800 placeholder:text-slate-400 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        placeholder="name@company.com"
                                        autoComplete="email"
                                        required
                                    />
                                </div>
                            </div>

                            <div>
                                <label htmlFor="password" className="block text-sm font-medium text-slate-700">
                                    Password
                                </label>
                                <div className="relative mt-2">
                                    <svg className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.9} d="M16.5 10.5V8.25a4.5 4.5 0 1 0-9 0v2.25m-.75 0h10.5a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5H6.75a1.5 1.5 0 0 1-1.5-1.5v-6a1.5 1.5 0 0 1 1.5-1.5Z" />
                                    </svg>
                                    <input
                                        id="password"
                                        type="password"
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        className="h-12 w-full rounded-xl border border-slate-300 bg-white pl-11 pr-4 text-sm text-slate-800 placeholder:text-slate-400 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        placeholder="Enter your password"
                                        autoComplete="current-password"
                                        required
                                    />
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={loading}
                                className="inline-flex h-12 w-full items-center justify-center rounded-xl bg-teal-700 px-4 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {loading ? 'Signing in...' : 'Sign in'}
                            </button>
                                </form>
                            ) : !googleEnabled ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">
                                    Password login is disabled. Use Google SSO to access the CRM.
                                </div>
                            ) : null}
                        </div>

                        <p className={`${singleSsoMode ? 'mt-7' : 'mt-5'} text-xs text-slate-500`}>
                            Access is restricted to authorized team members.
                        </p>
                        </div>
                    </section>

                    <aside className="relative hidden overflow-hidden bg-gradient-to-br from-teal-900 via-slate-900 to-slate-900 px-8 py-10 text-slate-100 lg:flex lg:flex-col lg:justify-center lg:px-12">
                        <div className="absolute -right-16 top-10 h-60 w-60 rounded-full bg-teal-300/25 blur-3xl" aria-hidden="true" />
                        <div className="absolute -left-20 bottom-6 h-64 w-64 rounded-full bg-cyan-400/15 blur-3xl" aria-hidden="true" />

                        <div className="relative w-full">
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-teal-200/85">Exotic Online Advertising</p>
                            <h2 className="mt-4 text-[2.1rem] leading-[1.15] font-semibold tracking-tight text-white">
                                Keep your sales operation sharp, visible, and aligned.
                            </h2>
                            <p className="mt-4 max-w-md text-sm leading-7 text-slate-200/85">
                                Built for fast-moving teams handling leads, renewals, and payment follow-up across multiple markets.
                            </p>
                        </div>

                        <ul className="relative mt-9 space-y-3">
                            {highlights.map((item) => (
                                <li key={item} className="flex items-start gap-3 rounded-xl border border-white/10 bg-white/[0.04] p-3.5 text-sm text-slate-100/95 backdrop-blur-sm">
                                    <span className="mt-1.5 h-2 w-2 rounded-full bg-teal-300" aria-hidden="true" />
                                    <span>{item}</span>
                                </li>
                            ))}
                        </ul>

                        <div className="relative mt-8 rounded-2xl border border-white/15 bg-white/[0.06] p-4 backdrop-blur-sm">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-white p-1.5">
                                    <img src={brandLogo} alt="" aria-hidden="true" className="h-8 w-auto object-contain" />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-white">Trusted team workspace</p>
                                    <p className="text-xs text-slate-300">Role-based access for operational security.</p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>
        </div>
    );
}
