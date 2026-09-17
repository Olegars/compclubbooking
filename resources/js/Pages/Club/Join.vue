<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3'
import AvatarWatermarkBg from '@/Components/AvatarWatermarkBg.vue'

const props = defineProps({
    clubs: { type: Array, default: () => [] },
    blocked_in_app: { type: Boolean, default: false },
})

const form = useForm({
    name: '',
    city: '',
    network_name: '',
    address: '',
    contact: '',
    website: '',
    admin_name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post('/clubs/join')
}
</script>

<template>
    <Head title="Зарегистрировать клуб" />
    <div class="min-h-screen bg-[#020202] text-white font-sans selection:bg-cyan-400 selection:text-black relative">
        <AvatarWatermarkBg />

        <div class="relative z-10 max-w-5xl mx-auto px-6 py-16 grid gap-10 lg:grid-cols-[1.1fr_0.9fr]">
            <div>
                <Link href="/" class="text-[10px] uppercase tracking-[0.35em] text-white/30 hover:text-cyan-400 font-black italic">
                    ← На главную
                </Link>
                <h1 class="mt-6 text-4xl font-black uppercase italic tracking-tighter">
                    Свой клуб <span class="text-cyan-400">в турнирах</span>
                </h1>
                <p class="mt-4 text-white/50 text-sm leading-relaxed max-w-xl">
                    Любой компьютерный клуб — из сети или независимый. Карточка появляется в списке соперников,
                    и две админки согласовывают регламент. Это не точка чужой сети и не устройство на работу.
                </p>

                <div v-if="blocked_in_app" class="mt-8 border border-amber-500/30 bg-amber-500/10 rounded-2xl p-5 text-amber-200 text-sm">
                    Откройте эту страницу в обычном браузере. Из приложения гостя или зала клуб завести нельзя.
                </div>

                <form v-else @submit.prevent="submit" class="mt-10 space-y-5">
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Название клуба</label>
                            <input v-model="form.name" type="text" required
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                            <div v-if="form.errors.name" class="text-red-400 text-xs mt-2">{{ form.errors.name }}</div>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Город</label>
                            <input v-model="form.city" type="text" required
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                            <div v-if="form.errors.city" class="text-red-400 text-xs mt-2">{{ form.errors.city }}</div>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Сеть (если есть)</label>
                            <input v-model="form.network_name" type="text" placeholder="необязательно"
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Адрес</label>
                            <input v-model="form.address" type="text"
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Контакт</label>
                            <input v-model="form.contact" type="text" placeholder="телефон или Telegram"
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Сайт</label>
                            <input v-model="form.website" type="text"
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                        </div>
                    </div>

                    <div class="pt-4 border-t border-white/10 space-y-4">
                        <p class="text-[10px] uppercase tracking-[0.3em] text-cyan-400 font-black">Учётка управляющего</p>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Ваше имя</label>
                            <input v-model="form.admin_name" type="text" required
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                            <div v-if="form.errors.admin_name" class="text-red-400 text-xs mt-2">{{ form.errors.admin_name }}</div>
                        </div>
                        <div>
                            <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Email</label>
                            <input v-model="form.email" type="email" required
                                   class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                            <div v-if="form.errors.email" class="text-red-400 text-xs mt-2">{{ form.errors.email }}</div>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Пароль</label>
                                <input v-model="form.password" type="password" required
                                       class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                                <div v-if="form.errors.password" class="text-red-400 text-xs mt-2">{{ form.errors.password }}</div>
                            </div>
                            <div>
                                <label class="text-[10px] uppercase tracking-widest text-white/40 mb-2 block">Повтор пароля</label>
                                <input v-model="form.password_confirmation" type="password" required
                                       class="w-full bg-black border border-white/10 rounded-2xl px-4 py-4 outline-none focus:border-cyan-500" />
                            </div>
                        </div>
                    </div>

                    <button type="submit" :disabled="form.processing"
                            class="w-full py-5 bg-cyan-500 hover:bg-cyan-400 text-black font-black uppercase tracking-widest rounded-2xl disabled:opacity-50">
                        {{ form.processing ? 'Создаём…' : 'Завести клуб и войти' }}
                    </button>
                    <p class="text-[10px] uppercase tracking-widest text-white/30 text-center">
                        Сразу админка турниров. Устройство на работу и локации сети — это другое.
                    </p>
                </form>
            </div>

            <div class="lg:pt-16">
                <h2 class="text-[10px] uppercase tracking-[0.35em] text-white/35 font-black mb-4">Уже в контуре</h2>
                <div v-if="!clubs.length" class="border border-dashed border-white/10 rounded-2xl p-8 text-white/30 text-xs uppercase tracking-widest">
                    Пока пусто — ваш клуб будет первым.
                </div>
                <div v-else class="space-y-2 max-h-[70vh] overflow-y-auto">
                    <div v-for="club in clubs" :key="club.id"
                         class="border border-white/5 bg-[#080808]/90 rounded-2xl px-4 py-3">
                        <div class="font-black uppercase italic">{{ club.name }}</div>
                        <div class="text-[11px] text-white/40 mt-1">
                            <span v-if="club.city">{{ club.city }}</span>
                            <span v-if="club.network_name"> · {{ club.network_name }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
