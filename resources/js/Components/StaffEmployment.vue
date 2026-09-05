<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

type Rule = { id: number; title: string; body: string }

type Profile = {
    full_name: string | null
    passport_series: string | null
    passport_number: string | null
    issued_by: string | null
    issued_at: string | null
    department_code: string | null
    birth_date: string | null
    has_scan: boolean
}

const props = defineProps<{
    rules: Rule[]
    acceptedIds: number[]
    rulesComplete: boolean
    profile: Profile
}>()

const rulesOpen = ref(!props.rulesComplete)
const scanName = ref('')

watch(() => props.rulesComplete, (done) => {
    if (!done) rulesOpen.value = true
}, { immediate: true })

const acceptedSet = computed(() => new Set(props.acceptedIds))
const acceptedCount = computed(() => props.acceptedIds.length)

const form = useForm({
    full_name: props.profile.full_name || '',
    passport_series: props.profile.passport_series || '',
    passport_number: props.profile.passport_number || '',
    issued_by: props.profile.issued_by || '',
    issued_at: props.profile.issued_at || '',
    department_code: props.profile.department_code || '',
    birth_date: props.profile.birth_date || '',
    passport_scan: null as File | null,
})

watch(() => props.profile, (next) => {
    form.full_name = next.full_name || form.full_name
    form.passport_series = next.passport_series || form.passport_series
    form.passport_number = next.passport_number || form.passport_number
    form.issued_by = next.issued_by || form.issued_by
    form.issued_at = next.issued_at || form.issued_at
    form.department_code = next.department_code || form.department_code
    form.birth_date = next.birth_date || form.birth_date
})

const fieldsFilled = computed(() => {
    return Boolean(
        form.full_name.trim()
        && /^\d{4}$/.test(form.passport_series)
        && /^\d{6}$/.test(form.passport_number)
        && form.issued_by.trim().length >= 8
        && form.issued_at
        && /^\d{3}-\d{3}$/.test(form.department_code)
        && form.birth_date
        && (form.passport_scan || props.profile.has_scan)
    )
})

const canHire = computed(() => props.rulesComplete && fieldsFilled.value && !form.processing)

const acceptRule = (id: number) => {
    if (acceptedSet.value.has(id) || form.processing) return
    router.post('/admin/salary/employment/rules', { rule_id: id }, { preserveScroll: true })
}

const onScan = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] || null
    form.passport_scan = file
    scanName.value = file?.name || ''
}

const hire = () => {
    if (!canHire.value) return
    form.post('/admin/salary/employment/hire', {
        forceFormData: true,
        preserveScroll: true,
    })
}

const inputClass = 'mt-2 w-full bg-black/40 border border-white/10 focus:border-[#22c55e]/50 rounded-2xl px-4 py-3 text-sm text-white outline-none'
</script>

<template>
    <div class="space-y-8">
        <div class="flex justify-between items-end border-b border-white/10 pb-6">
            <div>
                <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">
                    Устройство <span class="text-[#22c55e]">на работу</span>
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Правила, анкета, скан паспорта
                </p>
            </div>
            <div class="text-right">
                <div class="text-[10px] uppercase font-black tracking-widest text-white/30">Правила</div>
                <div class="text-sm font-black uppercase text-white mt-1">{{ acceptedCount }} / {{ rules.length }}</div>
            </div>
        </div>

        <div class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 flex flex-col md:flex-row md:items-center gap-6">
            <div class="flex-1">
                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Шаг 1</div>
                <p class="text-white text-sm font-bold mt-2">
                    Прочитайте внутренние правила и примите каждое отдельно.
                </p>
            </div>
            <button type="button" @click="rulesOpen = true"
                    class="px-8 py-4 border border-white/15 text-white rounded-2xl text-xs font-black uppercase tracking-widest">
                {{ rulesComplete ? 'Правила приняты' : 'Читать правила' }}
            </button>
        </div>

        <form class="bg-[#0a0a0a] border border-white/5 rounded-[1.125rem] p-8 space-y-6"
              :class="rulesComplete ? '' : 'opacity-40 pointer-events-none'"
              @submit.prevent="hire">
            <div>
                <div class="text-[10px] text-white/30 uppercase font-black tracking-widest">Шаг 2 · Личные данные</div>
                <p class="text-white/50 text-xs font-bold mt-2">ФИО и паспорт. Скан — фото или PDF разворота.</p>
            </div>

            <label class="block">
                <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">ФИО</span>
                <input v-model="form.full_name" type="text" autocomplete="name" :class="inputClass" placeholder="Иванов Иван Иванович">
                <p v-if="form.errors.full_name" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.full_name }}</p>
            </label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Серия паспорта</span>
                    <input v-model="form.passport_series" type="text" inputmode="numeric" maxlength="4" :class="inputClass" placeholder="1234">
                    <p v-if="form.errors.passport_series" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.passport_series }}</p>
                </label>
                <label class="block">
                    <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Номер паспорта</span>
                    <input v-model="form.passport_number" type="text" inputmode="numeric" maxlength="6" :class="inputClass" placeholder="567890">
                    <p v-if="form.errors.passport_number" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.passport_number }}</p>
                </label>
            </div>

            <label class="block">
                <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Кем выдан</span>
                <input v-model="form.issued_by" type="text" :class="inputClass" placeholder="ГУ МВД России по г. Москве">
                <p v-if="form.errors.issued_by" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.issued_by }}</p>
            </label>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="block">
                    <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Дата выдачи</span>
                    <input v-model="form.issued_at" type="date" :class="inputClass">
                    <p v-if="form.errors.issued_at" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.issued_at }}</p>
                </label>
                <label class="block">
                    <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Код подразделения</span>
                    <input v-model="form.department_code" type="text" maxlength="7" :class="inputClass" placeholder="770-001">
                    <p v-if="form.errors.department_code" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.department_code }}</p>
                </label>
                <label class="block">
                    <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Дата рождения</span>
                    <input v-model="form.birth_date" type="date" :class="inputClass">
                    <p v-if="form.errors.birth_date" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.birth_date }}</p>
                </label>
            </div>

            <label class="block">
                <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Скан паспорта</span>
                <input type="file" accept="image/*,.pdf" class="mt-3 w-full text-xs text-white/60 file:mr-4 file:px-4 file:py-3 file:rounded-xl file:border-0 file:bg-[#22c55e] file:text-black file:text-[10px] file:font-black file:uppercase file:tracking-widest" @change="onScan">
                <p class="text-white/40 text-[11px] font-bold mt-2">
                    {{ scanName || (profile.has_scan ? 'Скан уже загружен' : 'JPG, PNG или PDF, до 8 МБ') }}
                </p>
                <p v-if="form.errors.passport_scan" class="text-red-400 text-[10px] uppercase font-black mt-2">{{ form.errors.passport_scan }}</p>
            </label>

            <p v-if="form.errors.message" class="text-red-400 text-[10px] uppercase font-black">{{ form.errors.message }}</p>

            <button v-if="canHire" type="submit"
                    class="w-full py-5 bg-[#22c55e] hover:bg-[#1ea34d] text-black rounded-2xl text-xs font-black uppercase tracking-[0.2em] disabled:opacity-40">
                {{ form.processing ? 'Отправка…' : 'Устроиться' }}
            </button>
            <div v-else class="text-[10px] uppercase font-black tracking-widest text-white/25 text-center py-4">
                Кнопка «Устроиться» появится, когда приняты все правила и заполнены данные
            </div>
        </form>
    </div>

    <div v-if="rulesOpen" class="fixed inset-0 z-[99998] flex items-center justify-center p-4 font-mono">
        <div class="absolute inset-0 bg-black/80 backdrop-blur-sm"></div>
        <div class="relative z-10 w-full max-w-2xl max-h-[90vh] overflow-y-auto bg-[#0a0a0a] border border-[#22c55e]/30 rounded-[1rem] p-8 shadow-[0_0_80px_rgba(34,197,94,0.12)]">
            <h3 class="text-xl font-black uppercase italic tracking-tighter text-white mb-2">Правила клуба</h3>
            <p class="text-[10px] uppercase tracking-widest text-white/40 font-black mb-6">
                Заглушка. Каждое правило нужно принять отдельно. {{ acceptedCount }} из {{ rules.length }}
            </p>

            <div class="space-y-4">
                <div v-for="rule in rules" :key="rule.id" class="border border-white/10 rounded-2xl p-5">
                    <div class="text-white text-sm font-black uppercase italic">{{ rule.id }}. {{ rule.title }}</div>
                    <p class="text-white/50 text-xs font-bold leading-relaxed mt-2">{{ rule.body }}</p>
                    <button v-if="!acceptedSet.has(rule.id)" type="button" @click="acceptRule(rule.id)"
                            class="mt-4 px-5 py-3 bg-[#22c55e] text-black rounded-xl text-[10px] font-black uppercase tracking-widest">
                        Принимаю
                    </button>
                    <div v-else class="mt-4 text-[10px] uppercase font-black tracking-widest text-[#22c55e]">
                        Принято
                    </div>
                </div>
            </div>

            <button v-if="rulesComplete" type="button" @click="rulesOpen = false"
                    class="mt-6 w-full py-4 bg-[#22c55e] text-black rounded-2xl text-xs font-black uppercase tracking-widest">
                Дальше — анкета
            </button>
            <p v-else class="mt-6 text-center text-[10px] uppercase font-black tracking-widest text-white/30">
                Попап закроется, когда примете все 10 правил
            </p>
        </div>
    </div>
</template>
