import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js'; // <--- Импорт из NPM

const isClientApp = /CompClubClient/i.test(navigator.userAgent || '');
const clientPages = import.meta.glob([
    './Pages/Home/**/*.vue',
    './Pages/Auth/Login.vue',
    './Pages/Auth/RegisterView.vue',
    './Pages/Booking/**/*.vue',
    './Pages/User/**/*.vue',
    './Pages/Legal/**/*.vue',
]);

if (isClientApp && /^\/admin(\/|$)/.test(window.location.pathname)) {
    window.location.replace('/');
}

createInertiaApp({
    title: (title) => title,
    resolve: (name) => {
        if (isClientApp) {
            if (name.startsWith('Admin/') || name === 'Auth/AdminLogin') {
                return resolvePageComponent('./Pages/Home/Index.vue', clientPages);
            }
            return resolvePageComponent(`./Pages/${name}.vue`, clientPages);
        }
        return resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue'));
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue) // <--- Обязательно используем здесь
            .mount(el);
    },
});
