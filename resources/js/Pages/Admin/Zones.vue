<script setup>
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps({ zones: Array })

const form = useForm({
    name: '',
    slug: '',
    color: '#22c55e'
})

// Автоматически генерируем системное имя из названия (например: VIP Зал -> vip-zal)
const generateSlug = () => {
    form.slug = form.name.toLowerCase().replace(/[^a-z0-9]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')
}

const submitZone = () => {
    form.post('/admin/zones', { onSuccess: () => form.reset() })
}

const deleteZone = (id) => {
    if (confirm('Удалить эту зону?')) router.delete(`/admin/zones/${id}`)
}
</script>

<template>
    <AdminLayout>
        <div class="p-8 max-w-5xl mx-auto font-mono text-white">
            <h1 class="text-4xl font-black italic tracking-tighter uppercase text-cyan-500 mb-10">Топология залов</h1>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-2xl h-fit">
                    <h3 class="text-lg font-black uppercase italic mb-6">Новая зона</h3>
                    <form @submit.prevent="submitZone" class="space-y-4">
                        <div>
                            <input v-model="form.name" @input="generateSlug" type="text" placeholder="Название (VIP Зал)" class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm focus:border-cyan-500 outline-none" required />
                        </div>
                        <div>
                            <input v-model="form.slug" type="text" placeholder="Системное имя (vip)" class="w-full bg-black border border-white/10 rounded-xl p-4 text-sm text-white/50" required />
                        </div>
                        <div class="flex items-center gap-4">
                            <label class="text-[10px] uppercase text-white/40 font-black">Идентификатор (Цвет)</label>
                            <input v-model="form.color" type="color" class="w-10 h-10 rounded bg-transparent border-none cursor-pointer" />
                        </div>
                        <button type="submit" class="w-full py-4 mt-4 bg-cyan-500 text-black font-black uppercase text-[10px] rounded-xl tracking-widest hover:bg-cyan-400">Добавить зону</button>
                    </form>
                </div>

                <div class="md:col-span-2 space-y-4">
                    <div v-for="zone in zones" :key="zone.id" class="bg-[#0a0a0a] border border-white/5 p-6 rounded-3xl flex items-center justify-between group">
                        <div class="flex items-center gap-6">
                            <div class="w-8 h-8 rounded-full shadow-[0_0_15px_currentColor]" :style="{ backgroundColor: zone.color, color: zone.color }"></div>
                            <div>
                                <div class="text-xl font-black uppercase italic">{{ zone.name }}</div>
                                <div class="text-[10px] text-white/30 font-mono mt-1">ID: {{ zone.slug }}</div>
                            </div>
                        </div>
                        <button @click="deleteZone(zone.id)" class="text-red-500/40 hover:text-red-500 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>

                    <div v-if="zones.length === 0" class="p-10 border border-dashed border-white/10 rounded-3xl text-center text-white/30 text-sm italic uppercase font-black tracking-widest">
                        Зоны не заданы
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
