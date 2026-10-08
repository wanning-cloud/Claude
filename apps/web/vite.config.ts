import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// The build lives in podcast-admin/analytics/ on monteur-podcast.de; the API next to it in analytics/api/.
export default defineConfig({
  base: '/podcast-admin/analytics/',
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: { '/podcast-admin/analytics/api': 'http://127.0.0.1:8787' },
  },
  preview: {
    port: 4173,
    proxy: { '/podcast-admin/analytics/api': 'http://127.0.0.1:8787' },
  },
  build: {
    target: 'es2022',
    cssCodeSplit: true,
    reportCompressedSize: true,
    rollupOptions: {
      output: {
        manualChunks: (id: string) =>
          /node_modules\/(react|react-dom|react-router|scheduler)\//.test(id) ? 'react' : id.includes('@tanstack') ? 'query' : undefined,
      },
    },
  },
});
