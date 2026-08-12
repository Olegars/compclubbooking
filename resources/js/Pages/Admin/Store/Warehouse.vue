<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import SuggestInput from '@/Components/SuggestInput.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useToast } from '@/Composables/useToast'
import { normalizeScanLayout } from '@/utils/scanKeyboard'

type SpecField = { key: string, label: string, suggest: string }

const props = defineProps<{
    components: any[]
    suppliers: { id: number, name: string }[]
    types: Record<string, string>
    statuses: Record<string, string>
    specSchemas: Record<string, SpecField[]>
    specDictionaries: Record<string, string[]>
    filters: { type?: string | null }
    canManage: boolean
    canReceive: boolean
}>()

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'
const { info } = useToast()
const { receiveMode, enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()

const showForm = ref(false)
const showSupplier = ref(false)
const serialInput = ref<HTMLInputElement | null>(null)

const specs = reactive<Record<string, string>>({})
const remoteSuggest = reactive<Record<string, string[]>>({})

const form = useForm({
    id: null as number | null,
    name: '',
    type: 'cpu',
    store_supplier_id: null as number | null,
    purchase_price: 0,
    warranty_number: '',
    warranty_months: 36 as number | null,
    qty: 1,
    status: 'in_stock',
    notes: '',
    specs: {} as Record<string, string>,
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

const activeFields = computed(() => props.specSchemas[form.type] || [])

const composedName = computed(() => {
    const s = (k: string) => (specs[k] || '').trim()
    if (form.type === 'cpu') {
        const seriesModel = s('series') && s('model') ? `${s('series')}-${s('model')}` : `${s('series')} ${s('model')}`.trim()
        return [s('brand'), seriesModel, s('socket') ? `(${s('socket')})` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'ram') {
        return [s('brand'), s('ddr'), s('capacity'), s('speed'), s('form')].filter(Boolean).join(' ')
    }
    if (form.type === 'motherboard') {
        return [s('brand'), s('chipset'), s('model'), s('socket') ? `(${s('socket')})` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'gpu') {
        return [s('brand'), s('chip'), s('vram')].filter(Boolean).join(' ')
    }
    if (form.type === 'storage_ssd') {
        return [s('brand'), s('model'), s('capacity'), s('interface')].filter(Boolean).join(' ')
    }
    if (form.type === 'storage_hdd') {
        return [s('brand'), s('capacity'), s('rpm') ? `${s('rpm')}rpm` : ''].filter(Boolean).join(' ')
    }
    if (form.type === 'psu') {
        return [s('brand'), s('wattage') ? `${s('wattage')}W` : '', s('cert')].filter(Boolean).join(' ')
    }
    if (form.type === 'case') {
        return [s('brand'), s('model'), s('form')].filter(Boolean).join(' ')
    }
    if (form.type === 'cooler') {
        return [s('brand'), s('kind'), s('model')].filter(Boolean).join(' ')
    }
    if (form.type === 'fan') {
        return [s('brand'), s('size')].filter(Boolean).join(' ')
    }
    if (form.type === 'network') {
        return [s('brand'), s('kind'), s('model')].filter(Boolean).join(' ')
    }
    if (form.type === 'os') return s('name')
    if (form.type === 'other') return s('title')
    return ''
})

const nameTouched = ref(false)

watch(composedName, (v) => {
    if (!nameTouched.value) form.name = v
})

const resetSpecs = (preset: Record<string, string> = {}) => {
    Object.keys(specs).forEach(k => delete specs[k])
    for (const f of activeFields.value) {
        specs[f.key] = preset[f.key] || ''
    }
}

watch(() => form.type, () => {
    nameTouched.value = false
    resetSpecs()
    form.name = ''
})

const suggestionsFor = (field: SpecField) => {
    const dict = props.specDictionaries[field.suggest] || []
    const remote = remoteSuggest[field.key] || []
    return Array.from(new Set([...remote, ...dict]))
}

let suggestTimer: ReturnType<typeof setTimeout> | null = null
const onSuggestSearch = (field: SpecField, q: string) => {
    if (suggestTimer) clearTimeout(suggestTimer)
    if ((q || '').trim().length < 3) {
        remoteSuggest[field.key] = []
        return
    }
    suggestTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/admin/store/warehouse/suggest', {
                params: { type: form.type, field: field.key, q },
            })
            remoteSuggest[field.key] = data.items || []
        } catch {
            remoteSuggest[field.key] = []
        }
    }, 120)
}

const processReceiveScan = async (code: string) => {
    const serial = normalizeScanLayout(code)
    if (!serial) return

    if (!showForm.value) {
        openCreate(serial)
    } else {
        form.warranty_number = serial
    }

    info('Серийный номер подставлен')
    requestAnimationFrame(() => serialInput.value?.focus())
}

const setReceiveMode = (on: boolean) => {
    if (on) enableReceiveMode(processReceiveScan)
    else disableReceiveMode()
}

onMounted(() => {
    if (props.canReceive) setReceiveMode(true)
})

onUnmounted(() => disableReceiveMode())

const openCreate = (serial = '') => {
    form.reset()
    form.id = null
    form.type = 'cpu'
    form.warranty_months = 36
    form.qty = 1
    form.status = 'in_stock'
    form.purchase_price = 0
    form.warranty_number = serial
    form.name = ''
    nameTouched.value = false
    resetSpecs()
    showForm.value = true
    requestAnimationFrame(() => serialInput.value?.focus())
}

const openEdit = (c: any) => {
    form.id = c.id
    form.name = c.name
    nameTouched.value = true
    form.type = c.type
    form.store_supplier_id = c.store_supplier_id
    form.purchase_price = Number(c.purchase_price)
    form.warranty_number = c.warranty_number || c.barcode || ''
    form.warranty_months = c.warranty_months
    form.qty = Number(c.qty) || 1
    form.status = c.status
    form.notes = c.notes || ''
    resetSpecs(c.specs || {})
    showForm.value = true
}

const save = () => {
    form.specs = { ...specs }
    if (!form.name) form.name = composedName.value
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

const onSerialInput = (event: Event) => {
    form.warranty_number = normalizeScanLayout((event.target as HTMLInputElement).value)
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
                        Конструктор названия · скан серийника
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
                Сканер → серийный номер. Название соберите полями (после 3 символов — подсказки).
            </div>

            <div class="overflow-x-auto border border-white/5 rounded-2xl">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-widest text-white/30 border-b border-white/5">
                        <tr>
                            <th class="px-4 py-3">Тип</th>
                            <th class="px-4 py-3">Наименование</th>
                            <th class="px-4 py-3">Серийный номер</th>
                            <th class="px-4 py-3">Поставщик</th>
                            <th class="px-4 py-3">Закупка</th>
                            <th class="px-4 py-3">Гарантия</th>
                            <th class="px-4 py-3">Кол-во</th>
                            <th class="px-4 py-3">Кто принял</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in components" :key="c.id" class="border-b border-white/5 hover:bg-white/[0.02]">
                            <td class="px-4 py-3 text-amber-400/80">{{ types[c.type] || c.type }}</td>
                            <td class="px-4 py-3 font-black uppercase">{{ c.name }}</td>
                            <td class="px-4 py-3 text-cyan-400/80 font-mono">{{ c.warranty_number || '—' }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3">{{ money(c.purchase_price) }}</td>
                            <td class="px-4 py-3 text-white/40">{{ c.warranty_months ? c.warranty_months + ' мес.' : '—' }}</td>
                            <td class="px-4 py-3 font-black text-amber-400">{{ c.qty }}</td>
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

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4 overflow-y-auto" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-xl space-y-4 my-8" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">{{ form.id ? 'Редактировать' : 'Приход комплектующего' }}</h3>

                <div>
                    <label :class="labelClass">Серийный номер</label>
                    <input
                        ref="serialInput"
                        v-model="form.warranty_number"
                        data-scan-capture
                        placeholder="Сканер подставит сюда"
                        :class="fieldClass + ' text-cyan-400 border-cyan-500/40'"
                        @input="onSerialInput"
                    />
                </div>

                <div>
                    <label :class="labelClass">Тип комплектующего</label>
                    <select v-model="form.type" :class="fieldClass" required>
                        <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>

                <div class="rounded-2xl border border-white/10 p-4 space-y-3">
                    <div class="text-[10px] uppercase tracking-widest text-amber-400/80 font-black">Конструктор названия</div>
                    <div class="grid md:grid-cols-2 gap-3">
                        <SuggestInput
                            v-for="field in activeFields"
                            :key="form.type + '-' + field.key"
                            v-model="specs[field.key]"
                            :label="field.label"
                            :suggestions="suggestionsFor(field)"
                            :input-class="fieldClass"
                            :label-class="labelClass"
                            @search="(q) => onSuggestSearch(field, q)"
                        />
                    </div>
                    <div class="text-xs text-white/50 pt-1">
                        Итог:
                        <span class="text-amber-300 font-black uppercase ml-2">{{ composedName || '—' }}</span>
                    </div>
                </div>

                <div>
                    <label :class="labelClass">Название (можно поправить вручную)</label>
                    <input v-model="form.name" :placeholder="composedName || 'Соберётся из полей'" :class="fieldClass" @input="nameTouched = true" />
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

                <div>
                    <label :class="labelClass">Срок гарантии, мес.</label>
                    <input v-model.number="form.warranty_months" type="number" min="0" :class="fieldClass" />
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
