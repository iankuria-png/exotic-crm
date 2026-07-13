import React from 'react';
import { buildDiagnostics, copyDiagnostics } from '../utils/diagnostics';
import { reportClientError } from '../services/clientErrorReporter';

// Catches React render crashes so a component throw degrades to a branded panel
// instead of white-screening the SPA. Two shapes:
//   - fullScreen (default): outermost boundary, replaces the whole viewport.
//   - inline: nested boundary (inside MainLayout) that keeps the nav shell alive
//     and only replaces the page content, with a "Try again" that re-renders.
export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, copied: false };
        this.error = null;
    }

    static getDerivedStateFromError() {
        return { hasError: true, copied: false };
    }

    componentDidCatch(error, info) {
        this.error = error;
        reportClientError({
            message: error?.message || 'React render error',
            category: 'react_error',
            stack: `${error?.stack || ''}\n\nComponent stack:${info?.componentStack || ''}`,
            component: this.props.label || 'app',
        });
    }

    handleCopy = async () => {
        const ok = await copyDiagnostics(buildDiagnostics({
            error: this.error,
            extra: { boundary: this.props.label || 'app', kind: 'react_error' },
        }));
        this.setState({ copied: ok });
    };

    handleReset = () => {
        this.error = null;
        this.setState({ hasError: false, copied: false });
    };

    render() {
        if (!this.state.hasError) {
            return this.props.children;
        }

        const inline = this.props.variant === 'inline';
        const wrapperClass = inline
            ? 'flex min-h-[60vh] items-center justify-center p-6'
            : 'flex min-h-svh items-center justify-center bg-slate-50 p-6';

        return (
            <div className={wrapperClass}>
                <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
                    <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-rose-50 text-rose-600">
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <h1 className="mt-4 text-lg font-semibold text-slate-900">Something went wrong</h1>
                    <p className="mt-2 text-sm text-slate-600">
                        This part of the app hit an unexpected error. Your work is safe — try again, or reload.
                        We’ve logged the details for support.
                    </p>

                    <div className="mt-6 flex flex-col gap-2">
                        {inline ? (
                            <button
                                type="button"
                                onClick={this.handleReset}
                                className="inline-flex items-center justify-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700"
                            >
                                Try again
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => window.location.reload()}
                                className="inline-flex items-center justify-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-700"
                            >
                                Reload the app
                            </button>
                        )}

                        <div className="flex gap-2">
                            <a
                                href="/"
                                className="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                Go to dashboard
                            </a>
                            <button
                                type="button"
                                onClick={this.handleCopy}
                                className="inline-flex flex-1 items-center justify-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                {this.state.copied ? 'Copied ✓' : 'Copy diagnostics'}
                            </button>
                        </div>

                        <a
                            href="/network-check"
                            className="mt-1 text-xs font-medium text-teal-700 hover:text-teal-800 hover:underline"
                        >
                            Run a network check
                        </a>
                    </div>
                </div>
            </div>
        );
    }
}
