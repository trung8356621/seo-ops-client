import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

const editorDebugBuild = process.env.VITE_EDITOR_DEBUG_BUILD === '1';

export default defineConfig(createViteConfig({
    input: [
        'addons/content/resources/js/article-editor.jsx',
        'addons/content/resources/css/article-edit-page.css',
    ],
    buildDirectory: 'build-editor',
    build: { minify: editorDebugBuild ? false : undefined, sourcemap: editorDebugBuild },
}));
