import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { coberturaJavaScript } from './tests/coverage/vite-plugin.js';

export default defineConfig({
    build: process.env.CHAT_COVERAGE === '1' ? { sourcemap: true, minify: false } : {},
    plugins: [
        ...(process.env.CHAT_COVERAGE === '1' ? [coberturaJavaScript()] : []),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/chat.js'],
            refresh: true,
        }),
    ],
});
