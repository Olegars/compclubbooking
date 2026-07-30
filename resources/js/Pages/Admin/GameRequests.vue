<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

type TopRow = {
    title: string
    title_normalized: string
    requests_count: number
    users_count: number
    open_count: number
    last_requested_at: string | null
}

type RecentRow = {
    id: number
    title: string
    comment: string | null
    source: string
    status: string
    created_at: string | null
    user: { id?: number | null, name?: string | null, phone?: string | null }
}

const props = defineProps<{
    top: TopRow[]
    recent: RecentRow[]
    filter_status: string
    stats: { open: number, done: number, rejected: number }
}>()

const filters = [
    { id: 'open', label: 'Открытые' },
    { id: 'done', label: 'Сделано' },
    { id: 'rejected', label: 'Отклонено' },
    { id: 'all', label: 'Все' },
]

const setFilter = (status: string) => {
    router.get('/admin/game-requests', { status }, { preserveState: true, replace: true })
}

const bulkStatus = (titleNormalized: string, status: 'done' | 'rejected') => {
    const label = status === 'done' ? 'отметить установленным' : 'отклонить'
    if (!confirm(`Все открытые заявки «${titleNormalized}» — ${label}?`)) return
    router.post('/admin/game-requests/bulk-status', {
        title_normalized: titleNormalized,
        status,
    }, { preserveScroll: true })
}

const setStatus = (id: number, status: 'done' | 'rejected' | 'open') => {
    router.patch(`/admin/game-requests/${id}/status`, { status }, { preserveScroll: true })
}

const maxUsers = computed(() => Math.max(1, ...props.top.map(t => t.users_count)))

const formatDate = (iso: string | null) => {
    if (!iso) return '—'
    try {
        return new Date(iso).toLocaleString('ru-RU', {
            day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
        })
    } catch {
        return iso
    }
}
</script>

<template>
    <Head title="REACTOR | Заявки на игры" />
    <AdminLayout>
        <div class="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-2xl flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter text-white">
                        Заявки на <span class="text-cyan-400">игры</span>
                    </h1>
                    <p class="text-white/25 text-[10px] uppercase tracking-[0.35em] font-black mt-2 italic">
                        Топ спроса · что доустановить на диски
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <div class="px-5 py-3 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-center">
                        <div class="text-[9px] uppercase text-amber-500/70 font-black tracking-widest">Open</div>
                        <div class="text-2xl font-black text-amber-400 italic">{{ stats.open }}</div>
                    </div>
                    <div class="px-5 py-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-center">
                        <div class="text-[9px] uppercase text-emerald-500/70 font-black tracking-widest">Done</div>
                        <div class="text-2xl font-black text-emerald-400 italic">{{ stats.done }}</div>
                    </div>
                    <div class="px-5 py-3 rounded-2xl bg-white/5 border border-white/10 text-center">
                        <div class="text-[9px] uppercase text-white/30 font-black tracking-widest">Reject</div>
                        <div class="text-2xl font-black text-white/50 italic">{{ stats.rejected }}</div>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="f in filters" :key="f.id" type="button" @click="setFilter(f.id)"
                    class="px-5 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all"
                    :class="filter_status === f.id
                        ? 'bg-cyan-500/20 border-cyan-500 text-cyan-300'
                        : 'bg-black border-white/10 text-white/40 hover:border-white/30'"
                >
                    {{ f.label }}
                </button>
            </div>

            <section class="space-y-4">
                <h2 class="text-[11px] font-black uppercase tracking-[0.35em] text-cyan-400/80 italic px-2">Топ запросов</h2>
                <div v-if="top.length === 0" class="py-16 text-center border border-dashed border-white/10 rounded-[2rem] text-white/30 text-xs uppercase tracking-widest">
                    Пока нет заявок
                </div>
                <div v-else class="space-y-3">
                    <article
                        v-for="row in top" :key="row.title_normalized"
                        class="bg-[#0a0a0a] border border-white/5 rounded-[1.75rem] p-6"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                            <div class="min-w-0">
                                <div class="text-lg font-black uppercase italic tracking-tight text-white truncate">{{ row.title }}</div>
                                <div class="text-[10px] text-white/30 mt-1 uppercase tracking-widest">
                                    {{ row.users_count }} игроков · {{ row.requests_count }} заявок
                                    <span v-if="row.open_count"> · {{ row.open_count }} open</span>
                                    · {{ formatDate(row.last_requested_at) }}
                                </div>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <button type="button" @click="bulkStatus(row.title_normalized, 'done')"
                                        class="px-4 py-2 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-[9px] font-black uppercase tracking-widest hover:bg-emerald-500 hover:text-black transition-all">
                                    Установлено
                                </button>
                                <button type="button" @click="bulkStatus(row.title_normalized, 'rejected')"
                                        class="px-4 py-2 rounded-xl bg-white/5 border border-white/10 text-white/40 text-[9px] font-black uppercase tracking-widest hover:border-red-500/40 hover:text-red-400 transition-all">
                                    Отклонить
                                </button>
                            </div>
                        </div>
                        <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                            <div class="h-full rounded-full bg-cyan-500 transition-all"
                                 :style="{ width: `${Math.round((row.users_count / maxUsers) * 100)}%` }"></div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="space-y-4">
                <h2 class="text-[11px] font-black uppercase tracking-[0.35em] text-white/40 italic px-2">Последние заявки</h2>
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2rem] overflow-hidden">
                    <div v-for="r in recent" :key="r.id"
                         class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-b border-white/5 last:border-0">
                        <div class="min-w-0">
                            <div class="text-sm font-black text-white uppercase italic truncate">{{ r.title }}</div>
                            <div class="text-[10px] text-white/30 mt-1">
                                {{ r.user?.name || '—' }} · {{ r.user?.phone || '' }} · {{ r.source }} · {{ formatDate(r.created_at) }}
                            </div>
                            <div v-if="r.comment" class="text-[11px] text-white/40 mt-1">{{ r.comment }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full border"
                                  :class="{
                                      'border-amber-500/40 text-amber-400': r.status === 'open',
                                      'border-emerald-500/40 text-emerald-400': r.status === 'done',
                                      'border-white/10 text-white/30': r.status === 'rejected',
                                  }">
                                {{ r.status }}
                            </span>
                            <button v-if="r.status !== 'done'" type="button" @click="setStatus(r.id, 'done')"
                                    class="text-[9px] font-black uppercase text-emerald-500/70 hover:text-emerald-400">Done</button>
                            <button v-if="r.status === 'open'" type="button" @click="setStatus(r.id, 'rejected')"
                                    class="text-[9px] font-black uppercase text-white/30 hover:text-red-400">Reject</button>
                        </div>
                    </div>
                    <div v-if="recent.length === 0" class="py-12 text-center text-white/20 text-xs uppercase tracking-widest">
                        Пусто
                    </div>
                </div>
            </section>

            <div class="text-center">
                <Link href="/admin/licenses" class="text-[10px] font-black uppercase tracking-widest text-cyan-400/60 hover:text-cyan-400">
                    → Игры и лицензии
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
