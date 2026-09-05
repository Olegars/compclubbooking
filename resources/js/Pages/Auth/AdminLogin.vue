<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3';
import { useClubName } from '@/Composables/useClubName';
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue';

const clubName = useClubName();
const mode = ref('login')

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('admin.login'), {
        onFinish: () => form.reset('password'),
    });
};

const submitRegister = () => {
    registerForm.post(route('admin.register'), {
        onFinish: () => registerForm.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="`${clubName} | ${mode === 'register' ? 'Устройство на работу' : 'Вход в систему'}`" />
    <div class="admin-ui min-h-screen bg-[#020202] flex flex-col justify-center items-center p-6 font-sans text-white selection:bg-[#22c55e] selection:text-black relative">
        <AvatarWatermarkBg />

        <div class="relative z-10 w-full max-w-md bg-[#050505]/90 border border-white/5 rounded-[1rem] p-10 shadow-[0_0_80px_rgba(34,197,94,0.05)] animate-in">

            <div class="flex items-center gap-3 mb-2">
                <div class="w-3 h-3 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_10px_#22c55e]"></div>
                <h1 class="text-2xl font-bold uppercase tracking-tight">
                    {{ clubName }} <span class="text-[#22c55e]">Ctrl</span>
                </h1>
            </div>
            <p class="text-white/40 text-xs uppercase tracking-[0.16em] font-semibold mb-8">
                {{ mode === 'register' ? 'Регистрация сотрудника' : 'Вход для администраторов и операторов' }}
            </p>

            <div class="grid grid-cols-2 gap-2 mb-8">
                <button type="button"
                        class="py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="mode === 'login' ? 'bg-[#22c55e] text-black' : 'border border-white/10 text-white/50'"
                        @click="mode = 'login'">
                    Вход
                </button>
                <button type="button"
                        class="py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="mode === 'register' ? 'bg-[#22c55e] text-black' : 'border border-white/10 text-white/50'"
                        @click="mode = 'register'">
                    Устроиться
                </button>
            </div>

            <form v-if="mode === 'login'" @submit.prevent="submit" class="space-y-6">
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Email</label>
                    <input
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none transition-colors placeholder:text-white/20"
                        placeholder="admin@reactor.club"
                    >
                    <div v-if="form.errors.email" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ form.errors.email }}</div>
                </div>

                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Пароль</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none transition-colors"
                    >
                    <div v-if="form.errors.password" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ form.errors.password }}</div>
                </div>

                <label for="remember" class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.remember" type="checkbox" id="remember" class="w-4 h-4 rounded border-white/10 bg-black text-[#22c55e] focus:ring-0 focus:ring-offset-0">
                    <span class="text-xs uppercase text-white/50 tracking-wider font-semibold">Запомнить меня</span>
                </label>

                <button
                    :disabled="form.processing"
                    class="w-full py-5 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-bold uppercase text-sm tracking-wider rounded-2xl transition-all shadow-[0_0_30px_rgba(34,197,94,0.2)] active:scale-95 disabled:opacity-50"
                >
                    {{ form.processing ? 'Вход...' : 'Войти в систему' }}
                </button>
            </form>

            <form v-else @submit.prevent="submitRegister" class="space-y-6">
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Имя</label>
                    <input v-model="registerForm.name" type="text" autocomplete="name"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none">
                    <div v-if="registerForm.errors.name" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.name }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Email</label>
                    <input v-model="registerForm.email" type="email" autocomplete="email"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none">
                    <div v-if="registerForm.errors.email" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.email }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Пароль</label>
                    <input v-model="registerForm.password" type="password" autocomplete="new-password"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none">
                    <div v-if="registerForm.errors.password" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.password }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Повтор пароля</label>
                    <input v-model="registerForm.password_confirmation" type="password" autocomplete="new-password"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-[#22c55e] outline-none">
                </div>
                <button :disabled="registerForm.processing"
                        class="w-full py-5 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-bold uppercase text-sm tracking-wider rounded-2xl transition-all shadow-[0_0_30px_rgba(34,197,94,0.2)] active:scale-95 disabled:opacity-50">
                    {{ registerForm.processing ? 'Регистрация...' : 'Перейти в кабинет' }}
                </button>
                <p class="text-[10px] uppercase tracking-widest text-white/30 font-black text-center">
                    Дальше в кабинете: правила, паспорт, «Устроиться»
                </p>
            </form>
        </div>

        <div class="relative z-10 text-[11px] text-white/25 uppercase font-semibold tracking-[0.18em] mt-8">
            Terminal Node // v2.6
        </div>
    </div>
</template>
