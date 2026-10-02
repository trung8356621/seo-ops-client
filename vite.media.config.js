import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'addons/media/resources/js/article-media-picker-cache-bootstrap.js',
        'addons/media/resources/css/media-library.css',
        'addons/media/resources/css/image-splitter.css',
        'addons/media/resources/js/media-library-actions.js',
        'addons/media/resources/js/media-library-page.jsx',
        'addons/media/resources/js/watermark-editor-page.jsx',
        'addons/media/resources/css/watermark-editor.css',
        'addons/media/resources/css/image-optimization-settings.css',
        'addons/media/resources/js/media-image-editor-page.jsx',
    ],
    buildDirectory: 'build-media',
}));
