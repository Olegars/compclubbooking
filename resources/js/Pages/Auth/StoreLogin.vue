<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { useClubName } from '@/Composables/useClubName'
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue'

const clubName = useClubName()
const mode = ref('login')

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const registerForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'assembler',
})

const submit = () => {
    form.post(route('store.login'), {
        onFinish: () => form.reset('password'),
    })
}

const submitRegister = () => {
    registerForm.post(route('store.register'), {
        onFinish: () => registerForm.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="`${clubName} | ${mode === 'register' ? 'Устройство в магазин' : 'Вход в магазин'}`" />
    <div class="admin-ui min-h-screen bg-[#020202] flex flex-col justify-center items-center p-6 font-sans text-white selection:bg-amber-500 selection:text-black relative">
        <AvatarWatermarkBg />

        <div class="relative z-10 w-full max-w-md bg-[#050505]/90 border border-amber-500/20 rounded-[1rem] p-10 shadow-[0_0_80px_rgba(245,158,11,0.08)] animate-in">

            <div class="flex items-center gap-3 mb-2">
                <div class="w-3 h-3 bg-amber-500 rounded-full animate-pulse shadow-[0_0_10px_#f59e0b]"></div>
                <h1 class="text-2xl font-bold uppercase tracking-tight">
                    {{ clubName }} <span class="text-amber-400">Store</span>
                </h1>
            </div>
            <p class="text-white/40 text-xs uppercase tracking-[0.16em] font-semibold mb-8">
                {{ mode === 'register' ? 'Регистрация сотрудника магазина' : 'Вход для сборщиков и менеджеров' }}
            </p>

            <div class="grid grid-cols-2 gap-2 mb-8">
                <button type="button"
                        class="py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="mode === 'login' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                        @click="mode = 'login'">
                    Вход
                </button>
                <button type="button"
                        class="py-3 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="mode === 'register' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
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
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none transition-colors placeholder:text-white/20"
                        placeholder="build@0451.space"
                    >
                    <div v-if="form.errors.email" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ form.errors.email }}</div>
                </div>

                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Пароль</label>
                    <input
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none transition-colors"
                    >
                    <div v-if="form.errors.password" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ form.errors.password }}</div>
                </div>

                <label for="store-remember" class="flex items-center gap-3 cursor-pointer select-none">
                    <input v-model="form.remember" type="checkbox" id="store-remember" class="w-4 h-4 rounded border-white/10 bg-black text-amber-500 focus:ring-0 focus:ring-offset-0">
                    <span class="text-xs uppercase text-white/50 tracking-wider font-semibold">Запомнить меня</span>
                </label>

                <button
                    :disabled="form.processing"
                    class="w-full py-5 bg-amber-500 hover:bg-amber-400 text-black font-bold uppercase text-sm tracking-wider rounded-2xl transition-all shadow-[0_0_30px_rgba(245,158,11,0.25)] active:scale-95 disabled:opacity-50"
                >
                    {{ form.processing ? 'Вход...' : 'Войти в магазин' }}
                </button>
            </form>

            <form v-else @submit.prevent="submitRegister" class="space-y-6">
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Имя</label>
                    <input v-model="registerForm.name" type="text" autocomplete="name"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none">
                    <div v-if="registerForm.errors.name" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.name }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Email</label>
                    <input v-model="registerForm.email" type="email" autocomplete="email"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none">
                    <div v-if="registerForm.errors.email" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.email }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Должность</label>
                    <select v-model="registerForm.role"
                            class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none">
                        <option value="assembler">Сборщик</option>
                        <option value="store_manager">Менеджер магазина</option>
                    </select>
                    <div v-if="registerForm.errors.role" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.role }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Пароль</label>
                    <input v-model="registerForm.password" type="password" autocomplete="new-password"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none">
                    <div v-if="registerForm.errors.password" class="text-red-500 text-xs uppercase font-semibold tracking-wider mt-2">{{ registerForm.errors.password }}</div>
                </div>
                <div>
                    <label class="text-xs uppercase text-white/50 tracking-wider font-semibold mb-2 block">Повтор пароля</label>
                    <input v-model="registerForm.password_confirmation" type="password" autocomplete="new-password"
                           class="w-full bg-black border-2 border-white/5 rounded-2xl px-5 py-4 text-white font-semibold focus:border-amber-500 outline-none">
                </div>
                <button :disabled="registerForm.processing"
                        class="w-full py-5 bg-amber-500 hover:bg-amber-400 text-black font-bold uppercase text-sm tracking-wider rounded-2xl transition-all shadow-[0_0_30px_rgba(245,158,11,0.25)] active:scale-95 disabled:opacity-50">
                    {{ registerForm.processing ? 'Регистрация...' : 'Перейти к устройству' }}
                </button>
                <p class="text-[10px] uppercase tracking-widest text-white/30 font-black text-center">
                    Дальше: правила магазина, паспорт, проверка
                </p>
            </form>

            <Link href="/admin/login" class="mt-8 block text-center text-[10px] uppercase tracking-widest text-white/35 hover:text-amber-400 font-black">
                Админам клуба →
            </Link>
        </div>

        <div class="relative z-10 text-[11px] text-white/25 uppercase font-semibold tracking-[0.18em] mt-8">
            Store Node // v1.0
        </div>
    </div>
</template>
