<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    achievements: any[]
}>()

const editingId = ref<number | null>(null)

const form = useForm({
    title: '',
    description: '',
    type: 'play_hours',
    target_value: 10,
    period: 'weekly',
    reward_type: 'deposit_balance',
    reward_value: 100,
    night_start: 22,
    night_end: 6,
    is_active: true,
    sort_order: 0,
})

const typeHints: Record<string, string> = {
    play_hours: 'Сумма часов по завершённым сессиям за период',
    night_visits: 'Число визитов, начатых в ночном окне',
    visit_count: 'Число завершённых визитов за период',
}

const targetLabel = computed(() => {
    if (form.type === 'play_hours') return 'Цель (часы)'
    return 'Цель (визиты)'
})

const resetForm = () => {
    editingId.value = null
    form.reset()
    form.clearErrors()
    form.type = 'play_hours'
    form.target_value = 10
    form.period = 'weekly'
    form.reward_type = 'deposit_balance'
    form.reward_value = 100
    form.night_start = 22
    form.night_end = 6
    form.is_active = true
    form.sort_order = 0
}

const editAchievement = (a: any) => {
    editingId.value = a.id
    form.title = a.title
    form.description = a.description || ''
    form.type = a.type
    form.target_value = a.target_value
    form.period = a.period
    form.reward_type = a.reward_type
    form.reward_value = a.reward_value
    form.night_start = a.night_start ?? 22
    form.night_end = a.night_end ?? 6
    form.is_active = !!a.is_active
    form.sort_order = a.sort_order ?? 0
}

const submit = () => {
    if (editingId.value) {
        form.put(`/admin/achievements/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        })
    } else {
        form.post('/admin/achievements', {
            preserveScroll: true,
            onSuccess: () => resetForm(),
        })
    }
}

const toggleActive = (id: number) => {
    router.patch(`/admin/achievements/${id}/toggle`, {}, { preserveScroll: true })
}

const deleteAchievement = (id: number) => {
    if (!confirm('Удалить ачивку? Прогресс игроков по ней тоже удалится.')) return
    router.delete(`/admin/achievements/${id}`, { preserveScroll: true })
}

const typeLabel = (type: string) => ({
    play_hours: 'Часы игры',
    night_visits: 'Ночные визиты',
    visit_count: 'Визиты',
}[type] || type)

const periodLabel = (period: string) => ({
    once: 'Один раз',
    weekly: 'Еженедельно',
    monthly: 'Ежемесячно',
}[period] || period)

const rewardLabel = (type: string) => type === 'bonus_balance' ? 'Фантики' : 'Депозит ₽'
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white animate-in fade-in duration-500">
            <div class="flex justify-between items-end mb-8">
                <div>
                    <div class="text-[10px] text-purple-500 font-black uppercase mb-1 tracking-[0.3em] flex items-center gap-2">
                        <span class="w-1.5 h-1.5 bg-purple-500 rounded-full animate-pulse"></span>
                        Quest Engine
                    </div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-white">Квесты и ачивки</h1>
                    <p class="text-white/40 text-xs mt-2 max-w-xl">
                        Настройте цели вроде «10 часов за неделю» или «3 ночных визита» — бонусы начисляются автоматически после сессии.
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">
                <div class="xl:col-span-4 bg-[#0a0a0a] border border-purple-500/30 rounded-[2.5rem] p-8 shadow-[0_0_50px_rgba(168,85,247,0.05)] sticky top-8">
                    <h2 class="text-xl font-black uppercase italic text-purple-400 mb-6 border-b border-purple-500/20 pb-4">
                        {{ editingId ? 'Редактировать' : 'Новая ачивка' }}
                    </h2>

                    <form @submit.prevent="submit" class="space-y-5">
                        <div>
                            <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Название</label>
                            <input v-model="form.title" type="text" maxlength="120" placeholder="Сыграй 10 часов за неделю"
                                   class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white font-bold focus:border-purple-500 outline-none" required />
                            <div v-if="form.errors.title" class="text-red-500 text-[10px] mt-1 font-bold uppercase">{{ form.errors.title }}</div>
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Описание</label>
                            <textarea v-model="form.description" rows="2" maxlength="500" placeholder="Кратко для игрока"
                                      class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white text-sm focus:border-purple-500 outline-none resize-none" />
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Тип условия</label>
                            <div class="grid grid-cols-1 gap-2">
                                <button v-for="t in ['play_hours', 'night_visits', 'visit_count']" :key="t" type="button"
                                        @click="form.type = t"
                                        class="text-left px-4 py-3 border rounded-xl transition-all"
                                        :class="form.type === t ? 'bg-purple-500/20 border-purple-500 text-purple-300' : 'bg-black border-white/10 text-white/40 hover:border-white/30'">
                                    <div class="text-[10px] font-black uppercase tracking-widest">{{ typeLabel(t) }}</div>
                                    <div class="text-[9px] text-white/30 mt-1">{{ typeHints[t] }}</div>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">{{ targetLabel }}</label>
                                <input v-model="form.target_value" type="number" min="0.1" step="0.1"
                                       class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none" required />
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Период</label>
                                <select v-model="form.period"
                                        class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none">
                                    <option value="once">Один раз</option>
                                    <option value="weekly">Еженедельно</option>
                                    <option value="monthly">Ежемесячно</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="form.type === 'night_visits'" class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Ночь с (час)</label>
                                <input v-model="form.night_start" type="number" min="0" max="23"
                                       class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none" />
                            </div>
                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Ночь до (час)</label>
                                <input v-model="form.night_end" type="number" min="0" max="23"
                                       class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none" />
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Награда</label>
                            <div class="flex gap-2 mb-3">
                                <button type="button" @click="form.reward_type = 'deposit_balance'"
                                        class="flex-1 py-3 border rounded-xl font-black uppercase text-[10px] tracking-widest transition-all"
                                        :class="form.reward_type === 'deposit_balance' ? 'bg-emerald-500/20 border-emerald-500 text-emerald-400' : 'bg-black border-white/10 text-white/30'">
                                    Депозит ₽
                                </button>
                                <button type="button" @click="form.reward_type = 'bonus_balance'"
                                        class="flex-1 py-3 border rounded-xl font-black uppercase text-[10px] tracking-widest transition-all"
                                        :class="form.reward_type === 'bonus_balance' ? 'bg-purple-500/20 border-purple-500 text-purple-400' : 'bg-black border-white/10 text-white/30'">
                                    Фантики
                                </button>
                            </div>
                            <input v-model="form.reward_value" type="number" min="1" step="1"
                                   class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none" required />
                        </div>

                        <div class="flex gap-3">
                            <button type="submit" :disabled="form.processing"
                                    class="flex-1 py-5 bg-purple-600 hover:bg-purple-500 text-white font-black uppercase rounded-2xl tracking-[0.2em] transition-all shadow-[0_0_20px_rgba(147,51,234,0.3)] active:scale-95 disabled:opacity-50">
                                {{ form.processing ? '...' : (editingId ? 'Сохранить' : 'Создать') }}
                            </button>
                            <button v-if="editingId" type="button" @click="resetForm"
                                    class="px-5 py-5 border border-white/10 rounded-2xl text-white/40 hover:text-white text-[10px] font-black uppercase">
                                Отмена
                            </button>
                        </div>
                    </form>
                </div>

                <div class="xl:col-span-8 space-y-4">
                    <div v-if="achievements.length === 0" class="py-20 border-2 border-dashed border-white/5 rounded-[3rem] text-center bg-[#050505]">
                        <div class="text-white/20 text-[10px] uppercase font-black tracking-[0.4em] italic">Ачивок пока нет</div>
                    </div>

                    <div v-for="a in achievements" :key="a.id"
                         class="bg-[#050505] border rounded-[2rem] p-6 transition-all flex flex-col md:flex-row md:items-center gap-4 justify-between"
                         :class="a.is_active ? 'border-white/10 hover:border-purple-500/40' : 'border-white/5 opacity-50'">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <span class="text-[9px] uppercase font-black tracking-widest px-2 py-0.5 rounded bg-purple-500/15 text-purple-300 border border-purple-500/30">
                                    {{ typeLabel(a.type) }}
                                </span>
                                <span class="text-[9px] uppercase font-black tracking-widest px-2 py-0.5 rounded bg-white/5 text-white/40 border border-white/10">
                                    {{ periodLabel(a.period) }}
                                </span>
                                <span v-if="!a.is_active" class="text-[9px] uppercase font-black tracking-widest px-2 py-0.5 rounded bg-red-500/15 text-red-400 border border-red-500/30">
                                    Выкл
                                </span>
                            </div>
                            <div class="text-xl font-black uppercase italic tracking-tight text-white truncate">{{ a.title }}</div>
                            <div v-if="a.description" class="text-xs text-white/30 mt-1 line-clamp-2">{{ a.description }}</div>
                            <div class="mt-3 text-[10px] uppercase font-black tracking-widest text-white/40 flex flex-wrap gap-4">
                                <span>Цель: {{ a.target_value }}{{ a.type === 'play_hours' ? ' ч' : ' визит.' }}</span>
                                <span>Награда: +{{ Math.floor(a.reward_value) }} {{ rewardLabel(a.reward_type) }}</span>
                                <span v-if="a.type === 'night_visits'">Окно: {{ a.night_start }}:00–{{ a.night_end }}:00</span>
                                <span>Выдано: {{ a.completions_count || 0 }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button @click="editAchievement(a)" class="px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest text-white/60 hover:text-white hover:border-purple-500/40 transition-all">
                                Изменить
                            </button>
                            <button @click="toggleActive(a.id)" class="px-4 py-3 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-widest transition-all"
                                    :class="a.is_active ? 'text-emerald-400 hover:border-emerald-500/40' : 'text-white/30 hover:text-white'">
                                {{ a.is_active ? 'Вкл' : 'Выкл' }}
                            </button>
                            <button @click="deleteAchievement(a.id)" class="p-3 rounded-xl bg-white/5 border border-white/10 text-white/20 hover:text-red-500 hover:border-red-500/40 transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
