import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { readFileSync } from 'node:fs';
// Bound dependency discovery to this fixture; report HTML must not reload running tests.
export default defineConfig({
    plugins: [react(), {
        name: 'ceo-built-styles',
        transformIndexHtml() {
            const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
            return (manifest['resources/js/app.jsx'].css || []).map((file) => ({ tag: 'link', attrs: { rel: 'stylesheet', href: `/build/${file}` }, injectTo: 'head' }));
        },
    }],
    optimizeDeps: { entries: ['tests/browser/fixtures/ceo-revenue.html'] },
    server: { host: '127.0.0.1', port: 5189, strictPort: true, hmr: false,
        watch: { ignored: ['**/output/**', '**/public/build/**', '**/tests/browser/artifacts/**'] } },
});
