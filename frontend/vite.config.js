/**
 * Configuracion de compilacion y proxy de desarrollo Vite hacia el backend PHP.
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Development server: start PHP with `php -S localhost:8000 -t public`.
// inicia sesión en http://localhost:8000/login.php y luego `npm run dev`.
const PHP = process.env.PHP_URL || 'http://localhost:8000';

export default defineConfig({
  plugins: [react()],
  publicDir: false,
  server: {
    proxy: {
      '/api.php': { target: PHP, changeOrigin: true },
      '/login.php': { target: PHP, changeOrigin: true },
    },
  },
  build: {
    // The production bundle is deployed from public/build/.
    outDir: '../public/build',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'app.js',
        assetFileNames: 'app[extname]',
      },
    },
  },
});
