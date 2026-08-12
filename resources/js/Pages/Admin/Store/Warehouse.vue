<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useToast } from '@/Composables/useToast'

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
const { success, error: toastError, info } = useToast()
const { receiveMode, enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()

const showForm = ref(false)
const showSupplier = ref(false)
const scanFlashId = ref<number | null>(null)
const scanHint = ref('')
const receiveTargetId = ref<number | null>(null)

const form = useForm({
    id: null as number | null,
    name: '',
    barcode: '',
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

const processReceiveScan = async (code: string) => {
    if (showForm.value || showSupplier.value) {
        // Форма открыта — кладём код в штрихкод / гарантийный номер
        if (!form.barcode) form.barcode = code
        else if (!form.warranty_number) form.warranty_number = code
        else form.barcode = code
        info('Код подставлен в форму')
        return
    }

    try {
        const payload: Record<string, any> = { code }
        if (receiveTargetId.value) payload.store_component_id = receiveTargetId.value

        const { data } = await axios.post('/admin/store/warehouse/receive-scan', payload)
        success(`${data.component?.name || 'Позиция'} · +${data.added}`)
        scanFlashId.value = Number(data.component?.id)
        scanHint.value = `${data.component?.name} · остаток ${data.component?.qty}`
        setTimeout(() => {
            scanFlashId.value = null
            scanHint.value = ''
        }, 2500)
        router.reload({ only: ['components'], preserveScroll: true })
    } catch (e: any) {
        const status = e?.response?.status
        const msg = e?.response?.data?.message || 'Скан не принят'
        const scanned = e?.response?.data?.code || code

        if (status === 404 && props.canReceive) {
            openCreate(scanned)
            info('Новая позиция — заполните поля и сохраните')
            return
        }
        toastError(msg)
    }
}

const setReceiveMode = (on: boolean) => {
    if (on) {
        enableReceiveMode(processReceiveScan)
    } else {
        disableReceiveMode()
        receiveTargetId.value = null
        scanHint.value = ''
    }
}

onMounted(() => {
    if (props.canReceive) setReceiveMode(true)
})

onUnmounted(() => {
    disableReceiveMode()
})

const openCreate = (barcode = '') => {
    form.reset()
    form.id = null
    form.type = 'cpu'
    form.warranty_months = 12
    form.qty = 1
    form.status = 'in_stock'
    form.purchase_price = 0
    form.barcode = barcode
    showForm.value = true
}

const openEdit = (c: any) => {
    form.id = c.id
    form.name = c.name
    form.barcode = c.barcode || ''
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

const selectReceiveTarget = (id: number) => {
    receiveTargetId.value = receiveTargetId.value === id ? null : id
    if (receiveTargetId.value) setReceiveMode(true)
}

const fieldClass = 'w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm outline-none focus:border-amber-500/50'
const labelClass = 'block text-[10px] uppercase tracking-widest text-white/40 font-black mb-1.5'
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
                        Приёмка сканером · штрихкод в базу
                    </p>
                </div>
                <div class="flex flex-wrap gap-3 items-center">
                    <select v-model="filterType" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-[10px] uppercase font-black">
                        <option value="">Все типы</option>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button v-if="canReceive"
                            type="button"
                            @click="setReceiveMode(!receiveMode)"
                            class="px-5 py-3 rounded-2xl border text-[10px] font-black uppercase tracking-widest transition-all"
                            :class="receiveMode
                                ? 'bg-amber-500/15 border-amber-500/40 text-amber-400'
                                : 'border-white/10 text-white/50'">
                        {{ receiveMode ? 'Сканер ON' : 'Сканер OFF' }}
                    </button>
                    <button v-if="canManage" @click="showSupplier = true"
                            class="px-5 py-3 border border-white/10 text-white/60 font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Поставщик
                    </button>
                    <button v-if="canReceive" @click="openCreate()"
                            class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                        + Приход
                    </button>
                </div>
            </div>

            <div v-if="receiveMode && canReceive"
                 class="rounded-2xl border border-amber-500/30 bg-amber-500/5 px-5 py-4 text-[11px] text-amber-200/80 uppercase tracking-wider font-black">
                Режим сканера: пикните штрихкод — если позиция есть, qty +1; если нет — откроется форма новой позиции.
                <span v-if="receiveTargetId" class="text-amber-400"> Цель: #{{ receiveTargetId }}</span>
                <span v-if="scanHint" class="block mt-2 text-amber-400 normal-case tracking-normal">{{ scanHint }}</span>
            </div>

            <div class="overflow-x-auto border border-white/5 rounded-2xl">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-widest text-white/30 border-b border-white/5">
                        <tr>
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Наименование</th>
                            <th class="px-4 py-3">Штрихкод</th>
                            <th class="px-4 py-3">Поставщик</th>
                            <th class="px-4 py-3">Закупка</th>
                            <th class="px-4 py-3">Гарантия №</th>
                            <th class="px-4 py-3">Срок</th>
                            <th class="px-4 py-3">Кол-во</th>
                            <th class="px-4 py-3">Кто принял</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in components" :key="c.id"
                            :id="`component-${c.id}`"
                            class="border-b border-white/5 hover:bg-white/[0.02] transition-colors"
                            :class="scanFlashId === c.id ? 'bg-amber-500/10' : ''">
                            <td class="px-4 py-3 text-amber-400/80">{{ types[c.type] || c.type }}</td>
                            <td class="px-4 py-3 font-black uppercase">{{ c.name }}</td>
                            <td class="px-4 py-3 text-cyan-400/70 font-mono">{{ c.barcode || '—' }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3">{{ money(c.purchase_price) }}</td>
                            <td class="px-4 py-3 text-white/50">{{ c.warranty_number || '—' }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.warranty_months ? c.warranty_months + ' мес.' : '—' }}</td>
                            <td class="px-4 py-3 font-black text-amber-400">{{ c.qty }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.receiver?.name || '—' }}</td>
                            <td class="px-4 py-3 text-white/50">{{ statuses[c.status] || c.status }}</td>
                            <td class="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                                <button v-if="canReceive" @click="selectReceiveTarget(c.id)"
                                        class="text-[10px] uppercase font-black"
                                        :class="receiveTargetId === c.id ? 'text-amber-400' : 'text-white/30'">
                                    Scan
                                </button>
                                <button v-if="canManage" @click="openEdit(c)" class="text-amber-400 uppercase font-black text-[10px]">Edit</button>
                                <button v-if="canManage" @click="remove(c.id)" class="text-red-400 uppercase font-black text-[10px]">Del</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!components.length" class="text-white/30 text-sm py-10 text-center">Склад пуст — включите сканер и пикните первый код</div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-lg space-y-4" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">{{ form.id ? 'Редактировать' : 'Приход комплектующего' }}</h3>

                <div>
                    <label :class="labelClass">Тип комплектующего</label>
                    <select v-model="form.type" :class="fieldClass" required>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div>
                    <label :class="labelClass">Наименование</label>
                    <input v-model="form.name" placeholder="Например: Ryzen 5 5600" :class="fieldClass" required />
                </div>

                <div>
                    <label :class="labelClass">Штрихкод (EAN / GTIN)</label>
                    <input v-model="form.barcode" placeholder="Сканер подставит сам" :class="fieldClass + ' text-cyan-400 border-cyan-500/30'" />
                </div>

                <div>
                    <label :class="labelClass">Поставщик</label>
                    <select v-model="form.store_supplier_id" :class="fieldClass">
                        <option :value="null">Не указан</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">Цена закупки, ₽</label>
                        <input v-model.number="form.purchase_price" type="number" min="0" step="0.01" :class="fieldClass" required />
                    </div>
                    <div>
                        <label :class="labelClass">Количество</label>
                        <input v-model.number="form.qty" type="number" min="1" :class="fieldClass" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label :class="labelClass">Гарантийный номер</label>
                        <input v-model="form.warranty_number" placeholder="S/N или № гарантии" :class="fieldClass" />
                    </div>
                    <div>
                        <label :class="labelClass">Срок гарантии, мес.</label>
                        <input v-model.number="form.warranty_months" type="number" min="0" :class="fieldClass" />
                    </div>
                </div>

                <div v-if="form.id">
                    <label :class="labelClass">Статус</label>
                    <select v-model="form.status" :class="fieldClass">
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div>
                    <label :class="labelClass">Заметки</label>
                    <textarea v-model="form.notes" :class="fieldClass" rows="2" />
                </div>

                <div class="flex gap-3 justify-end pt-2">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>

        <div v-if="showSupplier" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showSupplier = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-4" @submit.prevent="saveSupplier">
                <h3 class="font-black uppercase italic text-xl">Поставщик магазина</h3>
                <div>
                    <label :class="labelClass">Название</label>
                    <input v-model="supplierForm.name" :class="fieldClass" required />
                </div>
                <div>
                    <label :class="labelClass">Телефон</label>
                    <input v-model="supplierForm.phone" :class="fieldClass" />
                </div>
                <div>
                    <label :class="labelClass">Заметки</label>
                    <input v-model="supplierForm.notes" :class="fieldClass" />
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showSupplier = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="supplierForm.processing">Добавить</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
