import React, { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api from '../services/api';
import { useToast } from '../components/ToastProvider';

const steps = [
    { id: 'environment', label: 'Environment', description: 'Verify PHP, extensions, writable paths, and the app key.' },
    { id: 'database', label: 'Database', description: 'Confirm connectivity and apply pending migrations.' },
    { id: 'admin', label: 'Admin Account', description: 'Create the first CRM admin and store the Sanctum token locally.' },
    { id: 'market', label: 'Market Setup', description: 'Add your first market and validate the WordPress connection.' },
    { id: 'diagnostics', label: 'Integration Tests', description: 'Run the shared diagnostics for WordPress, SMS, payment proxy, and scheduler.' },
    { id: 'launch', label: 'Launch Checklist', description: 'Review the final checklist, baseline mode, and finish setup.' },
];

const cronCommand = '* * * * * cd /home/d9410/crm.exotic-online.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1';

function statusChip(status) {
    if (['connected', 'healthy', 'success', 'complete'].includes(status)) {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (['configured_disabled', 'partial', 'degraded', 'pending', 'stale', 'missing', 'skipped'].includes(status)) {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    return 'bg-rose-50 text-rose-700 ring-rose-200';
}

function StepStatusIcon({ passed, active, attempted }) {
    if (passed) {
        return (
            <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.313a1 1 0 0 1-1.42 0L3.29 9.267a1 1 0 0 1 1.414-1.414l4.046 4.046 6.543-6.603a1 1 0 0 1 1.41-.006Z" clipRule="evenodd" />
                </svg>
            </span>
        );
    }

    if (attempted && !passed) {
        return (
            <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-rose-100 text-rose-700">
                <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" clipRule="evenodd" />
                </svg>
            </span>
        );
    }

    return (
        <span className={`inline-flex h-8 w-8 items-center justify-center rounded-full ${active ? 'bg-teal-100 text-teal-700' : 'bg-slate-100 text-slate-500'}`}>
            <span className="text-xs font-semibold">{active ? '...' : '•'}</span>
        </span>
    );
}

function SetupCard({ title, description, children, footer }) {
    return (
        <section className="crm-surface overflow-hidden">
            <div className="crm-panel-header">
                <div>
                    <h2 className="crm-panel-title">{title}</h2>
                    <p className="crm-panel-subtitle">{description}</p>
                </div>
            </div>
            <div className="space-y-4 p-4">{children}</div>
            {footer ? <div className="border-t border-slate-100 bg-slate-50/70 px-4 py-3">{footer}</div> : null}
        </section>
    );
}

function CheckList({ checks }) {
    return (
        <div className="grid gap-3 md:grid-cols-2">
            {Object.entries(checks || {}).map(([key, check]) => (
                <div key={key} className="rounded-lg border border-slate-200 bg-white p-3">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-sm font-semibold text-slate-900">{check.label}</p>
                            {'actual' in check ? <p className="mt-1 text-xs text-slate-500">Actual: {String(check.actual)}</p> : null}
                            {'expected' in check && typeof check.expected === 'string' ? <p className="text-xs text-slate-500">Expected: {check.expected}</p> : null}
                            {Array.isArray(check.missing) && check.missing.length > 0 ? (
                                <p className="mt-1 text-xs text-rose-700">Missing: {check.missing.join(', ')}</p>
                            ) : null}
                        </div>
                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(check.ok ? 'healthy' : 'error')}`}>
                            {check.ok ? 'Pass' : 'Fail'}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}

export default function Setup() {
    const navigate = useNavigate();
    const toast = useToast();
    const [loadingStatus, setLoadingStatus] = useState(true);
    const [setupStatus, setSetupStatus] = useState(null);
    const [currentStep, setCurrentStep] = useState(0);
    const [setupToken, setSetupToken] = useState('');
    const [statusError, setStatusError] = useState('');

    const [envState, setEnvState] = useState({ loading: false, attempted: false, result: null, error: '' });
    const [databaseState, setDatabaseState] = useState({ loading: false, attempted: false, result: null, error: '', migrating: false });
    const [adminState, setAdminState] = useState({ loading: false, attempted: false, result: null, error: '' });
    const [marketState, setMarketState] = useState({
        loading: false,
        attempted: false,
        result: null,
        error: '',
        testing: false,
        syncing: false,
        testResult: null,
        syncResult: null,
    });
    const [diagnosticsState, setDiagnosticsState] = useState({ loading: false, attempted: false, result: null, error: '' });
    const [launching, setLaunching] = useState(false);
    const [cronCopied, setCronCopied] = useState(false);
    const [baseline, setBaseline] = useState({
        mode: 'fresh_start',
        cutoff_date: new Date().toISOString().slice(0, 10),
    });

    const [adminForm, setAdminForm] = useState({
        name: '',
        email: '',
        password: '',
    });

    const [marketForm, setMarketForm] = useState({
        name: '',
        domain: '',
        country: '',
        is_active: false,
        wp_api_url: '',
        wp_api_user: '',
        wp_api_password: '',
        db_host: '',
        db_name: '',
        db_user: '',
        db_pass: '',
        db_prefix: 'wp_',
        currency_code: 'KES',
        timezone: '',
        phone_prefix: '254',
        support_chat_url: '',
        reason: 'Created first market from installation wizard',
    });

    useEffect(() => {
        let cancelled = false;

        const loadStatus = async () => {
            setLoadingStatus(true);
            setStatusError('');

            try {
                const { data } = await api.get('/crm/setup/status');
                if (cancelled) {
                    return;
                }

                setSetupStatus(data);
            } catch (error) {
                if (cancelled) {
                    return;
                }

                setStatusError(error?.response?.data?.message || 'Unable to check setup status.');
            } finally {
                if (!cancelled) {
                    setLoadingStatus(false);
                }
            }
        };

        loadStatus();

        return () => {
            cancelled = true;
        };
    }, []);

    const selectedPlatformId = marketState.result?.platform?.platform_id || null;
    const progressWidth = `${((currentStep + 1) / steps.length) * 100}%`;

    useEffect(() => {
        const shouldPoll = Boolean(
            selectedPlatformId
            && (marketState.syncing || marketState.syncResult?.run?.in_progress)
        );

        if (!shouldPoll) {
            return undefined;
        }

        let cancelled = false;

        const poll = async () => {
            try {
                const { data } = await api.get(`/crm/settings/integrations/platforms/${selectedPlatformId}/sync/latest`);
                if (cancelled) {
                    return;
                }

                setMarketState((current) => ({
                    ...current,
                    syncing: Boolean(data?.run?.in_progress),
                    syncResult: data ? {
                        status: data?.run?.status || data?.platform?.sync?.last_status || 'pending',
                        run: data?.run || null,
                        platform: data?.platform || current.result?.platform || null,
                        result: data?.platform?.sync?.last_result || null,
                    } : current.syncResult,
                    result: current.result ? {
                        ...current.result,
                        platform: data?.platform || current.result.platform,
                    } : current.result,
                }));
            } catch (error) {
                if (!cancelled) {
                    setMarketState((current) => ({
                        ...current,
                        syncing: false,
                        error: error?.response?.data?.message || 'Unable to refresh sync status.',
                    }));
                }
            }
        };

        poll();
        const interval = window.setInterval(poll, 4000);

        return () => {
            cancelled = true;
            window.clearInterval(interval);
        };
    }, [marketState.syncResult?.run?.in_progress, marketState.syncing, selectedPlatformId]);

    const stepPassed = useMemo(() => ({
        environment: Boolean(envState.result?.ok),
        database: Boolean(databaseState.result?.connected) && Number(databaseState.result?.pending_migrations ?? 1) === 0,
        admin: Boolean(adminState.result?.token),
        market: Boolean(marketState.result?.platform?.platform_id)
            && marketState.testResult?.status === 'healthy'
            && Boolean(marketState.result?.platform?.sync?.last_synced_at)
            && !marketState.syncing,
        diagnostics: Boolean(diagnosticsState.result)
            && !['error'].includes(diagnosticsState.result?.platform?.status || 'skipped')
            && !['error'].includes(diagnosticsState.result?.payment_proxy?.status || 'pending'),
        launch: false,
    }), [adminState.result, databaseState.result, diagnosticsState.result, envState.result, marketState.result, marketState.testResult]);

    const attempted = {
        environment: envState.attempted,
        database: databaseState.attempted,
        admin: adminState.attempted,
        market: marketState.attempted,
        diagnostics: diagnosticsState.attempted,
        launch: false,
    };

    const runEnvironmentCheck = async () => {
        setEnvState({ loading: true, attempted: true, result: null, error: '' });
        try {
            const { data } = await api.post('/crm/setup/check-env');
            setEnvState({ loading: false, attempted: true, result: data, error: '' });
            if (data.ok) {
                toast.success('Environment checks passed.');
            } else {
                toast.warning('Environment checks need attention.');
            }
        } catch (error) {
            setEnvState({
                loading: false,
                attempted: true,
                result: null,
                error: error?.response?.data?.message || 'Environment check failed.',
            });
            toast.error(error?.response?.data?.message || 'Environment check failed.');
        }
    };

    const runDatabaseCheck = async () => {
        setDatabaseState((current) => ({ ...current, loading: true, attempted: true, error: '' }));
        try {
            const { data } = await api.post('/crm/setup/check-database');
            setDatabaseState((current) => ({ ...current, loading: false, attempted: true, result: data, error: '' }));
            if (data.connected) {
                toast.success('Database connection succeeded.');
            } else {
                toast.warning('Database connection needs attention.');
            }
        } catch (error) {
            setDatabaseState((current) => ({
                ...current,
                loading: false,
                attempted: true,
                result: null,
                error: error?.response?.data?.message || 'Database check failed.',
            }));
            toast.error(error?.response?.data?.message || 'Database check failed.');
        }
    };

    const runMigrations = async () => {
        if (!setupToken.trim()) {
            toast.error('Enter the setup token before running migrations.');
            return;
        }

        setDatabaseState((current) => ({ ...current, migrating: true, error: '' }));
        try {
            const { data } = await api.post('/crm/setup/run-migrations', {}, {
                headers: { 'X-Setup-Token': setupToken.trim() },
            });
            setDatabaseState({
                loading: false,
                attempted: true,
                migrating: false,
                result: data.database,
                error: '',
            });
            setSetupStatus((current) => current ? { ...current, pending_migrations: data.database?.pending_migrations ?? 0 } : current);
            toast.success('Migrations completed successfully.');
        } catch (error) {
            setDatabaseState((current) => ({
                ...current,
                migrating: false,
                attempted: true,
                error: error?.response?.data?.message || 'Migration run failed.',
            }));
            toast.error(error?.response?.data?.message || 'Migration run failed.');
        }
    };

    const createAdmin = async () => {
        if (!setupToken.trim()) {
            toast.error('Enter the setup token before creating the admin account.');
            return;
        }

        setAdminState({ loading: true, attempted: true, result: null, error: '' });

        try {
            const { data } = await api.post('/crm/setup/create-admin', adminForm, {
                headers: { 'X-Setup-Token': setupToken.trim() },
            });

            localStorage.setItem('crm_token', data.token);
            localStorage.setItem('crm_user', JSON.stringify(data.user));

            setAdminState({ loading: false, attempted: true, result: data, error: '' });
            toast.success('Admin account created and session stored.');
        } catch (error) {
            setAdminState({
                loading: false,
                attempted: true,
                result: null,
                error: error?.response?.data?.message || 'Admin creation failed.',
            });
            toast.error(error?.response?.data?.message || 'Admin creation failed.');
        }
    };

    const createMarket = async () => {
        setMarketState((current) => ({
            ...current,
            loading: true,
            attempted: true,
            error: '',
        }));

        try {
            const { data } = await api.post('/crm/settings/integrations/platforms', marketForm);
            setMarketState((current) => ({
                ...current,
                loading: false,
                attempted: true,
                result: data,
                error: '',
                testResult: null,
                syncResult: null,
            }));
            toast.success('Market profile created.');
        } catch (error) {
            setMarketState((current) => ({
                ...current,
                loading: false,
                attempted: true,
                error: error?.response?.data?.message || 'Failed to create the market profile.',
            }));
            toast.error(error?.response?.data?.message || 'Failed to create the market profile.');
        }
    };

    const testMarket = async () => {
        if (!selectedPlatformId) {
            toast.error('Create the market before running the connection test.');
            return;
        }

        setMarketState((current) => ({ ...current, testing: true, error: '' }));
        try {
            const { data } = await api.post(`/crm/settings/integrations/platforms/${selectedPlatformId}/test-connection`, {
                reason: 'Setup wizard connection test',
            });
            setMarketState((current) => ({
                ...current,
                testing: false,
                testResult: data,
                result: {
                    ...current.result,
                    platform: data.platform,
                },
            }));
            toast.success('Market connection test passed.');
        } catch (error) {
            setMarketState((current) => ({
                ...current,
                testing: false,
                error: error?.response?.data?.message || 'Connection test failed.',
            }));
            toast.error(error?.response?.data?.message || 'Connection test failed.');
        }
    };

    const syncMarket = async () => {
        if (!selectedPlatformId) {
            toast.error('Create the market before running sync.');
            return;
        }

        setMarketState((current) => ({ ...current, syncing: true, error: '' }));
        try {
            const { data } = await api.post(`/crm/settings/integrations/platforms/${selectedPlatformId}/sync`, {
                scope: 'clients',
                mode: 'full',
                per_page: 100,
                reason: 'Initial setup sync',
            });
            setMarketState((current) => ({
                ...current,
                syncing: true,
                syncResult: data,
                result: {
                    ...current.result,
                    platform: data.platform,
                },
            }));
            toast.success(data?.message || 'Initial market sync has been queued.');
        } catch (error) {
            setMarketState((current) => ({
                ...current,
                syncing: false,
                error: error?.response?.data?.message || 'Initial sync failed.',
            }));
            toast.error(error?.response?.data?.message || 'Initial sync failed.');
        }
    };

    const runDiagnostics = async () => {
        setDiagnosticsState({ loading: true, attempted: true, result: null, error: '' });
        try {
            const { data } = await api.post('/crm/setup/run-diagnostics', selectedPlatformId ? {
                platform_id: selectedPlatformId,
            } : {});
            setDiagnosticsState({ loading: false, attempted: true, result: data, error: '' });
            if (data.payment_proxy?.status === 'error' || data.platform?.status === 'error') {
                toast.warning('Diagnostics completed with issues.');
            } else {
                toast.success('Diagnostics completed.');
            }
            if (data.data_baseline) {
                setBaseline(data.data_baseline);
            }
        } catch (error) {
            setDiagnosticsState({
                loading: false,
                attempted: true,
                result: null,
                error: error?.response?.data?.message || 'Diagnostics failed.',
            });
            toast.error(error?.response?.data?.message || 'Diagnostics failed.');
        }
    };

    const copyCronCommand = async () => {
        try {
            await navigator.clipboard.writeText(cronCommand);
            setCronCopied(true);
            toast.success('Cron command copied.');
            window.setTimeout(() => setCronCopied(false), 1800);
        } catch {
            toast.error('Unable to copy the cron command.');
        }
    };

    const completeSetup = async () => {
        setLaunching(true);
        try {
            await api.post('/crm/setup/complete', {
                data_baseline: baseline,
            });
            toast.success('Setup completed.');
            navigate('/', { replace: true });
        } catch (error) {
            toast.error(error?.response?.data?.message || 'Unable to complete setup.');
        } finally {
            setLaunching(false);
        }
    };

    const goNext = () => {
        if (currentStep < steps.length - 1) {
            setCurrentStep((current) => current + 1);
        }
    };

    const goBack = () => {
        if (currentStep > 0) {
            setCurrentStep((current) => current - 1);
        }
    };

    if (loadingStatus) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-100">
                <div className="crm-surface w-full max-w-md p-6 text-center">
                    <div className="mx-auto h-8 w-8 animate-spin rounded-full border-4 border-teal-600 border-t-transparent" />
                    <p className="mt-4 text-sm text-slate-600">Checking setup status...</p>
                </div>
            </div>
        );
    }

    if (statusError) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
                <div className="crm-surface w-full max-w-lg p-6">
                    <h1 className="text-xl font-semibold text-slate-900">Unable to start setup</h1>
                    <p className="mt-2 text-sm text-slate-600">{statusError}</p>
                    <button type="button" onClick={() => window.location.reload()} className="mt-5 crm-btn-primary">
                        Retry
                    </button>
                </div>
            </div>
        );
    }

    if (setupStatus && !setupStatus.is_first_run) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
                <div className="crm-surface w-full max-w-xl p-6">
                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip('healthy')}`}>
                        Setup completed
                    </span>
                    <h1 className="mt-4 text-2xl font-semibold tracking-tight text-slate-900">Setup already completed</h1>
                    <p className="mt-2 text-sm text-slate-600">
                        This CRM instance already has its installation flow marked as complete. Sign in to continue managing the workspace.
                    </p>
                    <div className="mt-6">
                        <Link to="/login" className="crm-btn-primary">
                            Go to login
                        </Link>
                    </div>
                </div>
            </div>
        );
    }

    const activeStep = steps[currentStep];
    const canGoNext = Boolean(stepPassed[activeStep.id]);

    return (
        <div className="min-h-screen bg-slate-100 px-4 py-8 sm:px-6">
            <div className="mx-auto flex max-w-7xl flex-col gap-6 lg:flex-row">
                <aside className="w-full lg:max-w-sm">
                    <div className="crm-surface overflow-hidden">
                        <div className="crm-panel-header">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-teal-700">Production Setup</p>
                                <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">ExoticCRM launch wizard</h1>
                                <p className="crm-panel-subtitle">Guide the CRM from first-run checks to a live dashboard in six steps.</p>
                            </div>
                        </div>
                        <div className="space-y-5 p-4">
                            <div>
                                <div className="flex items-center justify-between text-xs font-medium text-slate-500">
                                    <span>Progress</span>
                                    <span>{currentStep + 1}/{steps.length}</span>
                                </div>
                                <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                                    <div className="h-full rounded-full bg-teal-600 transition-all" style={{ width: progressWidth }} />
                                </div>
                            </div>

                            <ol className="space-y-3">
                                {steps.map((step, index) => (
                                    <li
                                        key={step.id}
                                        className={`rounded-xl border px-3 py-3 transition ${index === currentStep ? 'border-teal-200 bg-teal-50/60' : 'border-slate-200 bg-white'}`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setCurrentStep(index)}
                                            className="flex w-full items-start gap-3 text-left"
                                        >
                                            <StepStatusIcon
                                                passed={Boolean(stepPassed[step.id])}
                                                active={index === currentStep}
                                                attempted={Boolean(attempted[step.id])}
                                            />
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{step.label}</p>
                                                <p className="mt-1 text-xs leading-5 text-slate-500">{step.description}</p>
                                            </div>
                                        </button>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </div>
                </aside>

                <main className="min-w-0 flex-1 space-y-6">
                    {currentStep === 0 ? (
                        <SetupCard
                            title="Environment check"
                            description="Run the production prerequisites check before touching the database."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <button type="button" onClick={runEnvironmentCheck} disabled={envState.loading} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                        {envState.loading ? 'Checking...' : 'Run environment check'}
                                    </button>
                                    <button type="button" onClick={goNext} disabled={!canGoNext} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                        Next
                                    </button>
                                </div>
                            )}
                        >
                            <CheckList checks={envState.result?.checks} />
                            {envState.error ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{envState.error}</p> : null}
                        </SetupCard>
                    ) : null}

                    {currentStep === 1 ? (
                        <SetupCard
                            title="Database"
                            description="Confirm the database connection and clear the migration backlog before creating users."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button type="button" onClick={goBack} className="crm-btn-secondary">
                                            Back
                                        </button>
                                        <button type="button" onClick={runDatabaseCheck} disabled={databaseState.loading} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                            {databaseState.loading ? 'Checking...' : 'Check database'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={runMigrations}
                                            disabled={databaseState.migrating || Number(databaseState.result?.pending_migrations ?? setupStatus?.pending_migrations ?? 0) < 1}
                                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {databaseState.migrating ? 'Running migrations...' : 'Run migrations'}
                                        </button>
                                    </div>
                                    <button type="button" onClick={goNext} disabled={!canGoNext} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                        Next
                                    </button>
                                </div>
                            )}
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-sm font-semibold text-slate-900">Setup token</p>
                                    <p className="mt-1 text-xs text-slate-500">Used for protected first-run actions like migrations and admin creation.</p>
                                    <input
                                        value={setupToken}
                                        onChange={(event) => setSetupToken(event.target.value)}
                                        className="mt-3 crm-input"
                                        placeholder="Paste the SETUP_TOKEN from .env"
                                    />
                                </div>
                                <div className="rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-sm font-semibold text-slate-900">Connection summary</p>
                                    <div className="mt-3 space-y-2 text-sm text-slate-600">
                                        <p>Connected: <span className="font-semibold text-slate-900">{databaseState.result?.connected ? 'Yes' : 'Not yet checked'}</span></p>
                                        <p>Pending migrations: <span className="font-semibold text-slate-900">{databaseState.result?.pending_migrations ?? setupStatus?.pending_migrations ?? 'Unknown'}</span></p>
                                        <p>Table count: <span className="font-semibold text-slate-900">{databaseState.result?.table_count ?? 'Unknown'}</span></p>
                                    </div>
                                </div>
                            </div>
                            {databaseState.error ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{databaseState.error}</p> : null}
                        </SetupCard>
                    ) : null}

                    {currentStep === 2 ? (
                        <SetupCard
                            title="Admin account"
                            description="Create the first CRM admin. The returned Sanctum token is stored locally so the remaining steps can use protected endpoints."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button type="button" onClick={goBack} className="crm-btn-secondary">
                                            Back
                                        </button>
                                        <button type="button" onClick={createAdmin} disabled={adminState.loading} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                            {adminState.loading ? 'Creating admin...' : 'Create admin'}
                                        </button>
                                    </div>
                                    <button type="button" onClick={goNext} disabled={!canGoNext} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                        Next
                                    </button>
                                </div>
                            )}
                        >
                            <div className="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Full name</label>
                                    <input value={adminForm.name} onChange={(event) => setAdminForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="Operations Admin" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                                    <input type="email" value={adminForm.email} onChange={(event) => setAdminForm((current) => ({ ...current, email: event.target.value }))} className="crm-input" placeholder="admin@example.com" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Password</label>
                                    <input type="password" value={adminForm.password} onChange={(event) => setAdminForm((current) => ({ ...current, password: event.target.value }))} className="crm-input" placeholder="At least 8 characters" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Setup token</label>
                                    <input value={setupToken} onChange={(event) => setSetupToken(event.target.value)} className="crm-input" placeholder="Paste the SETUP_TOKEN from .env" />
                                </div>
                            </div>
                            {adminState.result?.user ? (
                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                                    Logged in as <span className="font-semibold">{adminState.result.user.email}</span>.
                                </div>
                            ) : null}
                            {adminState.error ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{adminState.error}</p> : null}
                        </SetupCard>
                    ) : null}

                    {currentStep === 3 ? (
                        <SetupCard
                            title="Market setup"
                            description="Create the first market profile, then test the WordPress plugin connection before syncing profiles."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button type="button" onClick={goBack} className="crm-btn-secondary">
                                            Back
                                        </button>
                                        <button type="button" onClick={createMarket} disabled={marketState.loading || !marketForm.timezone.trim()} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                            {marketState.loading ? 'Creating market...' : 'Create market'}
                                        </button>
                                        <button type="button" onClick={testMarket} disabled={marketState.testing || !selectedPlatformId} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                            {marketState.testing ? 'Testing...' : 'Test connection'}
                                        </button>
                                        <button type="button" onClick={syncMarket} disabled={marketState.syncing || !selectedPlatformId} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                            {marketState.syncing ? 'Syncing...' : 'Initial sync'}
                                        </button>
                                    </div>
                                    <button type="button" onClick={goNext} disabled={!canGoNext} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                        Next
                                    </button>
                                </div>
                            )}
                        >
                            <div className="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Market name</label>
                                    <input value={marketForm.name} onChange={(event) => setMarketForm((current) => ({ ...current, name: event.target.value }))} className="crm-input" placeholder="Kenya" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Domain</label>
                                    <input value={marketForm.domain} onChange={(event) => setMarketForm((current) => ({ ...current, domain: event.target.value }))} className="crm-input" placeholder="exotic-online.co.ke" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Country</label>
                                    <input value={marketForm.country} onChange={(event) => setMarketForm((current) => ({ ...current, country: event.target.value }))} className="crm-input" placeholder="Kenya" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Currency</label>
                                    <input value={marketForm.currency_code} onChange={(event) => setMarketForm((current) => ({ ...current, currency_code: event.target.value.toUpperCase() }))} className="crm-input" placeholder="KES" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Timezone</label>
                                    <input value={marketForm.timezone} onChange={(event) => setMarketForm((current) => ({ ...current, timezone: event.target.value }))} className="crm-input" placeholder="PHP/IANA timezone, e.g. Africa/Nairobi" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Phone prefix</label>
                                    <input value={marketForm.phone_prefix} onChange={(event) => setMarketForm((current) => ({ ...current, phone_prefix: event.target.value }))} className="crm-input" placeholder="254" />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress API URL</label>
                                    <input value={marketForm.wp_api_url} onChange={(event) => setMarketForm((current) => ({ ...current, wp_api_url: event.target.value }))} className="crm-input" placeholder="https://site.example/wp-json/exotic-crm-sync/v1" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress API user</label>
                                    <input value={marketForm.wp_api_user} onChange={(event) => setMarketForm((current) => ({ ...current, wp_api_user: event.target.value }))} className="crm-input" placeholder="wp-admin" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress application password</label>
                                    <input value={marketForm.wp_api_password} onChange={(event) => setMarketForm((current) => ({ ...current, wp_api_password: event.target.value }))} className="crm-input" placeholder="xxxx xxxx xxxx xxxx" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress DB host</label>
                                    <input value={marketForm.db_host} onChange={(event) => setMarketForm((current) => ({ ...current, db_host: event.target.value }))} className="crm-input" placeholder="localhost" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress DB name</label>
                                    <input value={marketForm.db_name} onChange={(event) => setMarketForm((current) => ({ ...current, db_name: event.target.value }))} className="crm-input" placeholder="cpanel_dbname" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress DB user</label>
                                    <input value={marketForm.db_user} onChange={(event) => setMarketForm((current) => ({ ...current, db_user: event.target.value }))} className="crm-input" placeholder="cpanel_dbuser" />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress DB password</label>
                                    <input value={marketForm.db_pass} onChange={(event) => setMarketForm((current) => ({ ...current, db_pass: event.target.value }))} className="crm-input" type="password" placeholder="WordPress DB password" />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">WordPress table prefix</label>
                                    <input value={marketForm.db_prefix} onChange={(event) => setMarketForm((current) => ({ ...current, db_prefix: event.target.value }))} className="crm-input" placeholder="wp_" />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Support chat URL</label>
                                    <input value={marketForm.support_chat_url} onChange={(event) => setMarketForm((current) => ({ ...current, support_chat_url: event.target.value }))} className="crm-input" placeholder="https://wa.me/..." />
                                </div>
                                <div className="md:col-span-2 rounded-md border border-sky-200 bg-sky-50/80 px-3 py-2 text-xs text-sky-800">
                                    For CRM “Provision in WordPress”, copy the DB values from the market site’s `wp-config.php`: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `$table_prefix`.
                                </div>
                            </div>

                            {marketState.result?.platform ? (
                                <div className="grid gap-3 md:grid-cols-4">
                                    <div className="rounded-lg border border-slate-200 bg-white p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Market</p>
                                        <p className="mt-2 text-sm font-semibold text-slate-900">{marketState.result.platform.platform_name}</p>
                                        <p className="mt-1 text-xs text-slate-500">{marketState.result.platform.domain}</p>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 bg-white p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">WP connection</p>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(marketState.testResult?.status || marketState.result.platform.wp_sync?.status || 'pending')}`}>
                                                {(marketState.testResult?.status || marketState.result.platform.wp_sync?.status || 'pending').replaceAll('_', ' ')}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-xs text-slate-500">Credentials ready: {marketState.result.platform.wp_sync?.credentials_ready ? 'Yes' : 'No'}</p>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 bg-white p-3">
                                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">WP provisioning DB</p>
                                        <p className="mt-2 text-xs text-slate-500">Credentials ready: {marketState.result.platform.wp_provisioning?.credentials_ready ? 'Yes' : 'No'}</p>
                                    </div>
                                    <div className="rounded-lg border border-slate-200 bg-white p-3">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Last sync</p>
                                            <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(marketState.syncResult?.status || marketState.result.platform.sync?.last_status || 'pending')}`}>
                                                {(marketState.syncResult?.status || marketState.result.platform.sync?.last_status || 'pending').replaceAll('_', ' ')}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-xs text-slate-500">{marketState.result.platform.sync?.last_result?.clients?.total ?? marketState.syncResult?.result?.clients?.total ?? 0} client records touched</p>
                                        {marketState.syncResult?.run?.in_progress ? (
                                            <p className="mt-1 text-xs text-amber-700">Background sync is running. You can continue with setup while it completes.</p>
                                        ) : null}
                                    </div>
                                </div>
                            ) : null}

                            {marketState.error ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{marketState.error}</p> : null}
                        </SetupCard>
                    ) : null}

                    {currentStep === 4 ? (
                        <SetupCard
                            title="Integration diagnostics"
                            description="Run the same health checks that stay available later inside Settings → System Health."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button type="button" onClick={goBack} className="crm-btn-secondary">
                                            Back
                                        </button>
                                        <button type="button" onClick={runDiagnostics} disabled={diagnosticsState.loading} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                            {diagnosticsState.loading ? 'Running diagnostics...' : 'Run diagnostics'}
                                        </button>
                                    </div>
                                    <button type="button" onClick={goNext} disabled={!canGoNext} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60">
                                        Next
                                    </button>
                                </div>
                            )}
                        >
                            {diagnosticsState.result ? (
                                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                    {[
                                        ['WordPress', diagnosticsState.result.platform],
                                        ['SMS gateway', diagnosticsState.result.sms],
                                        ['Payment proxy', diagnosticsState.result.payment_proxy],
                                        ['Scheduler', diagnosticsState.result.scheduler],
                                    ].map(([label, result]) => (
                                        <div key={label} className="rounded-lg border border-slate-200 bg-white p-3">
                                            <div className="flex items-center justify-between gap-2">
                                                <p className="text-sm font-semibold text-slate-900">{label}</p>
                                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(result?.status || 'pending')}`}>
                                                    {(result?.status || 'pending').replaceAll('_', ' ')}
                                                </span>
                                            </div>
                                            <p className="mt-2 text-xs leading-5 text-slate-500">{result?.message || 'No message returned.'}</p>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">Run diagnostics to populate the health cards.</p>
                            )}
                            {diagnosticsState.error ? <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{diagnosticsState.error}</p> : null}
                        </SetupCard>
                    ) : null}

                    {currentStep === 5 ? (
                        <SetupCard
                            title="Launch checklist"
                            description="Review the setup summary, pick the data baseline mode, copy the cron job, and launch the dashboard."
                            footer={(
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <button type="button" onClick={goBack} className="crm-btn-secondary">
                                        Back
                                    </button>
                                    <button type="button" onClick={completeSetup} disabled={launching} className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60">
                                        {launching ? 'Completing setup...' : 'Go to dashboard'}
                                    </button>
                                </div>
                            )}
                        >
                            <div className="grid gap-3 lg:grid-cols-2">
                                <div className="rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-sm font-semibold text-slate-900">Checklist</p>
                                    <ul className="mt-3 space-y-2 text-sm text-slate-600">
                                        <li>Environment checks: <span className="font-semibold text-slate-900">{stepPassed.environment ? 'Ready' : 'Pending'}</span></li>
                                        <li>Database migrations: <span className="font-semibold text-slate-900">{stepPassed.database ? 'Ready' : 'Pending'}</span></li>
                                        <li>Admin session: <span className="font-semibold text-slate-900">{stepPassed.admin ? adminState.result?.user?.email : 'Pending'}</span></li>
                                        <li>First market: <span className="font-semibold text-slate-900">{marketState.result?.platform?.platform_name || 'Pending'}</span></li>
                                        <li>Diagnostics: <span className="font-semibold text-slate-900">{stepPassed.diagnostics ? 'Checked' : 'Pending'}</span></li>
                                    </ul>
                                </div>

                                <div className="rounded-lg border border-slate-200 bg-white p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">Scheduler cron</p>
                                            <p className="mt-1 text-xs text-slate-500">Add this in cPanel once the code is deployed.</p>
                                        </div>
                                        <button type="button" onClick={copyCronCommand} className="crm-btn-secondary px-3 py-2">
                                            {cronCopied ? 'Copied' : 'Copy'}
                                        </button>
                                    </div>
                                    <pre className="crm-mono mt-3 overflow-x-auto rounded-lg bg-slate-950 p-3 text-xs text-emerald-200">{cronCommand}</pre>
                                </div>
                            </div>

                            <div className="rounded-lg border border-slate-200 bg-white p-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-900">Data baseline</p>
                                        <p className="mt-1 text-xs text-slate-500">Choose whether the CRM starts from fresh records or includes legacy shared-database history.</p>
                                    </div>
                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${statusChip(baseline.mode === 'include_legacy' ? 'healthy' : 'pending')}`}>
                                        {baseline.mode === 'include_legacy' ? 'Include legacy' : 'Fresh start'}
                                    </span>
                                </div>
                                <div className="mt-4 flex flex-wrap gap-3">
                                    {[
                                        ['fresh_start', 'Fresh Start'],
                                        ['include_legacy', 'Include Legacy'],
                                    ].map(([value, label]) => (
                                        <label key={value} className="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                                            <input
                                                type="radio"
                                                name="baseline_mode"
                                                value={value}
                                                checked={baseline.mode === value}
                                                onChange={() => setBaseline((current) => ({ ...current, mode: value }))}
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                {baseline.mode === 'fresh_start' ? (
                                    <div className="mt-4 max-w-xs">
                                        <label className="mb-1 block text-sm font-medium text-slate-700">Cutoff date</label>
                                        <input
                                            type="date"
                                            value={baseline.cutoff_date}
                                            onChange={(event) => setBaseline((current) => ({ ...current, cutoff_date: event.target.value }))}
                                            className="crm-input"
                                        />
                                    </div>
                                ) : null}
                            </div>
                        </SetupCard>
                    ) : null}
                </main>
            </div>
        </div>
    );
}
