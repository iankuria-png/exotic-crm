import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
// Task-owned browser fixture server; no Laravel hot file, database or upstream proxy.
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 5186, strictPort: true, watch: { ignored: ['**/output/**', '**/plans/**', '**/docs/**', '**/app/**', '**/storage/**', '**/tests/Feature/**', '**/tests/browser/artifacts/**'] } } });
