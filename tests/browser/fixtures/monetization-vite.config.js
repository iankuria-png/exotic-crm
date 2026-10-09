import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Isolated UI fixture server; never writes Laravel's public/hot or accesses the database.
export default defineConfig({ plugins: [react(), tailwindcss()], server: { host: '127.0.0.1', port: 5174, strictPort: true } });
