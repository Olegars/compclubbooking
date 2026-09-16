<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useAdminBarcodeScanner } from '@/Composables/useAdminBarcodeScanner'
import { useClubName } from '@/Composables/useClubName'

const props = defineProps<{
    canManageCatalog?: boolean
    canAdjustStock?: boolean
    reasonCodes?: { code: string, label: string }[]
    products?: any[]
    suppliers?: { id: number, name: string }[]
}>()

const canManageCatalog = computed(() => Boolean(props.canManageCatalog))
const canAdjustStock = computed(() => props.canAdjustStock !== false)
const suppliers = computed(() => props.suppliers || [])
const { receiveMode, enableReceiveMode, disableReceiveMode } = useAdminBarcodeScanner()
const clubName = useClubName()

const cloneProducts = (list: any[] = []) => list.map((p) => ({
    ...p,
    stock: Number(p.stock) || 0,
    min_stock: p.min_stock == null ? null : Number(p.min_stock),
    cost_price: p.cost_price == null ? null : Number(p.cost_price),
    price: Number(p.price) || 0,
    id: Number(p.id),
    requires_marking: Boolean(p.requires_marking),
    supplier_id: p.supplier_id == null ? null : Number(p.supplier_id),
}))

const products = ref<any[]>(cloneProducts(props.products || []))
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')
const isLoading = ref(!props.products?.length)
const scannedId = ref<number | null>(null)
const lastScannedName = ref('')
const stockError = ref('')
const receiveProductId = ref<number | null>(null)
const receiveUnitCost = ref<number | null>(null)
const receiveSupplierId = ref<number | null>(null)
const receiveInvoiceNumber = ref<string | null>(null)

type InvoiceLine = {
    name: string
    qty: number
    scanned_qty: number
    unit_cost: number | null
    barcode: string | null
    amount: number | null
    product_id: number | null
    product_name: string | null
    requires_marking: boolean
    match: 'barcode' | 'name' | 'none'
    note: string | null
}

type InvoiceDraft = {
    invoice_number: string | null
    invoice_date: string | null
    supplier_name: string | null
    supplier_inn: string | null
    supplier_id: number | null
    lines: InvoiceLine[]
    unmatched: number
    marked: number
}

const invoice = ref<InvoiceDraft | null>(null)
const invoiceExtras = ref<{ product_id: number, name: string, qty: number }[]>([])
const invoiceInput = ref<HTMLInputElement | null>(null)
const invoiceBusy = ref(false)

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

const stockThreshold = (item: any) => (item.min_stock != null ? Number(item.min_stock) : 5)
const isLow = (item: any) => Number(item.stock) <= stockThreshold(item)

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
    cost_price: null as number | null,
    stock: 0,
    min_stock: null as number | null,
    barcode: '',
    requires_marking: false,
    supplier_id: null as number | null,
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

const digitsOnly = (value: string | number | null | undefined) => String(value ?? '').replace(/\D/g, '')
const barcodesEqual = (a?: string | null, b?: string | null) => {
    const left = digitsOnly(a)
    const right = digitsOnly(b)
    if (left.length < 8 || right.length < 8) return false
    return left === right || left.replace(/^0+/, '') === right.replace(/^0+/, '')
}

const findInvoiceLine = (product: any, code: string): InvoiceLine | null => {
    if (!invoice.value) return null
    const id = Number(product?.id)
    const barcode = digitsOnly(product?.barcode || code)
    const under = invoice.value.lines.find(l => Number(l.product_id) === id && Number(l.scanned_qty) < Number(l.qty))
    if (under) return under
    const same = invoice.value.lines.find(l => Number(l.product_id) === id)
    if (same) return same
    if (barcode.length >= 8) {
        return invoice.value.lines.find(l => barcodesEqual(l.barcode, barcode) && Number(l.scanned_qty) < Number(l.qty))
            || invoice.value.lines.find(l => barcodesEqual(l.barcode, barcode))
            || null
    }
    return null
}

const applyScanToInvoice = (product: any, code: string) => {
    if (!invoice.value) return 'ok'
    const line = findInvoiceLine(product, code)
    if (!line) {
        const id = Number(product?.id)
        const extra = invoiceExtras.value.find(x => x.product_id === id)
        if (extra) extra.qty += 1
        else invoiceExtras.value.push({ product_id: id, name: product?.name || 'Товар', qty: 1 })
        return 'extra'
    }
    if (!line.product_id) {
        line.product_id = Number(product.id)
        line.product_name = product.name || line.product_name
        line.requires_marking = Boolean(product.requires_marking)
        line.match = line.match === 'none' ? 'barcode' : line.match
        line.note = product.requires_marking ? 'Сканируйте каждый КМ' : null
    }
    line.scanned_qty = Number(line.scanned_qty || 0) + 1
    return Number(line.scanned_qty) > Number(line.qty) ? 'over' : 'ok'
}

const processReceiveScan = async (code: string) => {
    if (isModalOpen.value || isWriteOffOpen.value) return

    stockError.value = ''
    try {
        const previewProduct = receiveProductId.value
            ? products.value.find(p => Number(p.id) === Number(receiveProductId.value))
            : products.value.find(p => barcodesEqual(p.barcode, code))
        const invoiceLine = previewProduct ? findInvoiceLine(previewProduct, code) : null

        const payload: Record<string, any> = { code }
        if (receiveProductId.value) payload.product_id = receiveProductId.value
        else if (invoiceLine?.product_id) payload.product_id = invoiceLine.product_id

        const lineCost = invoiceLine?.unit_cost
        const cost = lineCost != null && Number(lineCost) >= 0 ? Number(lineCost) : receiveUnitCost.value
        if (cost != null && cost >= 0) payload.unit_cost = cost

        const supplierId = invoice.value?.supplier_id || receiveSupplierId.value
        if (supplierId) payload.supplier_id = supplierId
        if (invoice.value?.invoice_number || receiveInvoiceNumber.value) {
            payload.invoice_number = invoice.value?.invoice_number || receiveInvoiceNumber.value
        }
        if (invoice.value) payload.create_invoice = false

        const { data } = await axios.post('/admin/api/inventory/receive-scan', payload)
        applyProductStock(data.product)
        const scanKind = applyScanToInvoice(data.product, code)

        lastScannedName.value = `${data.product?.name || 'Товар'} · ${data.mode === 'marking' ? 'КМ' : 'EAN'} +1`
        scannedId.value = Number(data.product?.id)
        document.getElementById(`product-${data.product?.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
        if (scanKind === 'extra') stockError.value = 'Скан не из накладной'
        else if (scanKind === 'over') stockError.value = 'Больше, чем в накладной'

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

const invoiceExpected = computed(() => (invoice.value?.lines || []).reduce((s, l) => s + Number(l.qty || 0), 0))
const invoiceScanned = computed(() => (invoice.value?.lines || []).reduce((s, l) => s + Number(l.scanned_qty || 0), 0))
const invoiceShort = computed(() => (invoice.value?.lines || []).filter(l => Number(l.scanned_qty) < Number(l.qty)).length)
const invoiceOver = computed(() => (invoice.value?.lines || []).filter(l => Number(l.scanned_qty) > Number(l.qty)).length)
const invoiceUnmapped = computed(() => (invoice.value?.lines || []).filter(l => !l.product_id).length)
const invoiceExtraQty = computed(() => invoiceExtras.value.reduce((s, x) => s + x.qty, 0))
const invoiceBalanced = computed(() => Boolean(
    invoice.value
    && invoice.value.lines.length
    && invoiceUnmapped.value === 0
    && invoiceShort.value === 0
    && invoiceOver.value === 0
    && invoiceExtraQty.value === 0
))

const lineStatus = (line: InvoiceLine) => {
    const got = Number(line.scanned_qty || 0)
    const need = Number(line.qty || 0)
    if (!line.product_id) return 'wait'
    if (got === need) return 'ok'
    if (got > need) return 'over'
    return 'short'
}

const triggerInvoiceInput = () => {
    if (!receiveMode.value) setReceiveMode(true)
    invoiceInput.value?.click()
}

const uploadInvoicePhoto = async (e: Event) => {
    const target = e.target as HTMLInputElement
    const file = target.files?.[0]
    target.value = ''
    if (!file) return
    if (!receiveMode.value) setReceiveMode(true)
    invoiceBusy.value = true
    stockError.value = ''
    try {
        const formData = new FormData()
        formData.append('photo', file)
        const { data } = await axios.post('/admin/api/inventory/parse-invoice', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            timeout: 120000,
        })
        invoice.value = {
            ...data,
            lines: (data.lines || []).map((l: InvoiceLine) => ({
                ...l,
                scanned_qty: Number(l.scanned_qty || 0),
                qty: Number(l.qty || 0),
            })),
        }
        invoiceExtras.value = []
        if (data.supplier_id) receiveSupplierId.value = Number(data.supplier_id)
        if (data.invoice_number) receiveInvoiceNumber.value = data.invoice_number
        const priced = (data.lines || []).find((l: InvoiceLine) => l.unit_cost != null)
        if (priced && receiveUnitCost.value == null) receiveUnitCost.value = Number(priced.unit_cost)
    } catch (err: any) {
        stockError.value = err?.response?.data?.message || 'Не удалось распознать накладную'
        setTimeout(() => { stockError.value = '' }, 5000)
    } finally {
        invoiceBusy.value = false
    }
}

const assignInvoiceProduct = (line: InvoiceLine, productId: number | null) => {
    const id = productId ? Number(productId) : null
    line.product_id = id
    const product = products.value.find(p => Number(p.id) === id)
    line.product_name = product?.name || null
    line.requires_marking = Boolean(product?.requires_marking)
    if (product?.barcode && !line.barcode) line.barcode = product.barcode
    line.note = !product
        ? 'Нет в каталоге — выберите позицию, затем сканируйте'
        : (product.requires_marking ? 'Сканируйте каждый КМ' : null)
}

const dismissInvoice = () => {
    invoice.value = null
    invoiceExtras.value = []
    receiveInvoiceNumber.value = null
}

const closeInvoice = async () => {
    if (!invoice.value || !invoiceBalanced.value) {
        stockError.value = 'Накладная и факт пока не совпадают'
        setTimeout(() => { stockError.value = '' }, 4000)
        return
    }
    invoiceBusy.value = true
    try {
        await axios.post('/admin/api/inventory/close-invoice', {
            supplier_id: receiveSupplierId.value || invoice.value.supplier_id,
            invoice_number: invoice.value.invoice_number,
            invoice_date: invoice.value.invoice_date,
            lines: invoice.value.lines,
            extras: invoiceExtras.value,
        })
        lastScannedName.value = 'Накладная сошлась'
        dismissInvoice()
        setTimeout(() => { lastScannedName.value = '' }, 2500)
    } catch (err: any) {
        stockError.value = err?.response?.data?.message || 'Сверка не прошла'
        setTimeout(() => { stockError.value = '' }, 5000)
    } finally {
        invoiceBusy.value = false
    }
}

const selectReceiveTarget = (id: number | null) => {
    if (receiveProductId.value === id) {
        receiveProductId.value = null
        return
    }
    receiveProductId.value = id
    const p = products.value.find(x => Number(x.id) === Number(id))
    if (p) {
        if (receiveUnitCost.value == null && p.cost_price != null) {
            receiveUnitCost.value = Number(p.cost_price)
        }
        if (!receiveSupplierId.value && p.supplier_id) {
            receiveSupplierId.value = Number(p.supplier_id)
        }
    }
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
            cost_price: product.cost_price == null ? null : Number(product.cost_price),
            stock: Number(product.stock),
            min_stock: product.min_stock == null ? null : Number(product.min_stock),
            barcode: product.barcode || '',
            requires_marking: Boolean(product.requires_marking),
            supplier_id: product.supplier_id == null ? null : Number(product.supplier_id),
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
            cost_price: null,
            stock: 0,
            min_stock: 5,
            barcode: '',
            requires_marking: false,
            supplier_id: null,
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
        if (key === 'current_image_url') return
        if (key === 'requires_marking') {
            formData.append(key, val ? '1' : '0')
            return
        }
        if (key === 'supplier_id' || key === 'min_stock' || key === 'cost_price') {
            formData.append(key, val === null || val === undefined || val === '' ? '' : String(val))
            return
        }
        if (val !== null) {
            formData.append(key, val as any)
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
                    <h1 class="text-4xl font-black uppercase italic text-cyan-500 tracking-tighter">{{ clubName }} <span class="text-white">Warehouse</span></h1>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="w-2 h-2 bg-cyan-500 rounded-full animate-pulse shadow-[0_0_10px_#06b6d4]"></div>
                        <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black italic">
                            <template v-if="receiveMode">
                                <template v-if="invoice">
                                    {{ invoiceBalanced ? 'Накладная и факт совпали' : `Накладная ${invoiceScanned}/${invoiceExpected} · сканируйте факт` }}
                                </template>
                                <template v-else>
                                    {{ receiveTargetName ? `Приёмка → ${receiveTargetName}` : 'Режим приёмки · фото накладной или скан КМ / EAN' }}
                                </template>
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
                    <template v-if="receiveMode && canManageCatalog">
                        <input v-model.number="receiveUnitCost" type="number" min="0" step="0.01" placeholder="Закуп ₽"
                               class="w-28 bg-black border border-amber-500/40 rounded-xl px-3 py-3 text-[11px] text-amber-300 font-bold outline-none" />
                        <select v-model.number="receiveSupplierId"
                                class="bg-black border border-amber-500/40 rounded-xl px-3 py-3 text-[11px] text-white/70 outline-none max-w-[180px]">
                            <option :value="null">Поставщик</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </template>
                    <template v-if="receiveMode">
                        <input ref="invoiceInput" type="file" accept="image/*" capture="environment" class="hidden" @change="uploadInvoicePhoto" />
                        <button type="button" @click="triggerInvoiceInput" :disabled="invoiceBusy"
                                class="px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest border transition-all cursor-pointer disabled:opacity-40"
                                :class="invoice ? 'border-amber-500/50 text-amber-300' : 'border-white/10 text-white/50 hover:text-white'">
                            {{ invoiceBusy ? 'Читаем фото…' : (invoice ? 'Другое фото' : 'Накладная') }}
                        </button>
                    </template>
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

            <div v-if="invoice" class="rounded-[1.25rem] border p-6 space-y-4"
                 :class="invoiceBalanced ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5'">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.3em] font-black text-amber-400/80">Накладная · сверка</div>
                        <div class="text-xl font-black uppercase italic mt-1">
                            {{ invoice.invoice_number || 'Без номера' }}
                            <span v-if="invoice.invoice_date" class="text-white/30 text-sm not-italic font-bold ml-2">{{ invoice.invoice_date }}</span>
                        </div>
                        <div class="text-white/40 text-[11px] mt-1">
                            {{ invoice.supplier_name || (suppliers.find(s => s.id === invoice.supplier_id)?.name) || 'Поставщик не распознан' }}
                            <span v-if="invoice.supplier_inn"> · ИНН {{ invoice.supplier_inn }}</span>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-black italic"
                             :class="invoiceBalanced ? 'text-emerald-400' : 'text-amber-300'">
                            {{ invoiceScanned }}<span class="text-white/20"> / {{ invoiceExpected }}</span>
                        </div>
                        <div class="text-[10px] uppercase font-black tracking-widest mt-1"
                             :class="invoiceBalanced ? 'text-emerald-400/80' : 'text-white/30'">
                            {{ invoiceBalanced ? 'совпало' : `${invoiceShort ? 'не хватает '+invoiceShort+' поз. ' : ''}${invoiceOver ? 'лишних '+invoiceOver+' ' : ''}${invoiceExtraQty ? 'не из док. '+invoiceExtraQty : ''}`.trim() || 'сканируйте факт' }}
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-[10px] uppercase tracking-widest text-white/30">
                            <tr>
                                <th class="py-2 pr-3">Документ</th>
                                <th class="py-2 pr-3">Каталог</th>
                                <th class="py-2 pr-3 text-right">Накл.</th>
                                <th class="py-2 pr-3 text-right">Факт</th>
                                <th class="py-2 text-right">Закуп</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, idx) in invoice.lines" :key="idx"
                                class="border-t border-white/5"
                                :class="{
                                    'text-emerald-300': lineStatus(line) === 'ok',
                                    'text-amber-300': lineStatus(line) === 'short' || lineStatus(line) === 'wait',
                                    'text-red-400': lineStatus(line) === 'over',
                                }">
                                <td class="py-3 pr-3">
                                    <div class="font-black uppercase italic">{{ line.name }}</div>
                                    <div v-if="line.barcode" class="text-[10px] text-white/30 font-mono mt-0.5">{{ line.barcode }}</div>
                                    <div v-if="line.note" class="text-[10px] text-white/35 mt-0.5">{{ line.note }}</div>
                                </td>
                                <td class="py-3 pr-3">
                                    <select :value="line.product_id ?? ''"
                                            @change="assignInvoiceProduct(line, Number($event.target.value) || null)"
                                            class="w-full max-w-[220px] bg-black border border-white/10 rounded-lg px-2 py-2 text-[11px] outline-none">
                                        <option value="">Не сопоставлено</option>
                                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </select>
                                </td>
                                <td class="py-3 pr-3 text-right font-black">{{ line.qty }}</td>
                                <td class="py-3 pr-3 text-right font-black">{{ line.scanned_qty }}</td>
                                <td class="py-3 text-right text-white/50">{{ line.unit_cost != null ? Number(line.unit_cost).toFixed(2) : '—' }}</td>
                            </tr>
                            <tr v-for="extra in invoiceExtras" :key="'x'+extra.product_id" class="border-t border-red-500/20 text-red-400">
                                <td class="py-3 pr-3 font-black uppercase italic" colspan="2">Не в накладной · {{ extra.name }}</td>
                                <td class="py-3 pr-3 text-right">0</td>
                                <td class="py-3 pr-3 text-right font-black">{{ extra.qty }}</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap gap-3 justify-end">
                    <button type="button" @click="dismissInvoice"
                            class="px-5 py-3 border border-white/10 text-white/40 hover:text-white rounded-2xl text-[10px] font-black uppercase cursor-pointer">
                        Сбросить черновик
                    </button>
                    <button type="button" @click="closeInvoice" :disabled="!invoiceBalanced || invoiceBusy"
                            class="px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest cursor-pointer disabled:opacity-30"
                            :class="invoiceBalanced ? 'bg-emerald-500 text-black' : 'bg-white/10 text-white/40'">
                        {{ invoiceBalanced ? 'Сошлось · закрыть' : 'Ждём совпадения' }}
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
                        item.stock <= 0 ? 'border-red-500/50 bg-red-950/20 opacity-80' : isLow(item) ? 'border-red-500/40 bg-red-900/5' : 'border-white/5',
                        scannedId === item.id ? 'ring-4 ring-cyan-500 border-cyan-500 scale-[1.03] z-20 shadow-[0_0_60px_rgba(6,182,212,0.4)] bg-cyan-500/5' : ''
                     ]">

                    <div v-if="scannedId === item.id" class="absolute inset-0 bg-cyan-500/10 animate-pulse pointer-events-none"></div>
                    <div v-if="item.requires_marking" class="absolute top-4 left-4 z-10 px-3 py-1 rounded-lg bg-violet-600/90 text-white text-[9px] font-black uppercase tracking-widest">
                        КМ
                    </div>
                    <div v-if="item.stock <= 0" class="absolute top-4 right-4 z-10 px-3 py-1 rounded-lg bg-red-600 text-white text-[9px] font-black uppercase tracking-widest">
                        Нет в наличии
                    </div>
                    <div v-else-if="item.min_stock != null && isLow(item)" class="absolute top-4 right-4 z-10 px-3 py-1 rounded-lg bg-amber-500 text-black text-[9px] font-black uppercase tracking-widest">
                        Мало (≤{{ item.min_stock }})
                    </div>

                    <div class="aspect-square bg-white/5 rounded-[1rem] mb-6 flex items-center justify-center border border-white/5 relative overflow-hidden group-hover:bg-cyan-500/5 transition-all">
                        <img :src="item.image ? (item.image.startsWith('/') ? item.image : '/' + item.image) : '/images/shop/default.png'"
                             :alt="item.name" loading="lazy" decoding="async"
                             class="w-3/4 h-3/4 object-contain transition-transform duration-700 group-hover:scale-110" />
                        <div class="absolute top-5 right-5 px-4 py-1.5 bg-black/80 backdrop-blur-xl rounded-full border border-white/10 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full" :class="isLow(item) ? 'bg-red-500 animate-ping' : 'bg-cyan-500'"></span>
                            <span class="text-[11px] font-black text-white italic">{{ item.stock }} <span class="opacity-30">шт</span></span>
                        </div>
                    </div>

                    <div class="flex justify-between items-start mb-3">
                        <div class="text-base font-black text-white uppercase italic tracking-tighter leading-tight">{{ item.name }}</div>
                        <div class="text-right">
                            <div class="text-2xl font-black text-cyan-500 italic tracking-tighter">{{ Math.floor(item.price) }}₽</div>
                            <div v-if="item.cost_price != null" class="text-[9px] text-white/30 uppercase font-black">себ. {{ Number(item.cost_price).toFixed(0) }}₽</div>
                        </div>
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
                                <input v-model="form.barcode" data-scan-capture type="text" placeholder="01… / EAN-13" class="w-full bg-black border border-cyan-500/50 rounded-2xl px-5 py-4 text-cyan-500 font-bold focus:border-cyan-500 outline-none" />
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

                        <div class="grid grid-cols-3 gap-6">
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Себестоимость</label>
                                <input v-model.number="form.cost_price" type="number" min="0" step="0.01"
                                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" />
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Мин. остаток</label>
                                <input v-model.number="form.min_stock" type="number" min="0"
                                       class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" placeholder="алерт" />
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Поставщик</label>
                                <select v-model.number="form.supplier_id" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold outline-none">
                                    <option :value="null">—</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
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
