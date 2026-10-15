import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import vm from 'node:vm';
import { File } from 'node:buffer';
import React from 'react';
import axios from 'axios';
import { transformWithEsbuild } from 'vite';

// Exercise the actual composer and shared Axios transforms over a local HTTP
// connection: backend upload tests alone cannot catch a file serialized as JSON.
const apiSource = fs.readFileSync(new URL('../../resources/js/services/api.js', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export default api;', 'this.api = api;');
const apiContext = vm.createContext({
    axios, localStorage: { getItem: () => null }, readImpersonationSnapshot: () => null,
});
vm.runInContext(apiSource, apiContext);
const api = apiContext.api;
const composerSource = fs.readFileSync(new URL('../../resources/js/components/clients/StoryComposerModal.jsx', import.meta.url), 'utf8')
    .replace(/^import .*;\n/gm, '').replace('export default function', 'function');
const { code } = await transformWithEsbuild(composerSource, 'StoryComposerModal.jsx', { loader: 'jsx', jsx: 'transform' });

function findSubmit(element) {
    if (!element || typeof element !== 'object') return null;
    if (element.type === 'button' && /^Post .*stor/.test(element.props.children)) return element;
    for (const child of React.Children.toArray(element.props?.children)) {
        const found = findSubmit(child);
        if (found) return found;
    }
    return null;
}

function composer({ source = 'upload', file = null, selected = null, parts = 1, start = 0, duration = 0 } = {}) {
    const state = [source, selected, file, '', '  New story  ', start, parts, duration, null, false, ''];
    const errors = [];
    const posted = [];
    let index = 0;
    const context = vm.createContext({
        React, api, FormData,
        useState: () => { const slot = index++; return [state[slot], (value) => { if (slot === 10 && value) errors.push(value); }]; },
        useRef: () => ({ current: null }), useEffect: () => {}, useMemo: (fn) => fn(),
        useQuery: () => ({ data: { data: [] }, isLoading: false }),
    });
    vm.runInContext(`${code}\nthis.Component = StoryComposerModal;`, context);
    const element = context.Component({
        open: true, clientId: 123, limits: { clip_seconds: 15, max_parts: 8, max_active: 8 },
        onClose() {}, onPosted: (data) => posted.push(data),
    });
    return { button: findSubmit(element), errors, posted };
}

const requests = [];
const server = http.createServer(async (request, response) => {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    requests.push({ headers: request.headers, url: request.url, body: Buffer.concat(chunks).toString('latin1') });
    response.writeHead(201, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ success: true, story_ids: [456] }));
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
api.defaults.baseURL = `http://127.0.0.1:${server.address().port}/api`;

try {
    for (const [name, options] of [
        ['photo', { file: new File(['story-photo-bytes'], 'story.jpg', { type: 'image/jpeg' }) }],
        ['video', { file: new File(['story-video-bytes'], 'story.mp4', { type: 'video/mp4' }), start: 3, parts: 2, duration: 40 }],
        ['profile media', { source: 'media', selected: { id: 789, url: '/gallery.jpg', mime_type: 'image/jpeg' } }],
    ]) {
        const fixture = composer(options);
        assert.equal(fixture.button.props.disabled, false);
        await fixture.button.props.onClick();
        assert.deepEqual(fixture.errors, []);
        assert.equal(fixture.posted.length, 1);
        const request = requests.at(-1);
        assert.equal(request.url, '/api/crm/clients/123/stories');
        assert.match(request.headers['content-type'], /^multipart\/form-data; boundary=/, `${name} must arrive as multipart`);
        assert.match(request.body, /name="caption"\r\n\r\nNew story\r\n/);
        if (options.file) {
            assert.ok(request.body.includes(`name="file"; filename="${options.file.name}"`));
            assert.ok(request.body.includes(await options.file.text()), `${name} file bytes must survive transport`);
            assert.ok(request.body.includes(`Content-Type: ${options.file.type}`));
            assert.doesNotMatch(request.body, /name="attachment_id"/);
        } else {
            assert.match(request.body, /name="attachment_id"\r\n\r\n789\r\n/);
            assert.doesNotMatch(request.body, /name="file"/);
        }
        if (name === 'video') {
            assert.match(request.body, /name="start"\r\n\r\n3\r\n/);
            assert.match(request.body, /name="parts"\r\n\r\n2\r\n/);
        }
        console.log(`${name}: multipart, payload and success callback passed`);
    }
    assert.equal(composer().button.props.disabled, true, 'No file selected must prevent posting');
    console.log('Story composer: photo, video, profile media and empty-selection checks passed.');
} finally {
    await new Promise((resolve) => server.close(resolve));
}
