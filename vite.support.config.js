import { defineConfig } from 'vite';
import { createViteConfig } from './vite.shared.config.js';

export default defineConfig(createViteConfig({
    input: [
        'resources/js/support-ticket/headerTicketComposer.js',
        'resources/css/support-ticket-header.css',
        'addons/content/resources/js/chat/groupChatApp.js',
        'addons/content/resources/js/chat/ticketPanel.js',
        'addons/content/resources/js/chat/unreadBadge.js',
        'addons/ai-prompt/resources/css/global-ai-chat.css',
    ],
    buildDirectory: 'build-support',
}));
