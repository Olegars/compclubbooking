import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite'; // 1. Импортируем плагин
import path from 'path';


export default defineConfig({
    plugins: [
        tailwindcss(), // 2. Добавляем в начало списка плагинов
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),

        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '0.0.0.0', // Слушаем все IP
        port: 5173,      // Стандартный порт Vite (или твой 22223)
        hmr: {
            host: '192.168.222.2' // КРИТИЧНО: Чтобы Laravel генерировал ссылки на этот IP
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});
