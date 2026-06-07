import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Vite proxies /api/* to the Symfony dev server so CORS never bites us.
// Run Symfony separately: `symfony serve` or `php -S 127.0.0.1:8000 -t public`
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
