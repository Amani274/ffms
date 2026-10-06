import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Production build is written to ../app and served by Apache at /ffms/app/.
// In dev, API calls to /ffms/api are proxied to XAMPP's Apache.
export default defineConfig({
  base: './',
  plugins: [react()],
  build: { outDir: '../app', emptyOutDir: true, chunkSizeWarningLimit: 900 },
  server: {
    port: 5173,
    proxy: { '/ffms/api': { target: 'http://localhost', changeOrigin: false } },
  },
});
