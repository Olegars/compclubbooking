import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js'; // <--- Импорт из NPM

const isClientApp = /CompClubClient/i.test(navigator.userAgent || '');
const isAdminApp = /CompClubAdmin/i.test(navigator.userAgent || '');
const isBossApp = /CompClubBoss/i.test(navigator.userAgent || '');
const isStoreApp = /CompClubStore/i.test(navigator.userAgent || '');
const clientPages = import.meta.glob([
    './Pages/Home/**/*.vue',
    './Pages/Auth/Login.vue',
    './Pages/Auth/RegisterView.vue',
    './Pages/Booking/**/*.vue',
    './Pages/User/**/*.vue',
    './Pages/Legal/**/*.vue',
]);
const adminPages = import.meta.glob([
    './Pages/Admin/**/*.vue',
    './Pages/Auth/AdminLogin.vue',
]);
const bossPages = import.meta.glob([
    './Pages/Admin/**/*.vue',
    './Pages/Auth/AdminLogin.vue',
    './Pages/Auth/StoreLogin.vue',
    './Pages/Auth/StoreHire.vue',
]);
const storePages = import.meta.glob([
    './Pages/Admin/Salary.vue',
    './Pages/Admin/StoreCabinet.vue',
    './Pages/Admin/SystemDocs.vue',
    './Pages/Admin/Store/**/*.vue',
    './Pages/Auth/StoreLogin.vue',
    './Pages/Auth/StoreHire.vue',
]);
const isStoreAppPage = (name) => (
    name === 'Admin/Salary'
    || name === 'Admin/StoreCabinet'
    || name === 'Admin/SystemDocs'
    || name.startsWith('Admin/Store/')
    || name === 'Auth/StoreLogin'
    || name === 'Auth/StoreHire'
);
const isBossAppPage = (name) => (
    name.startsWith('Admin/')
    || name === 'Auth/AdminLogin'
    || name === 'Auth/StoreLogin'
    || name === 'Auth/StoreHire'
);

if (isClientApp && /^\/(admin|store)(\/|$)/.test(window.location.pathname)) {
    window.location.replace('/');
}

if (isAdminApp && !/^\/admin(\/|$)/.test(window.location.pathname)) {
    window.location.replace('/admin/login');
}

if (isBossApp && !/^\/(admin|store)(\/|$)/.test(window.location.pathname)) {
    window.location.replace('/admin/login');
}

if (isStoreApp) {
    const path = window.location.pathname;
    const storeOk = /^\/store(\/|$)/.test(path)
        || /^\/admin\/salary(\/|$)/.test(path)
        || /^\/admin\/store(\/|$)/.test(path)
        || /^\/admin\/docs(\/|$)/.test(path);
    if (!storeOk) {
        window.location.replace('/store/login');
    }
}

createInertiaApp({
    title: (title) => title,
    resolve: (name) => {
        if (isClientApp) {
            if (name.startsWith('Admin/') || name === 'Auth/AdminLogin' || name === 'Auth/StoreLogin' || name === 'Auth/StoreHire') {
                return resolvePageComponent('./Pages/Home/Index.vue', clientPages);
            }
            return resolvePageComponent(`./Pages/${name}.vue`, clientPages);
        }
        if (isStoreApp) {
            if (!isStoreAppPage(name)) {
                return resolvePageComponent('./Pages/Auth/StoreLogin.vue', storePages);
            }
            return resolvePageComponent(`./Pages/${name}.vue`, storePages);
        }
        if (isBossApp) {
            if (!isBossAppPage(name)) {
                return resolvePageComponent('./Pages/Auth/AdminLogin.vue', bossPages);
            }
            return resolvePageComponent(`./Pages/${name}.vue`, bossPages);
        }
        if (isAdminApp) {
            if (!(name.startsWith('Admin/') || name === 'Auth/AdminLogin')) {
                return resolvePageComponent('./Pages/Auth/AdminLogin.vue', adminPages);
            }
            return resolvePageComponent(`./Pages/${name}.vue`, adminPages);
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
