import React from 'react';
import StatusChip from './StatusChip';

export default function AdminEditBar({
    article,
    onEdit,
    onOpenCtas,
    onOpenMedia,
    onOpenWalkthroughs,
    onPublish,
    onDuplicate,
    onDelete,
}) {
    return (
        <section className="crm-surface flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div className="flex items-center gap-3">
                <p className="text-sm font-semibold text-slate-900">Admin authoring</p>
                <StatusChip status={article?.status} />
            </div>
            <div className="flex flex-wrap gap-2">
                <button type="button" onClick={onEdit} className="crm-btn-secondary px-3 py-2 text-sm">Edit</button>
                <button type="button" onClick={onOpenCtas} className="crm-btn-secondary px-3 py-2 text-sm">Manage CTAs</button>
                <button type="button" onClick={onOpenMedia} className="crm-btn-secondary px-3 py-2 text-sm">Manage media</button>
                <button type="button" onClick={onOpenWalkthroughs} className="crm-btn-secondary px-3 py-2 text-sm">Record walkthrough</button>
                <button type="button" onClick={onPublish} className="crm-btn-primary px-3 py-2 text-sm">
                    {article?.status === 'published' ? 'Republish' : 'Publish'}
                </button>
                <button type="button" onClick={onDuplicate} className="crm-btn-secondary px-3 py-2 text-sm">Duplicate</button>
                <button type="button" onClick={onDelete} className="crm-btn-danger px-3 py-2 text-sm">Delete</button>
            </div>
        </section>
    );
}
