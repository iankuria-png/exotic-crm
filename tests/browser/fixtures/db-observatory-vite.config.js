import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
// Isolated component server, with no Laravel hot-file or production database access.
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 5175, strictPort: true, proxy: { '/api': 'http://127.0.0.1:8087' } } });
