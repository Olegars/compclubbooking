<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

const props = defineProps<{
    claims: any[]
    map_reviews: any[]
    settings: {
        bonus_amount: number
        site_reviews_limit: number
        show_on_site: boolean
    }
    review_meta?: {
        yandex_maps_url?: string | null
        twogis_url?: string | null
        bonus_amount?: number
    }
}>()

const settingsForm = useForm({
    bonus_amount: props.settings.bonus_amount,
    site_reviews_limit: props.settings.site_reviews_limit,
    show_on_site: props.settings.show_on_site,
})

const syncing = ref(false)

const saveSettings = () => {
    settingsForm.post('/admin/bonuses/settings', { preserveScroll: true })
}

const syncNow = () => {
    if (syncing.value) return
    syncing.value = true
    router.post('/admin/bonuses/sync', {}, {
        preserveScroll: true,
        onFinish: () => { syncing.value = false },
    })
}

const updateStatus = (id: number, status: 'approved' | 'rejected') => {
    const actionName = status === 'approved' ? 'подтвердить начисление бонуса' : 'отклонить заявку'
    if (confirm(`Вы уверены, что хотите ${actionName}?`)) {
        router.post(`/admin/api/bonuses/verify/${id}`, { status }, {
            preserveScroll: true,
        })
    }
}

const statusLabel = (status: string) => {
    switch (status) {
        case 'pending': return 'Ожидает'
        case 'approved': return 'Одобрено'
        case 'rejected': return 'Отклонено'
        case 'expired': return 'Истекло'
        default: return status
    }
}

const sourceLabel = (source: string | null | undefined) => {
    if (source === 'yandex') return 'Я.Карты'
    if (source === '2gis') return '2ГИС'
    if (source === 'manual') return 'Вручную'
    return source || '—'
}
</script>

<template>
    <Head :title="`${clubName} | Бонусы за отзывы`" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl gap-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-yellow-500 tracking-tighter">Бонусы <span class="text-white">за отзывы</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Сумма · заявки · лента Яндекс/2ГИС · вывод на сайт</p>
                </div>
                <div class="flex flex-wrap gap-3 w-full md:w-auto">
                    <div class="px-6 py-4 bg-white/5 rounded-2xl border border-white/5 text-center">
                        <div class="text-[10px] text-white/20 uppercase font-black tracking-widest">Ожидают</div>
                        <div class="text-2xl font-black text-yellow-500">{{ claims.filter(c => c.status === 'pending').length }}</div>
                    </div>
                    <div class="px-6 py-4 bg-white/5 rounded-2xl border border-white/5 text-center">
                        <div class="text-[10px] text-white/20 uppercase font-black tracking-widest">В ленте</div>
                        <div class="text-2xl font-black text-cyan-400">{{ map_reviews.length }}</div>
                    </div>
                    <button type="button" @click="syncNow" :disabled="syncing"
                            class="px-6 py-4 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 text-cyan-400 text-[10px] font-black uppercase tracking-widest italic hover:bg-cyan-500/20 disabled:opacity-50">
                        {{ syncing ? 'Синхронизация…' : 'Синхронизировать сейчас' }}
                    </button>
                </div>
            </div>

            <div class="bg-[#0a0a0a] border border-yellow-500/20 rounded-[1rem] p-8 grid md:grid-cols-[1fr_auto] gap-6 items-end">
                <div class="grid sm:grid-cols-3 gap-4">
                    <div>
                        <label class="text-[10px] text-yellow-500/70 uppercase font-black tracking-widest block mb-2">Сумма бонуса, ₽</label>
                        <input v-model.number="settingsForm.bonus_amount" type="number" min="0" step="1"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white text-lg font-black outline-none focus:border-yellow-500/50" />
                    </div>
                    <div>
                        <label class="text-[10px] text-white/40 uppercase font-black tracking-widest block mb-2">Отзывов на сайте</label>
                        <input v-model.number="settingsForm.site_reviews_limit" type="number" min="1" max="24"
                               class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-white font-black outline-none focus:border-yellow-500/50" />
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-3 px-4 py-3 bg-black border border-white/10 rounded-xl w-full cursor-pointer">
                            <input v-model="settingsForm.show_on_site" type="checkbox" class="w-5 h-5 accent-yellow-500" />
                            <span class="text-[10px] text-white/60 uppercase font-black tracking-widest">Показывать на сайте</span>
                        </label>
                    </div>
                </div>
                <button type="button" @click="saveSettings" :disabled="settingsForm.processing"
                        class="px-8 py-4 bg-yellow-500 text-black rounded-2xl text-[10px] font-black uppercase tracking-widest italic hover:bg-yellow-400 disabled:opacity-50">
                    Сохранить
                </button>
            </div>

            <div class="space-y-4">
                <h2 class="text-sm font-black uppercase italic tracking-[0.3em] text-white/40">Заявки игроков</h2>
                <div v-for="claim in claims" :key="claim.id"
                     class="bg-[#050505] border border-white/5 rounded-[0.875rem] p-6 flex flex-col md:flex-row gap-8 items-start transition-all hover:border-white/10"
                     :class="{'opacity-50 grayscale': claim.status !== 'pending'}">

                    <div class="w-full md:w-64 shrink-0">
                        <div class="text-[10px] text-white/20 uppercase font-black mb-2 italic tracking-widest">Пользователь</div>
                        <div class="text-white font-black uppercase italic tracking-tight text-lg">{{ claim.user?.name }}</div>
                        <div class="text-white/40 text-xs font-mono mt-1">{{ claim.user?.phone }}</div>
                        <div class="mt-4 inline-block px-3 py-1 bg-yellow-500/10 rounded-lg text-[10px] font-black text-yellow-500 italic border border-yellow-500/20 uppercase tracking-widest">
                            Награда: +{{ Math.floor(claim.bonus_amount) }}₽
                        </div>
                        <div class="mt-3 text-[9px] uppercase font-black tracking-widest text-white/30">
                            {{ statusLabel(claim.status) }}
                            <span v-if="claim.source"> · {{ sourceLabel(claim.source) }}</span>
                            <span v-if="claim.matched_score != null"> · score {{ Number(claim.matched_score).toFixed(2) }}</span>
                        </div>
                        <div v-if="claim.external_author_id" class="mt-2 text-[9px] text-white/25 font-mono break-all">
                            author: {{ claim.external_author_id }}
                        </div>
                    </div>

                    <div class="flex-1 w-full bg-black/40 rounded-2xl p-5 border border-white/5 relative overflow-hidden">
                        <div class="text-[10px] text-white/20 uppercase font-black mb-3 italic tracking-widest">Текст отзыва:</div>
                        <div class="text-sm text-white/80 leading-relaxed whitespace-pre-wrap break-words">
                            {{ claim.review_text || '—' }}
                        </div>
                        <div v-if="claim.status === 'pending'" class="mt-4 text-[10px] text-yellow-500 uppercase font-black italic tracking-widest bg-yellow-500/5 p-3 rounded-xl border border-yellow-500/10">
                            Ожидает автосверки или ручного решения
                        </div>
                        <div v-if="claim.status === 'approved'" class="absolute top-4 right-4 text-[#22c55e] font-black text-xs tracking-widest uppercase italic bg-[#22c55e]/10 px-3 py-1 rounded-lg border border-[#22c55e]/20">ОДОБРЕНО</div>
                        <div v-if="claim.status === 'rejected'" class="absolute top-4 right-4 text-red-500 font-black text-xs tracking-widest uppercase italic bg-red-500/10 px-3 py-1 rounded-lg border border-red-500/20">ОТКЛОНЕНО</div>
                    </div>

                    <div v-if="claim.status === 'pending'" class="w-full md:w-auto flex flex-row md:flex-col gap-2 shrink-0">
                        <button @click="updateStatus(claim.id, 'approved')"
                                class="flex-1 px-8 py-5 bg-[#22c55e] text-black hover:bg-[#2ae06d] rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] transition-all italic">
                            Зачислить
                        </button>
                        <button @click="updateStatus(claim.id, 'rejected')"
                                class="flex-1 px-8 py-5 bg-white/5 border border-red-500/30 text-red-500 hover:bg-red-500/10 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] transition-all italic">
                            Отказ
                        </button>
                    </div>
                </div>
                <div v-if="claims.length === 0" class="py-16 text-center border border-dashed border-white/5 rounded-[0.875rem] text-white/20 text-[10px] uppercase tracking-widest">
                    Заявок пока нет
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-sm font-black uppercase italic tracking-[0.3em] text-white/40">Отзывы с карт</h2>
                <div class="grid gap-3">
                    <div v-for="review in map_reviews" :key="review.id"
                         class="bg-[#050505] border border-white/5 rounded-2xl p-5 grid md:grid-cols-[1fr_auto] gap-4">
                        <div>
                            <div class="flex flex-wrap items-center gap-3 mb-2">
                                <span class="text-[10px] font-black uppercase tracking-widest text-cyan-400">{{ sourceLabel(review.source) }}</span>
                                <span class="text-yellow-500 text-xs font-black">{{ '★'.repeat(Math.round(Number(review.rating) || 0)) }}</span>
                                <span class="text-white/50 text-xs font-bold">{{ review.author_name || 'Аноним' }}</span>
                                <span v-if="review.rewarded_user" class="text-[9px] uppercase font-black tracking-widest text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 rounded-lg">
                                    Бонус → {{ review.rewarded_user.name }} ({{ review.rewarded_user.phone }})
                                </span>
                            </div>
                            <div class="text-sm text-white/75 leading-relaxed whitespace-pre-wrap">{{ review.text }}</div>
                            <div v-if="review.external_author_id" class="mt-2 text-[9px] text-white/20 font-mono break-all">
                                author_id: {{ review.external_author_id }}
                            </div>
                        </div>
                        <div class="text-right text-[10px] text-white/25 uppercase font-black tracking-widest shrink-0">
                            {{ review.reviewed_at ? new Date(review.reviewed_at).toLocaleDateString('ru-RU') : '—' }}
                        </div>
                    </div>
                    <div v-if="map_reviews.length === 0" class="py-16 text-center border border-dashed border-white/5 rounded-[0.875rem] text-white/20 text-[10px] uppercase tracking-widest">
                        Лента пуста — нажмите «Синхронизировать сейчас»
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
