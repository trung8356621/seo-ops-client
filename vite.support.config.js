import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'resources/js/service-widget/headerServiceWidget.js',
        'resources/css/service-widget-header.css',
        'resources/js/support-widget/headerSupportWidget.js',
        'resources/css/support-widget-header.css',
        'addons/content/resources/js/chat/groupChatApp.js',
        'addons/content/resources/js/chat/unreadBadge.js',
        'addons/ai-prompt/resources/css/global-ai-chat.css',
    ],
    buildDirectory: 'build-support',
}));
