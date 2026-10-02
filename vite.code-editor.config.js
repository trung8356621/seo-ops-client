import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: ['resources/js/admin/code-editor/index.js'],
    buildDirectory: 'build-code-editor',
    reactPlugin: false,
}));
