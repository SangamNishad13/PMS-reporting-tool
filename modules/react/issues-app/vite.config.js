import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
        pure_funcs: ['console.log', 'console.info', 'console.debug', 'console.warn']
      }
    },
    rollupOptions: {
      output: {
        entryFileNames: 'issues-app.js',
        chunkFileNames: 'issues-app-[name].js',
        assetFileNames: 'issues-app.[ext]',
        manualChunks: undefined
      },
    },
  },
})
