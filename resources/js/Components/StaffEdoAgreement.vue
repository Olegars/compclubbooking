<script setup>
import { computed, onMounted, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    edo: { type: Object, required: true },
})

const phone = ref('')
const telegram = ref('')
const code = ref('')
const seen = ref({ agreement: false, kedo: false, bonus: false })

const sections = computed(() => [
    { key: 'agreement', title: 'Соглашение о простой электронной подписи', body: props.edo.texts?.agreement || '' },
    { key: 'kedo', title: 'Положение о КЭДО', body: props.edo.texts?.kedo || '' },
    { key: 'bonus', title: 'Премии и слоты', body: props.edo.texts?.bonus || '' },
])

const allSeen = computed(() => seen.value.agreement && seen.value.kedo && seen.value.bonus)

const mark = (key, event) => {
    const el = event.target
    if (el.scrollHeight - el.scrollTop - el.clientHeight < 28) {
        seen.value[key] = true
    }
}

onMounted(() => {
    document.querySelectorAll('[data-edo-scroll]').forEach((el) => {
        if (el.scrollHeight <= el.clientHeight + 4) {
            seen.value[el.getAttribute('data-edo-scroll')] = true
        }
    })
})

const otpForm = useForm({ purpose: 'agreement', phone: '', telegram_id: '' })
const signForm = useForm({ phone: '', telegram_id: '', code: '', scrolled: false })

const sendOtp = () => {
    otpForm.phone = phone.value
    otpForm.telegram_id = telegram.value
    otpForm.post('/admin/salary/edo/otp', { preserveScroll: true })
}

const sign = () => {
    signForm.phone = phone.value
    signForm.telegram_id = telegram.value
    signForm.code = code.value
    signForm.scrolled = true
    signForm.post('/admin/salary/edo/sign', { preserveScroll: true })
}
</script>

<template>
    <div class="bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1.125rem] p-8 shadow-2xl space-y-6">
        <div>
            <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">КЭДО · {{ edo.version }}</div>
            <h1 class="text-2xl font-black uppercase italic text-white tracking-tighter mt-2">
                Документы и <span class="text-[#22c55e]">подпись</span>
            </h1>
            <p class="text-white/50 text-sm mt-3 max-w-3xl">
                До смен и кассы нужно прочитать три текста до конца и подтвердить их кодом из Telegram или SMS.
                Код на привязанный номер — простая электронная подпись.
            </p>
        </div>

        <section v-for="section in sections" :key="section.key" class="space-y-2">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xs font-black uppercase tracking-widest text-white">{{ section.title }}</h2>
                <span class="text-[10px] uppercase font-black tracking-widest" :class="seen[section.key] ? 'text-[#22c55e]' : 'text-white/30'">
                    {{ seen[section.key] ? 'Прочитано' : 'Прокрутите до конца' }}
                </span>
            </div>
            <div
                class="max-h-40 overflow-y-auto bg-black/40 border border-white/10 rounded-2xl p-4 text-sm text-white/70 whitespace-pre-wrap"
                :data-edo-scroll="section.key"
                @scroll="mark(section.key, $event)"
            >{{ section.body }}</div>
        </section>

        <div class="grid md:grid-cols-2 gap-4">
            <label class="block text-[10px] uppercase font-black tracking-widest text-white/40">
                Телефон
                <input v-model="phone" type="tel" autocomplete="tel"
                       class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none" />
            </label>
            <label class="block text-[10px] uppercase font-black tracking-widest text-white/40">
                Telegram chat id, если бот уже открыт
                <input v-model="telegram" type="text" inputmode="numeric"
                       class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none" />
            </label>
        </div>

        <div class="flex flex-wrap gap-3 items-end">
            <button type="button" :disabled="otpForm.processing" @click="sendOtp"
                    class="px-6 py-3 border border-white/15 rounded-2xl text-xs font-black uppercase tracking-widest text-white disabled:opacity-40">
                Получить код
            </button>
            <label class="block text-[10px] uppercase font-black tracking-widest text-white/40">
                Код из 6 цифр
                <input v-model="code" type="text" inputmode="numeric" maxlength="6"
                       class="mt-2 w-40 bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none tracking-[0.3em]" />
            </label>
            <button type="button" :disabled="!allSeen || signForm.processing || code.length !== 6" @click="sign"
                    class="px-6 py-3 bg-[#22c55e] text-black rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                Подписать
            </button>
        </div>
        <p v-if="signForm.errors.code || otpForm.errors.message" class="text-red-400 text-xs font-bold">
            {{ signForm.errors.code || otpForm.errors.message }}
        </p>
    </div>
</template>
