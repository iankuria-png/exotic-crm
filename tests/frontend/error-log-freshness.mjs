import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { transformWithEsbuild } from 'vite';

const source = fs.readFileSync(new URL('../../resources/js/pages/Settings.jsx', import.meta.url), 'utf8');
const start = source.indexOf('function errorLogLevelClasses(');
const end = source.indexOf('\nfunction roleClasses(', start);
const { code } = await transformWithEsbuild(source.slice(start, end), 'error-log-fixture.jsx', { loader: 'jsx', jsx: 'transform' });
const now = Date.now();
const row = { id: 1, message: 'A real incident', occurrence_count: 9, last_seen_at: new Date(now).toISOString(), source: 'exception', level: 'error' };
const data = { data: [row], total: 1, current_page: 1, per_page: 25, last_page: 1, summary: { active_today: 1, unresolved_total: 1 } };
function fixture({ list = {}, detail = {}, selected = null, status = 'unresolved', mutationError = false } = {}) {
    let state = 0; const options = []; let listRefresh = 0; let detailRefresh = 0;
    const sandbox = {
        React, Date, console,
        useState: (initial) => { const index = state++; return [index === 6 ? selected : index === 5 ? status : typeof initial === 'function' ? initial() : initial, () => {}]; },
        useEffect: () => {},
        useQueryClient: () => ({ invalidateQueries() {} }),
        useQuery: (config) => { options.push(config); return options.length === 1
            ? { data, dataUpdatedAt: now, refetch: () => { listRefresh++; }, ...list }
            : { dataUpdatedAt: now, refetch: () => { detailRefresh++; }, ...detail }; },
        useMutation: () => ({ isError: mutationError, mutate() {} }),
        DiagnosticsExportModal: () => null,
        api: { get: (...args) => Promise.resolve({ data: { args } }) },
    };
    vm.createContext(sandbox);
    vm.runInContext(`${code}\nthis.Component = ErrorLogsWorkspace;`, sandbox);
    const element = sandbox.Component();
    return { html: renderToStaticMarkup(element), element, options, refreshes: () => [listRefresh, detailRefresh] };
}
function findButton(element, label) {
    if (!element || typeof element !== 'object') return null;
    if (element.type === 'button' && element.props.children === label) return element;
    for (const child of React.Children.toArray(element.props?.children)) {
        const found = findButton(child, label); if (found) return found;
    }
    return null;
}
const current = fixture();
assert.match(current.html, /Active today/);
assert.doesNotMatch(current.html, /Occurrences today/);
assert.match(current.html, /Last updated/);
assert.equal(current.options[0].refetchInterval, 30_000);
assert.equal(current.options[1].refetchInterval, 30_000);
findButton(current.element, 'Refresh now').props.onClick();
assert.deepEqual(current.refreshes(), [1, 0]);

const failed = fixture({ list: { data: undefined, isError: true, dataUpdatedAt: 0 } });
assert.match(failed.html, /Unable to load incidents/);
assert.doesNotMatch(failed.html, /No incidents match|No unresolved errors/);
assert.match(failed.html, /error-pulse-stat-value[^>]*>—/);
const stale = fixture({ list: { isError: true, dataUpdatedAt: now - 90_000 } });
assert.match(stale.html, /last successful snapshot/);
assert.match(stale.html, /A real incident/);
assert.match(fixture({ list: { dataUpdatedAt: now - 90_000 } }).html, /Updates delayed/);
assert.match(fixture({ list: { data: { ...data, data: [], total: 0 } } }).html, /No incidents match/);

const opened = fixture({ selected: 1, detail: { data: { ...row, notes: 'Verified repair', resolved_at: null, occurrences: [] } } });
assert.match(opened.html, /Previous resolution/);
assert.match(opened.html, /automatically reopen/);
findButton(opened.element, 'Refresh now').props.onClick();
assert.deepEqual(opened.refreshes(), [1, 1]);
assert.match(fixture({ selected: 1, detail: { data: { ...row, notes: 'Verified repair', resolved_at: new Date(now).toISOString(), occurrences: [] } } }).html, /Resolution notes/);
assert.match(fixture({ selected: 1, detail: { data: row, isError: true } }).html, /last successful detail snapshot/);
assert.match(fixture({ selected: 1, detail: { isError: true, dataUpdatedAt: 0 } }).html, /Unable to load incident details/);
assert.match(fixture({ mutationError: true }).html, /status could not be saved/);
const all = fixture({ status: '' });
assert.equal((await all.options[0].queryFn()).args[1].params.status, 'all');
console.log('Error Logs: 14 actual-component state/refresh checks passed.');
