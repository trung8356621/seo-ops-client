import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import path from 'path';

export function createViteConfig({ input, buildDirectory = 'build', reactPlugin = true, additionalPlugins = [], refresh = false, build = {} }) {
    return {
        server: { fs: { allow: ['.', '..', path.resolve(import.meta.dirname, '../omnichannel-addons')] } },
        plugins: [
            laravel({ input, buildDirectory, refresh }),
            ...(reactPlugin ? [react()] : []),
            ...additionalPlugins,
        ],
        build: {
            outDir: `public/${buildDirectory}`,
            emptyOutDir: true,
            chunkSizeWarningLimit: 1000,
            ...build,
            rollupOptions: {
                ...build.rollupOptions,
                output: { manualChunks: sharedManualChunks, ...build.rollupOptions?.output },
            },
        },
        resolve: {
            preserveSymlinks: true,
            dedupe: ['react', 'react-dom', 'lucide-react'],
            alias: {
                '@content-addon': path.resolve(import.meta.dirname, 'addons/content/resources/js'),
                '@media-addon': path.resolve(import.meta.dirname, 'addons/media/resources/js'),
                '@seo-addon': path.resolve(import.meta.dirname, 'addons/seo/resources/js'),
                '@wordpress-addon': path.resolve(import.meta.dirname, 'addons/wordpress/resources/js'),
                '@publishing-addon': path.resolve(import.meta.dirname, 'addons/publishing/resources/js'),
                '@content-projects-addon': path.resolve(import.meta.dirname, 'addons/content-projects/resources/js'),
                '@search-intel-addon': path.resolve(import.meta.dirname, 'addons/search-intelligence/resources/js'),
                '@ai-prompt-addon': path.resolve(import.meta.dirname, 'addons/ai-prompt/resources/js'),
                '@agent-addon': path.resolve(import.meta.dirname, 'addons/agent/resources/js'),
                '@agent-runtime': path.resolve(import.meta.dirname, 'addons/agent-runtime/resources/js'),
                '@client-core': path.resolve(import.meta.dirname, 'resources/js/client-core'),
                react: path.resolve(import.meta.dirname, 'node_modules/react'),
                'react-dom': path.resolve(import.meta.dirname, 'node_modules/react-dom'),
                'lucide-react': path.resolve(import.meta.dirname, 'node_modules/lucide-react'),
            },
        },
    };
}

function sharedManualChunks(id) {
    if (!id.includes('node_modules')) return undefined;
    const parts = id.split('node_modules/')[1]?.split('/') ?? [];
    const pkgName = parts[0]?.startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0];
    if (['react', 'react-dom', 'scheduler', 'use-sync-external-store'].includes(pkgName)) return 'react-vendor';
    if (pkgName?.startsWith('@tiptap/') || pkgName?.startsWith('prosemirror-')) return 'tiptap-vendor';
    return 'vendor';
}
