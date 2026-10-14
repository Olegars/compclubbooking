<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import axios from 'axios'

const props = defineProps({
    panel: { type: Object, default: null },
    spaces: { type: Array, default: () => [] },
    detectors: { type: Array, default: () => [] },
    live: { type: Object, default: () => ({ count: 0, alarms: [] }) },
})

const panelForm = useForm({
    host: props.panel?.host || '',
    port: props.panel?.port || 40000,
    panel_address: props.panel?.panel_address || 1,
    enabled: Boolean(props.panel?.enabled),
})

const rows = ref((props.detectors || []).map((row) => ({
    space_id: row.space_id,
    device_address: row.device_address,
    loop_address: row.loop_address,
})))

const detectorForm = useForm({ detectors: rows.value })

const addRow = () => {
    rows.value.push({
        space_id: props.spaces[0]?.id || '',
        device_address: 1,
        loop_address: rows.value.length + 1,
    })
}

const saveDetectors = () => {
    detectorForm.detectors = rows.value
    detectorForm.post('/admin/fire/detectors', { preserveScroll: true })
}

const resetting = ref(false)
const resetAlarm = async () => {
    if (resetting.value) return
    resetting.value = true
    try {
        await axios.post('/admin/api/fire/reset')
        router.reload({ only: ['live', 'panel'] })
    } finally {
        resetting.value = false
    }
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 max-w-3xl space-y-8" :class="live.count > 0 ? 'pt-20' : ''">
            <div>
                <h1 class="text-2xl font-black uppercase tracking-tight">Пожар</h1>
                <p class="mt-2 text-sm text-white/55 leading-relaxed">
                    Дым в комнате закрывает все её компьютеры экраном «пожар, срочно покиньте комнату».
                    Снять экран руками нельзя: он уйдёт, когда датчик перестанет видеть дым.
                    «Отмена тревоги» гасит Рокот и щит, сессию не открывает.
                </p>
            </div>

            <section v-if="live.count > 0" class="rounded-2xl border border-red-500/40 bg-red-500/10 p-5 space-y-3">
                <div class="text-red-400 font-black uppercase tracking-[0.18em]">Пожар</div>
                <div v-for="alarm in live.alarms" :key="alarm.id" class="text-sm">
                    {{ alarm.space }} <span class="text-white/40">с {{ alarm.since }}</span>
                </div>
                <button type="button"
                        class="px-4 py-2 rounded-xl bg-red-600 text-xs font-black uppercase tracking-wider disabled:opacity-50"
                        :disabled="resetting"
                        @click="resetAlarm">
                    {{ resetting ? 'Сброс…' : 'Отмена тревоги' }}
                </button>
            </section>

            <form class="rounded-2xl border border-white/10 p-5 space-y-4" @submit.prevent="panelForm.post('/admin/fire/panel')">
                <h2 class="text-sm font-black uppercase tracking-wider text-white/80">С2000-Ethernet</h2>
                <p class="text-xs text-white/40 leading-relaxed">
                    RS-232 преобразователя — в порт компьютера пульта С2000М. Линию датчиков программа не опрашивает, мастер на ней остаётся пульт.
                </p>
                <label class="block text-xs uppercase tracking-wider text-white/45">
                    IP
                    <input v-model="panelForm.host" class="mt-1 w-full bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm text-white" placeholder="192.168.1.50">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-xs uppercase tracking-wider text-white/45">
                        UDP-порт
                        <input v-model.number="panelForm.port" type="number" min="1" max="65535" class="mt-1 w-full bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm text-white">
                    </label>
                    <label class="block text-xs uppercase tracking-wider text-white/45">
                        Адрес пульта
                        <input v-model.number="panelForm.panel_address" type="number" min="1" max="127" class="mt-1 w-full bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm text-white">
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="panelForm.enabled" type="checkbox">
                    Опрашивать пульт
                </label>
                <p v-if="panel?.last_error" class="text-xs text-amber-300">{{ panel.last_error }}</p>
                <button type="submit" class="px-4 py-2 rounded-xl bg-white text-black text-xs font-black uppercase tracking-wider" :disabled="panelForm.processing">
                    Сохранить
                </button>
            </form>

            <form class="rounded-2xl border border-white/10 p-5 space-y-4" @submit.prevent="saveDetectors">
                <h2 class="text-sm font-black uppercase tracking-wider text-white/80">Датчик → комната</h2>
                <p class="text-xs text-white/40">Адрес прибора — это КДЛ на линии RS-485. Адрес шлейфа — датчик на ДПЛС.</p>
                <div v-for="(row, index) in rows" :key="index" class="grid grid-cols-[1fr_90px_90px_auto] gap-2 items-center">
                    <select v-model="row.space_id" class="bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm">
                        <option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.name }}</option>
                    </select>
                    <input v-model.number="row.device_address" type="number" min="1" max="127" class="bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm" title="Адрес КДЛ">
                    <input v-model.number="row.loop_address" type="number" min="1" max="127" class="bg-black/40 border border-white/10 rounded-xl px-3 py-2 text-sm" title="Адрес датчика">
                    <button type="button" class="text-white/40 text-xs uppercase" @click="rows.splice(index, 1)">Убрать</button>
                </div>
                <p v-if="detectorForm.errors.detectors" class="text-xs text-red-400">{{ detectorForm.errors.detectors }}</p>
                <div class="flex gap-3">
                    <button type="button" class="px-4 py-2 rounded-xl border border-white/15 text-xs uppercase tracking-wider" @click="addRow">Датчик</button>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-white text-black text-xs font-black uppercase tracking-wider" :disabled="detectorForm.processing">
                        Сохранить комнаты
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
