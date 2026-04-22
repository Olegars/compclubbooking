<script setup>
import { useForm } from '@inertiajs/vue3';

// ЭТУ СТРОЧКУ НУЖНО УДАЛИТЬ, если она там есть:
// import { route } from 'ziggy-js';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    // Функция route() подхватится автоматически из глобального плагина
    form.post(route('admin.login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <div class="min-h-screen bg-slate-950 flex flex-col justify-center items-center p-6">
        <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-xl p-8 shadow-2xl">
            <h1 class="text-2xl font-bold text-white mb-2">REACTOR Control</h1>
            <p class="text-slate-400 mb-6 text-sm">Вход для администраторов и операторов</p>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-xs uppercase tracking-widest text-slate-500 mb-1 font-bold">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 text-white focus:border-purple-500 outline-none transition"
                        placeholder="admin@reactor.club"
                    >
                    <div v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</div>
                </div>

                <div>
                    <label class="block text-xs uppercase tracking-widest text-slate-500 mb-1 font-bold">Пароль</label>
                    <input
                        v-model="form.password"
                        type="password"
                        class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-3 text-white focus:border-purple-500 outline-none transition"
                    >
                </div>

                <div class="flex items-center">
                    <input v-model="form.remember" type="checkbox" id="remember" class="rounded border-slate-700 text-purple-600 bg-slate-950">
                    <label for="remember" class="ml-2 text-sm text-slate-400">Запомнить меня</label>
                </div>

                <button
                    :disabled="form.processing"
                    class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-lg transition shadow-lg shadow-purple-900/20"
                >
                    {{ form.processing ? 'Вход...' : 'ВОЙТИ В СИСТЕМУ' }}
                </button>
            </form>
        </div>
    </div>
</template>
