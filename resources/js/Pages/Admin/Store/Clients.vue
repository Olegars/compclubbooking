<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    clients: any[]
    filters: { q?: string | null }
    canManage: boolean
    readOnly: boolean
}>()

const showForm = ref(false)
const form = useForm({
    id: null as number | null,
    name: '',
    phone: '',
    email: '',
    notes: '',
})

const q = computed({
    get: () => props.filters?.q || '',
    set: (v: string) => router.get('/admin/store/clients', v ? { q: v } : {}, { preserveState: true, replace: true })
})

const openCreate = () => {
    form.reset()
    form.id = null
    showForm.value = true
}

const openEdit = (c: any) => {
    form.id = c.id
    form.name = c.name
    form.phone = c.phone
    form.email = c.email || ''
    form.notes = c.notes || ''
    showForm.value = true
}

const save = () => {
    if (form.id) {
        form.put(`/admin/store/clients/${form.id}`, { onSuccess: () => { showForm.value = false } })
    } else {
        form.post('/admin/store/clients', { onSuccess: () => { showForm.value = false } })
    }
}

const remove = (id: number) => {
    if (!confirm('Удалить клиента магазина?')) return
    router.delete(`/admin/store/clients/${id}`)
}
</script>

<template>
    <Head title="REACTOR | Клиенты магазина" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Clients</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Отдельно от клиентов клуба</p>
                </div>
                <div class="flex gap-3">
                    <input v-model="q" placeholder="Поиск..." class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm w-56" />
                    <button v-if="canManage" @click="openCreate"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Клиент
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="c in clients" :key="c.id" class="border border-white/5 bg-[#080808] rounded-2xl p-6 space-y-3">
                    <div class="font-black uppercase">{{ c.name }}</div>
                    <div class="text-xs text-white/40">{{ c.phone }}</div>
                    <div v-if="c.email" class="text-xs text-white/30">{{ c.email }}</div>
                    <div class="text-[10px] uppercase tracking-widest text-white/20">
                        Заказов: {{ c.orders_count || 0 }} · Гарантий: {{ c.warranties_count || 0 }}
                    </div>
                    <div v-if="canManage" class="flex gap-2 pt-2">
                        <button @click="openEdit(c)" class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">Edit</button>
                        <button @click="remove(c.id)" class="px-3 py-2 rounded-xl border border-red-500/30 text-[10px] uppercase font-black text-red-400">Del</button>
                    </div>
                    <div v-else-if="readOnly" class="text-[10px] text-white/20 uppercase tracking-widest">Только просмотр</div>
                </div>
                <div v-if="!clients.length" class="text-white/30 text-sm py-10 col-span-full text-center">Клиентов нет</div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-4" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">{{ form.id ? 'Редактировать' : 'Новый клиент' }}</h3>
                <input v-model="form.name" placeholder="Имя" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="form.phone" placeholder="Телефон" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="form.email" placeholder="Email" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
