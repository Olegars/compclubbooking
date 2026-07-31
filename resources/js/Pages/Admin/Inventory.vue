<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'

const props = defineProps<{
    canManageCatalog?: boolean
    canAdjustStock?: boolean
    reasonCodes?: { code: string, label: string }[]
    products?: any[]
}>()

const canManageCatalog = computed(() => Boolean(props.canManageCatalog))
const canAdjustStock = computed(() => props.canAdjustStock !== false)
const { receiveMode, enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()

const cloneProducts = (list: any[] = []) => list.map((p) => ({
    ...p,
    stock: Number(p.stock) || 0,
    id: Number(p.id),
    requires_marking: Boolean(p.requires_marking),
}))

const products = ref<any[]>(cloneProducts(props.products || []))
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')
const isLoading = ref(!props.products?.length)
const scannedId = ref<number | null>(null)
const lastScannedName = ref('')
const stockError = ref('')
const receiveProductId = ref<number | null>(null)

const isModalOpen = ref(false)
const isWriteOffOpen = ref(false)
const adjustMode = ref<'write_off' | 'comp'>('write_off')
const writeOffCode = ref('')
const writeOffProductId = ref<number | null>(null)
const writeOffQty = ref(1)
const writeOffReasonCode = ref('spoilage')
const writeOffReason = ref('')
const isProcessing = ref(false)
const imagePreview = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const reasonOptions = computed(() => {
    const list = props.reasonCodes?.length
        ? props.reasonCodes
        : [
            { code: 'spoilage', label: 'Брак / порча' },
            { code: 'expired', label: 'Просрочка' },
            { code: 'broken', label: 'Разбито / бой' },
            { code: 'comp', label: 'Угощение / бесплатно' },
            { code: 'other', label: 'Иное' },
        ]
    if (adjustMode.value === 'comp') {
        return list.filter(r => r.code === 'comp' || r.code === 'other')
    }
    return list.filter(r => r.code !== 'comp')
})

const writeOffProduct = computed(() =>
    products.value.find(p => Number(p.id) === Number(writeOffProductId.value)) || null
)

const writeOffNeedsKm = computed(() => Boolean(writeOffProduct.value?.requires_marking))


const form = ref({
    id: null as number | null,
    name: '',
    category: 'Снэки',
    price: 100,
    stock: 0,
    barcode: '',
    requires_marking: false,
    image: null as File | null,
    current_image_url: '',
})

const applyProductStock = (productPayload: any) => {
    if (!productPayload?.id) return
    const idx = products.value.findIndex(p => Number(p.id) === Number(productPayload.id))
    if (idx === -1) {
        products.value.push(cloneProducts([productPayload])[0])
        return
    }
    products.value[idx] = {
        ...products.value[idx],
        ...cloneProducts([productPayload])[0],
    }
}

const processReceiveScan = async (code: string) => {
    if (isModalOpen.value || isWriteOffOpen.value) return

    stockError.value = ''
    try {
        const payload: Record<string, any> = { code }
        if (receiveProductId.value) payload.product_id = receiveProductId.value

        const { data } = await axios.post('/admin/api/inventory/receive-scan', payload)
        applyProductStock(data.product)

        lastScannedName.value = `${data.product?.name || 'Товар'} · ${data.mode === 'marking' ? 'КМ' : 'EAN'} +1`
        scannedId.value = Number(data.product?.id)
        document.getElementById(`product-${data.product?.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })

        setTimeout(() => {
            scannedId.value = null
            lastScannedName.value = ''
        }, 2000)
    } catch (e: any) {
        const msg = e?.response?.data?.message || 'Скан не принят'
        stockError.value = msg
        if (canManageCatalog.value && e?.response?.status === 404) {
            if (confirm(`${msg}\nЗавести новую позицию с этим кодом как GTIN/EAN?`)) {
                openModal()
                form.value.barcode = code
            }
        }
        setTimeout(() => { stockError.value = '' }, 4000)
    }
}

const setReceiveMode = (on: boolean) => {
    if (on) {
        enableReceiveMode(processReceiveScan)
    } else {
        disableReceiveMode()
        receiveProductId.value = null
    }
}

const selectReceiveTarget = (id: number | null) => {
    if (receiveProductId.value === id) {
        receiveProductId.value = null
        return
    }
    receiveProductId.value = id
    if (id) setReceiveMode(true)
}

const fetchProducts = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/admin/api/inventory/products')
        const list = Array.isArray(data) ? data : (data.products || [])
        products.value = cloneProducts(list)
    } catch (e) {
        console.error('Warehouse Link Lost')
    } finally {
        isLoading.value = false
    }
}

const openModal = (product: any = null) => {
    if (!canManageCatalog.value) return
    if (product) {
        form.value = {
            id: product.id,
            name: product.name,
            category: product.category,
            price: Math.floor(product.price),
            stock: Number(product.stock),
            barcode: product.barcode || '',
            requires_marking: Boolean(product.requires_marking),
            image: null,
            current_image_url: product.image || '',
        }
        imagePreview.value = product.image
            ? (product.image.startsWith('/') ? product.image : '/' + product.image)
            : null
    } else {
        form.value = {
            id: null,
            name: '',
            category: 'Снэки',
            price: 100,
            stock: 0,
            barcode: '',
            requires_marking: false,
            image: null,
            current_image_url: '',
        }
        imagePreview.value = null
    }
    isModalOpen.value = true
}

const triggerFileInput = () => fileInput.value?.click()

const handleFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement
    if (target.files && target.files[0]) {
        form.value.image = target.files[0]
        imagePreview.value = URL.createObjectURL(target.files[0])
    }
}

const saveProduct = async () => {
    if (!canManageCatalog.value) return
    isProcessing.value = true
    const formData = new FormData()
    Object.entries(form.value).forEach(([key, val]) => {
        if (val !== null && key !== 'current_image_url') {
            if (key === 'requires_marking') {
                formData.append(key, val ? '1' : '0')
            } else {
                formData.append(key, val as any)
            }
        }
    })

    try {
        await axios.post('/admin/api/inventory/save', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        await fetchProducts()
        isModalOpen.value = false
    } catch (e) {
        alert('Sync Error')
    } finally {
        isProcessing.value = false
    }
}

const deleteProduct = async (id: number) => {
    if (!canManageCatalog.value) return
    if (!confirm('Снять позицию с учёта и удалить из базы?')) return
    try {
        await axios.delete(`/admin/api/inventory/delete/${id}`)
        products.value = products.value.filter(p => p.id !== id)
    } catch (e) {
        alert('Sync Error')
    }
}

const openAdjustModal = (mode: 'write_off' | 'comp' = 'write_off', product: any = null) => {
    if (!canAdjustStock.value) return
    adjustMode.value = mode
    writeOffCode.value = ''
    writeOffQty.value = 1
    writeOffReason.value = ''
    writeOffReasonCode.value = mode === 'comp' ? 'comp' : 'spoilage'
    writeOffProductId.value = product?.id ?? null
    isWriteOffOpen.value = true
}

const submitWriteOff = async () => {
    if (!writeOffProductId.value && !writeOffCode.value.trim()) {
        alert('Выберите товар или отсканируйте КМ')
        return
    }
    if (writeOffNeedsKm.value || (!writeOffProduct.value && writeOffCode.value.trim())) {
        if (!writeOffCode.value.trim()) {
            alert('Отсканируйте DataMatrix списываемой единицы')
            return
        }
    } else if (!writeOffProductId.value) {
        alert('Выберите товар')
        return
    }

    isProcessing.value = true
    try {
        if (writeOffNeedsKm.value || (!writeOffProduct.value && writeOffCode.value.trim())) {
            const { data } = await axios.post('/admin/api/inventory/write-off', {
                code: writeOffCode.value.trim(),
                type: adjustMode.value,
                reason_code: writeOffReasonCode.value,
                reason: writeOffReason.value || undefined,
            })
            applyProductStock(data.product)
        } else {
            const { data } = await axios.post('/admin/api/inventory/adjust', {
                product_id: writeOffProductId.value,
                qty: writeOffQty.value,
                type: adjustMode.value,
                reason_code: adjustMode.value === 'comp' ? 'comp' : writeOffReasonCode.value,
                reason: writeOffReason.value || undefined,
            })
            applyProductStock(data.product)
        }
        isWriteOffOpen.value = false
        writeOffCode.value = ''
        writeOffProductId.value = null
    } catch (e: any) {
        alert(e?.response?.data?.message || 'Ошибка списания')
    } finally {
        isProcessing.value = false
    }
}

onMounted(() => {
    if (!products.value.length) fetchProducts()
    else isLoading.value = false
})
onUnmounted(() => {
    disableReceiveMode()
})

const filteredProducts = computed(() => {
    if (activeCategory.value === 'Все') return products.value
    return products.value.filter(p => p.category === activeCategory.value)
})

const receiveTargetName = computed(() => {
    if (!receiveProductId.value) return null
    return products.value.find(p => p.id === receiveProductId.value)?.name || null
})
</script>

<template>
    <Head title="LOGISTICS // MARKING" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 relative">

            <Transition name="slide">
                <div v-if="lastScannedName" class="fixed top-24 right-10 z-[100] bg-cyan-500 text-black px-8 py-4 rounded-2xl font-black uppercase italic shadow-[0_0_50px_rgba(6,182,212,0.5)] flex items-center gap-4 border-2 border-white/20">
                    <span class="text-2xl">⚡</span>
                    <div>
                        <div class="text-[10px] leading-none opacity-70">RECEIVE OK</div>
                        <div>{{ lastScannedName }}</div>
                    </div>
                </div>
            </Transition>

            <Transition name="slide">
                <div v-if="stockError" class="fixed top-24 right-10 z-[100] bg-red-600 text-white px-8 py-4 rounded-2xl font-black uppercase italic shadow-[0_0_50px_rgba(220,38,38,0.45)] border-2 border-white/20 max-w-md">
                    {{ stockError }}
                </div>
            </Transition>

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-r from-cyan-500/5 to-transparent pointer-events-none"></div>
                <div class="relative z-10">
                    <h1 class="text-4xl font-black uppercase italic text-cyan-500 tracking-tighter">Reactor <span class="text-white">Warehouse</span></h1>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="w-2 h-2 bg-cyan-500 rounded-full animate-pulse shadow-[0_0_10px_#06b6d4]"></div>
                        <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black italic">
                            <template v-if="receiveMode">
                                {{ receiveTargetName ? `Приёмка → ${receiveTargetName}` : 'Режим приёмки · сканируйте КМ / EAN' }}
                            </template>
                            <template v-else>
                                Скан списывает в заказ · для приёмки включите режим
                            </template>
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4 relative z-10">
                    <button type="button" @click="setReceiveMode(!receiveMode)"
                            class="px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all cursor-pointer"
                            :class="receiveMode
                                ? 'bg-amber-500 text-black border-amber-500 shadow-[0_0_20px_rgba(245,158,11,0.35)]'
                                : 'border-white/10 text-white/40 hover:text-white'">
                        {{ receiveMode ? 'Приёмка · вкл' : 'Режим приёмки' }}
                    </button>
                    <div class="flex gap-2 p-1.5 bg-white/5 rounded-2xl border border-white/5 backdrop-blur-md">
                        <button v-for="cat in categories" :key="cat" type="button" @click="activeCategory = cat"
                                class="px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all cursor-pointer"
                                :class="activeCategory === cat ? 'bg-cyan-500 text-black shadow-[0_0_20px_rgba(6,182,212,0.4)]' : 'text-white/40 hover:text-white'">
                            {{ cat }}
                        </button>
                    </div>
                    <button v-if="receiveProductId" type="button" @click="selectReceiveTarget(null)"
                            class="px-5 py-3 border border-white/10 text-white/40 hover:text-white rounded-2xl text-[10px] font-black uppercase cursor-pointer">
                        Сброс цели
                    </button>
                    <button v-if="canAdjustStock" type="button" @click="openAdjustModal('write_off')"
                            class="px-5 py-3 border border-red-500/30 text-red-400 hover:bg-red-500 hover:text-black rounded-2xl text-[10px] font-black uppercase cursor-pointer">
                        Списание
                    </button>
                    <button v-if="canAdjustStock" type="button" @click="openAdjustModal('comp')"
                            class="px-5 py-3 border border-amber-500/30 text-amber-400 hover:bg-amber-500 hover:text-black rounded-2xl text-[10px] font-black uppercase cursor-pointer">
                        Угощение
                    </button>
                    <button v-if="canManageCatalog" type="button" @click="openModal()"
                            class="px-8 py-4 bg-cyan-500 hover:bg-cyan-400 text-black font-black uppercase rounded-2xl shadow-[0_0_30px_rgba(6,182,212,0.2)] transition-all italic text-xs cursor-pointer">
                        + Manual Add
                    </button>
                </div>
            </div>

            <div v-if="isLoading" class="flex justify-center py-40">
                <div class="w-16 h-16 border-4 border-cyan-500/10 border-t-cyan-500 rounded-full animate-spin"></div>
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <div v-for="item in filteredProducts" :key="item.id" :id="`product-${item.id}`"
                     class="bg-[#050505] border rounded-[1.125rem] p-8 group transition-all duration-500 flex flex-col relative overflow-hidden"
                     :class="[
                        receiveProductId === item.id ? 'border-cyan-500 ring-2 ring-cyan-500/40' : '',
                        item.stock <= 0 ? 'border-red-500/50 bg-red-950/20 opacity-80' : item.stock <= 5 ? 'border-red-500/40 bg-red-900/5' : 'border-white/5',
                        scannedId === item.id ? 'ring-4 ring-cyan-500 border-cyan-500 scale-[1.03] z-20 shadow-[0_0_60px_rgba(6,182,212,0.4)] bg-cyan-500/5' : ''
                     ]">

                    <div v-if="scannedId === item.id" class="absolute inset-0 bg-cyan-500/10 animate-pulse pointer-events-none"></div>
                    <div v-if="item.requires_marking" class="absolute top-4 left-4 z-10 px-3 py-1 rounded-lg bg-violet-600/90 text-white text-[9px] font-black uppercase tracking-widest">
                        КМ
                    </div>
                    <div v-if="item.stock <= 0" class="absolute top-4 right-4 z-10 px-3 py-1 rounded-lg bg-red-600 text-white text-[9px] font-black uppercase tracking-widest">
                        Нет в наличии
                    </div>

                    <div class="aspect-square bg-white/5 rounded-[1rem] mb-6 flex items-center justify-center border border-white/5 relative overflow-hidden group-hover:bg-cyan-500/5 transition-all">
                        <img :src="item.image ? (item.image.startsWith('/') ? item.image : '/' + item.image) : '/images/shop/default.png'"
                             :alt="item.name" loading="lazy" decoding="async"
                             class="w-3/4 h-3/4 object-contain transition-transform duration-700 group-hover:scale-110" />
                        <div class="absolute top-5 right-5 px-4 py-1.5 bg-black/80 backdrop-blur-xl rounded-full border border-white/10 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="item.stock <= 5 ? 'bg-red-500 animate-ping' : 'bg-cyan-500'"></span>
                            <span class="text-[11px] font-black text-white italic">{{ item.stock }} <span class="opacity-30">шт</span></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-start mb-3">
                        <div class="text-base font-black text-white uppercase italic tracking-tighter leading-tight">{{ item.name }}</div>
                        <div class="text-2xl font-black text-cyan-500 italic tracking-tighter">{{ Math.floor(item.price) }}₽</div>
                    </div>

                    <button type="button"
                            @click="selectReceiveTarget(receiveProductId === item.id ? null : item.id)"
                            class="mb-4 w-full py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border transition-all cursor-pointer"
                            :class="receiveProductId === item.id
                                ? 'bg-cyan-500 text-black border-cyan-500'
                                : 'bg-white/5 text-white/40 border-white/10 hover:text-white'">
                        {{ receiveProductId === item.id ? 'Цель приёмки · активна' : 'Сканировать в эту позицию' }}
                    </button>

                    <div class="mb-4 px-3 py-2 rounded-xl border border-dashed border-cyan-500/20 text-center text-[9px] uppercase font-black tracking-widest text-cyan-500/50">
                        {{ item.requires_marking ? 'Приёмка: уникальный DataMatrix' : 'Приёмка: EAN → +1' }}
                    </div>

                    <div v-if="canAdjustStock" class="mt-auto flex gap-3">
                        <button v-if="canManageCatalog" type="button" @click="openModal(item)" class="flex-1 py-4 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/30 hover:text-white transition-all cursor-pointer">Изменить</button>
                        <button type="button" @click="openAdjustModal('write_off', item)" class="flex-1 py-4 bg-red-500/10 border border-red-500/20 text-red-400 rounded-2xl text-[10px] font-black uppercase hover:bg-red-500 hover:text-black transition-all cursor-pointer">Списать</button>
                        <button v-if="canManageCatalog" type="button" @click="deleteProduct(item.id)" class="px-5 py-4 bg-red-500/5 border border-red-500/10 text-red-500 rounded-2xl hover:bg-red-500 hover:text-black transition-all cursor-pointer">🗑️</button>
                    </div>
                    <div v-else class="mt-auto py-3 text-center text-[9px] uppercase font-black tracking-widest text-white/25">
                        Выдача маркировки — в очереди заказов
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isModalOpen = false"></div>
                <div class="relative w-full max-w-xl bg-[#050505] border-2 border-cyan-500/30 rounded-[1.25rem] p-12 shadow-[0_0_120px_rgba(6,182,212,0.2)]">
                    <h2 class="text-cyan-500 text-3xl font-black uppercase italic mb-10 tracking-tighter">{{ form.id ? 'Update SKU' : 'Register SKU' }}</h2>

                    <div class="space-y-6">
                        <div class="flex flex-col items-center justify-center border-2 border-dashed border-white/10 rounded-[0.875rem] p-6 bg-black/50 hover:border-cyan-500/40 transition-all cursor-pointer group" @click="triggerFileInput">
                            <input type="file" ref="fileInput" class="hidden" accept="image/*" @change="handleFileChange" />
                            <div v-if="imagePreview" class="w-32 h-32 relative rounded-xl overflow-hidden bg-white/5 border border-white/10">
                                <img :src="imagePreview" class="w-full h-full object-contain" />
                            </div>
                            <div v-else class="text-center py-4">
                                <span class="text-3xl block mb-2">📸</span>
                                <span class="text-[10px] text-white/40 font-black uppercase tracking-widest">Загрузить аватар</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Название</label>
                                <input v-model="form.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl px-5 py-4 text-white font-bold focus:border-cyan-500 outline-none" />
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">GTIN / EAN</label>
                                <input v-model="form.barcode" type="text" placeholder="01… / EAN-13" class="w-full bg-black border border-cyan-500/50 rounded-2xl px-5 py-4 text-cyan-500 font-bold focus:border-cyan-500 outline-none" />
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-6">
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Сектор</label>
                                <select v-model="form.category" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold outline-none">
                                    <option v-for="c in categories.slice(1)" :key="c" :value="c">{{ c }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Цена</label>
                                <input v-model.number="form.price" type="number" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" />
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Остаток</label>
                                <input v-model.number="form.stock" type="number" :disabled="form.requires_marking"
                                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold disabled:opacity-40" />
                            </div>
                        </div>

                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <input v-model="form.requires_marking" type="checkbox" class="size-5 accent-cyan-500" />
                            <span class="text-[11px] font-black uppercase tracking-widest text-white/70">
                                Требует DataMatrix (Честный знак)
                            </span>
                        </label>
                        <p v-if="form.requires_marking" class="text-[10px] text-white/30">
                            Остаток считается по принятым КМ. В GTIN укажите код товара для авто-привязки скана.
                        </p>
                    </div>

                    <div class="mt-12 flex gap-4">
                        <button type="button" @click="isModalOpen = false" class="flex-1 py-5 border border-white/10 text-white/30 uppercase font-black rounded-2xl hover:text-white transition-all cursor-pointer">Abort</button>
                        <button type="button" @click="saveProduct" :disabled="isProcessing" class="flex-[2] py-5 bg-cyan-500 hover:bg-cyan-400 text-black uppercase font-black italic rounded-2xl cursor-pointer disabled:opacity-40">
                            Confirm Sync
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="isWriteOffOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isWriteOffOpen = false"></div>
                <div class="relative w-full max-w-md bg-[#050505] border-2 rounded-[1rem] p-10 space-y-5"
                     :class="adjustMode === 'comp' ? 'border-amber-500/30' : 'border-red-500/30'">
                    <h2 class="text-2xl font-black uppercase italic tracking-tighter"
                        :class="adjustMode === 'comp' ? 'text-amber-400' : 'text-red-500'">
                        {{ adjustMode === 'comp' ? 'Угощение / бесплатно' : 'Списание со склада' }}
                    </h2>
                    <p class="text-[10px] text-white/30 uppercase tracking-widest font-black">
                        С причиной — чтобы не всплыло как расхождение на пересменке
                    </p>

                    <div class="flex gap-2">
                        <button type="button" @click="adjustMode = 'write_off'; writeOffReasonCode = 'spoilage'"
                                class="flex-1 py-3 rounded-xl text-[10px] font-black uppercase cursor-pointer border"
                                :class="adjustMode === 'write_off' ? 'bg-red-600 text-white border-red-600' : 'border-white/10 text-white/40'">
                            Списание
                        </button>
                        <button type="button" @click="adjustMode = 'comp'; writeOffReasonCode = 'comp'"
                                class="flex-1 py-3 rounded-xl text-[10px] font-black uppercase cursor-pointer border"
                                :class="adjustMode === 'comp' ? 'bg-amber-500 text-black border-amber-500' : 'border-white/10 text-white/40'">
                            Угощение
                        </button>
                    </div>

                    <div>
                        <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Товар</label>
                        <select v-model.number="writeOffProductId" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold outline-none">
                            <option :value="null">— выберите —</option>
                            <option v-for="p in products" :key="p.id" :value="p.id">
                                {{ p.name }} ({{ p.stock }} шт){{ p.requires_marking ? ' · КМ' : '' }}
                            </option>
                        </select>
                    </div>

                    <div v-if="writeOffNeedsKm">
                        <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">DataMatrix единицы</label>
                        <input v-model="writeOffCode" type="text" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" placeholder="Сканируйте КМ" />
                    </div>
                    <div v-else-if="writeOffProductId" class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Количество</label>
                            <input v-model.number="writeOffQty" type="number" min="1" :max="writeOffProduct?.stock || 999"
                                   class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" />
                        </div>
                        <div class="flex items-end pb-2 text-[10px] text-white/30 uppercase font-black">
                            На складе: {{ writeOffProduct?.stock ?? 0 }}
                        </div>
                    </div>

                    <div>
                        <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Причина</label>
                        <select v-model="writeOffReasonCode" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold outline-none">
                            <option v-for="r in reasonOptions" :key="r.code" :value="r.code">{{ r.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Комментарий (необязательно)</label>
                        <input v-model="writeOffReason" type="text" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" placeholder="Детали…" />
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="isWriteOffOpen = false" class="flex-1 py-4 border border-white/10 text-white/40 rounded-xl font-black uppercase text-[10px] cursor-pointer">Отмена</button>
                        <button type="button" @click="submitWriteOff" :disabled="isProcessing"
                                class="flex-1 py-4 rounded-xl font-black uppercase text-[10px] cursor-pointer disabled:opacity-40"
                                :class="adjustMode === 'comp' ? 'bg-amber-500 text-black' : 'bg-red-600 text-white'">
                            {{ adjustMode === 'comp' ? 'Выдать бесплатно' : 'Списать' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: zoom-in 0.4s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(20px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.slide-enter-active, .slide-leave-active { transition: all 0.5s ease; }
.slide-enter-from { transform: translateX(100%); opacity: 0; }
.slide-leave-to { transform: translateX(100%); opacity: 0; }
input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
</style>
