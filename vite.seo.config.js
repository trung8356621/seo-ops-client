import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'addons/seo/resources/js/article-seo-preview.jsx',
        'addons/seo/resources/js/keyword-detail-panel.jsx',
        'addons/seo/resources/js/keyword-destinations-modal.jsx',
        'addons/seo/resources/js/domain-context.js',
        'addons/seo/resources/css/operational-landing-dashboard.css',
        'addons/seo/resources/css/ops-statistics.css',
    ],
    buildDirectory: 'build-seo',
}));
