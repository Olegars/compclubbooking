<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

type Ad = {
    id: number
    config_id: string
    title: string
    description: string
    price: number
    status: string
    components: { type: string, name: string, sale?: number }[]
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
}>()

const tab = computed({
    get: () => props.tab || 'ads',
    set: (v: string) => router.get('/admin/store/avito', { tab: v, chat: props.active_chat?.chat_id }, { preserveState: true, replace: true }),
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

const statusLabel: Record<string, string> = {
    active: 'в фиде',
    archived: 'архив',
    blocked: 'блок',
}

const openChat = (chat: Chat) => {
    router.get('/admin/store/avito', { tab: 'chats', chat: chat.chat_id, mark_read: 1 }, { preserveState: true })
}

const saveSettings = () => settingsForm.put('/admin/store/avito/settings')

const generate = () => {
    router.post('/admin/store/avito/generate', { count: settingsForm.ads_per_hour }, { preserveScroll: true })
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
                <div class="flex gap-2">
                    <button class="px-4 py-3 rounded-xl text-[10px] uppercase font-black"
                            :class="tab === 'ads' ? 'bg-amber-500 text-black' : 'border border-white/10 text-white/50'"
                            @click="tab = 'ads'">Объявления</button>
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
                        <span v-if="settings.last_generate_result" class="ml-3">
                            последняя пачка: {{ settings.last_generate_result.created }} шт
                        </span>
                    </div>
                    <button v-if="canManage" class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-2xl"
                            @click="generate">Сгенерировать пачку</button>
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
                    <div v-if="!ads.length" class="text-white/30 text-sm py-10 text-center">Объявлений нет — включите генерацию в настройках.</div>
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
                    <div v-if="!chats.length" class="p-6 text-white/30 text-sm">Чатов пока нет.</div>
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
                    <p class="text-[11px] text-white/35 leading-relaxed">В кабинете Avito → Автозагрузка укажите эту ссылку. Без полей из XML объявление не разместить: процессор/видеокарта/ОЗУ подставляются из каталога и сверяются со словарём Avito (DeepSeek добивает то, что не разобрала эвристика).</p>
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
                <button class="px-4 py-2 text-[10px] uppercase font-black text-white/40" @click="openAd = null">Закрыть</button>
            </div>
        </div>
    </AdminLayout>
</template>
