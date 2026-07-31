<script setup>
import { Head, useForm } from '@inertiajs/vue3';

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
    <Head title="REACTOR | Вход в систему" />
    <div class="min-h-screen bg-[#020202] flex flex-col justify-center items-center p-6 font-mono text-white selection:bg-[#22c55e] selection:text-black">

        <div class="w-full max-w-md bg-[#050505] border border-white/5 rounded-[1rem] p-10 shadow-[0_0_80px_rgba(34,197,94,0.05)] animate-in">

            <div class="flex items-center gap-3 mb-2">
                <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_10px_#22c55e]"></div>
                <h1 class="text-2xl font-black uppercase italic tracking-tighter">
                    Reactor <span class="text-[#22c55e]">Ctrl</span>
                </h1>
            </div>
            <p class="text-white/20 text-[10px] uppercase tracking-[0.25em] font-black italic mb-10">
                Вход для администраторов и операторов
            </p>

            <form @submit.prevent="submit" class="space-y-6">
                <div>
                    <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-bold focus:border-[#22c55e] outline-none transition-colors placeholder:text-white/20"
                        placeholder="admin@reactor.club"
                    >
                    <div v-if="form.errors.email" class="text-red-500 text-[10px] uppercase font-black tracking-widest mt-2">{{ form.errors.email }}</div>
                </div>

                <div>
                    <label class="text-[10px] uppercase text-white/40 tracking-widest font-black italic mb-2 block">Пароль</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-bold focus:border-[#22c55e] outline-none transition-colors"
                    >
                    <div v-if="form.errors.password" class="text-red-500 text-[10px] uppercase font-black tracking-widest mt-2">{{ form.errors.password }}</div>
                </div>

                <label for="remember" class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.remember" type="checkbox" id="remember" class="w-4 h-4 rounded border-white/10 bg-black text-[#22c55e] focus:ring-0 focus:ring-offset-0">
                    <span class="text-[10px] uppercase text-white/40 tracking-widest font-black">Запомнить меня</span>
                </label>

                <button
                    :disabled="form.processing"
                    class="w-full py-5 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-black uppercase text-[10px] tracking-widest rounded-2xl transition-all shadow-[0_0_30px_rgba(34,197,94,0.2)] active:scale-95 disabled:opacity-50"
                >
                    {{ form.processing ? 'Вход...' : 'Войти в систему' }}
                </button>
            </form>
        </div>

        <div class="text-[9px] text-white/10 uppercase font-black tracking-[0.3em] italic mt-8">
            Terminal Node // v2.6
        </div>
    </div>
</template>
