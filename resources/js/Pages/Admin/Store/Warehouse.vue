<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    components: any[]
    suppliers: { id: number, name: string }[]
    types: Record<string, string>
    statuses: Record<string, string>
    filters: { type?: string | null }
    canManage: boolean
    canReceive: boolean
}>()

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'

const showForm = ref(false)
const showSupplier = ref(false)

const form = useForm({
    id: null as number | null,
    name: '',
    type: 'cpu',
    store_supplier_id: null as number | null,
    purchase_price: 0,
    warranty_number: '',
    warranty_months: 12 as number | null,
    qty: 1,
    status: 'in_stock',
    notes: '',
})

const supplierForm = useForm({
    name: '',
    phone: '',
    notes: '',
})

const filterType = computed({
    get: () => props.filters?.type || '',
    set: (v: string) => router.get('/admin/store/warehouse', v ? { type: v } : {}, { preserveState: true }),
})

const openCreate = () => {
    form.reset()
    form.id = null
    form.type = 'cpu'
    form.warranty_months = 12
    form.qty = 1
    form.status = 'in_stock'
    showForm.value = true
}

const openEdit = (c: any) => {
    form.id = c.id
    form.name = c.name
    form.type = c.type
    form.store_supplier_id = c.store_supplier_id
    form.purchase_price = Number(c.purchase_price)
    form.warranty_number = c.warranty_number || ''
    form.warranty_months = c.warranty_months
    form.qty = Number(c.qty) || 1
    form.status = c.status
    form.notes = c.notes || ''
    showForm.value = true
}

const save = () => {
    if (form.id) {
        form.put(`/admin/store/warehouse/${form.id}`, { onSuccess: () => { showForm.value = false } })
    } else {
        form.post('/admin/store/warehouse', { onSuccess: () => { showForm.value = false } })
    }
}

const remove = (id: number) => {
    if (!confirm('Удалить комплектующее?')) return
    router.delete(`/admin/store/warehouse/${id}`)
}

const saveSupplier = () => {
    supplierForm.post('/admin/store/warehouse/suppliers', {
        onSuccess: () => { showSupplier.value = false; supplierForm.reset() },
    })
}
</script>

<template>
    <Head title="REACTOR | Склад комплектующих" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex flex-wrap justify-between items-end gap-4 border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">
                        Склад <span class="text-amber-400">комплектующих</span>
                    </h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                        Приёмка деталей магазина при клубе
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <select v-model="filterType" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все типы</option>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button v-if="canManage" @click="showSupplier = true"
                            class="px-5 py-3 border border-white/10 text-white/60 font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Поставщик
                    </button>
                    <button v-if="canReceive" @click="openCreate"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Приход
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto border border-white/5 rounded-2xl">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-widest text-white/30 border-b border-white/5">
                        <tr>
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Наименование</th>
                            <th class="px-4 py-3">Поставщик</th>
                            <th class="px-4 py-3">Закупка</th>
                            <th class="px-4 py-3">Гарантия №</th>
                            <th class="px-4 py-3">Срок</th>
                            <th class="px-4 py-3">Кто принял</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in components" :key="c.id" class="border-b border-white/5 hover:bg-white/[0.02]">
                            <td class="px-4 py-3 text-amber-400/80">{{ types[c.type] || c.type }}</td>
                            <td class="px-4 py-3 font-black uppercase">{{ c.name }} <span v-if="c.qty > 1" class="text-white/30">×{{ c.qty }}</span></td>
                            <td class="px-4 py-3 text-white/40">{{ c.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3">{{ money(c.purchase_price) }}</td>
                            <td class="px-4 py-3 text-white/50">{{ c.warranty_number || '—' }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.warranty_months ? c.warranty_months + ' мес.' : '—' }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.receiver?.name || '—' }}</td>
                            <td class="px-4 py-3 text-white/50">{{ statuses[c.status] || c.status }}</td>
                            <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                <button v-if="canManage" @click="openEdit(c)" class="text-amber-400 uppercase font-black text-[10px]">Edit</button>
                                <button v-if="canManage" @click="remove(c.id)" class="text-red-400 uppercase font-black text-[10px]">Del</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!components.length" class="text-white/30 text-sm py-10 text-center">Склад пуст</div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-lg space-y-3" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl mb-2">{{ form.id ? 'Редактировать' : 'Приход комплектующего' }}</h3>
                <select v-model="form.type" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required>
                    <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                </select>
                <input v-model="form.name" placeholder="Наименование" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <select v-model="form.store_supplier_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option :value="null">Поставщик</option>
                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model.number="form.purchase_price" type="number" min="0" step="0.01" placeholder="Цена закупки" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                    <input v-model.number="form.qty" type="number" min="1" placeholder="Кол-во" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model="form.warranty_number" placeholder="Гарантийный номер" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                    <input v-model.number="form.warranty_months" type="number" min="0" placeholder="Гарантия, мес." class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>
                <select v-if="form.id" v-model="form.status" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
                <textarea v-model="form.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" rows="2" />
                <div class="flex gap-3 justify-end pt-2">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>

        <div v-if="showSupplier" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showSupplier = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-3" @submit.prevent="saveSupplier">
                <h3 class="font-black uppercase italic text-xl">Поставщик магазина</h3>
                <input v-model="supplierForm.name" placeholder="Название" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="supplierForm.phone" placeholder="Телефон" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <input v-model="supplierForm.notes" placeholder="Заметки" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showSupplier = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="supplierForm.processing">Добавить</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
