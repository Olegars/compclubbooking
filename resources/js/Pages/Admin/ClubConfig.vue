<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import AdminConfirm from '@/Components/AdminConfirm.vue'
import ClubDocumentsEditor from '@/Components/ClubDocumentsEditor.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()

type DocumentRow = {
    id: number
    title: string
    kind: 'employment' | 'fire_safety'
    slug: string
    is_system: boolean
    sort_order: number
    sections: Array<{ id: number; title: string; body: string; sort_order: number }>
}

const props = defineProps<{
    shift_hours: 12 | 24
    starts_hour: number
    documents: DocumentRow[]
}>()

const tabFromUrl = () => {
    const query = page.url.split('?')[1] || ''
    return new URLSearchParams(query).get('tab') === 'documents' ? 'documents' : 'shifts'
}

const tab = ref<'shifts' | 'documents'>(tabFromUrl())
watch(() => page.url, () => { tab.value = tabFromUrl() })

const setTab = (next: 'shifts' | 'documents') => {
    tab.value = next
    router.get('/admin/config', next === 'documents' ? { tab: 'documents' } : {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const hourOptions = Array.from({ length: 24 }, (_, hour) => hour)
const busy = ref(false)
const confirmOpen = ref(false)
const pendingHours = ref<12 | 24>(props.shift_hours)
const pendingStartHour = ref(props.starts_hour)

watch(() => props.shift_hours, (next) => { pendingHours.value = next })
watch(() => props.starts_hour, (next) => { pendingStartHour.value = next })

const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
watch(flashSuccess, (msg) => { if (msg) success(msg) }, { immediate: true })
watch(() => (page.props as any).errors as Record<string, string> | undefined, (errors) => {
    if (!errors) return
    const first = errors.message || Object.values(errors)[0]
    if (first) error(String(first))
}, { immediate: true })

const formatClock = (hour: number) => `${String(((hour % 24) + 24) % 24).padStart(2, '0')}:00`

const windowLabel = (hours: 12 | 24, start: number) => {
    if (hours === 24) {
        return `сутки ${formatClock(start)} — ${formatClock(start)}`
    }
    return `день ${formatClock(start)}–${formatClock(start + 12)}, ночь ${formatClock(start + 12)}–${formatClock(start)}`
}

const askHours = (hours: 12 | 24) => {
    if (busy.value || hours === props.shift_hours) return
    pendingHours.value = hours
    pendingStartHour.value = props.starts_hour
    confirmOpen.value = true
}

const askStart = (hour: number) => {
    if (busy.value || hour === props.starts_hour) return
    pendingHours.value = props.shift_hours
    pendingStartHour.value = hour
    confirmOpen.value = true
}

const apply = () => {
    if (busy.value) return
    busy.value = true
    router.post('/admin/config/shifts', {
        hours: pendingHours.value,
        starts_hour: pendingStartHour.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false
            confirmOpen.value = false
        },
    })
}
</script>

<template>
    <Head :title="`${clubName} | Конфигурация`" />
    <AdminLayout>
        <div class="max-w-3xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl space-y-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                        Конфигурация
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Смены клуба · документы на сайте
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            class="px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest"
                            :class="tab === 'shifts' ? 'bg-cyan-500 text-black' : 'border border-white/15 text-white/60 hover:text-white'"
                            @click="setTab('shifts')">
                        Смены
                    </button>
                    <button type="button"
                            class="px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest"
                            :class="tab === 'documents' ? 'bg-cyan-500 text-black' : 'border border-white/15 text-white/60 hover:text-white'"
                            @click="setTab('documents')">
                        Документы
                    </button>
                </div>
            </div>

            <div v-if="tab === 'shifts'" class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-8">
                <div>
                    <div class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Схема</div>
                    <p class="text-white/50 text-xs font-bold mt-2">
                        Сейчас: {{ windowLabel(shift_hours, starts_hour) }}.
                        Уже выбранные сотрудниками смены сохраняются, свободные слоты другой схемы снимаются.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" :disabled="busy"
                            class="px-6 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest"
                            :class="shift_hours === 12 ? 'bg-cyan-500 text-black' : 'border border-white/15 text-white/60 hover:text-white'"
                            @click="askHours(12)">
                        12 часов
                    </button>
                    <button type="button" :disabled="busy"
                            class="px-6 py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest"
                            :class="shift_hours === 24 ? 'bg-cyan-500 text-black' : 'border border-white/15 text-white/60 hover:text-white'"
                            @click="askHours(24)">
                        24 часа
                    </button>
                </div>

                <div>
                    <label class="block text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic mb-3">
                        Начало смены
                    </label>
                    <div class="flex flex-wrap items-center gap-4">
                        <select
                            class="bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-4 text-xl font-black text-white outline-none"
                            :value="starts_hour"
                            :disabled="busy"
                            @change="askStart(Number(($event.target as HTMLSelectElement).value))"
                        >
                            <option v-for="hour in hourOptions" :key="hour" :value="hour">
                                {{ formatClock(hour) }}
                            </option>
                        </select>
                        <div class="text-[10px] font-black uppercase tracking-widest text-white/30">
                            конец {{ formatClock(starts_hour + shift_hours) }}
                        </div>
                    </div>
                    <p class="mt-4 text-[11px] text-white/35 italic leading-relaxed">
                        10, 11, 12… — час приёмки. При 12 часах вторая смена начинается через 12 часов.
                    </p>
                </div>
            </div>

            <div v-else class="space-y-6">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8">
                    <div class="text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic">Документы</div>
                    <p class="text-white/50 text-xs font-bold mt-2">
                        Тексты, которые кандидат видит при устройстве: свёрнутые заголовки, внутри — «Принимаю».
                        Кнопка «Добавить» создаёт новый раздел документа.
                    </p>
                </div>
                <ClubDocumentsEditor :documents="documents" @error="error" />
            </div>
        </div>

        <AdminConfirm
            :is-open="confirmOpen"
            tone="primary"
            title="Сохранить конфигурацию смен"
            :message="`Схема: ${windowLabel(pendingHours, pendingStartHour)}. Свободные слоты другой схемы снимутся, уже выбранные смены останутся.`"
            confirm-text="Применить"
            cancel-text="Назад"
            :is-processing="busy"
            @close="confirmOpen = false"
            @confirm="apply"
        />
    </AdminLayout>
</template>
