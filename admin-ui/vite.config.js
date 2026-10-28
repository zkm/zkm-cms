import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Builds into public/admin-app with hashed file names, plus manifest.json, which the PHP view
// reads to find the current files. (No ?v= query: the page chunks import "./app-<hash>.js", and
// a different URL for the entry would load the app twice, with two routers fighting.)
// `npm run dev` rebuilds on every change; reload the browser to see it.
export default defineConfig({
  plugins: [vue()],
  base: '/admin-app/',
  build: {
    outDir: '../public/admin-app',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {
      input: 'src/main.js',
      output: {
        entryFileNames: 'app-[hash].js',
        chunkFileNames: 'chunk-[name]-[hash].js',
        assetFileNames: '[name]-[hash][extname]',
      },
    },
  },
})
