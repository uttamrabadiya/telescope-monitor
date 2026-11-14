import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
export default defineConfig({
  plugins: [tailwindcss(), vue()],
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {
      input: 'resources/js/main.ts',
      output: {
        entryFileNames: 'assets/monitor-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
})
