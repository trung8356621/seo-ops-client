import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'addons/content-projects/resources/js/task-builder.jsx',
        'addons/content-projects/resources/js/automation-workflow-builder.jsx',
        'addons/content-projects/resources/css/automation-workflow-builder.css',
        'addons/content-projects/resources/js/automation-workflow-viewer.jsx',
        'addons/content-projects/resources/css/automation-workflow-viewer.css',
        'addons/content-projects/resources/css/project-run-step.css',
        'addons/content-projects/resources/css/project-run-queue.css',
        'addons/content-projects/resources/js/project-run-queue.js',
        'addons/content/resources/js/article-execution-history.jsx',
    ],
    buildDirectory: 'build-projects',
}));
