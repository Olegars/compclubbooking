<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    edo: { type: Object, required: true },
})

const text = ref('')
const code = ref('')
const files = ref(null)
const left = ref('Считаем срок')
let timer = null

const tick = () => {
    const deadline = props.edo.incident?.deadline_at
    if (!deadline) {
        left.value = 'Срок ещё не начат'
        return
    }
    const diff = new Date(deadline).getTime() - Date.now()
    if (diff <= 0) {
        left.value = 'Срок истёк'
        return
    }
    const hours = Math.floor(diff / 3600000)
    const mins = Math.floor((diff % 3600000) / 60000)
    left.value = `Осталось ${hours} ч ${String(mins).padStart(2, '0')} мин`
}

onMounted(() => {
    tick()
    timer = setInterval(tick, 30000)
})
onUnmounted(() => clearInterval(timer))

const otpForm = useForm({ purpose: 'explanation', phone: '', telegram_id: '' })
const sendForm = useForm({ text: '', code: '', files: [] })

const sendOtp = () => {
    otpForm.post('/admin/salary/edo/otp', { preserveScroll: true })
}

const submit = () => {
    sendForm.text = text.value
    sendForm.code = code.value
    sendForm.files = files.value ? Array.from(files.value) : []
    sendForm.post('/admin/salary/edo/explanation', { preserveScroll: true, forceFormData: true })
}

const waiting = () => props.edo.incident?.status !== 'demand_sent'
</script>

<template>
    <div class="bg-[#0a0a0a] border border-red-500/40 rounded-[1.125rem] p-8 shadow-2xl space-y-6">
        <div>
            <div class="text-[10px] text-red-400 uppercase font-black tracking-widest">Дисциплинарный инцидент</div>
            <h1 class="text-2xl font-black uppercase italic text-white tracking-tighter mt-2">
                {{ edo.incident?.type_label }}
            </h1>
            <p class="text-white/70 text-sm mt-4 leading-relaxed">
                Уведомление о необходимости предоставить письменные объяснения по факту отсутствия на рабочем месте
                {{ edo.incident?.slot_label || '' }} в соответствии со статьёй 193 ТК РФ.
            </p>
            <p class="text-white/40 text-xs mt-2">Статус: {{ edo.incident?.status_label }}</p>
        </div>

        <div class="bg-black/40 border border-white/10 rounded-2xl px-5 py-4">
            <div class="text-[10px] uppercase font-black tracking-widest text-white/30">Срок на объяснения</div>
            <div class="text-xl font-black text-white mt-1">{{ left }}</div>
        </div>

        <div v-if="edo.documents?.length" class="flex flex-wrap gap-2">
            <a v-for="doc in edo.documents" :key="doc.id"
               :href="`/admin/salary/edo/documents/${doc.id}`" target="_blank"
               class="px-4 py-2 border border-white/10 rounded-xl text-[10px] uppercase font-black tracking-widest text-white/70 hover:text-white">
                {{ doc.title }}
            </a>
        </div>

        <form v-if="!waiting()" class="space-y-4" @submit.prevent="submit">
            <label class="block text-[10px] uppercase font-black tracking-widest text-white/40">
                Укажите причину отсутствия
                <textarea v-model="text" rows="5"
                          class="mt-2 w-full bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none"></textarea>
            </label>
            <label class="block text-[10px] uppercase font-black tracking-widest text-white/40">
                Больничный, справка, протокол — pdf или фото, до 5 файлов
                <input type="file" multiple accept=".pdf,image/jpeg,image/png,image/webp" class="mt-2 block text-sm text-white/70"
                       @change="files = $event.target.files" />
            </label>
            <div class="flex flex-wrap gap-3 items-end">
                <button type="button" :disabled="otpForm.processing" @click="sendOtp"
                        class="px-6 py-3 border border-white/15 rounded-2xl text-xs font-black uppercase tracking-widest text-white disabled:opacity-40">
                    Код для подписи
                </button>
                <input v-model="code" type="text" inputmode="numeric" maxlength="6" placeholder="000000"
                       class="w-36 bg-black/40 border border-white/10 rounded-2xl px-4 py-3 text-sm text-white outline-none tracking-[0.3em]" />
                <button type="submit" :disabled="sendForm.processing || text.trim().length < 10 || code.length !== 6"
                        class="px-6 py-3 bg-red-500 text-black rounded-2xl text-xs font-black uppercase tracking-widest disabled:opacity-40">
                    Подписать и отправить
                </button>
            </div>
            <p v-if="sendForm.errors.message || otpForm.errors.message" class="text-red-400 text-xs font-bold">
                {{ sendForm.errors.message || otpForm.errors.message }}
            </p>
        </form>

        <div v-else class="text-sm text-white/70">
            <p v-if="edo.incident?.status === 'explanation_submitted'">
                Объяснительная у управляющего. Касса и слоты закрыты, пока он не признает причину уважительной.
            </p>
            <p v-else-if="edo.incident?.status === 'expired_no_response'">
                Срок вышел, объяснений нет. Дальше решает комиссия.
            </p>
            <p v-else-if="edo.incident?.explanation_text" class="whitespace-pre-wrap text-white/50 mt-3">
                {{ edo.incident.explanation_text }}
            </p>
            <p v-else>Материалы переданы на взыскание. Бумажный приказ подписывает работодатель.</p>
        </div>
    </div>
</template>
