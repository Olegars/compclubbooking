<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    products: any[]
    movements: any[]
    categories: string[]
    canManageCatalog: boolean
    canAdjustStock: boolean
    canInventory: boolean
}>()

const categoryLabel: Record<string, string> = {
    component: 'Комплектующие',
    pc: 'ПК / сборки',
    peripheral: 'Периферия',
    service: 'Услуги',
}

const money = (v: any) => Number(v || 0).toLocaleString('ru-RU') + ' ₽'

const form = useForm({
    id: null as number | null,
    name: '',
    sku: '',
    category: 'component',
    price: 0,
    cost: null as number | null,
    stock: 0,
    serial_tracked: false,
    is_active: true,
})

const adjust = useForm({
    store_product_id: null as number | null,
    type: 'receive' as 'receive' | 'write_off' | 'inventory',
    qty: 1,
    reason: '',
})

const showForm = ref(false)
const showAdjust = ref(false)

const openCreate = () => {
    form.reset()
    form.id = null
    form.category = 'component'
    form.is_active = true
    showForm.value = true
}

const openEdit = (p: any) => {
    form.id = p.id
    form.name = p.name
    form.sku = p.sku || ''
    form.category = p.category
    form.price = Number(p.price)
    form.cost = p.cost == null ? null : Number(p.cost)
    form.stock = Number(p.stock)
    form.serial_tracked = Boolean(p.serial_tracked)
    form.is_active = Boolean(p.is_active)
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
    if (!confirm('Удалить товар?')) return
    router.delete(`/admin/store/warehouse/${id}`)
}

const openAdjust = (p: any, type: 'receive' | 'write_off' | 'inventory') => {
    adjust.store_product_id = p.id
    adjust.type = type
    adjust.qty = type === 'inventory' ? Number(p.stock) : 1
    adjust.reason = ''
    showAdjust.value = true
}

const submitAdjust = () => {
    adjust.post('/admin/store/warehouse/adjust', { onSuccess: () => { showAdjust.value = false } })
}

const activeProducts = computed(() => props.products || [])
</script>

<template>
    <Head title="REACTOR | Склад магазина" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 pb-20 px-4 font-mono">
            <div class="flex justify-between items-end border-b border-white/10 pb-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic tracking-tighter">Store <span class="text-amber-400">Warehouse</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Склад компьютеров и комплектующих</p>
                </div>
                <button v-if="canManageCatalog" @click="openCreate"
                        class="px-6 py-4 bg-amber-500 text-black font-black uppercase tracking-widest text-[10px] rounded-2xl">
                    + Товар
                </button>
            </div>

            <div class="grid gap-3">
                <div v-for="p in activeProducts" :key="p.id"
                     class="border border-white/5 bg-[#080808] rounded-2xl p-5 flex flex-wrap gap-4 items-center justify-between">
                    <div>
                        <div class="font-black uppercase text-sm">{{ p.name }}</div>
                        <div class="text-[10px] text-white/30 uppercase tracking-widest mt-1">
                            {{ categoryLabel[p.category] || p.category }}
                            <span v-if="p.sku"> · SKU {{ p.sku }}</span>
                            <span v-if="!p.is_active" class="text-red-400"> · off</span>
                        </div>
                    </div>
                    <div class="text-right text-xs">
                        <div class="font-black">{{ money(p.price) }}</div>
                        <div class="text-white/40 mt-1">Остаток: <span class="text-amber-400 font-black">{{ p.stock }}</span></div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="canAdjustStock" @click="openAdjust(p, 'receive')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50 hover:text-white">Приход</button>
                        <button v-if="canManageCatalog" @click="openAdjust(p, 'write_off')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50 hover:text-white">Списание</button>
                        <button v-if="canInventory" @click="openAdjust(p, 'inventory')"
                                class="px-3 py-2 rounded-xl border border-white/10 text-[10px] uppercase font-black text-white/50 hover:text-white">Инвент.</button>
                        <button v-if="canManageCatalog" @click="openEdit(p)"
                                class="px-3 py-2 rounded-xl border border-amber-500/30 text-[10px] uppercase font-black text-amber-400">Edit</button>
                        <button v-if="canManageCatalog" @click="remove(p.id)"
                                class="px-3 py-2 rounded-xl border border-red-500/30 text-[10px] uppercase font-black text-red-400">Del</button>
                    </div>
                </div>
                <div v-if="!activeProducts.length" class="text-white/30 text-sm py-10 text-center">Склад пуст</div>
            </div>

            <div>
                <h2 class="text-[10px] uppercase tracking-[0.3em] text-white/30 font-black mb-4">Последние движения</h2>
                <div class="space-y-2">
                    <div v-for="m in movements" :key="m.id" class="text-xs border border-white/5 rounded-xl px-4 py-3 flex justify-between gap-4">
                        <span class="text-white/50">{{ m.product?.name || '—' }} · {{ m.type }} · {{ m.admin?.name || '—' }}</span>
                        <span :class="m.qty >= 0 ? 'text-[#22c55e]' : 'text-red-400'" class="font-black">{{ m.qty > 0 ? '+' : '' }}{{ m.qty }} → {{ m.stock_after }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showForm = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-lg space-y-4" @submit.prevent="save">
                <h3 class="font-black uppercase italic text-xl">{{ form.id ? 'Редактировать' : 'Новый товар' }}</h3>
                <input v-model="form.name" placeholder="Название" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="form.sku" placeholder="SKU" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <select v-model="form.category" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm">
                    <option v-for="c in categories" :key="c" :value="c">{{ categoryLabel[c] || c }}</option>
                </select>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model.number="form.price" type="number" min="0" step="0.01" placeholder="Цена" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                    <input v-model.number="form.cost" type="number" min="0" step="0.01" placeholder="Себестоимость" class="bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                </div>
                <input v-if="!form.id" v-model.number="form.stock" type="number" min="0" placeholder="Остаток" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <label class="flex items-center gap-2 text-xs text-white/50"><input v-model="form.serial_tracked" type="checkbox" /> Серийный учёт</label>
                <label class="flex items-center gap-2 text-xs text-white/50"><input v-model="form.is_active" type="checkbox" /> Активен</label>
                <div class="flex gap-3 justify-end pt-2">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showForm = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="form.processing">Сохранить</button>
                </div>
            </form>
        </div>

        <div v-if="showAdjust" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4" @click.self="showAdjust = false">
            <form class="bg-[#0a0a0a] border border-white/10 rounded-3xl p-8 w-full max-w-md space-y-4" @submit.prevent="submitAdjust">
                <h3 class="font-black uppercase italic text-xl">Движение склада</h3>
                <div class="text-xs text-white/40 uppercase tracking-widest">{{ adjust.type }}</div>
                <input v-model.number="adjust.qty" type="number" min="1" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" required />
                <input v-model="adjust.reason" placeholder="Причина" class="w-full bg-black border border-white/10 rounded-xl px-4 py-3 text-sm" />
                <div class="flex gap-3 justify-end">
                    <button type="button" class="px-4 py-3 text-[10px] uppercase font-black text-white/40" @click="showAdjust = false">Отмена</button>
                    <button class="px-6 py-3 bg-amber-500 text-black text-[10px] uppercase font-black rounded-xl" :disabled="adjust.processing">OK</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
