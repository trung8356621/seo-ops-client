import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'resources/css/app.css',
        'resources/css/filament/admin/theme.css',
        'resources/js/app.js',
        'resources/js/help-admin/help-admin-editor.jsx',
        'resources/js/admin-dashboard-usage-charts.js',
        'addons/content/resources/js/utils/systemDateTime.js',
        'addons/ai-prompt/resources/css/ai-result.css',
    ],
    refresh: true,
    additionalPlugins: [tailwindcss()],
}));
