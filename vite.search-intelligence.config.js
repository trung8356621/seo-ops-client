import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: ['addons/search-intelligence/resources/js/performance-hub-gsc-chart.js'],
    buildDirectory: 'build-search',
    reactPlugin: false,
}));
