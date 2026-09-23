import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Public SPA: mounted on #wpsd-public-root / #wpsd-lookup-root via shortcodes.
// Kept lean (route-level code-splitting, only imported shadcn components).
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'assets/public-dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'assets/public-src/main.jsx',
      output: {
        entryFileNames: 'wpsd-public.js',
        assetFileNames: 'wpsd-public.[ext]',
        manualChunks: {
          lookup: ['assets/public-src/lookup.jsx'],
        },
      },
    },
  },
});
