<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    incidents: { type: Array, default: () => [] },
})

const page = usePage()
const filter = ref('open')
const busy = ref(false)

const rows = computed(() => {
    if (filter.value === 'all') return props.incidents
    if (filter.value === 'done') {
        return props.incidents.filter((row) => row.status === 'resolved_excused' || row.status === 'punished')
    }
    return props.incidents.filter((row) => row.status !== 'resolved_excused' && row.status !== 'punished')
})

const errorText = computed(() => page.props.errors?.message || '')

const post = (url) => {
    busy.value = true
    router.post(url, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

const when = (iso) => {
    if (!iso) return '—'
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return iso
    return date.toLocaleString('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
    <section class="bg-[#050505] border border-white/5 rounded-[0.875rem] p-6 space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-sm font-black uppercase italic tracking-widest text-white">Кадровые инциденты</h2>
                <p class="text-white/40 text-xs mt-1">Проект докладной для управляющего. Доступ не закрывается и оплата не списывается</p>
            </div>
            <div class="flex gap-2">
                <button type="button" class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="filter === 'open' ? 'bg-red-500 text-black' : 'border border-white/10 text-white/50'"
                        @click="filter = 'open'">Открытые</button>
                <button type="button" class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="filter === 'done' ? 'bg-white text-black' : 'border border-white/10 text-white/50'"
                        @click="filter = 'done'">Закрытые</button>
                <button type="button" class="px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest"
                        :class="filter === 'all' ? 'bg-purple-500 text-black' : 'border border-white/10 text-white/50'"
                        @click="filter = 'all'">Все</button>
            </div>
        </div>

        <p v-if="errorText" class="text-red-400 text-xs font-bold">{{ errorText }}</p>
        <p v-if="rows.length === 0" class="text-white/30 text-xs uppercase tracking-widest py-6">Инцидентов нет</p>

        <article v-for="row in rows" :key="row.id" class="border border-white/10 rounded-2xl p-5 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-white font-black">{{ row.admin_name }} · {{ row.type_label }}</div>
                    <div class="text-white/40 text-xs mt-1">{{ row.slot_label }}</div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] uppercase font-black tracking-widest text-amber-300">{{ row.status_label }}</div>
                    <div class="text-white/40 text-[11px] mt-1">Срок {{ when(row.deadline_at) }}</div>
                </div>
            </div>

            <p v-if="row.explanation_text" class="text-sm text-white/70 whitespace-pre-wrap">{{ row.explanation_text }}</p>
            <p v-if="!row.demand_delivered_at" class="text-xs text-white/40">Сотрудник ещё не открыл требование в кабинете.</p>
            <p v-if="row.status === 'memo_for_signature'" class="text-xs text-amber-200/80">
                Докладная на подписи старшего администратора. Это не приказ: кабинет открыт, баллы на месте.
            </p>
            <p v-else-if="row.status !== 'resolved_excused'" class="text-xs text-white/40">
                Автоматика подготовила проект. «Подтвердить увольнение» сразу собирает докладную на подпись.
            </p>
            <p class="text-[11px] text-white/30">Подписей комиссии: {{ row.signatures }} / {{ row.signatures_required }}</p>

            <div class="flex flex-wrap gap-2">
                <a v-for="doc in row.documents" :key="doc.id" :href="`/admin/salary/edo/documents/${doc.id}`" target="_blank"
                   class="px-3 py-2 border border-white/10 rounded-xl text-[10px] uppercase font-black tracking-widest text-white/60">
                    {{ doc.title }}
                </a>
                <button v-if="row.can_deliver" type="button" :disabled="busy" @click="post(`/admin/staff/incidents/${row.id}/deliver`)"
                        class="px-3 py-2 border border-white/15 rounded-xl text-[10px] uppercase font-black tracking-widest text-white">
                    Зафиксировать вручение
                </button>
                <button v-if="row.can_sign" type="button" :disabled="busy" @click="post(`/admin/staff/incidents/${row.id}/sign`)"
                        class="px-3 py-2 bg-cyan-500 text-black rounded-xl text-[10px] uppercase font-black tracking-widest">
                    Подписать акт
                </button>
                <button v-if="row.can_excuse" type="button" :disabled="busy"
                        @click="busy = true; router.post(`/admin/staff/incidents/${row.id}/resolve`, { decision: 'excuse' }, { preserveScroll: true, onFinish: () => busy = false })"
                        class="px-3 py-2 bg-[#22c55e] text-black rounded-xl text-[10px] uppercase font-black tracking-widest">
                    Причина уважительная
                </button>
                <button v-if="row.can_confirm_dismissal" type="button" :disabled="busy"
                        @click="busy = true; router.post(`/admin/staff/incidents/${row.id}/resolve`, { decision: 'confirm_dismissal' }, { preserveScroll: true, onFinish: () => busy = false })"
                        class="px-3 py-2 bg-red-500 text-black rounded-xl text-[10px] uppercase font-black tracking-widest">
                    Подтвердить увольнение
                </button>
                <button v-if="row.can_sign_memo" type="button" :disabled="busy" @click="post(`/admin/staff/incidents/${row.id}/sign`)"
                        class="px-3 py-2 bg-white text-black rounded-xl text-[10px] uppercase font-black tracking-widest">
                    Подписать докладную
                </button>
                <a v-if="row.can_export" :href="`/admin/staff/incidents/${row.id}/dossier`"
                   class="px-3 py-2 border border-white/15 rounded-xl text-[10px] uppercase font-black tracking-widest text-white">
                    Архив ZIP
                </a>
            </div>
        </article>
    </section>
</template>
