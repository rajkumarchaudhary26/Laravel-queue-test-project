import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ mode }) => ({
  plugins: [vue()],
  server: {
    host: '0.0.0.0',
    port: 8090,
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  define: {
    __DEV_MODE__: mode === 'dev',
  },
}));
