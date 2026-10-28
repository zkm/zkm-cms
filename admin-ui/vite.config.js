import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Builds into public/admin-app with fixed file names; the PHP view adds ?v= for cache busting.
// `npm run dev` rebuilds on every change; reload the browser to see it.
export default defineConfig({
  plugins: [vue()],
  base: '/admin-app/',
  build: {
    outDir: '../public/admin-app',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.js',
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: 'chunk-[name]-[hash].js',
        assetFileNames: (asset) => (asset.names?.[0]?.endsWith('.css') ? 'app.css' : '[name][extname]'),
      },
    },
  },
})
