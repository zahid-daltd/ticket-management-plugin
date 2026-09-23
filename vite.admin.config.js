import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Admin SPA: mounted on #wpsd-admin-root in wp-admin.
// Output is committed to /assets/admin-dist/ so production needs no Node.
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'assets/admin-dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'assets/admin-src/main.jsx',
      output: {
        entryFileNames: 'wpsd-admin.js',
        assetFileNames: 'wpsd-admin.[ext]',
      },
    },
  },
});
