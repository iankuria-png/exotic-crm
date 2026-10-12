import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Isolated UI fixture: no Laravel hot file or database access.
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 5176, strictPort: true } });
