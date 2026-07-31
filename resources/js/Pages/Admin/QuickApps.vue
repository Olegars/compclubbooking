<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({ apps: Array })

const showModal = ref(false)
const isEditing = ref(false)

const form = useForm({
    id: null,
    title: '',
    exe_path: '',
    launch_args: '',
    sort_order: 0,
    is_enabled: true,
})

const openCreate = () => {
    isEditing.value = false
    form.reset()
    form.id = null
    form.sort_order = (props.apps?.length ?? 0) * 10
    form.is_enabled = true
    showModal.value = true
}

const openEdit = (app) => {
    isEditing.value = true
    form.id = app.id
    form.title = app.title
    form.exe_path = app.exe_path || ''
    form.launch_args = app.launch_args || ''
    form.sort_order = app.sort_order ?? 0
    form.is_enabled = Boolean(app.is_enabled)
    showModal.value = true
}

const submit = () => {
    form.post('/admin/quick-apps', {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false
            form.reset()
        },
    })
}

const remove = (id) => {
    if (!confirm('Удалить приложение из быстрого меню шелла?')) return
    router.delete(`/admin/quick-apps/${id}`, { preserveScroll: true })
}
</script>

<template>
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <div class="text-[10px] font-black uppercase tracking-[0.25em] text-cyan-500 italic">Shell Bridge</div>
                    <h1 class="mt-1 text-3xl font-black italic text-white">Быстрый софт</h1>
                    <p class="mt-2 max-w-2xl text-sm text-white/40">
                        Discord, Telegram, Soundpad, VPN и другие утилиты для меню в левом верхнем углу шелла.
                        Путь — как у игр: полный путь к .exe или .lnk на клубных ПК.
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded-xl border border-cyan-500/40 bg-cyan-500/10 px-5 py-3 text-xs font-black uppercase tracking-wider text-cyan-400 transition hover:bg-cyan-500/20"
                    @click="openCreate"
                >
                    + Добавить
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-white/10 bg-black/40">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-white/10 text-[10px] font-black uppercase tracking-widest text-white/35">
                        <tr>
                            <th class="px-5 py-4">#</th>
                            <th class="px-5 py-4">Название</th>
                            <th class="px-5 py-4">Путь (.exe / .lnk)</th>
                            <th class="px-5 py-4">Аргументы</th>
                            <th class="px-5 py-4">Статус</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!apps?.length">
                            <td colspan="6" class="px-5 py-10 text-center text-white/30">
                                Пока пусто. Добавьте Discord / Telegram — они появятся в меню шелла.
                            </td>
                        </tr>
                        <tr
                            v-for="app in apps"
                            :key="app.id"
                            class="border-b border-white/5 transition hover:bg-white/[0.02]"
                        >
                            <td class="px-5 py-4 font-mono text-white/40">{{ app.sort_order }}</td>
                            <td class="px-5 py-4 font-bold text-white">{{ app.title }}</td>
                            <td class="max-w-[360px] truncate px-5 py-4 font-mono text-xs text-white/50" :title="app.exe_path">
                                {{ app.exe_path }}
                            </td>
                            <td class="px-5 py-4 font-mono text-xs text-white/40">{{ app.launch_args || '—' }}</td>
                            <td class="px-5 py-4">
                                <span
                                    class="rounded-lg border px-2 py-1 text-[10px] font-black uppercase tracking-wider"
                                    :class="app.is_enabled
                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400'
                                        : 'border-white/10 bg-white/5 text-white/30'"
                                >
                                    {{ app.is_enabled ? 'вкл' : 'выкл' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <button type="button" class="mr-3 text-xs font-bold text-cyan-400 hover:underline" @click="openEdit(app)">
                                    Изменить
                                </button>
                                <button type="button" class="text-xs font-bold text-red-400 hover:underline" @click="remove(app.id)">
                                    Удалить
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div
            v-if="showModal"
            class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            @click.self="showModal = false"
        >
            <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-[#0a0a0a] p-6 shadow-2xl">
                <div class="mb-5 text-lg font-black italic text-white">
                    {{ isEditing ? 'Изменить приложение' : 'Новое приложение' }}
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-white/40">Название</label>
                        <input v-model="form.title" type="text" class="w-full rounded-xl border border-white/10 bg-black px-4 py-3 text-white outline-none focus:border-cyan-500/50" placeholder="Discord" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-white/40">Путь к .exe / .lnk</label>
                        <input v-model="form.exe_path" type="text" class="w-full rounded-xl border border-white/10 bg-black px-4 py-3 font-mono text-sm text-white outline-none focus:border-cyan-500/50" placeholder="C:\Users\Public\Desktop\Discord.lnk" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-white/40">Аргументы (опционально)</label>
                        <input v-model="form.launch_args" type="text" class="w-full rounded-xl border border-white/10 bg-black px-4 py-3 font-mono text-sm text-white outline-none focus:border-cyan-500/50" placeholder="--processStart Discord.exe" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-white/40">Порядок</label>
                            <input v-model.number="form.sort_order" type="number" min="0" class="w-full rounded-xl border border-white/10 bg-black px-4 py-3 text-white outline-none focus:border-cyan-500/50" />
                        </div>
                        <label class="mt-6 flex items-center gap-3 text-sm text-white/70">
                            <input v-model="form.is_enabled" type="checkbox" class="h-4 w-4 rounded border-white/20 bg-black text-cyan-500" />
                            Показывать в шелл
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-xl px-4 py-2 text-xs font-bold uppercase tracking-wider text-white/40 hover:text-white" @click="showModal = false">
                        Отмена
                    </button>
                    <button
                        type="button"
                        class="rounded-xl border border-cyan-500/40 bg-cyan-500/15 px-5 py-2 text-xs font-black uppercase tracking-wider text-cyan-400 disabled:opacity-40"
                        :disabled="form.processing"
                        @click="submit"
                    >
                        Сохранить
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
