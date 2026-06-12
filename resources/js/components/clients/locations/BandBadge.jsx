import React from 'react';
import { BANDS } from './locationMeta';

export default function BandBadge({ band = 'insufficient' }) {
    const meta = BANDS[band] || BANDS.insufficient;

    return (
        <span className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold ${meta.chip}`}>
            <span className={`h-2 w-2 rounded-full ${meta.dot}`} aria-hidden="true" />
            <span className="sr-only">Status: </span>
            {meta.label}
        </span>
    );
}
