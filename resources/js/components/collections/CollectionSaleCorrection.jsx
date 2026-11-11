import React, { useState } from 'react';
import CollectionActivationCorrection from './CollectionActivationCorrection';
import { secondaryButton } from './collectionUi';

export default function CollectionSaleCorrection({ item, receipt, onUpdated }) {
    const [action, setAction] = useState('');
    const pending = item.corrections?.find(c => c.request_key && c.state !== 'confirmed');
    return <section aria-label={`Correct activation for ${item.client.name}`} className="mt-4 space-y-3 border-t border-slate-200 pt-3">
        {!action && pending && <><p className="text-sm text-amber-900">This payment correction is saved and needs its website result checked.</p><button type="button" className={secondaryButton} onClick={() => setAction(pending.action || 'reassign')}>Check correction progress</button></>}
        {!pending && !action && item.correction_available && <div className="flex flex-wrap gap-3"><button type="button" className={secondaryButton} onClick={() => setAction('reassign')}>Change profile or package</button>{item.state !== 'unassigned' && <button type="button" className={secondaryButton} onClick={() => setAction('undo_effect')}>Remove activation</button>}</div>}
        {!pending && !item.correction_available && <p className="text-xs text-slate-600">A manager needs to check the saved activation or linked benefit before correcting it.</p>}
        {action && <CollectionActivationCorrection receipt={receipt} item={item} action={action} initialCorrection={pending || null} onUpdated={onUpdated} onCancel={() => setAction('')} />}
    </section>;
}
