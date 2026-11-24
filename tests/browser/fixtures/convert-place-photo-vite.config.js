import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
// Isolated component fixture for the Lead Scraper convert dialog; API answers are stubbed in the page.
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 5188, strictPort: true } });
