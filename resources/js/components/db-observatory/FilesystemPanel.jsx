import React, { useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import dbObservatory from '../../services/dbObservatory';
import { apiError, ErrorState, humanize, Loading } from './shared';
import { OperationResult } from './ContainmentPanel';

export default function FilesystemPanel({ platformId }) {
    const [diagnostic, setDiagnostic] = useState(null);
    const [operation, setOperation] = useState(null);
    const [error, setError] = useState(null);
    const requestKeys = useRef({});
    const query = useQuery({ queryKey: ['dbo', 'filesystem', platformId], queryFn: () => dbObservatory.filesystem(platformId) });
    const diagnose = useMutation({ mutationFn: () => dbObservatory.diagnoseFilesystem(platformId), onSuccess: (data) => { setDiagnostic(data); setError(null); query.refetch(); }, onError: (e) => setError(apiError(e)) });
    const preview = useMutation({ mutationFn: (id) => { requestKeys.current[id] ||= crypto.randomUUID(); return dbObservatory.quarantinePreview(id, { request_key: requestKeys.current[id] }); }, onSuccess: (data) => { setOperation(data.operation); setError(null); }, onError: (e) => setError(apiError(e)) });
    return <div className="space-y-4">
        <div><h4 className="font-semibold text-slate-900">Registered filesystem root</h4><p className="mt-1 text-xs leading-5 text-slate-500">Requires a provisioned account helper with a restricted SSH command and pinned host key. Discovered addon roots require separate registration. A zero-byte unexpected file is historical evidence, not proof of an active shell.</p></div>
        <button type="button" className="crm-btn-primary" disabled={diagnose.isPending} onClick={() => diagnose.mutate()}>{diagnose.isPending ? 'Inspecting registered root…' : 'Run read-only diagnostics'}</button>
        {diagnostic ? <div aria-live="polite" className="rounded bg-slate-50 p-3 text-xs"><p>Core checksum command: {diagnostic.core.verified ? 'Passed; unexpected files listed separately' : 'Failed or unavailable — inspect gaps'}</p>{diagnostic.gaps.map((gap, i) => <p key={i} className="mt-1 text-amber-800">{gap.path || ''} {humanize(gap.reason)}</p>)}<p className="mt-2">Coverage: {diagnostic.coverage_scope}. Unregistered discoveries: {diagnostic.unregistered_discoveries.length}.</p></div> : null}
        {error ? <p role="alert" className="rounded bg-rose-50 p-3 text-xs text-rose-800">{error}</p> : null}
        {query.isLoading ? <Loading rows={2} /> : query.isError ? <ErrorState error={query.error} onRetry={query.refetch} /> : query.data.observations.length ? <ul className="divide-y divide-slate-100">{query.data.observations.map((file) => <li key={file.id} className="py-3"><p className="crm-mono break-all text-xs font-semibold">{file.metadata.path}</p><p className="mt-1 text-xs text-slate-500">{file.metadata.size} bytes · {file.metadata.source}</p><button type="button" className="crm-btn-secondary mt-2" disabled={preview.isPending} onClick={() => preview.mutate(file.id)}>Preview quarantine</button></li>)}</ul> : <p className="text-sm text-slate-500">No file observations saved yet. Diagnostics report their limits explicitly.</p>}
        {operation ? <OperationResult key={operation.id} initial={operation} /> : null}
    </div>;
}
