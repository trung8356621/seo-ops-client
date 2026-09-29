import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['addons/agent-runtime/resources/js/app/main.jsx'],
            buildDirectory: 'build-agent',
            refresh: false,
        }),
        react(),
    ],
    build: {
        outDir: 'public/build-agent',
        emptyOutDir: true,
        chunkSizeWarningLimit: 1000,
    },
    resolve: {
        preserveSymlinks: true,
        dedupe: ['react', 'react-dom', 'lucide-react'],
        alias: {
            '@agent-runtime': path.resolve(__dirname, 'addons/agent-runtime/resources/js'),
            react: path.resolve(__dirname, 'node_modules/react'),
            'react-dom': path.resolve(__dirname, 'node_modules/react-dom'),
            'lucide-react': path.resolve(__dirname, 'node_modules/lucide-react'),
        },
    },
});
