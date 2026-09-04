<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

type Part = {
    id: number
    label: string
    socket?: string | null
    ddr?: string | null
    ram_gb?: number | null
    capacity_gb?: number | null
    wattage?: number | null
    avito_code?: string | null
}

type Cfg = {
    id: number
    name: string
    socket: string
    ddr: string
    sort_order: number
    use_count: number
    enabled: boolean
    last_used_at?: string | null
    cpu?: string | null
    ram?: string | null
    ssd?: string | null
    psu?: string | null
}

type Ad = {
    id: number
    config_id: string
    title: string
    description: string
    price: number
    status: string
    components: { type: string, name: string, sale?: number }[]
    xml?: Record<string, string>
    generated_at?: string
}

type Chat = {
    id: number
    chat_id: string
    client_name?: string | null
    ad_title?: string | null
    config_id?: string | null
    unread: boolean
    important: boolean
    last_message_at?: string | null
}

type Message = {
    id: number
    from_us: boolean
    content?: { text?: string } | null
    created_at?: string
}

const props = defineProps<{
    tab: string
    settings: Record<string, any>
    feed_url: string
    ads: Ad[]
    chats: Chat[]
    active_chat: Chat | null
    messages: Message[]
    canManage: boolean
    unread: number
    filters?: { q?: string | null }
    parts?: { cpu: Part[], ram: Part[], ssd: Part[], psu: Part[] }
    configs?: Cfg[]
}>()

const tab = computed({
    get: () => props.tab || 'ads',
    set: (v: string) => router.get('/admin/store/avito', {
        tab: v,
        chat: props.active_chat?.chat_id,
        ...(q.value ? { q: q.value } : {}),
    }, { preserveState: true, replace: true }),
})

const q = computed({
    get: () => props.filters?.q || '',
    set: (v: string) => router.get('/admin/store/avito', {
        tab: props.tab || 'ads',
        chat: props.active_chat?.chat_id,
        ...(v.trim() ? { q: v.trim() } : {}),
    }, { preserveState: true, replace: true }),
})

const settingsForm = useForm({
    enabled: Boolean(props.settings.enabled),
    ads_per_hour: props.settings.ads_per_hour ?? 20,
    keep_active: props.settings.keep_active ?? 200,
    address: props.settings.address || '',
    contact_phone: props.settings.contact_phone || '',
    manager_name: props.settings.manager_name || '',
    pc_type: props.settings.pc_type || 'Игровой',
    markup_percent: props.settings.markup_percent ?? 15,
    extra_rub: props.settings.extra_rub ?? 4000,
    round_to: props.settings.round_to ?? 100,
    discount_over_60k_pct: props.settings.discount_over_60k_pct ?? 2,
    discount_over_100k_pct: props.settings.discount_over_100k_pct ?? 4,
    client_id: props.settings.client_id || '',
    client_secret: '',
    avito_user_id: props.settings.avito_user_id || '',
    auto_reply_enabled: Boolean(props.settings.auto_reply_enabled),
    auto_reply_from: props.settings.auto_reply_from ?? 0,
    auto_reply_to: props.settings.auto_reply_to ?? 10,
    auto_reply_text: props.settings.auto_reply_text || '',
})

const reply = useForm({
    chat_id: props.active_chat?.chat_id || '',
    text: '',
})

const openAd = ref<Ad | null>(null)
const generating = computed(() => props.settings?.last_generate_result?.status === 'running')
const dictSyncing = computed(() => props.settings?.last_dict_sync_result?.status === 'running')
const dictStats = computed(() => props.settings?.dict_stats || {})

let pollTimer: ReturnType<typeof setInterval> | null = null
watch([generating, dictSyncing], ([gen, dict]) => {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
    if (!gen && !dict) return
    pollTimer = setInterval(() => {
        router.reload({ only: ['ads', 'settings', 'configs'], preserveScroll: true })
    }, 5000)
}, { immediate: true })
onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer)
})

const statusLabel: Record<string, string> = {
    active: 'в фиде',
    archived: 'архив',
    blocked: 'блок',
}

const openChat = (chat: Chat) => {
    router.get('/admin/store/avito', { tab: 'chats', chat: chat.chat_id, mark_read: 1, ...(q.value ? { q: q.value } : {}) }, { preserveState: true })
}

const saveSettings = () => settingsForm.put('/admin/store/avito/settings')

const createCfg = useForm({
    cpu_part_id: '' as string | number,
    ram_part_id: '' as string | number,
    ssd_part_id: '' as string | number,
    psu_part_id: '' as string | number,
})

const filterSockets = ref<string[]>([])
const filterDdrs = ref<string[]>([])
const socketOpts = [
    { id: 'AM4', label: 'AM4' },
    { id: 'AM5', label: 'AM5' },
    { id: 'LGA1700', label: '1700' },
    { id: 'LGA1851', label: '1851' },
]
const ddrOpts = ['DDR4', 'DDR5']

const parts = computed(() => props.parts || { cpu: [], ram: [], ssd: [], psu: [] })
const allConfigs = computed(() => props.configs || [])

const filteredCpus = computed(() => {
    const list = parts.value.cpu
    if (!filterSockets.value.length) return list
    return list.filter(p => p.socket && filterSockets.value.includes(p.socket))
})
const filteredRams = computed(() => {
    const list = parts.value.ram
    if (!filterDdrs.value.length) return list
    return list.filter(p => p.ddr && filterDdrs.value.includes(p.ddr))
})
const filteredConfigs = computed(() => {
    return allConfigs.value.filter(c => {
        if (filterSockets.value.length && !filterSockets.value.includes(c.socket)) return false
        if (filterDdrs.value.length && !filterDdrs.value.includes(c.ddr)) return false
        return true
    })
})

const nextConfigId = computed(() => {
    const enabled = allConfigs.value.filter(c => c.enabled)
    if (!enabled.length) return null
    const last = Number(props.settings?.last_config_id || 0)
    const idx = enabled.findIndex(c => c.id === last)
    if (idx < 0) return enabled[0].id
    return enabled[(idx + 1) % enabled.length].id
})

const toggleSocket = (id: string) => {
    const i = filterSockets.value.indexOf(id)
    if (i >= 0) filterSockets.value.splice(i, 1)
    else filterSockets.value.push(id)
}
const toggleDdr = (id: string) => {
    const i = filterDdrs.value.indexOf(id)
    if (i >= 0) filterDdrs.value.splice(i, 1)
    else filterDdrs.value.push(id)
}

watch(filteredCpus, (list) => {
    if (createCfg.cpu_part_id && !list.some(p => p.id === Number(createCfg.cpu_part_id))) {
        createCfg.cpu_part_id = ''
    }
})
watch(filteredRams, (list) => {
    if (createCfg.ram_part_id && !list.some(p => p.id === Number(createCfg.ram_part_id))) {
        createCfg.ram_part_id = ''
    }
})

const generate = () => {
    router.post('/admin/store/avito/generate', { count: settingsForm.ads_per_hour }, { preserveScroll: true })
}

const saveConfig = () => {
    createCfg.post('/admin/store/avito/configs', {
        preserveScroll: true,
        onSuccess: () => {
            createCfg.reset()
            createCfg.cpu_part_id = ''
            createCfg.ram_part_id = ''
            createCfg.ssd_part_id = ''
            createCfg.psu_part_id = ''
        },
    })
}

const toggleConfig = (c: Cfg) => {
    router.post(`/admin/store/avito/configs/${c.id}`, { enabled: !c.enabled }, { preserveScroll: true })
}

const deleteConfig = (c: Cfg) => {
    if (!confirm(`Удалить конфигурацию №${c.sort_order}?`)) return
    router.delete(`/admin/store/avito/configs/${c.id}`, { preserveScroll: true })
}

const syncDicts = () => {
    router.post('/admin/store/avito/dicts', {}, { preserveScroll: true })
}

const setStatus = (ad: Ad, status: string) => {
    router.post(`/admin/store/avito/ads/${ad.id}`, { status }, { preserveScroll: true })
}

const sendReply = () => {
    if (!props.active_chat) return
    reply.chat_id = props.active_chat.chat_id
    reply.post('/admin/store/avito/chats/send', {
        preserveScroll: true,
        onSuccess: () => { reply.text = '' },
    })
}

const sendBom = () => {
    if (!props.active_chat) return
    const configId = props.active_chat.config_id
    if (!configId) return
    router.post('/admin/store/avito/chats/bom', {
        chat_id: props.active_chat.chat_id,
        config_id: configId,
    }, { preserveScroll: true })
}

const connectWebhook = () => router.post('/admin/store/avito/webhook', {}, { preserveScroll: true })

const copyFeed = async () => {
    try {
        await navigator.clipboard.writeText(props.feed_url)
    } catch {
        // ignore
    }
}

const messageText = (m: Message) => m.content?.text || ''
</script>

<template>
    <Head :title="`${clubName} | Avito`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Avito</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Один аккаунт · только системные блоки · XML автозагрузка
                    </p>
                </div>
                <div class="flex gap-2 items-center">
                    <input v-model="q" placeholder="ID сборки…"
                           class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm w-44 uppercase tracking-widest" />
                    <button class="px-4 py-3 rounded-xl text-[10px] uppercase font-black"
                            :class="tab === 'ads' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                            @click="tab = 'ads'">Объявления</button>
                    <button class="px-4 py-3 rounded-xl text-[10px] uppercase font-black"
                            :class="tab === 'configs' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                            @click="tab = 'configs'">Конфигурации</button>
                    <button class="px-4 py-3 rounded-xl text-[10px] uppercase font-black relative"
                            :class="tab === 'chats' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                            @click="tab = 'chats'">
                        Чаты
                        <span v-if="unread > 0" class="absolute -top-2 -right-2 min-w-[18px] px-1 rounded-full bg-red-500 text-white text-[9px]">{{ unread }}</span>
                    </button>
                    <button class="px-4 py-3 rounded-xl text-[10px] uppercase font-black"
                            :class="tab === 'settings' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                            @click="tab = 'settings'">Настройки</button>
                </div>
            </div>

            <div v-if="tab === 'ads'" class="space-y-4">
                <div class="flex flex-wrap gap-3 items-center justify-between">
                    <div class="text-[10px] uppercase tracking-widest text-white/30">
                        В фиде: {{ ads.filter(a => a.status === 'active').length }} · всего {{ ads.length }}
                        <span v-if="generating" class="ml-3 text-amber-400">генерация… DeepSeek пишет тексты, это 1–3 мин</span>
                        <span v-else-if="settings.last_generate_result?.created != null" class="ml-3">
                            последняя пачка: {{ settings.last_generate_result.created }} шт
                        </span>
                    </div>
                    <button v-if="canManage" class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-2xl disabled:opacity-40"
                            :disabled="generating"
                            @click="generate">{{ generating ? 'Идёт генерация' : 'Сгенерировать пачку' }}</button>
                </div>
                <div class="space-y-2">
                    <div v-for="ad in ads" :key="ad.id"
                         class="border border-white/5 bg-[#080808] rounded-2xl p-5 flex flex-wrap gap-4 justify-between">
                        <button class="text-left space-y-1 min-w-0 flex-1" @click="openAd = ad">
                            <div class="text-[10px] uppercase tracking-widest text-amber-400">ID {{ ad.config_id }} · {{ ad.price }} ₽</div>
                            <div class="font-black uppercase truncate">{{ ad.title }}</div>
                            <div class="text-[10px] text-white/30 uppercase">{{ statusLabel[ad.status] || ad.status }}</div>
                        </button>
                        <div v-if="canManage" class="flex gap-2 items-start">
                            <button class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400"
                                    @click="setStatus(ad, ad.status === 'active' ? 'blocked' : 'active')">
                                {{ ad.status === 'active' ? 'Убрать из фида' : 'В фид' }}
                            </button>
                        </div>
                    </div>
                    <div v-if="!ads.length" class="text-white/30 text-sm py-10 text-center">{{ q ? 'Нет объявлений с таким ID' : 'Объявлений нет — включите генерацию в настройках.' }}</div>
                </div>
            </div>

            <div v-if="tab === 'configs'" class="space-y-6">
                <div class="flex flex-wrap gap-2 items-center">
                    <button v-for="s in socketOpts" :key="s.id"
                            class="px-3 py-2 rounded-xl text-[10px] uppercase font-black border"
                            :class="filterSockets.includes(s.id) ? 'bg-amber-500 text-black border-amber-500' : 'border-white/10 text-white/50'"
                            @click="toggleSocket(s.id)">{{ s.label }}</button>
                    <button v-for="d in ddrOpts" :key="d"
                            class="px-3 py-2 rounded-xl text-[10px] uppercase font-black border"
                            :class="filterDdrs.includes(d) ? 'bg-amber-500 text-black border-amber-500' : 'border-white/10 text-white/50'"
                            @click="toggleDdr(d)">{{ d }}</button>
                    <div class="text-[10px] uppercase tracking-widest text-white/30 ml-auto">
                        Следующая в генерации: №{{ allConfigs.find(c => c.id === nextConfigId)?.sort_order || '—' }}
                    </div>
                </div>

                <form v-if="canManage" class="border border-white/5 rounded-2xl p-5 bg-[#080808] space-y-4" @submit.prevent="saveConfig">
                    <div class="font-black uppercase italic text-sm">Новая конфигурация</div>
                    <p v-if="!parts.cpu.length" class="text-[11px] text-amber-400/80">
                        Абстрактные комплектующие пусты. На сервере: php artisan db:seed --class=StoreAvitoPartsSeeder
                    </p>
                    <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="text-[10px] uppercase tracking-widest text-white/40">Процессор
                            <select v-model="createCfg.cpu_part_id" required class="mt-2 w-full bg-black border border-white/10 rounded-xl px-3 py-3 text-sm">
                                <option value="">—</option>
                                <option v-for="p in filteredCpus" :key="p.id" :value="p.id">{{ p.label }}</option>
                            </select>
                        </label>
                        <label class="text-[10px] uppercase tracking-widest text-white/40">Память
                            <select v-model="createCfg.ram_part_id" required class="mt-2 w-full bg-black border border-white/10 rounded-xl px-3 py-3 text-sm">
                                <option value="">—</option>
                                <option v-for="p in filteredRams" :key="p.id" :value="p.id">{{ p.label }}</option>
                            </select>
                        </label>
                        <label class="text-[10px] uppercase tracking-widest text-white/40">SSD M.2
                            <select v-model="createCfg.ssd_part_id" required class="mt-2 w-full bg-black border border-white/10 rounded-xl px-3 py-3 text-sm">
                                <option value="">—</option>
                                <option v-for="p in parts.ssd" :key="p.id" :value="p.id">{{ p.label }}</option>
                            </select>
                        </label>
                        <label class="text-[10px] uppercase tracking-widest text-white/40">Блок питания
                            <select v-model="createCfg.psu_part_id" required class="mt-2 w-full bg-black border border-white/10 rounded-xl px-3 py-3 text-sm">
                                <option value="">—</option>
                                <option v-for="p in parts.psu" :key="p.id" :value="p.id">{{ p.label }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="flex justify-end">
                        <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-2xl" :disabled="createCfg.processing">Добавить</button>
                    </div>
                </form>

                <div class="space-y-2">
                    <div v-for="c in filteredConfigs" :key="c.id"
                         class="border rounded-2xl p-5 flex flex-wrap gap-4 justify-between items-start"
                         :class="c.id === nextConfigId ? 'border-amber-500/50 bg-amber-500/5' : 'border-white/5 bg-[#080808]'">
                        <div class="min-w-0 space-y-1">
                            <div class="text-[10px] uppercase tracking-widest text-amber-400">
                                №{{ c.sort_order }}
                                · {{ c.socket }}
                                · {{ c.ddr }}
                                · использована {{ c.use_count }}
                                <span v-if="c.id === nextConfigId" class="ml-2 text-amber-300">следующая</span>
                            </div>
                            <div class="font-black uppercase text-sm">{{ c.name }}</div>
                            <div class="text-[11px] text-white/40">{{ c.cpu }} · {{ c.ram }} · {{ c.ssd }} · {{ c.psu }}</div>
                        </div>
                        <div v-if="canManage" class="flex gap-2 items-center shrink-0">
                            <button class="px-3 py-2 rounded-xl border text-[10px] uppercase font-black"
                                    :class="c.enabled ? 'border-amber-500/30 text-amber-400' : 'border-white/10 text-white/30'"
                                    @click="toggleConfig(c)">{{ c.enabled ? 'Вкл' : 'Выкл' }}</button>
                            <button class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/40"
                                    @click="deleteConfig(c)">Удалить</button>
                        </div>
                    </div>
                    <div v-if="!filteredConfigs.length" class="text-white/30 text-sm py-10 text-center">
                        {{ allConfigs.length ? 'Нет конфигураций под выбранные галки.' : 'Конфигураций нет — соберите первую из абстрактных комплектующих.' }}
                    </div>
                </div>
            </div>

            <div v-if="tab === 'chats'" class="grid lg:grid-cols-[320px_1fr] gap-4 min-h-[520px]">
                <div class="border border-white/5 rounded-2xl overflow-hidden bg-[#080808]">
                    <div v-for="c in chats" :key="c.id"
                         class="px-4 py-3 border-b border-white/5 cursor-pointer"
                         :class="active_chat?.chat_id === c.chat_id ? 'bg-amber-500/10' : 'hover:bg-white/[0.03]'"
                         @click="openChat(c)">
                        <div class="flex justify-between gap-2">
                            <div class="font-black uppercase text-sm truncate">{{ c.client_name || 'Гость' }}</div>
                            <span v-if="c.unread" class="w-2 h-2 rounded-full bg-amber-400 mt-1 shrink-0"></span>
                        </div>
                        <div class="text-[10px] text-white/30 truncate">{{ c.config_id || c.ad_title || c.chat_id }}</div>
                    </div>
                    <div v-if="!chats.length" class="p-6 text-white/30 text-sm">{{ q ? 'Нет чатов с таким ID' : 'Чатов пока нет.' }}</div>
                </div>
                <div class="border border-white/5 rounded-2xl bg-[#080808] flex flex-col min-h-[520px]">
                    <div v-if="active_chat" class="px-5 py-4 border-b border-white/5 flex justify-between gap-3">
                        <div>
                            <div class="font-black uppercase">{{ active_chat.client_name || 'Гость' }}</div>
                            <div class="text-[10px] text-white/30">{{ active_chat.ad_title }} · {{ active_chat.config_id }}</div>
                        </div>
                        <button v-if="canManage && active_chat.config_id"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400"
                                @click="sendBom">Отправить комплектацию</button>
                    </div>
                    <div class="flex-1 overflow-y-auto p-5 space-y-3">
                        <div v-for="m in messages" :key="m.id"
                             class="max-w-[80%] rounded-2xl px-4 py-3 text-sm whitespace-pre-wrap"
                             :class="m.from_us ? 'ml-auto bg-amber-500/15 text-amber-50' : 'bg-white/5'">
                            {{ messageText(m) }}
                        </div>
                    </div>
                    <form v-if="canManage && active_chat" class="p-4 border-t border-white/5 flex gap-2" @submit.prevent="sendReply">
                        <input v-model="reply.text" class="flex-1 bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" placeholder="Ответ в Avito…" />
                        <button class="px-5 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="reply.processing">Отправить</button>
                    </form>
                    <div v-if="!active_chat" class="m-auto text-white/30 text-sm">Выберите чат</div>
                </div>
            </div>

            <form v-if="tab === 'settings'" class="space-y-8" @submit.prevent="saveSettings">
                <div class="grid md:grid-cols-2 gap-6">
                    <label class="flex items-center gap-3 text-sm">
                        <input v-model="settingsForm.enabled" type="checkbox" class="accent-amber-500" />
                        Автогенерация каждый час
                    </label>
                    <div class="flex gap-3 items-end">
                        <label class="flex-1 text-[10px] uppercase tracking-widest text-white/40">Шт / час
                            <input v-model="settingsForm.ads_per_hour" type="number" min="1" max="50" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        </label>
                        <label class="flex-1 text-[10px] uppercase tracking-widest text-white/40">Держать в фиде
                            <input v-model="settingsForm.keep_active" type="number" min="20" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        </label>
                    </div>
                    <label class="text-[10px] uppercase tracking-widest text-white/40 md:col-span-2">Адрес для Avito
                        <input v-model="settingsForm.address" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" placeholder="Москва, …" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Телефон
                        <input v-model="settingsForm.contact_phone" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Менеджер
                        <input v-model="settingsForm.manager_name" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Тип ПК
                        <select v-model="settingsForm.pc_type" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                            <option>Игровой</option>
                            <option>Офисный</option>
                            <option>Для учебы</option>
                        </select>
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Наценка, %
                        <input v-model="settingsForm.markup_percent" type="number" step="0.1" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Корпус в цене, ₽
                        <input v-model="settingsForm.extra_rub" type="number" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Округление, ₽
                        <input v-model="settingsForm.round_to" type="number" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                </div>

                <div class="border border-white/5 rounded-2xl p-6 space-y-4">
                    <div class="font-black uppercase italic">XML-фид</div>
                    <div class="text-xs text-white/50 break-all">{{ feed_url }}</div>
                    <button type="button" class="px-4 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400" @click="copyFeed">Копировать URL</button>
                    <p class="text-[11px] text-white/35 leading-relaxed">В кабинете Avito → Автозагрузка укажите эту ссылку. Характеристики (процессор, видеокарта, плата, ОЗУ) должны совпадать со справочником Avito — не с названиями чипов NVIDIA/GeForce.</p>
                </div>

                <div class="border border-white/5 rounded-2xl p-6 space-y-4">
                    <div class="font-black uppercase italic">Справочник Avito</div>
                    <p class="text-[11px] text-white/35 leading-relaxed">Официальные значения тегов XML (BrandProcessor, BrandVideocard = ZOTAC/Palit, полное имя платы). Без синка автозагрузка отклонит объявления.</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-[11px] text-white/60">
                        <div>CPU brand {{ dictStats.BrandProcessor || 0 }}</div>
                        <div>CPU code {{ dictStats.CodeProcessor || 0 }}</div>
                        <div>GPU brand {{ dictStats.BrandVideocard || 0 }}</div>
                        <div>GPU model {{ dictStats.ModelVideocard || 0 }}</div>
                        <div>MB brand {{ dictStats.BrandMotherboard || 0 }}</div>
                        <div>MB model {{ dictStats.ModelMotherboard || 0 }}</div>
                        <div>ОЗУ {{ dictStats.RamSize || 0 }}</div>
                    </div>
                    <div class="text-[11px] text-white/40" v-if="settings.last_dict_sync_at">Последний синк: {{ settings.last_dict_sync_at }}</div>
                    <div class="text-[11px] text-red-400" v-if="settings.last_dict_sync_result?.error">{{ settings.last_dict_sync_result.error }}</div>
                    <button type="button" class="px-4 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400 disabled:opacity-40" :disabled="dictSyncing" @click="syncDicts">{{ dictSyncing ? 'Качаю справочник…' : 'Скачать XML-справочник Avito' }}</button>
                </div>

                <div class="border border-white/5 rounded-2xl p-6 space-y-4">
                    <div class="font-black uppercase italic">Мессенджер Avito</div>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Client ID
                        <input v-model="settingsForm.client_id" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Client secret {{ settings.has_client_secret ? '(задан, пустое поле не затирает)' : '' }}
                        <input v-model="settingsForm.client_secret" type="password" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="text-[10px] uppercase tracking-widest text-white/40">Avito user id
                        <input v-model="settingsForm.avito_user_id" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    </label>
                    <label class="flex items-center gap-3 text-sm">
                        <input v-model="settingsForm.auto_reply_enabled" type="checkbox" class="accent-amber-500" />
                        Ночной автоответ
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-[10px] uppercase tracking-widest text-white/40">С часа
                            <input v-model="settingsForm.auto_reply_from" type="number" min="0" max="23" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        </label>
                        <label class="text-[10px] uppercase tracking-widest text-white/40">До часа
                            <input v-model="settingsForm.auto_reply_to" type="number" min="0" max="23" class="mt-2 w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                        </label>
                    </div>
                    <textarea v-model="settingsForm.auto_reply_text" rows="3" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    <button type="button" class="px-4 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400" @click="connectWebhook">Зарегистрировать webhook</button>
                </div>

                <div v-if="settings.last_error" class="text-red-400 text-sm">{{ settings.last_error }}</div>

                <div class="flex justify-end">
                    <button class="px-8 py-4 bg-amber-500 text-black text-[10px] uppercase font-black rounded-2xl" :disabled="settingsForm.processing">Сохранить</button>
                </div>
            </form>
        </div>

        <div v-if="openAd" class="fixed inset-0 bg-black/70 z-50 p-4 overflow-y-auto" @click.self="openAd = null">
            <div class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 max-w-2xl mx-auto my-8 space-y-4">
                <div class="text-[10px] uppercase tracking-widest text-amber-400">ID {{ openAd.config_id }} · {{ openAd.price }} ₽</div>
                <div class="font-black uppercase italic text-xl">{{ openAd.title }}</div>
                <pre class="text-sm text-white/70 whitespace-pre-wrap font-sans">{{ openAd.description }}</pre>
                <ul class="text-sm text-white/50 space-y-1">
                    <li v-for="(c, i) in openAd.components" :key="i">{{ c.type }} — {{ c.name }}</li>
                </ul>
                <dl v-if="openAd.xml" class="text-sm text-white/60 space-y-1 border-t border-white/5 pt-4">
                    <div v-for="(v, k) in openAd.xml" :key="k" class="flex gap-2">
                        <dt class="text-white/35 w-44 shrink-0">{{ k }}</dt>
                        <dd>{{ v }}</dd>
                    </div>
                </dl>
                <button class="px-4 py-2 text-[10px] uppercase font-black text-white/40" @click="openAd = null">Закрыть</button>
            </div>
        </div>
    </AdminLayout>
</template>
