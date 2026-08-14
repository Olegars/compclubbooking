<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useToast } from '@/Composables/useToast'

type Club = { id: number; name: string }
type Settings = {
    id: number
    club_id: number | null
    is_enabled: boolean
    provider: string
    api_base_url: string | null
    api_login: string | null
    has_api_secret: boolean
    marker_duration_sec: number
    marker_pre_sec: number
    default_channel: string | null
    webhook_path: string | null
    webhook_method: string
    notes: string | null
}
type EventRow = {
    id: number
    club_id: number | null
    code: string
    name: string
    description: string | null
    is_enabled: boolean
    trigger_key: string | null
    channel: string | null
    marker_title: string | null
    sort: number
}

const props = defineProps<{
    settings: Settings
    events: EventRow[]
    providers: Record<string, string>
    triggers: Record<string, string>
    clubs: Club[]
    pending_jobs?: number
}>()

const { success, error } = useToast()
const testBusy = ref(false)

const settingsForm = useForm({
    club_id: props.settings.club_id,
    is_enabled: props.settings.is_enabled,
    provider: props.settings.provider,
    api_base_url: props.settings.api_base_url || '',
    api_login: props.settings.api_login || '',
    api_secret: '',
    clear_api_secret: false,
    marker_duration_sec: props.settings.marker_duration_sec,
    marker_pre_sec: props.settings.marker_pre_sec,
    default_channel: props.settings.default_channel || '',
    webhook_path: props.settings.webhook_path || '',
    webhook_method: props.settings.webhook_method || 'POST',
    notes: props.settings.notes || '',
})

const isHikvision = computed(() => settingsForm.provider === 'hikvision')

const saveSettings = () => {
    settingsForm.put('/admin/video-surveillance', {
        preserveScroll: true,
        onSuccess: () => {
            success('Настройки сохранены')
            settingsForm.api_secret = ''
            settingsForm.clear_api_secret = false
        },
        onError: () => error('Не удалось сохранить настройки'),
    })
}

const newEvent = reactive({
    name: '',
    code: '',
    description: '',
    trigger_key: 'hid.disconnected',
    channel: '',
    marker_title: '',
    is_enabled: true,
})

const createEvent = () => {
    if (!newEvent.name.trim()) {
        error('Укажите название события')
        return
    }
    router.post('/admin/video-surveillance/events', {
        club_id: settingsForm.club_id,
        name: newEvent.name.trim(),
        code: newEvent.code.trim() || null,
        description: newEvent.description.trim() || null,
        trigger_key: newEvent.trigger_key || null,
        channel: newEvent.channel.trim() || null,
        marker_title: newEvent.marker_title.trim() || null,
        is_enabled: newEvent.is_enabled,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            success('Событие создано')
            newEvent.name = ''
            newEvent.code = ''
            newEvent.description = ''
            newEvent.channel = ''
            newEvent.marker_title = ''
            newEvent.trigger_key = 'hid.disconnected'
            newEvent.is_enabled = true
        },
        onError: (errs) => error((Object.values(errs)[0] as string) || 'Ошибка создания'),
    })
}

const toggleEvent = (ev: EventRow) => {
    router.put(`/admin/video-surveillance/events/${ev.id}`, {
        ...ev,
        is_enabled: !ev.is_enabled,
    }, {
        preserveScroll: true,
        onSuccess: () => success(ev.is_enabled ? 'Событие выключено' : 'Событие включено'),
    })
}

const removeEvent = (ev: EventRow) => {
    if (!confirm(`Удалить событие «${ev.name}»?`)) return
    router.delete(`/admin/video-surveillance/events/${ev.id}`, {
        preserveScroll: true,
        onSuccess: () => success('Событие удалено'),
    })
}

const triggerLabel = (key: string | null) => {
    if (!key) return '—'
    return props.triggers[key] || key
}

const runTest = async () => {
    testBusy.value = true
    try {
        const { data } = await axios.post('/admin/video-surveillance/test', {
            club_id: settingsForm.club_id,
        })
        success(data.message || 'OK')
    } catch (e: any) {
        error(e?.response?.data?.message || 'Тест не прошёл')
    } finally {
        testBusy.value = false
    }
}

const seedMouseEvent = () => {
    newEvent.name = 'Отключение мыши'
    newEvent.code = 'mouse_disconnect'
    newEvent.description = 'Метка при отключении периферии (HID disconnected)'
    newEvent.trigger_key = 'hid.disconnected'
    newEvent.marker_title = 'HID: отключение периферии'
}

const seedSosEvent = () => {
    newEvent.name = 'SOS'
    newEvent.code = 'sos'
    newEvent.description = 'Метка при SOS с терминала'
    newEvent.trigger_key = 'sos'
    newEvent.marker_title = 'SOS'
}
</script>

<template>
    <Head title="Видеонаблюдение · метки" />
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4 text-white">

            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">
                        Video <span class="text-cyan-500">Markers</span>
                    </h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                        Метки на таймлайне · настройка и события
                    </p>
                    <p v-if="(pending_jobs || 0) > 0" class="text-amber-400/80 text-[10px] font-black uppercase tracking-widest mt-3">
                        В очереди агента: {{ pending_jobs }}
                    </p>
                </div>
                <button type="button" :disabled="testBusy" @click="runTest"
                        class="px-5 py-3 rounded-xl border border-cyan-500/40 text-cyan-400 text-[10px] font-black uppercase tracking-widest hover:bg-cyan-500 hover:text-black transition-all cursor-pointer disabled:opacity-40">
                    {{ testBusy ? 'Отправка…' : 'Тест метки' }}
                </button>
            </div>

            <!-- Settings -->
            <section class="bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] p-8 space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xs font-black uppercase tracking-[0.3em] text-white/40 italic">Подключение</h2>
                    <label class="inline-flex items-center gap-3 cursor-pointer">
                        <span class="text-[10px] font-black uppercase tracking-widest text-white/40">Включено</span>
                        <input v-model="settingsForm.is_enabled" type="checkbox" class="w-5 h-5 accent-cyan-500" />
                    </label>
                </div>

                <div v-if="isHikvision" class="p-5 rounded-2xl border border-cyan-500/20 bg-cyan-500/5 text-[11px] text-white/60 leading-relaxed space-y-2">
                    <p class="text-[9px] font-black uppercase tracking-widest text-cyan-400">DS-7764NI-M4 · ISAPI</p>
                    <p>
                        Облако до регистратора не достучится. URL — LAN-адрес NVR
                        (<span class="text-white/80 font-mono">http://192.168.x.x</span>), канал — номер камеры
                        (1 → track 101). На NVR: System → Network → Platform Access → ISAPI, HTTP Digest.
                    </p>
                    <p>
                        Агент в LAN: <span class="text-white/80 font-mono">scripts/hikvision-marker-agent.ps1</span>
                        (токен <span class="text-white/80 font-mono">VIDEO_MARKER_RELAY_TOKEN</span> или
                        <span class="text-white/80 font-mono">CLUB_WOL_RELAY_TOKEN</span>).
                        Тег на таймлайне + lock интервала (длина / захват до события).
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Провайдер</span>
                        <select v-model="settingsForm.provider"
                                class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50">
                            <option v-for="(label, key) in providers" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Канал / камера по умолчанию</span>
                        <input v-model="settingsForm.default_channel" type="text" :placeholder="isHikvision ? '1' : 'cam-01'"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">{{ isHikvision ? 'NVR Base URL (LAN)' : 'API Base URL' }}</span>
                        <input v-model="settingsForm.api_base_url" type="url" :placeholder="isHikvision ? 'http://192.168.1.64' : 'https://nvr.local/api'"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">{{ isHikvision ? 'ISAPI path (optional)' : 'Webhook path' }}</span>
                        <input v-model="settingsForm.webhook_path" type="text" :placeholder="isHikvision ? '/ISAPI/ContentMgmt/record/tracks/{track}/recordTag' : '/markers'"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label v-if="!isHikvision" class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Метод</span>
                        <select v-model="settingsForm.webhook_method"
                                class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50">
                            <option value="POST">POST</option>
                            <option value="PUT">PUT</option>
                            <option value="PATCH">PATCH</option>
                        </select>
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">{{ isHikvision ? 'Логин NVR' : 'Логин (optional)' }}</span>
                        <input v-model="settingsForm.api_login" type="text" autocomplete="off"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">
                            Секрет / токен
                            <span v-if="settings.has_api_secret" class="text-cyan-500/70">(сохранён)</span>
                        </span>
                        <input v-model="settingsForm.api_secret" type="password" autocomplete="new-password" placeholder="••••••"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                        <label v-if="settings.has_api_secret" class="flex items-center gap-2 mt-2 text-[10px] text-white/40 cursor-pointer">
                            <input v-model="settingsForm.clear_api_secret" type="checkbox" class="accent-red-500" />
                            Очистить сохранённый секрет
                        </label>
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Длина метки, сек</span>
                        <input v-model.number="settingsForm.marker_duration_sec" type="number" min="5" max="600"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Захват до события, сек</span>
                        <input v-model.number="settingsForm.marker_pre_sec" type="number" min="0" max="300"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Заметки</span>
                        <textarea v-model="settingsForm.notes" rows="2"
                                  class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50 resize-none" />
                    </label>
                </div>

                <button type="button" :disabled="settingsForm.processing" @click="saveSettings"
                        class="px-8 py-4 bg-cyan-500 hover:bg-cyan-400 text-black font-black uppercase text-xs tracking-widest rounded-xl transition-all cursor-pointer disabled:opacity-40">
                    Сохранить подключение
                </button>
            </section>

            <!-- Events -->
            <section class="bg-[#0a0a0a] border border-white/5 rounded-[0.875rem] p-8 space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <h2 class="text-xs font-black uppercase tracking-[0.3em] text-white/40 italic">События</h2>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" @click="seedMouseEvent"
                                class="text-[10px] font-black uppercase tracking-widest text-cyan-400/80 hover:text-cyan-300 cursor-pointer">
                            + Шаблон: отключение мыши
                        </button>
                        <button type="button" @click="seedSosEvent"
                                class="text-[10px] font-black uppercase tracking-widest text-cyan-400/80 hover:text-cyan-300 cursor-pointer">
                            + Шаблон: SOS
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-5 rounded-2xl border border-dashed border-white/10 bg-black/40">
                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Название</span>
                        <input v-model="newEvent.name" type="text" placeholder="Отключение мыши"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Код (optional)</span>
                        <input v-model="newEvent.code" type="text" placeholder="mouse_disconnect"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50 font-mono" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Триггер системы</span>
                        <select v-model="newEvent.trigger_key"
                                class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50">
                            <option v-for="(label, key) in triggers" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>
                    <label class="block space-y-2 md:col-span-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Описание</span>
                        <input v-model="newEvent.description" type="text"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Заголовок метки</span>
                        <input v-model="newEvent.marker_title" type="text" placeholder="как на таймлайне NVR"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <label class="block space-y-2">
                        <span class="text-[9px] uppercase font-black tracking-widest text-white/30">Канал (override)</span>
                        <input v-model="newEvent.channel" type="text"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-cyan-500/50" />
                    </label>
                    <div class="md:col-span-2">
                        <button type="button" @click="createEvent"
                                class="px-6 py-3 bg-white/5 hover:bg-cyan-500 hover:text-black border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all cursor-pointer">
                            Создать событие
                        </button>
                    </div>
                </div>

                <div v-if="!events.length" class="py-10 text-center text-white/20 text-[10px] font-black uppercase tracking-widest">
                    Событий пока нет — создайте «Отключение мыши» для теста
                </div>

                <div v-else class="space-y-3">
                    <div v-for="ev in events" :key="ev.id"
                         class="flex flex-col sm:flex-row sm:items-center gap-4 p-5 rounded-2xl border border-white/5 bg-black/30">
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="text-sm font-black uppercase italic text-white">{{ ev.name }}</span>
                                <span class="text-[9px] font-mono text-cyan-500/70">{{ ev.code }}</span>
                                <span class="text-[9px] px-2 py-0.5 rounded-md border"
                                      :class="ev.is_enabled ? 'border-[#22c55e]/40 text-[#22c55e]' : 'border-white/10 text-white/30'">
                                    {{ ev.is_enabled ? 'ON' : 'OFF' }}
                                </span>
                            </div>
                            <div class="text-[10px] text-white/40 uppercase tracking-widest">
                                Триггер: {{ triggerLabel(ev.trigger_key) }}
                            </div>
                            <div v-if="ev.description" class="text-xs text-white/50 mt-1">{{ ev.description }}</div>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <button type="button" @click="toggleEvent(ev)"
                                    class="px-4 py-2 rounded-xl border border-white/10 text-[9px] font-black uppercase tracking-widest text-white/50 hover:text-white cursor-pointer">
                                {{ ev.is_enabled ? 'Выкл' : 'Вкл' }}
                            </button>
                            <button type="button" @click="removeEvent(ev)"
                                    class="px-4 py-2 rounded-xl border border-red-500/30 text-[9px] font-black uppercase tracking-widest text-red-400 hover:bg-red-500 hover:text-black cursor-pointer">
                                Удалить
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.35s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>
