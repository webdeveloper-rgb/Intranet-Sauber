import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Para desarrollar: arranca PHP (php -S localhost:8000 en la raíz del repo),
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
    // El resultado se genera en build/, que SÍ se guarda en Git (el servidor no compila)
    outDir: '../build',
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
