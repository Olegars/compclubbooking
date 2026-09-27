<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import MainLayout from '@/Layouts/MainLayout.vue'
import AvatarEditor from '@/Components/AvatarEditor.vue'

const page = usePage()
const user = page.props.auth.user
const faceit = computed(() => (page.props as any).faceit || { mode: 'off' })
const flash = computed(() => (page.props as any).flash || {})

const showSuccess = ref(false)

const form = useForm({
    name: user.name,
    email: user.email,
    phone: user.phone || '',
})

const updateProfile = () => {
    // ИСПРАВЛЕН РОУТ С /auth/profile НА /account/profile
    form.patch('/account/profile', {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess.value = true
            setTimeout(() => { showSuccess.value = false }, 3000)
        },
    })
}

const unlinkFaceit = () => {
    router.post('/account/faceit/unlink', {}, { preserveScroll: true })
}
</script>

<template>
    <MainLayout>
        <div class="max-w-2xl mx-auto w-full bg-white/5 md:bg-[#0a0a0a] border border-white/10 md:border-white/5 rounded-xl md:rounded-[1rem] p-4 sm:p-8 md:p-10 md:shadow-2xl relative">

            <div v-if="showSuccess" class="absolute top-8 right-8 bg-[#22c55e]/20 border border-[#22c55e]/50 text-[#22c55e] px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest animate-pulse">
                Обновлено
            </div>

            <h2 class="text-[#22c55e] text-2xl font-black mb-8 tracking-widest uppercase italic">Настройки аккаунта</h2>

            <div v-if="faceit.mode && faceit.mode !== 'off'" class="mb-10 pb-8 border-b border-white/5">
                <span class="text-[10px] uppercase text-white/30 tracking-[0.2em] ml-1 font-black block mb-4">FACEIT</span>
                <p v-if="flash.error" class="text-red-400 text-xs mb-3">{{ flash.error }}</p>
                <p v-if="flash.success" class="text-[#22c55e] text-xs mb-3">{{ flash.success }}</p>
                <div v-if="faceit.linked" class="flex items-center gap-4">
                    <img v-if="faceit.avatar_url" :src="faceit.avatar_url" alt="" class="w-12 h-12 rounded-full object-cover" />
                    <div>
                        <div class="font-black">{{ faceit.nickname }}</div>
                        <div class="text-[11px] text-white/50">уровень {{ faceit.skill_level }} · Elo {{ faceit.elo }}<span v-if="faceit.banned"> · бан</span></div>
                        <div v-if="faceit.stale_label" class="text-[10px] text-white/30">{{ faceit.stale_label }}</div>
                    </div>
                </div>
                <p v-else class="text-sm text-white/50 mb-3">Привяжи FACEIT, чтобы в зале подставился уровень, а не заглушка.</p>
                <a v-if="faceit.hub_url" :href="faceit.hub_url" target="_blank" rel="noopener" class="inline-block mt-3 text-[10px] uppercase tracking-widest text-orange-300">Вступить в хаб клуба</a>
                <div class="mt-4">
                    <a v-if="!faceit.linked" href="/account/faceit/redirect" class="inline-block px-4 py-2 rounded-xl bg-orange-500 text-black text-[10px] font-black uppercase tracking-widest">Привязать FACEIT</a>
                    <button v-else type="button" class="text-[10px] uppercase tracking-widest text-red-400" @click="unlinkFaceit">Отвязать</button>
                </div>
            </div>

            <div class="mb-10 pb-8 border-b border-white/5">
                <span class="text-[10px] uppercase text-white/30 tracking-[0.2em] ml-1 font-black block mb-5">Фото профиля</span>
                <AvatarEditor />
            </div>

            <form @submit.prevent="updateProfile" class="space-y-6">
                <div class="space-y-2">
                    <label class="text-[10px] uppercase text-white/30 tracking-[0.2em] ml-4 font-black">Игровой никнейм</label>
                    <input v-model="form.name" type="text"
                           class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white font-mono focus:border-[#22c55e] focus:ring-0 transition-all outline-none" />
                    <div v-if="form.errors.name" class="text-red-500 text-[10px] ml-4 uppercase">{{ form.errors.name }}</div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] uppercase text-white/30 tracking-[0.2em] ml-4 font-black">Привязанный номер</label>
                    <div class="w-full bg-black/50 border border-white/5 rounded-2xl p-4 text-white/40 font-mono flex justify-between items-center cursor-not-allowed">
                        <span>{{ form.phone || 'Не привязан' }}</span>
                        <span class="text-[9px] border border-white/10 px-2 py-1 rounded italic text-white/20">FIXED</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] uppercase text-white/30 tracking-[0.2em] ml-4 font-black">Электронная почта</label>
                    <input v-model="form.email" type="email"
                           class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white font-mono focus:border-[#22c55e] focus:ring-0 transition-all outline-none" />
                    <div v-if="form.errors.email" class="text-red-500 text-[10px] ml-4 uppercase">{{ form.errors.email }}</div>
                </div>

                <div class="pt-6">
                    <button type="submit" :disabled="form.processing"
                            class="w-full py-5 bg-white/5 border border-white/10 rounded-2xl font-black uppercase text-xs tracking-[0.3em] text-white hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] transition-all disabled:opacity-30">
                        {{ form.processing ? 'Сохранение...' : 'Обновить профиль' }}
                    </button>
                </div>
            </form>

            <div class="mt-12 pt-8 border-t border-white/5 flex justify-between items-center">
                <div class="text-[9px] text-white/20 uppercase tracking-widest font-mono">
                    ID Аккаунта: #{{ user.id.toString().padStart(6, '0') }}
                </div>
                <button class="text-red-500/50 hover:text-red-500 text-[9px] uppercase tracking-widest transition-colors font-black">
                    Удалить аккаунт
                </button>
            </div>
        </div>
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
</style>
