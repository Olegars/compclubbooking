<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, usePage } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'
import { useToast } from '@/Composables/useToast'

type SessionRow = {
    id: number
    user_id: number
    name: string | null
    phone: string | null
    kind: string
    status: string
    steam_id: string | null
    match_id: string | null
    integrity_ok: boolean
    alive: boolean
    last_heartbeat_at: string | null
}

type BanRow = {
    id: number
    user_id: number | null
    name: string | null
    scope: string
    reason: string | null
    active: boolean
    ends_at: string | null
}

const props = defineProps<{
    mode: string
    stack: string
    home_online: number
    sessions: SessionRow[]
    bans: BanRow[]
    events: Array<{ id: number; kind: string; message: string | null; steam_id: string | null }>
    kicks: Record<string, string>
}>()

const clubName = useClubName()
const page = usePage()
const { success, error } = useToast()
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)
watch(flashSuccess, (msg) => { if (msg) success(msg) }, { immediate: true })

const scope = ref('match_making')
const days = ref(7)
const reason = ref('')
const busy = ref(false)

const banUser = (userId: number) => {
    if (busy.value) return
    busy.value = true
    router.post('/admin/fair-play/bans', {
        user_id: userId,
        scope: scope.value,
        reason: reason.value,
        days: Number(days.value),
    }, {
        preserveScroll: true,
        onError: () => error('Не удалось записать бан'),
        onFinish: () => { busy.value = false },
    })
}

const pardon = (id: number) => {
    router.post(`/admin/fair-play/bans/${id}/pardon`, {}, { preserveScroll: true })
}
</script>

<template>
    <Head :title="`${clubName} | Fair Play`" />
    <AdminLayout>
        <div class="max-w-4xl mx-auto space-y-8 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem]">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">Fair Play</h1>
                <p class="text-white/40 text-[10px] uppercase tracking-[0.35em] font-black mt-2">
                    {{ stack }} · режим {{ mode }} · дома на сервере {{ home_online }}
                </p>
                <p class="text-white/55 text-xs mt-4 leading-relaxed">
                    Пока режим off, арена клуба ходит со общим паролем. gate включает одноразовый connect_token.
                    Бан match_making и tournament не закрывает бронь и бар. full_ban закрывает.
                </p>
            </div>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-4">
                <h2 class="text-[11px] uppercase tracking-[0.3em] text-white/40 font-black">Сессии</h2>
                <div class="flex flex-wrap gap-3 text-[11px]">
                    <label class="flex items-center gap-2">
                        <span class="text-white/40">Скоуп</span>
                        <select v-model="scope" class="bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white">
                            <option value="match_making">match_making</option>
                            <option value="tournament">tournament</option>
                            <option value="full_ban">full_ban</option>
                        </select>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-white/40">Дней</span>
                        <input v-model.number="days" type="number" min="0" max="3650" class="w-20 bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white">
                    </label>
                    <input v-model="reason" type="text" placeholder="Причина" class="flex-1 min-w-[12rem] bg-black/40 border border-white/10 rounded-lg px-3 py-2 text-white">
                </div>
                <p v-if="!sessions.length" class="text-white/35 text-xs">Живых сессий нет.</p>
                <article v-for="row in sessions" :key="row.id" class="border border-white/10 rounded-xl px-4 py-3 flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-white font-black text-sm">{{ row.name || 'Игрок' }} · {{ row.kind }}</div>
                        <div class="text-[10px] text-white/40">
                            {{ row.alive ? 'на связи' : row.status }}
                            <span v-if="row.steam_id"> · {{ row.steam_id }}</span>
                            <span v-if="!row.integrity_ok"> · целостность нет</span>
                        </div>
                    </div>
                    <button type="button" class="px-3 py-2 border border-red-500/40 text-red-300 rounded-lg text-[10px] uppercase font-black" @click="banUser(row.user_id)">
                        Бан
                    </button>
                </article>
            </section>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-3">
                <h2 class="text-[11px] uppercase tracking-[0.3em] text-white/40 font-black">Баны</h2>
                <p v-if="!bans.length" class="text-white/35 text-xs">Пусто.</p>
                <article v-for="ban in bans" :key="ban.id" class="border border-white/10 rounded-xl px-4 py-3 flex items-center gap-3">
                    <div class="flex-1">
                        <div class="text-white text-sm font-black">{{ ban.name || ban.user_id }} · {{ ban.scope }}</div>
                        <div class="text-[10px] text-white/40">{{ ban.reason || 'без причины' }} · {{ ban.active ? 'активен' : 'снят' }}</div>
                    </div>
                    <button v-if="ban.active" type="button" class="px-3 py-2 border border-white/15 text-white/70 rounded-lg text-[10px] uppercase font-black" @click="pardon(ban.id)">
                        Снять
                    </button>
                </article>
            </section>

            <section class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-6 space-y-2">
                <h2 class="text-[11px] uppercase tracking-[0.3em] text-white/40 font-black">Тексты кика</h2>
                <p v-for="(text, key) in kicks" :key="key" class="text-[11px] text-white/55">
                    <span class="text-cyan-300">{{ key }}</span> — {{ text }}
                </p>
                <p v-for="event in events" :key="event.id" class="text-[10px] text-white/35">
                    {{ event.kind }} · {{ event.message }}
                </p>
            </section>
        </div>
    </AdminLayout>
</template>
