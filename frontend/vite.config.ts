import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({plugins:[vue(),tailwindcss()],server:{port:5173,strictPort:true,proxy:{'/api':{target:process.env.BAHITUNG_API_TARGET || 'http://127.0.0.1:8000'},'/sanctum':{target:process.env.BAHITUNG_API_TARGET || 'http://127.0.0.1:8000'}}}});
