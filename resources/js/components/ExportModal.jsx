import React, { useEffect, useMemo } from 'react';

export default function ExportModal({
    open,
    title,
    subtitle,
    exportLabel = 'Export .xlsx',
    cancelLabel = 'Cancel',
    onClose,
    onExport,
    exportDisabled = false,
    isExporting = false,
    children,
    footerContent = null,
}) {
    const dialogId = useMemo(() => `export-dialog-${Math.random().toString(36).slice(2, 10)}`, []);
    const titleId = `${dialogId}-title`;
    const subtitleId = subtitle ? `${dialogId}-subtitle` : undefined;

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handleEscape = (event) => {
            if (event.key !== 'Escape' || isExporting) {
                return;
            }

            event.preventDefault();
            onClose?.();
        };

        window.addEventListener('keydown', handleEscape);
        return () => window.removeEventListener('keydown', handleEscape);
    }, [isExporting, onClose, open]);

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-[115] flex items-center justify-center bg-slate-900/45 p-4" onClick={isExporting ? undefined : onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={subtitleId}
                className="flex max-h-[88vh] w-full max-w-3xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
                onClick={(event) => event.stopPropagation()}
            >
                <header className="crm-panel-header">
                    <div>
                        <h3 id={titleId} className="crm-panel-title">{title}</h3>
                        {subtitle ? <p id={subtitleId} className="crm-panel-subtitle">{subtitle}</p> : null}
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {children}
                </div>

                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-4">
                    <div className="text-sm text-slate-500">
                        {footerContent}
                    </div>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={onClose} disabled={isExporting} className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-50">
                            {cancelLabel}
                        </button>
                        <button
                            type="button"
                            onClick={onExport}
                            disabled={exportDisabled || isExporting}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isExporting ? 'Exporting...' : exportLabel}
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    );
}
